<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\InventoryLayer;
use App\Models\InventoryLayerConsumption;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place stock moves.
 *
 * Every purchase, sale, return and adjustment comes through `post()`, on the
 * web and through the API alike. Nothing else may write to `products.on_hand`,
 * `inventory_layers` or `inventory_movements`.
 *
 * The rules it keeps, all inside one transaction:
 *
 *   - **Stock never goes negative.** An outgoing line that asks for more than
 *     the batches hold is refused and the whole document rolls back.
 *   - **A document posts once.** Movements already written against the same
 *     document mean it has been posted, and it is refused.
 *   - **No backdating.** Costs are taken from the oldest batch first, in the
 *     order things were posted, so a document dated before the last one would
 *     eat the wrong batches.
 *   - **`on_hand` always equals the sum of the batches behind it.** Every
 *     test in `InventoryLedgerTest` ends by checking exactly that.
 *
 * Product rows are locked in ascending id order. Two documents that happen to
 * share products would otherwise be able to take each other's locks in the
 * opposite order and deadlock.
 *
 * Quantities may carry three decimals (2.5 m). Every sum here is done in
 * whole thousandths through `App\Support\Quantity`, and every batch and
 * product is written with the exact new figure rather than a float added on
 * top of the old one, so "on hand equals its batches" never drifts by a
 * thousandth. A product whose unit is not switched to decimals is held to
 * whole numbers here as well as on its form.
 *
 * **Stock sits at a location** (phase 31.2). Every batch and movement names
 * one, and `location_stocks` holds each product's figure at each place, kept
 * beside the total on `products.on_hand`. Only `post()` is told a location
 * (the default one until a document can name its own); a void, a return and
 * a purchase return follow the batches they touch, wherever those sit. The
 * product rows stay the one lock: every path takes them first, so the
 * per-location rows are read under it with a locking read and written with
 * their exact new figure.
 *
 * **A transfer moves stock between locations** (phase 31.5) through
 * `transfer()`, never two `post()` calls: out of the source oldest batch
 * first, and into the destination as new batches at the cost of each batch
 * it ate. `reverseTransfer()` undoes one while that stock is untouched.
 *
 * A note on the tests: they run on SQLite, which takes one writer at a time,
 * so they prove the arithmetic but not the locking. MySQL is the real guard.
 */
class InventoryLedger
{
    public function __construct(protected AuditService $audit) {}

    /**
     * Throw the dashboard's cached figures away once the document is safely
     * committed.
     *
     * After the commit, not inside it: another request reading the figures
     * mid-transaction would cache the state from before this document and
     * leave it there for the rest of the TTL. A rolled-back transaction
     * discards the callback, so a refused document flushes nothing.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }

    /**
     * Post one document.
     *
     * @param  Model  $document  What is being posted, for a trail back.
     * @param  list<array{product_id:int,quantity:int|float|string,unit_cost?:float|string|null}>  $lines
     * @param  Location|null  $at  Where the stock comes in or goes out; the
     *                             default location when none is named.
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function post(
        Model $document,
        string $reference,
        Carbon $date,
        MovementType $type,
        array $lines,
        ?Location $at = null,
    ): array {
        if ($lines === []) {
            throw InventoryException::nothingToPost();
        }

        return DB::transaction(function () use ($document, $reference, $date, $type, $lines, $at) {
            // The lock comes first. Checking for an earlier posting before
            // taking it would let two posts of the same document both see
            // nothing, then queue on the products and both go through.
            $products = $this->lockProducts($lines);

            $this->refusePostingTwice($document, $reference);
            $this->refuseBackdating($date);

            $location = $at ?? Location::default() ?? throw InventoryException::noDefaultLocation();

            $movements = [];
            // The running balance per product, in thousandths, so a document
            // naming the same product on two lines still writes a correct
            // stock card. Beside it, each product's figure at each location.
            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;

            foreach ($lines as $line) {
                $product = $products[$line['product_id']];
                $quantity = Quantity::toMilli($line['quantity']);

                if ($quantity < 1) {
                    throw InventoryException::quantityMustBePositive($product->sku);
                }

                $this->refuseParts($product, $quantity);

                $movements[] = $type->isIncoming()
                    ? $this->receive($document, $reference, $date, $type, $product, $location, $quantity, $line, $balances, $stocks)
                    : $this->issue($document, $reference, $date, $type, $product, $location, $quantity, $balances, $stocks);
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $movements;
        });
    }

    /* ------------------------------------------------------------ Incoming */

    /**
     * Bring stock in: open a batch at what it cost, and write the card.
     *
     * @param  int  $quantity  In thousandths.
     * @param  array<string, mixed>  $line
     * @param  array<int, int>  $balances  In thousandths.
     */
    protected function receive(
        Model $document,
        string $reference,
        Carbon $date,
        MovementType $type,
        Product $product,
        Location $location,
        int $quantity,
        array $line,
        array &$balances,
        array &$stocks,
    ): InventoryMovement {
        $unitCost = round((float) ($line['unit_cost'] ?? $product->cost), 2);

        InventoryLayer::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'source_type' => $document->getMorphClass(),
            'source_id' => $document->getKey(),
            'reference' => $reference,
            'layer_date' => $date,
            'quantity_received' => Quantity::toDb($quantity),
            'quantity_remaining' => Quantity::toDb($quantity),
            'unit_cost' => $unitCost,
        ]);

        $balances[$product->id] += $quantity;
        $stocks[$product->id][$location->id] = ($stocks[$product->id][$location->id] ?? 0) + $quantity;

        return InventoryMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'source_type' => $document->getMorphClass(),
            'source_id' => $document->getKey(),
            'reference' => $reference,
            'type' => $type,
            'movement_date' => $date,
            'quantity_in' => Quantity::toDb($quantity),
            'quantity_out' => 0,
            'balance_after' => Quantity::toDb($balances[$product->id]),
            'unit_cost' => $unitCost,
            'total_cost' => Quantity::cents($quantity, $unitCost) / 100,
        ]);
    }

    /* ------------------------------------------------------------ Outgoing */

    /**
     * Take stock out, oldest batch first.
     *
     * @param  int  $quantity  In thousandths.
     * @param  array<int, int>  $balances  In thousandths.
     */
    protected function issue(
        Model $document,
        string $reference,
        Carbon $date,
        MovementType $type,
        Product $product,
        Location $location,
        int $quantity,
        array &$balances,
        array &$stocks,
    ): InventoryMovement {
        $available = $stocks[$product->id][$location->id] ?? 0;
        $total = $balances[$product->id];

        if ($quantity > $available) {
            // Enough on the books, but some of it sits somewhere else: say
            // where, rather than "not enough in stock" over a full shelf.
            if ($quantity <= $total) {
                throw InventoryException::notEnoughStockAt(
                    $product->sku,
                    $location->name,
                    Quantity::fromMilli($quantity),
                    Quantity::fromMilli($available),
                    Quantity::fromMilli($total - $available),
                );
            }

            throw InventoryException::notEnoughStock(
                $product->sku,
                Quantity::fromMilli($quantity),
                Quantity::fromMilli($available),
            );
        }

        $layers = InventoryLayer::query()
            ->fifo($product->id, $location->id)
            ->lockForUpdate()
            ->get();

        $inLayers = $layers->sum(fn (InventoryLayer $layer) => Quantity::toMilli($layer->quantity_remaining));

        if ($inLayers < $quantity) {
            throw InventoryException::layersDoNotMatchStock(
                $product->sku,
                Quantity::fromMilli($available),
                Quantity::fromMilli($inLayers),
            );
        }

        $balances[$product->id] -= $quantity;
        $stocks[$product->id][$location->id] = $available - $quantity;

        $movement = InventoryMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'source_type' => $document->getMorphClass(),
            'source_id' => $document->getKey(),
            'reference' => $reference,
            'type' => $type,
            'movement_date' => $date,
            'quantity_in' => 0,
            'quantity_out' => Quantity::toDb($quantity),
            'balance_after' => Quantity::toDb($balances[$product->id]),
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);

        $outstanding = $quantity;
        // Centavos times thousandths, rounded once at the end, so a line
        // drawn from three batches is not rounded three times.
        $cost = 0;

        foreach ($layers as $layer) {
            if ($outstanding === 0) {
                break;
            }

            $remaining = Quantity::toMilli($layer->quantity_remaining);
            $taken = min($outstanding, $remaining);
            $layerCost = (float) $layer->unit_cost;

            InventoryLayerConsumption::create([
                'inventory_movement_id' => $movement->id,
                'inventory_layer_id' => $layer->id,
                'quantity' => Quantity::toDb($taken),
                'unit_cost' => $layerCost,
            ]);

            // Straight to the table: a layer writes no audit row of its own.
            InventoryLayer::whereKey($layer->id)
                ->update(['quantity_remaining' => Quantity::toDb($remaining - $taken)]);

            $cost += Quantity::centavos($layerCost) * $taken;
            $outstanding -= $taken;
        }

        // What the stock actually cost, averaged over the batches it came
        // from. For a sale this is the cost of goods sold on that line.
        $cents = Quantity::settle($cost);

        $movement->forceFill([
            'total_cost' => $cents / 100,
            'unit_cost' => $this->unitCostOf($cents, $quantity),
        ])->save();

        return $movement;
    }

    /**
     * Take back everything a document brought in.
     *
     * This is what voiding a purchase does. It cannot go through `post()`:
     * that would find the document's own movements and refuse it as already
     * posted, and it would eat the oldest batches in the warehouse rather
     * than the ones this document actually created.
     *
     * Locks are taken in the same order as everywhere else — the products
     * first, in ascending id order, then the batches themselves.
     *
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function reverseReceipt(Model $document, string $reference, Carbon $date): array
    {
        return DB::transaction(function () use ($document, $reference, $date) {
            $layers = InventoryLayer::where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->orderBy('product_id')
                ->orderBy('id')
                ->get();

            if ($layers->isEmpty()) {
                throw InventoryException::nothingToReverse($reference);
            }

            $products = Product::whereIn('id', $layers->pluck('product_id')->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Read again under the lock: the check on the model happened
            // before anything was held, so by now somebody may have sold a
            // unit of it.
            $layers = InventoryLayer::whereIn('id', $layers->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $movements = [];

            foreach ($layers as $layer) {
                $product = $products[$layer->product_id];
                $quantity = Quantity::toMilli($layer->quantity_remaining);

                if ($quantity < Quantity::toMilli($layer->quantity_received)) {
                    throw InventoryException::stockHasMoved(
                        $product->sku,
                        $layer->quantity_received,
                        $layer->quantity_remaining,
                        $this->transfersHolding([$layer->id]),
                    );
                }

                if ($quantity === 0) {
                    continue;
                }

                $balances[$product->id] -= $quantity;
                // Out of the location the delivery came into.
                $stocks[$product->id][$layer->location_id] = ($stocks[$product->id][$layer->location_id] ?? 0) - $quantity;

                $cost = Quantity::cents($quantity, $layer->unit_cost) / 100;

                $movement = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $layer->location_id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::PurchaseVoid,
                    'movement_date' => $date,
                    'quantity_in' => 0,
                    'quantity_out' => Quantity::toDb($quantity),
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $layer->unit_cost,
                    'total_cost' => $cost,
                ]);

                InventoryLayerConsumption::create([
                    'inventory_movement_id' => $movement->id,
                    'inventory_layer_id' => $layer->id,
                    'quantity' => Quantity::toDb($quantity),
                    'unit_cost' => $layer->unit_cost,
                ]);

                InventoryLayer::whereKey($layer->id)->update(['quantity_remaining' => 0]);

                $movements[] = $movement;
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $movements;
        });
    }

    /**
     * Put back everything a document took out.
     *
     * This is what voiding a sale does, and it is the mirror of
     * `reverseReceipt()`. The sale kept a receipt of which batch each unit
     * came out of, so every unit goes back into the batch it actually left,
     * at that batch's own cost.
     *
     * No new batch is opened. The old one's `quantity_remaining` simply goes
     * back up, which keeps "on hand equals the sum of the batches" true and
     * leaves FIFO in the right order.
     *
     * The trade-off, stated plainly: voiding an old sale returns stock to an
     * old batch, so the next sale is costed from that batch again. That is
     * truer to what happened than inventing a fresh batch would be.
     *
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function reverseIssue(Model $document, string $reference, Carbon $date): array
    {
        return DB::transaction(function () use ($document, $reference, $date) {
            $movements = InventoryMovement::where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->where('quantity_out', '>', 0)
                ->orderBy('id')
                ->get();

            if ($movements->isEmpty()) {
                throw InventoryException::nothingToPutBack($reference);
            }

            $products = Product::whereIn('id', $movements->pluck('product_id')->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $written = [];

            foreach ($movements as $movement) {
                $product = $products[$movement->product_id];

                $taken = InventoryLayerConsumption::where('inventory_movement_id', $movement->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                // Back into the batches it left, at the locations they sit.
                $this->putBack($taken->groupBy('inventory_layer_id')->map(
                    fn ($rows) => $rows->sum(fn (InventoryLayerConsumption $c) => Quantity::toMilli($c->quantity))
                )->all(), $product->id, $stocks);

                $quantity = Quantity::toMilli($movement->quantity_out);
                $balances[$product->id] += $quantity;

                $written[] = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $movement->location_id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::SaleVoid,
                    'movement_date' => $date,
                    'quantity_in' => Quantity::toDb($quantity),
                    'quantity_out' => 0,
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $movement->unit_cost,
                    'total_cost' => $movement->total_cost,
                ]);
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $written;
        });
    }

    /**
     * Put back part of what a document took out.
     *
     * This is a sales return. Where `reverseIssue()` undoes a whole sale,
     * this undoes some of it: each unit goes back into the batch it actually
     * came out of, at that batch's own cost, and the consumption row records
     * how much of it has now come back.
     *
     * @param  list<array{product_id:int,take:array<int,int|float|string>}>  $lines
     *                                                                               `take` maps a consumption id to how much of
     *                                                                               it is coming back, as a quantity.
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function restoreConsumed(Model $document, string $reference, Carbon $date, array $lines): array
    {
        if ($lines === []) {
            throw InventoryException::nothingReturned();
        }

        return DB::transaction(function () use ($document, $reference, $date, $lines) {
            $products = $this->lockProducts($lines);

            // The form checks the date too, but outside the lock. This is
            // the one that decides.
            $this->refuseBackdating($date);

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $written = [];

            foreach ($lines as $line) {
                $product = $products[$line['product_id']];
                $quantity = 0;
                $cost = 0;
                $back = [];

                $consumptions = InventoryLayerConsumption::whereIn('id', array_keys($line['take']))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Written first with nothing in it, and filled in once the
                // batches are known; its location is set from them below.
                $movement = new InventoryMovement([
                    'product_id' => $product->id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::SalesReturn,
                    'movement_date' => $date,
                    'quantity_in' => 0,
                    'quantity_out' => 0,
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => 0,
                    'total_cost' => 0,
                ]);

                foreach ($line['take'] as $consumptionId => $wanted) {
                    $wanted = Quantity::toMilli($wanted);

                    if ($wanted < 1) {
                        continue;
                    }

                    $consumption = $consumptions[$consumptionId] ?? null;

                    if (! $consumption) {
                        continue;
                    }

                    $returned = Quantity::toMilli($consumption->returned_quantity);
                    $left = Quantity::toMilli($consumption->quantity) - $returned;

                    if ($wanted > $left) {
                        throw InventoryException::moreThanWasSold(
                            $product->sku,
                            Quantity::fromMilli($wanted),
                            Quantity::fromMilli(max(0, $left)),
                        );
                    }

                    $this->refuseParts($product, $wanted);

                    // Back into the batch it left, so FIFO keeps its order.
                    $back[$consumption->inventory_layer_id] = ($back[$consumption->inventory_layer_id] ?? 0) + $wanted;

                    InventoryLayerConsumption::whereKey($consumption->id)
                        ->update(['returned_quantity' => Quantity::toDb($returned + $wanted)]);

                    $quantity += $wanted;
                    $cost += Quantity::centavos($consumption->unit_cost) * $wanted;
                }

                if ($quantity === 0) {
                    continue;
                }

                // One sale line left one location, so its batches all sit
                // there; the return comes back to it.
                $locations = $this->putBack($back, $product->id, $stocks);

                $balances[$product->id] += $quantity;
                $cents = Quantity::settle($cost);

                $movement->forceFill([
                    'location_id' => $locations[0],
                    'quantity_in' => Quantity::toDb($quantity),
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $this->unitCostOf($cents, $quantity),
                    'total_cost' => $cents / 100,
                ])->save();

                $written[] = $movement;
            }

            if ($written === []) {
                throw InventoryException::nothingReturned();
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $written;
        });
    }

    /**
     * Take stock out of named batches rather than the oldest ones.
     *
     * This is a purchase return. The goods go back to the supplier who sent
     * them, so they have to come out of that supplier's own delivery — not
     * whatever happens to be oldest on the shelf.
     *
     * @param  list<array{product_id:int,layer_id:int,quantity:int|float|string}>  $lines
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function issueFromLayers(Model $document, string $reference, Carbon $date, array $lines): array
    {
        if ($lines === []) {
            throw InventoryException::nothingReturned();
        }

        return DB::transaction(function () use ($document, $reference, $date, $lines) {
            $products = $this->lockProducts($lines);

            // The form checks the date too, but outside the lock. This is
            // the one that decides.
            $this->refuseBackdating($date);

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $written = [];

            foreach ($lines as $line) {
                $quantity = Quantity::toMilli($line['quantity']);

                if ($quantity < 1) {
                    continue;
                }

                $product = $products[$line['product_id']];

                $this->refuseParts($product, $quantity);

                $layer = InventoryLayer::whereKey($line['layer_id'])->lockForUpdate()->first();
                $remaining = Quantity::toMilli($layer?->quantity_remaining);

                if (! $layer || $remaining < $quantity) {
                    throw InventoryException::notEnoughOfThisDelivery(
                        $product->sku,
                        Quantity::fromMilli($quantity),
                        Quantity::fromMilli($remaining),
                    );
                }

                $unitCost = (float) $layer->unit_cost;
                $cost = Quantity::cents($quantity, $unitCost) / 100;

                $balances[$product->id] -= $quantity;
                // Out of the location that delivery sits at.
                $stocks[$product->id][$layer->location_id] = ($stocks[$product->id][$layer->location_id] ?? 0) - $quantity;

                $movement = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $layer->location_id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::PurchaseReturn,
                    'movement_date' => $date,
                    'quantity_in' => 0,
                    'quantity_out' => Quantity::toDb($quantity),
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $unitCost,
                    'total_cost' => $cost,
                ]);

                InventoryLayerConsumption::create([
                    'inventory_movement_id' => $movement->id,
                    'inventory_layer_id' => $layer->id,
                    'quantity' => Quantity::toDb($quantity),
                    'unit_cost' => $unitCost,
                ]);

                InventoryLayer::whereKey($layer->id)
                    ->update(['quantity_remaining' => Quantity::toDb($remaining - $quantity)]);

                $written[] = $movement;
            }

            if ($written === []) {
                throw InventoryException::nothingReturned();
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $written;
        });
    }

    /* ----------------------------------------------------------- Transfers */

    /**
     * Move stock from one location to another (phase 31.5).
     *
     * Each line leaves the source oldest batch first, exactly as a sale
     * would, and **every batch it ate opens a batch of its own at the
     * destination, at that batch's cost** — never one averaged batch, which
     * would round and shift the stock's value by centavos. A batch is never
     * moved by changing its location: consumption rows, `putBack()` and a
     * purchase line's batch all assume a batch stays where it was opened.
     * The new batch names the one it was split from.
     *
     * The product's total does not change. The stock card reads one
     * *Transfer Out* at the source and one *Transfer In* at the destination
     * per line, both at the same cost.
     *
     * @param  list<array{product_id:int,quantity:int|float|string}>  $lines
     * @return list<array{out:InventoryMovement,in:InventoryMovement}>
     *
     * @throws InventoryException
     */
    public function transfer(
        Model $document,
        string $reference,
        Carbon $date,
        array $lines,
        Location $from,
        Location $to,
    ): array {
        if ($lines === []) {
            throw InventoryException::nothingToPost();
        }

        if ($from->id === $to->id) {
            throw InventoryException::sameLocation();
        }

        return DB::transaction(function () use ($document, $reference, $date, $lines, $from, $to) {
            $products = $this->lockProducts($lines);

            $this->refusePostingTwice($document, $reference);
            $this->refuseBackdating($date);

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $written = [];

            foreach ($lines as $line) {
                $product = $products[$line['product_id']];
                $quantity = Quantity::toMilli($line['quantity']);

                if ($quantity < 1) {
                    throw InventoryException::quantityMustBePositive($product->sku);
                }

                $this->refuseParts($product, $quantity);

                $out = $this->issue($document, $reference, $date, MovementType::TransferOut, $product, $from, $quantity, $balances, $stocks);

                // One batch at the destination for each batch it came out of.
                InventoryLayerConsumption::where('inventory_movement_id', $out->id)
                    ->orderBy('id')
                    ->get(['inventory_layer_id', 'quantity', 'unit_cost'])
                    ->each(fn (InventoryLayerConsumption $taken) => InventoryLayer::create([
                        'product_id' => $product->id,
                        'location_id' => $to->id,
                        'source_type' => $document->getMorphClass(),
                        'source_id' => $document->getKey(),
                        'transferred_from_layer_id' => $taken->inventory_layer_id,
                        'reference' => $reference,
                        'layer_date' => $date,
                        'quantity_received' => $taken->quantity,
                        'quantity_remaining' => $taken->quantity,
                        'unit_cost' => $taken->unit_cost,
                    ]));

                $balances[$product->id] += $quantity;
                $stocks[$product->id][$to->id] = ($stocks[$product->id][$to->id] ?? 0) + $quantity;

                $in = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $to->id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::TransferIn,
                    'movement_date' => $date,
                    'quantity_in' => Quantity::toDb($quantity),
                    'quantity_out' => 0,
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $out->unit_cost,
                    'total_cost' => $out->total_cost,
                ]);

                $written[] = ['out' => $out, 'in' => $in];
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $written;
        });
    }

    /**
     * Undo a transfer: what it brought to the destination comes back off,
     * and goes back into the very batches it left at the source, so FIFO and
     * a purchase return find them where they were.
     *
     * Only while what it brought in is untouched — the same rule as a void
     * of a delivery. Once some of it has been sold or moved on, the goods are
     * moved back with another transfer instead.
     *
     * @return list<InventoryMovement>
     *
     * @throws InventoryException
     */
    public function reverseTransfer(Model $document, string $reference, Carbon $date): array
    {
        return DB::transaction(function () use ($document, $reference, $date) {
            $layers = InventoryLayer::where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->orderBy('id')
                ->get(['id', 'product_id']);

            $outgoing = InventoryMovement::where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->where('type', MovementType::TransferOut)
                ->orderBy('id')
                ->get();

            if ($layers->isEmpty() || $outgoing->isEmpty()) {
                throw InventoryException::nothingToReverse($reference);
            }

            $products = Product::whereIn('id', $layers->pluck('product_id')->merge($outgoing->pluck('product_id'))->unique()->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Read again under the lock: somebody may have sold from them.
            $layers = InventoryLayer::whereIn('id', $layers->pluck('id'))
                ->with('location:id,name')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $balances = $this->balancesOf($products);
            $stocks = $this->stocksOf($products);
            $before = $stocks;
            $written = [];

            // Off the destination first.
            foreach ($layers as $layer) {
                $product = $products[$layer->product_id];
                $quantity = Quantity::toMilli($layer->quantity_remaining);

                if ($quantity < Quantity::toMilli($layer->quantity_received)) {
                    throw InventoryException::transferHasMoved(
                        $product->sku,
                        $layer->location?->name ?? 'the destination',
                        $layer->quantity_received,
                        $layer->quantity_remaining,
                    );
                }

                $balances[$product->id] -= $quantity;
                $stocks[$product->id][$layer->location_id] = ($stocks[$product->id][$layer->location_id] ?? 0) - $quantity;

                $movement = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $layer->location_id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::TransferVoidOut,
                    'movement_date' => $date,
                    'quantity_in' => 0,
                    'quantity_out' => Quantity::toDb($quantity),
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $layer->unit_cost,
                    'total_cost' => Quantity::cents($quantity, $layer->unit_cost) / 100,
                ]);

                InventoryLayerConsumption::create([
                    'inventory_movement_id' => $movement->id,
                    'inventory_layer_id' => $layer->id,
                    'quantity' => Quantity::toDb($quantity),
                    'unit_cost' => $layer->unit_cost,
                ]);

                InventoryLayer::whereKey($layer->id)->update(['quantity_remaining' => 0]);

                $written[] = $movement;
            }

            // Then back into the batches it left, at the source.
            foreach ($outgoing as $movement) {
                $product = $products[$movement->product_id];

                $taken = InventoryLayerConsumption::where('inventory_movement_id', $movement->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $this->putBack($taken->groupBy('inventory_layer_id')->map(
                    fn ($rows) => $rows->sum(fn (InventoryLayerConsumption $c) => Quantity::toMilli($c->quantity))
                )->all(), $product->id, $stocks);

                $quantity = Quantity::toMilli($movement->quantity_out);
                $balances[$product->id] += $quantity;

                $written[] = InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => $movement->location_id,
                    'source_type' => $document->getMorphClass(),
                    'source_id' => $document->getKey(),
                    'reference' => $reference,
                    'type' => MovementType::TransferVoidIn,
                    'movement_date' => $date,
                    'quantity_in' => Quantity::toDb($quantity),
                    'quantity_out' => 0,
                    'balance_after' => Quantity::toDb($balances[$product->id]),
                    'unit_cost' => $movement->unit_cost,
                    'total_cost' => $movement->total_cost,
                ]);
            }

            $this->writeBalances($balances);
            $this->writeStocks($stocks, $before);

            $this->refreshTheFigures();

            return $written;
        });
    }

    /**
     * The posted transfers that took stock out of these batches and still
     * hold it elsewhere — named in a refusal, so the user knows what to do.
     * `voidable` says whether what each brought to the other place is still
     * untouched, so that voiding it would put the goods back.
     *
     * @param  list<int>  $layerIds
     * @return list<array{reference:string,voidable:bool}>
     */
    public function transfersHolding(array $layerIds): array
    {
        if ($layerIds === []) {
            return [];
        }

        $morph = (new StockTransfer)->getMorphClass();

        $transfers = DB::table('inventory_layer_consumptions')
            ->join('inventory_movements', 'inventory_movements.id', '=', 'inventory_layer_consumptions.inventory_movement_id')
            ->join('stock_transfers', function ($join) use ($morph) {
                $join->on('stock_transfers.id', '=', 'inventory_movements.source_id')
                    ->where('inventory_movements.source_type', $morph);
            })
            ->whereIn('inventory_layer_consumptions.inventory_layer_id', $layerIds)
            ->where('inventory_movements.type', MovementType::TransferOut->value)
            ->where('stock_transfers.status', DocumentStatus::Posted->value)
            ->orderBy('stock_transfers.id')
            ->distinct()
            ->pluck('stock_transfers.reference', 'stock_transfers.id');

        if ($transfers->isEmpty()) {
            return [];
        }

        // One read: which of them opened a batch that has been drawn on since.
        $touched = InventoryLayer::query()
            ->where('source_type', $morph)
            ->whereIn('source_id', $transfers->keys())
            ->whereColumn('quantity_remaining', '<', 'quantity_received')
            ->distinct()
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $transfers->map(fn (string $reference, int $id) => [
            'reference' => $reference,
            'voidable' => ! in_array($id, $touched, true),
        ])->values()->all();
    }

    /* ------------------------------------------------------------- Guards */

    /**
     * Lock every product the document names, lowest id first.
     *
     * The unit rides along, so `refuseParts()` can ask it whether
     * decimals are allowed without a query per line.
     *
     * @param  list<array{product_id:int}>  $lines
     * @return Collection<int, Product>
     */
    protected function lockProducts(array $lines): Collection
    {
        $ids = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();

        return Product::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->with('unit:id,code,allows_decimals')
            ->get()
            ->keyBy('id');
    }

    /**
     * Refuse a part of a unit that is counted whole: 2.5 pcs.
     *
     * Every form checks this first. This is the one that decides, for the
     * API, a seeder or anything else that reaches the ledger.
     *
     * @param  int  $quantity  In thousandths.
     */
    protected function refuseParts(Product $product, int $quantity): void
    {
        if ($quantity % Quantity::SCALE === 0 || $product->unit?->allows_decimals) {
            return;
        }

        throw InventoryException::wholeNumbersOnly(
            $product->sku,
            Quantity::fromMilli($quantity),
            $product->unit?->code,
        );
    }

    /**
     * Each product's stock, in thousandths.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    protected function balancesOf(Collection $products): array
    {
        return $products->map(fn (Product $p) => Quantity::toMilli($p->on_hand))->all();
    }

    /**
     * Write each product's new stock, the exact figure worked out.
     *
     * One write per product, through the query builder, so the product
     * fires no model event and writes no audit row for stock that the
     * document already explains.
     *
     * @param  array<int, int>  $balances  In thousandths.
     */
    protected function writeBalances(array $balances): void
    {
        foreach ($balances as $productId => $balance) {
            Product::whereKey($productId)->update(['on_hand' => Quantity::toDb($balance)]);
        }
    }

    /**
     * Each product's stock at each location, in thousandths.
     *
     * A locking read, taken after the product rows are already held: a
     * plain read inside the transaction could answer from a snapshot older
     * than the last document's commit (REPEATABLE READ), and two tills would
     * both see the same pieces.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, array<int, int>> Product => location => thousandths.
     */
    protected function stocksOf(Collection $products): array
    {
        $stocks = [];

        LocationStock::whereIn('product_id', $products->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'location_id', 'on_hand'])
            ->each(function (LocationStock $row) use (&$stocks) {
                $stocks[$row->product_id][$row->location_id] = Quantity::toMilli($row->on_hand);
            });

        return $stocks;
    }

    /**
     * Write each changed figure, exactly. A product's first stock at a
     * location opens its row.
     *
     * @param  array<int, array<int, int>>  $stocks  As they are now.
     * @param  array<int, array<int, int>>  $before  As `stocksOf()` read them.
     */
    protected function writeStocks(array $stocks, array $before): void
    {
        foreach ($stocks as $productId => $locations) {
            foreach ($locations as $locationId => $milli) {
                $had = $before[$productId][$locationId] ?? null;

                if ($had === $milli) {
                    continue;
                }

                if ($had === null) {
                    LocationStock::create([
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'on_hand' => Quantity::toDb($milli),
                    ]);

                    continue;
                }

                LocationStock::where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->update(['on_hand' => Quantity::toDb($milli)]);
            }
        }
    }

    /**
     * Put stock back into the batches it came out of, and onto the figure
     * of the location each batch sits at.
     *
     * Each batch is read under a lock and written with its exact new
     * figure. An `increment()` would add a float on SQLite, and 0.1 + 0.2
     * would be stored as 0.30000000000000004.
     *
     * @param  array<int, int>  $quantities  Batch id => thousandths.
     * @param  array<int, array<int, int>>  $stocks  Product => location => thousandths.
     * @return list<int> The locations the stock went back to, first batch first.
     */
    protected function putBack(array $quantities, int $productId, array &$stocks): array
    {
        $quantities = array_filter($quantities);

        if ($quantities === []) {
            return [];
        }

        $layers = InventoryLayer::whereIn('id', array_keys($quantities))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'location_id', 'quantity_remaining']);

        $locations = [];

        foreach ($layers as $layer) {
            InventoryLayer::whereKey($layer->id)->update([
                'quantity_remaining' => Quantity::toDb(
                    Quantity::toMilli($layer->quantity_remaining) + $quantities[$layer->id]
                ),
            ]);

            $stocks[$productId][$layer->location_id] = ($stocks[$productId][$layer->location_id] ?? 0) + $quantities[$layer->id];
            $locations[] = (int) $layer->location_id;
        }

        return array_values(array_unique($locations));
    }

    /**
     * What one unit cost, from a line's total and its quantity.
     *
     * @param  int  $cents  The line's cost, in centavos.
     * @param  int  $quantity  In thousandths.
     */
    protected function unitCostOf(int $cents, int $quantity): float
    {
        return round(($cents * 10) / $quantity, 2);
    }

    /**
     * A document already carrying movements has been posted before.
     *
     * The read is a locking one. A plain `exists()` under MySQL's repeatable
     * read can answer from a snapshot taken before the first posting
     * committed, which is exactly the case this guard is here to catch.
     */
    protected function refusePostingTwice(Model $document, string $reference): void
    {
        $posted = InventoryMovement::where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->lockForUpdate()
            ->first();

        if ($posted !== null) {
            throw InventoryException::alreadyPosted($reference);
        }
    }

    /**
     * Refuse a date earlier than the last document posted.
     */
    protected function refuseBackdating(Carbon $date): void
    {
        $last = InventoryMovement::max('movement_date');

        if ($last === null) {
            return;
        }

        $last = Carbon::parse($last);

        if ($date->lt($last->startOfDay())) {
            throw InventoryException::backdated(
                $date->toDateString(),
                $last->toDateString(),
            );
        }
    }

    /* ------------------------------------------------------------ Checking */

    /**
     * Does every product's stock still match the batches behind it?
     *
     * Compared in thousandths: SQLite adds a DECIMAL as a float, so a sum
     * of 0.1, 0.1 and 0.1 arrives as 0.30000000000000004.
     *
     * @return list<array{sku:string,on_hand:int|float,layers:int|float}> The ones that do not.
     */
    public function reconcile(): array
    {
        $layerTotals = InventoryLayer::query()
            ->selectRaw('product_id, SUM(quantity_remaining) AS total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        return Product::query()
            ->get(['id', 'sku', 'on_hand'])
            ->map(fn (Product $product) => [
                'sku' => $product->sku,
                'on_hand' => $product->on_hand,
                'layers' => Quantity::normalize($layerTotals[$product->id] ?? 0),
            ])
            ->filter(fn (array $row) => Quantity::toMilli($row['on_hand']) !== Quantity::toMilli($row['layers']))
            ->values()
            ->all();
    }

    /**
     * Does each location's figure match the batches sitting there, and do a
     * product's locations add up to its total (phase 31.2)?
     *
     * Three sums over the whole table, compared in thousandths.
     *
     * @return list<array{sku:string,location:string,recorded:int|float,expected:int|float,what:string}> The ones that do not.
     */
    public function reconcileLocations(): array
    {
        $atLocation = InventoryLayer::query()
            ->selectRaw('product_id, location_id, SUM(quantity_remaining) AS total')
            ->groupBy('product_id', 'location_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->product_id}:{$row->location_id}" => Quantity::toMilli(Quantity::normalize($row->total))]);

        $rows = LocationStock::query()->get(['product_id', 'location_id', 'on_hand']);
        $recorded = $rows->mapWithKeys(fn (LocationStock $r) => ["{$r->product_id}:{$r->location_id}" => Quantity::toMilli($r->on_hand)]);

        $products = Product::query()->get(['id', 'sku', 'on_hand'])->keyBy('id');
        $locations = Location::query()->pluck('name', 'id');
        $wrong = [];

        // Each location against the batches there, both ways round, so a
        // batch with no figure beside it is caught as well.
        foreach ($recorded->keys()->merge($atLocation->keys())->unique() as $key) {
            [$productId, $locationId] = array_map('intval', explode(':', $key));
            $have = $recorded[$key] ?? 0;
            $should = $atLocation[$key] ?? 0;

            if ($have !== $should) {
                $wrong[] = [
                    'sku' => $products[$productId]->sku ?? "#{$productId}",
                    'location' => $locations[$locationId] ?? "#{$locationId}",
                    'recorded' => Quantity::fromMilli($have),
                    'expected' => Quantity::fromMilli($should),
                    'what' => 'at this location, against its batches',
                ];
            }
        }

        // The locations of each product against its total.
        $sums = $recorded->groupBy(fn ($milli, $key) => (int) explode(':', $key)[0], true)
            ->map(fn ($group) => $group->sum());

        foreach ($products as $product) {
            $sum = $sums[$product->id] ?? 0;

            if ($sum !== Quantity::toMilli($product->on_hand)) {
                $wrong[] = [
                    'sku' => $product->sku,
                    'location' => 'All locations',
                    'recorded' => Quantity::fromMilli($sum),
                    'expected' => $product->on_hand,
                    'what' => 'the locations added up, against the product\'s total',
                ];
            }
        }

        return $wrong;
    }
}
