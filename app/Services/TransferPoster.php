<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a transfer and posts it in the same breath (phase 31.5).
 *
 * The goods leave one location and arrive at another in one step: there
 * is no *in transit*, no approval and nothing to receive at the other end —
 * defaults taken while decision 4 stays open. The ledger does the moving;
 * this writes the document around it, takes the number, copies the cost
 * back and refuses to move stock a customer order holds at the source.
 */
class TransferPoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
    ) {}

    /**
     * @param  list<array{product_id:int,quantity:int|float|string}>  $lines
     */
    public function post(
        array $lines,
        int $fromId,
        int $toId,
        ?Carbon $date = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): StockTransfer {
        $date ??= Carbon::today();

        if ($fromId === $toId) {
            throw InventoryException::sameLocation();
        }

        // Retried, for the first document of a year: see AdjustmentPoster.
        return DB::transaction(function () use ($lines, $fromId, $toId, $date, $notes, $idempotencyKey) {
            // Both places, read with a shared lock before the number, so
            // neither can be switched off between the check and the posting.
            [$from, $to] = $this->locations($fromId, $toId);

            $reference = $this->numbers->next('documents.transfer_prefix', $date);

            $transfer = new StockTransfer([
                'reference' => $reference,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => DocumentStatus::Posted,
                'transfer_date' => $date,
                'notes' => $notes,
                'total_cost' => 0,
                'idempotency_key' => $idempotencyKey,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            // The document writes one Post row of its own below.
            app(AuditService::class)->withoutAuditing(fn () => $transfer->save());

            foreach ($lines as $line) {
                StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => 0,
                    'total_cost' => 0,
                ]);
            }

            $moved = $this->ledger->transfer($transfer, $reference, $date, $lines, $from, $to);

            // The ledger holds the product rows now, so what is reserved at
            // the source is weighed against what is left there.
            $this->refuseTakingReservedStock($this->taken($lines), $from);

            $total = $this->copyCostsBack($transfer, array_column($moved, 'out'));

            $transfer->forceFill(['total_cost' => $total])->saveQuietly();

            $transfer->auditCustom(
                AuditAction::Post,
                "Posted transfer [{$reference}] from {$from->name} to {$to->name}: ".count($lines).' line(s).',
                ['from' => $from->name, 'to' => $to->name, 'lines' => count($lines), 'total_cost' => $total],
            );

            return $transfer->fresh(['lines']);
        }, attempts: 3);
    }

    /**
     * Undo a posted transfer while what it brought in is untouched.
     */
    public function void(StockTransfer $transfer, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason) {
            $locked = StockTransfer::whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentStatus::Posted) {
                throw InventoryException::alreadyVoided($locked->reference);
            }

            $this->ledger->reverseTransfer($locked, $locked->reference, Carbon::today());

            // The void takes the goods back off the destination: never what
            // an order holds there.
            $lines = $locked->lines()->get(['product_id', 'quantity'])
                ->map(fn (StockTransferLine $l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity])
                ->all();

            $this->refuseTakingReservedStock($this->taken($lines), $locked->toLocation()->firstOrFail());

            app(AuditService::class)->withoutAuditing(fn () => $locked->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Void,
                "Voided transfer [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh(['lines']);
        }, attempts: 3);
    }

    /**
     * Both locations, in id order, with a shared lock. A switched-off one
     * is refused: the form checked, but outside the transaction.
     *
     * @return array{0: Location, 1: Location}
     */
    protected function locations(int $fromId, int $toId): array
    {
        $found = Location::whereIn('id', [$fromId, $toId])->orderBy('id')->sharedLock()->get()->keyBy('id');

        foreach (['from' => $fromId, 'to' => $toId] as $side => $id) {
            $location = $found[$id] ?? null;

            if (! $location || ! $location->is_active) {
                throw new InventoryException($side === 'from'
                    ? 'The location the goods are moved from has been switched off. Pick another one.'
                    : 'The location the goods are moved to has been switched off. Pick another one.');
            }
        }

        return [$found[$fromId], $found[$toId]];
    }

    /**
     * Thousandths per product, the lines added up.
     *
     * @param  list<array{product_id:int,quantity:int|float|string}>  $lines
     * @return array<int, int>
     */
    protected function taken(array $lines): array
    {
        $taken = [];

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $taken[$id] = ($taken[$id] ?? 0) + Quantity::toMilli($line['quantity']);
        }

        return $taken;
    }

    /**
     * Roll back when what is left at the place is less than the customer
     * orders hold there. Read with a shared lock, after the ledger wrote.
     *
     * @param  array<int, int>  $taken  Thousandths per product.
     */
    protected function refuseTakingReservedStock(array $taken, Location $location): void
    {
        $held = Reservations::reserved(array_keys($taken), locking: true, locationId: $location->id);

        if ($held === []) {
            return;
        }

        $there = Reservations::atLocation(array_keys($held), $location->id, locking: true);

        foreach (Product::query()->whereIn('id', array_keys($held))->orderBy('id')->get(['id', 'sku']) as $product) {
            $left = $there[$product->id] ?? 0;

            if ($left < $held[$product->id]) {
                // What was free before this took its share.
                $free = max(0, $left + ($taken[$product->id] ?? 0) - $held[$product->id]);

                throw InventoryException::heldForOrdersAt($product->sku, $location->name, Quantity::format(Quantity::fromMilli($free)));
            }
        }
    }

    /**
     * Each line's cost is known once the ledger has run: the batches it ate.
     *
     * @param  list<\App\Models\InventoryMovement>  $movements  The outgoing ones, a line each.
     */
    protected function copyCostsBack(StockTransfer $transfer, array $movements): float
    {
        $total = 0;
        $lines = $transfer->lines()->orderBy('id')->get();

        foreach ($movements as $index => $movement) {
            $line = $lines[$index] ?? null;

            if (! $line) {
                continue;
            }

            $line->forceFill([
                'unit_cost' => $movement->unit_cost,
                'total_cost' => $movement->total_cost,
            ])->save();

            $total += Quantity::centavos($movement->total_cost);
        }

        return $total / 100;
    }
}
