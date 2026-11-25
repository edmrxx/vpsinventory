<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goods sent back to a supplier.
 *
 * The mirror of a sales return, and the one place stock leaves without FIFO
 * deciding which: the goods go back to the supplier who sent them, so they
 * must come out of that supplier's own delivery.
 *
 * What can go back is therefore whichever is smaller — what is left of the
 * line, or what is left of the batch it opened. Once units have been sold
 * they are gone, and no amount of paperwork brings them back from a
 * customer to put in the supplier's van.
 */
class PurchaseReturnPoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{purchase_id:int,return_date:string|Carbon,reason:string,notes?:string|null,idempotency_key?:string|null}  $header
     * @param  list<array{purchase_line_id:int,quantity:int|float|string}>  $lines
     */
    public function post(array $header, array $lines): PurchaseReturn
    {
        $date = $header['return_date'] instanceof Carbon
            ? $header['return_date']
            : Carbon::parse($header['return_date']);

        return DB::transaction(function () use ($header, $lines, $date) {
            $purchase = Purchase::whereKey($header['purchase_id'])->lockForUpdate()->firstOrFail();

            if (! $purchase->isPosted()) {
                throw InventoryException::alreadyVoided($purchase->reference);
            }

            $wanted = $this->onlyRealQuantities($lines);

            if ($wanted === []) {
                throw InventoryException::nothingReturned();
            }

            $reference = $this->numbers->next('documents.purchase_return_prefix', $date);

            $return = new PurchaseReturn([
                'reference' => $reference,
                'purchase_id' => $purchase->id,
                'return_date' => $date,
                'status' => DocumentStatus::Posted,
                'reason' => $header['reason'],
                'notes' => $header['notes'] ?? null,
                'total_cost' => 0,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $return->save());

            $purchaseLines = PurchaseLine::whereIn('id', array_keys($wanted))
                ->where('purchase_id', $purchase->id)
                ->with('layer', 'product')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $plan = [];
            $total = 0;

            // $quantity is in thousandths, from onlyRealQuantities().
            foreach ($wanted as $purchaseLineId => $quantity) {
                $line = $purchaseLines[$purchaseLineId] ?? null;

                if (! $line || ! $line->inventory_layer_id) {
                    throw InventoryException::nothingReturned();
                }

                if ($quantity > Quantity::toMilli($line->returnable())) {
                    throw InventoryException::notEnoughOfThisDelivery(
                        $line->product->sku,
                        Quantity::fromMilli($quantity),
                        $line->returnable(),
                    );
                }

                $cents = $this->valueOf($line, $quantity);
                $lineCost = $cents / 100;
                $total += $cents;
                $unitCost = $line->unit_id === null
                    ? (float) $line->unit_cost
                    : round($cents * Quantity::SCALE / $quantity / 100, 2);

                PurchaseReturnLine::create([
                    'purchase_return_id' => $return->id,
                    'purchase_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity' => Quantity::toDb($quantity),
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ]);

                $plan[] = [
                    'product_id' => $line->product_id,
                    'layer_id' => $line->inventory_layer_id,
                    'quantity' => Quantity::toDb($quantity),
                ];

                // The line is locked above, so the exact new figure is
                // written rather than a float added to the old one.
                PurchaseLine::whereKey($line->id)->update([
                    'returned_quantity' => Quantity::toDb(Quantity::toMilli($line->returned_quantity) + $quantity),
                ]);
            }

            $total /= 100;

            $this->ledger->issueFromLayers($return, $reference, $date, $plan);

            // What comes back comes off an unpaid bill first (phase 24). What is
            // left of it, on a bill already paid, stays with the supplier as
            // credit for a later bill: no cash comes back from a supplier. The
            // purchase is locked above, so the exact new balance is written.
            $cents = Quantity::centavos(round($total, 2));
            $owed = Quantity::centavos((float) $purchase->balance_due);
            $credited = min($cents, max(0, $owed));
            $supplierCredit = $cents - $credited;

            if ($credited > 0) {
                $purchase->forceFill(['balance_due' => ($owed - $credited) / 100])->saveQuietly();
            }

            $return->forceFill([
                'total_cost' => round($total, 2),
                'credited_amount' => $credited / 100,
                'supplier_credit_amount' => $supplierCredit / 100,
            ])->saveQuietly();

            $return->auditCustom(
                AuditAction::Post,
                "Posted purchase return [{$reference}] against [{$purchase->reference}]: "
                    .count($plan).' line(s), '.number_format($total, 2).'.',
                [
                    'purchase' => $purchase->reference,
                    'total_cost' => round($total, 2),
                    'credited_amount' => $credited / 100,
                    'supplier_credit_amount' => $supplierCredit / 100,
                ],
            );

            return $return->fresh(['lines', 'purchase']);
        }, attempts: 3);
    }

    /**
     * What this many pieces of a line are worth back, in centavos.
     *
     * Bought by the piece: the pieces at the piece cost, as always.
     *
     * Bought by the box: the pieces' share of what the line actually cost,
     * because the piece cost is rounded. A box of 12 at 100 is 8.33 a
     * piece, and twelve of those is 99.96 — four centavos the supplier was
     * paid and would never be asked for. So the share is worked out from
     * the line total, and the last pieces back take whatever is left of it.
     * A box returned whole then gives back exactly 100.00.
     *
     * @param  int  $quantity  In thousandths.
     */
    protected function valueOf(PurchaseLine $line, int $quantity): int
    {
        if ($line->unit_id === null) {
            return Quantity::cents($quantity, (float) $line->unit_cost);
        }

        $paid = Quantity::centavos($line->line_total);
        $bought = Quantity::toMilli($line->quantity);
        $nowAllBack = Quantity::toMilli($line->returned_quantity) + $quantity >= $bought;

        if ($nowAllBack) {
            $alreadyBack = Quantity::centavos($line->purchaseReturnLines()->sum('line_cost'));

            return $paid - $alreadyBack;
        }

        // Multiplied before it is divided, half up.
        return intdiv(2 * $paid * $quantity + $bought, 2 * $bought);
    }

    /**
     * @param  list<array{purchase_line_id:int,quantity:int|float|string}>  $lines
     * @return array<int, int> Purchase line id => thousandths.
     */
    protected function onlyRealQuantities(array $lines): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            $quantity = Quantity::toMilli($line['quantity'] ?? 0);

            if ($quantity > 0) {
                $id = (int) $line['purchase_line_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + $quantity;
            }
        }

        return $wanted;
    }
}
