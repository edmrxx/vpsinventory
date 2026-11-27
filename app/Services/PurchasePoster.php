<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Support\DeliveryPayment;
use App\Support\OrderReceiving;
use App\Support\SupplierAccount;
use App\Models\Supplier;
use App\Support\Quantity;
use App\Support\UnitConversion;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a purchase and posts it in the same breath.
 *
 * Built to the shape `AdjustmentPoster` set in phase 7, and phases 9 and 10
 * should follow it too: take the document number first, then call the
 * ledger. That fixes the lock order — the sequence row, then the products in
 * ascending id order — so two posters can never take the same locks the
 * opposite way round.
 *
 * A delivery against a purchase order (phase 23) locks the order row before
 * all of that, and weighs the delivery against it again through
 * `OrderReceiving`. A void locks the purchase, then its order, then calls the
 * ledger, so both take the order before the products.
 *
 * Since phase 31.3 the goods go on the shelf at the location the form names
 * (*Receive at*). That row is read with a shared lock after the order and
 * before the number, so it cannot be switched off mid-posting while two
 * deliveries may still post into it at once. A void needs no location: the
 * ledger takes the goods out of wherever their batches sit.
 */
class PurchasePoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * Record goods bought in, and put them on the shelf.
     *
     * Every total is worked out here. Whatever the form sent is ignored, so
     * a changed figure in the browser cannot change what is stored.
     *
     * A line may be typed in another unit — 5 boxes at 400 — and is priced
     * as typed. The shelf takes what that is in the product's own unit, 500
     * pieces, and the batch takes the cost of one piece: the line total
     * shared over the pieces, to the centavo. The factor is read from the
     * product, never from the form, and copied onto the line. Each line's
     * `unit_cost` is what one of the typed unit cost.
     *
     * @param  array{supplier_id:int,purchase_date:string|Carbon,supplier_reference?:string|null,notes?:string|null,idempotency_key?:string|null,purchase_order_id?:int|null}  $header
     * @param  list<array{product_id:int,unit_id?:int|null,quantity:int|float|string,unit_cost:float|string,purchase_order_line_id?:int|null}>  $lines
     */
    public function post(array $header, array $lines): Purchase
    {
        // Counted from 0, so each line meets its own conversion.
        $lines = array_values($lines);

        $date = $header['purchase_date'] instanceof Carbon
            ? $header['purchase_date']
            : Carbon::parse($header['purchase_date']);

        // Retried, because the very first document of a year has to create
        // its counter row and two of those at once deadlock on MySQL.
        return DB::transaction(function () use ($header, $lines, $date) {
            $priced = $this->price($lines);
            [$order, $receiving] = $this->weighAgainstOrder($header, $lines, $priced);
            $location = $this->receivingLocation($header);
            $reference = $this->numbers->next('documents.purchase_prefix', $date);

            $purchase = new Purchase([
                'reference' => $reference,
                'supplier_reference' => $header['supplier_reference'] ?? null,
                'supplier_id' => $header['supplier_id'],
                'location_id' => $location->id,
                'purchase_order_id' => $order?->id,
                'purchase_date' => $date,
                'status' => DocumentStatus::Posted,
                'total_amount' => 0,
                // Stamped here, once, from the supplier as they stand
                // today. A VAT-registered supplier charges VAT whether or
                // not we are registered, so this does not ask the Tax
                // Settings switch — that one is about what *we* charge.
                'vat_rate' => Supplier::whereKey($header['supplier_id'])->value('is_vat_registered')
                    ? Vat::rate()
                    : 0,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            // One Post row says everything the observer's Create row would.
            $this->audit->withoutAuditing(fn () => $purchase->save());

            $total = 0.0;

            foreach ($priced as $index => $line) {
                PurchaseLine::create([
                    'purchase_id' => $purchase->id,
                    'purchase_order_line_id' => $order ? ($lines[$index]['purchase_order_line_id'] ?? null ?: null) : null,
                    'product_id' => $line['product_id'],
                    'unit_id' => $line['unit_id'],
                    'unit_factor' => $line['factor'],
                    'unit_quantity' => $line['unit_quantity'],
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $line['line_total'],
                ]);

                $total += $line['line_total'];
            }

            // The cost is always passed through: the ledger would otherwise
            // fall back to the product's own cost, which is not what this
            // delivery was actually bought at.
            $movements = $this->ledger->post(
                $purchase,
                $reference,
                $date,
                MovementType::Purchase,
                array_map(fn (array $line) => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                ], $priced),
                $location,
            );

            $this->rememberLayers($purchase, $movements);

            // Paid on delivery, on the supplier's account (phase 24), or a
            // down payment with the rest on account (phase 24.5). What is on
            // account waits for a supplier payment, due on the day the
            // supplier's terms say, stamped here once. Weighed again here,
            // on the total just priced: the form's figure is never trusted.
            $paying = DeliveryPayment::weigh(
                Quantity::centavos(round($total, 2)),
                ! empty($header['on_account']),
                $header['paid_now'] ?? null,
                array_key_exists('payment_method_id', $header),
                $header['payment_method_id'] ?? null,
                $header['payment_reference'] ?? null,
            );

            if ($paying['errors'] !== []) {
                throw ValidationException::withMessages($paying['errors']);
            }

            $owed = $paying['on_account'] / 100;
            $supplier = Supplier::find($header['supplier_id']);

            $purchase->forceFill([
                'total_amount' => round($total, 2),
                'on_account' => $owed,
                'balance_due' => $owed,
                'due_date' => $owed > 0 ? SupplierAccount::dueDate($supplier, $date) : null,
                'payment_method_id' => $paying['payment_method_id'],
                'payment_reference' => $paying['payment_reference'],
            ])->saveQuietly();

            $this->rememberLastCost($priced);

            if ($order) {
                $this->receive($order, $receiving, 1);
            }

            $purchase->auditCustom(
                AuditAction::Post,
                "Posted purchase [{$reference}]: ".count($lines).' line(s), '
                    .number_format($total, 2)
                    .($order ? ", against order [{$order->reference}] (now ".strtolower($order->status->label()).').' : '.'),
                ['lines' => count($lines), 'total_amount' => round($total, 2), 'order' => $order?->reference],
            );

            return $purchase->fresh(['lines', 'supplier']);
        }, attempts: 3);
    }

    /**
     * Where the goods go on the shelf (phase 31.3), read under a shared lock
     * so it cannot be switched off while this delivery posts into it.
     *
     * A caller that names none — a seeder, or a delivery from before 31.3 —
     * gets the default location, the one every document posted at until now.
     *
     * @param  array<string, mixed>  $header
     *
     * @throws InventoryException
     */
    protected function receivingLocation(array $header): Location
    {
        if (empty($header['location_id'])) {
            return Location::default() ?? throw InventoryException::noDefaultLocation();
        }

        $location = Location::whereKey($header['location_id'])->sharedLock()->first();

        if (! $location || ! $location->is_active) {
            throw new InventoryException('The location picked for this delivery has been switched off. Pick another one.');
        }

        return $location;
    }

    /**
     * The order this delivery fills, locked, and what each of its lines takes.
     *
     * Asked again here whatever the form said: somebody may have posted
     * another delivery against the same order, or closed it, a moment ago.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $priced
     * @return array{0: ?PurchaseOrder, 1: array<int, int>}
     */
    protected function weighAgainstOrder(array $header, array $lines, array $priced): array
    {
        if (empty($header['purchase_order_id'])) {
            return [null, []];
        }

        $order = PurchaseOrder::whereKey($header['purchase_order_id'])->lockForUpdate()->firstOrFail();
        $order->load(['lines' => fn ($q) => $q->orderBy('id'), 'lines.product.unit:id,code']);

        $rows = [];

        foreach ($priced as $index => $line) {
            $rows[$index] = [
                'order_line_id' => ! empty($lines[$index]['purchase_order_line_id']) ? (int) $lines[$index]['purchase_order_line_id'] : null,
                'product_id' => $line['product_id'],
                'milli' => Quantity::toMilli($line['quantity']),
            ];
        }

        $weighed = OrderReceiving::weigh($order, (int) $header['supplier_id'], $rows);

        if ($weighed['errors'] !== []) {
            throw InventoryException::orderRefused(reset($weighed['errors']));
        }

        return [$order, $weighed['received']];
    }

    /**
     * Add (or, on a void, take off) what a delivery brought in against each
     * order line, and say where the order stands now.
     *
     * The order is locked by the caller, so the exact new figure is written
     * rather than an increment SQLite would do as a float.
     *
     * @param  array<int, int>  $received  Order line id => thousandths.
     * @param  int  $sign  1 to receive, -1 to take back.
     */
    protected function receive(PurchaseOrder $order, array $received, int $sign): void
    {
        foreach ($order->lines as $line) {
            if (! isset($received[$line->id])) {
                continue;
            }

            $now = max(0, Quantity::toMilli($line->received_quantity) + $sign * $received[$line->id]);

            $line->forceFill(['received_quantity' => Quantity::toDb($now)])->save();
            $line->received_quantity = Quantity::fromMilli($now);
        }

        $status = OrderReceiving::statusOf($order);

        if ($status !== $order->status) {
            $order->forceFill(['status' => $status])->saveQuietly();
        }
    }

    /**
     * Every line as it is stored: what was typed, and what that is in the
     * product's own unit.
     *
     * Bought by the piece, the piece cost is exactly what was typed. Bought
     * by the box, it is the line total over the pieces, half up to the
     * centavo — 5 boxes of 100 at 400 is 4.00 a piece. A box of 12 at 100
     * is 8.33 a piece, so that batch is worth 99.96 against 100.00 paid;
     * the batch can only hold a cost to the centavo. A purchase return of
     * such a line gives back its share of the 100.00, not 8.33 a piece.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id:int, unit_id:?int, factor:int|float, unit_quantity:int|float, unit_price:float, quantity:string, unit_cost:float, line_total:float}>
     */
    protected function price(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);
        $priced = [];

        foreach ($lines as $index => $line) {
            $unit = $converted[$index];

            if ($unit['error'] !== null || $unit['base'] === null || $unit['base'] <= 0) {
                throw InventoryException::cannotConvert($unit['error'] ?? 'A line could not be read in its product\'s own unit.');
            }

            $typed = Quantity::normalize($line['quantity']);
            $unitPrice = round((float) $line['unit_cost'], 2);
            $lineTotal = Quantity::lineTotal($typed, $unitPrice);

            $unitCost = $unit['unit_id'] === null
                ? $unitPrice
                : $this->pieceCost(Quantity::centavos($lineTotal), $unit['base']);

            $priced[] = [
                'product_id' => (int) $line['product_id'],
                'unit_id' => $unit['unit_id'],
                'factor' => $unit['factor'],
                'unit_quantity' => $typed,
                'unit_price' => $unitPrice,
                'quantity' => Quantity::toDb($unit['base']),
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
            ];
        }

        return $priced;
    }

    /**
     * One piece's share of a line, half up to the centavo.
     *
     * @param  int  $cents  The line total, in centavos.
     * @param  int  $milli  The pieces, in thousandths.
     */
    protected function pieceCost(int $cents, int $milli): float
    {
        return intdiv(2 * $cents * Quantity::SCALE + $milli, 2 * $milli) / 100;
    }

    /**
     * Tie each line to the batch it opened.
     *
     * A purchase return has to draw from this delivery's own batch, not
     * from whatever is oldest on the shelf, so the link is kept here.
     *
     * @param  list<InventoryMovement>  $movements
     */
    protected function rememberLayers(Purchase $purchase, array $movements): void
    {
        $lines = $purchase->lines()->orderBy('id')->get();

        $layers = InventoryLayer::where('source_type', $purchase->getMorphClass())
            ->where('source_id', $purchase->getKey())
            ->orderBy('id')
            ->pluck('id');

        foreach ($lines as $index => $line) {
            if (isset($layers[$index])) {
                $line->forceFill(['inventory_layer_id' => $layers[$index]])->save();
            }
        }
    }

    /**
     * Take a purchase back off the shelf.
     *
     * Only while none of its stock has moved. Once a unit has been sold the
     * cost of that sale is already settled, so the way back is a purchase
     * return, not a void.
     */
    public function void(Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason) {
            // Read the status under the lock: two people clicking Void at
            // the same moment must not both get through.
            $locked = Purchase::whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw InventoryException::alreadyPosted($locked->reference);
            }

            // A bill something was paid against stays: the payment is voided
            // first. Read with a lock, so a payment being posted is waited for.
            if ($locked->hasLivePayments(locking: true)) {
                throw InventoryException::hasSupplierPayments($locked->reference);
            }

            // What it brought in against its order goes back on the order,
            // which is waiting for those goods again.
            $order = $locked->purchase_order_id
                ? PurchaseOrder::whereKey($locked->purchase_order_id)->lockForUpdate()->first()
                : null;

            $this->ledger->reverseReceipt($locked, $locked->reference, Carbon::today());

            if ($order) {
                $order->load('lines');

                $taken = $locked->lines()
                    ->whereNotNull('purchase_order_line_id')
                    ->get(['purchase_order_line_id', 'quantity'])
                    ->groupBy('purchase_order_line_id')
                    ->map(fn ($rows) => $rows->sum(fn ($row) => Quantity::toMilli($row->quantity)))
                    ->all();

                $this->receive($order, $taken, -1);
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
                // A voided bill owes nothing; what went on account stays as posted.
                'balance_due' => 0,
            ])->save());

            $this->restoreLastCost($locked);

            $locked->auditCustom(
                AuditAction::Void,
                "Voided purchase [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh(['lines', 'supplier']);
        }, attempts: 3);
    }

    /**
     * Put each product's cost back to the last price that still stands.
     *
     * A voided purchase was never really bought, so its price must not stay
     * on the product. The next candidate is the newest line of a purchase
     * that is still posted; failing that, the batch opening stock or an
     * adjustment brought in. If there is nothing at all, the cost is left
     * where it is rather than reset to zero.
     *
     * The voided purchase is not simply skipped: voiding two purchases in a
     * row has to walk back through both, which only works by asking what is
     * still standing each time.
     */
    protected function restoreLastCost(Purchase $purchase): void
    {
        $productIds = $purchase->lines()->pluck('product_id')->unique();

        foreach ($productIds as $productId) {
            $cost = PurchaseLine::query()
                ->where('purchase_lines.product_id', $productId)
                ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
                ->where('purchases.status', DocumentStatus::Posted->value)
                ->orderByDesc('purchases.purchase_date')
                ->orderByDesc('purchases.id')
                ->orderByDesc('purchase_lines.id')
                ->value('purchase_lines.unit_cost');

            if ($cost === null) {
                // Nothing bought stands, so fall back to whatever opening
                // stock or an adjustment last brought this product in at.
                $cost = InventoryLayer::query()
                    ->where('product_id', $productId)
                    ->where('source_type', '!=', (new Purchase)->getMorphClass())
                    ->orderByDesc('id')
                    ->value('unit_cost');
            }

            if ($cost === null) {
                continue;
            }

            Product::whereKey($productId)->update(['cost' => round((float) $cost, 2)]);
        }
    }

    /**
     * Keep the product's cost as the last thing it was actually bought at.
     *
     * Written through the query builder, so the product fires no model event
     * and writes no audit row for a figure the purchase already explains.
     * The FIFO valuation never reads this: it comes from the batches.
     *
     * @param  list<array{product_id:int,unit_cost:float|string}>  $lines  The piece cost, from price().
     */
    protected function rememberLastCost(array $lines): void
    {
        foreach ($lines as $line) {
            Product::whereKey($line['product_id'])
                ->update(['cost' => round((float) $line['unit_cost'], 2)]);
        }
    }
}
