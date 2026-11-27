<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Models\InventoryLayerConsumption;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goods a customer brought back.
 *
 * The sale kept a receipt of which batch every unit came out of, so a return
 * is not a guess: each unit goes back into the batch it actually left, at
 * that batch's own cost.
 *
 * Units are put back **newest first** — the last one out is the first one
 * back. Anything else would leave the earlier, cheaper batches half eaten
 * while a later one sat full, which is not how the goods moved.
 *
 * Locks, in the order every poster uses: the sale first, because it decides
 * what may be returned at all, then the sequence row, then the sale's lines
 * and the consumption rows those lines ate, and the products last of all.
 */
class SalesReturnPoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{sale_id:int,return_date:string|Carbon,reason:string,notes?:string|null,idempotency_key?:string|null}  $header
     * @param  list<array{sale_line_id:int,quantity:int|float|string}>  $lines
     */
    public function post(array $header, array $lines): SalesReturn
    {
        $date = $header['return_date'] instanceof Carbon
            ? $header['return_date']
            : Carbon::parse($header['return_date']);

        return DB::transaction(function () use ($header, $lines, $date) {
            $sale = Sale::whereKey($header['sale_id'])->lockForUpdate()->firstOrFail();

            if (! $sale->isPosted()) {
                throw InventoryException::alreadyVoided($sale->reference);
            }

            $wanted = $this->onlyRealQuantities($lines);

            if ($wanted === []) {
                throw InventoryException::nothingReturned();
            }

            $reference = $this->numbers->next('documents.sales_return_prefix', $date);

            $return = new SalesReturn([
                'reference' => $reference,
                'sale_id' => $sale->id,
                'return_date' => $date,
                'status' => DocumentStatus::Posted,
                'reason' => $header['reason'],
                'notes' => $header['notes'] ?? null,
                'total_amount' => 0,
                'total_cost' => 0,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $return->save());

            $saleLines = SaleLine::whereIn('id', array_keys($wanted))
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $plan = [];
            $refund = 0.0;

            // $quantity is in thousandths, from onlyRealQuantities().
            foreach ($wanted as $saleLineId => $quantity) {
                $saleLine = $saleLines[$saleLineId] ?? null;

                if (! $saleLine) {
                    throw InventoryException::nothingReturned();
                }

                if ($quantity > Quantity::toMilli($saleLine->returnable())) {
                    throw InventoryException::moreThanWasSold(
                        $saleLine->product->sku,
                        Quantity::fromMilli($quantity),
                        $saleLine->returnable(),
                    );
                }

                $lineRefund = $this->refundFor($sale, $saleLine, $quantity);
                $unitRefund = round($lineRefund * Quantity::SCALE / $quantity, 2);
                $refund += $lineRefund;

                SalesReturnLine::create([
                    'sales_return_id' => $return->id,
                    'sale_line_id' => $saleLine->id,
                    'product_id' => $saleLine->product_id,
                    'quantity' => Quantity::toDb($quantity),
                    'unit_refund' => $unitRefund,
                    'line_refund' => $lineRefund,
                    'line_cost' => 0,
                ]);

                $plan[] = [
                    'product_id' => $saleLine->product_id,
                    'take' => $this->newestFirst($saleLine, $quantity),
                ];

                // The line is locked above, so the exact new figure is
                // written rather than a float added to the old one.
                SaleLine::whereKey($saleLine->id)->update([
                    'returned_quantity' => Quantity::toDb(Quantity::toMilli($saleLine->returned_quantity) + $quantity),
                ]);
            }

            $movements = $this->ledger->restoreConsumed($return, $reference, $date, $plan);

            $cost = $this->copyCostsBack($return, $movements);

            $credited = $this->takeOffBalance($sale, round($refund, 2));

            $return->forceFill([
                'total_amount' => round($refund, 2),
                'total_cost' => $cost,
                'credited_amount' => $credited,
            ])->saveQuietly();

            $return->auditCustom(
                AuditAction::Post,
                "Posted sales return [{$reference}] against [{$sale->reference}]: "
                    .count($plan).' line(s), '.number_format($refund, 2).'.',
                ['sale' => $sale->reference, 'total_amount' => round($refund, 2), 'credited_amount' => $credited],
            );

            return $return->fresh(['lines', 'sale']);
        }, attempts: 3);
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * Goods back on a sale not yet paid for come off what is owed.
     *
     * Handing over cash for something nobody has paid for would pay the
     * customer twice. So a return clears the sale's balance first, and only
     * what is left of its value goes back as cash. The sale is locked by now.
     *
     * @return float What came off the balance.
     */
    protected function takeOffBalance(Sale $sale, float $value): float
    {
        $owed = Quantity::centavos((float) $sale->balance_due);
        $credited = min(Quantity::centavos($value), $owed);

        if ($credited > 0) {
            $sale->forceFill(['balance_due' => ($owed - $credited) / 100])->saveQuietly();
        }

        return $credited / 100;
    }

    /**
     * Which consumptions to draw on, last one out first.
     *
     * @param  int  $quantity  In thousandths.
     * @return array<int, string> consumption id => quantity
     */
    protected function newestFirst(SaleLine $saleLine, int $quantity): array
    {
        $consumptions = InventoryLayerConsumption::where('inventory_movement_id', $saleLine->inventory_movement_id)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        $take = [];
        $outstanding = $quantity;

        foreach ($consumptions as $consumption) {
            if ($outstanding === 0) {
                break;
            }

            $left = Quantity::toMilli($consumption->quantity) - Quantity::toMilli($consumption->returned_quantity);

            if ($left < 1) {
                continue;
            }

            $amount = min($outstanding, $left);
            $take[$consumption->id] = Quantity::toDb($amount);
            $outstanding -= $amount;
        }

        if ($outstanding > 0) {
            throw InventoryException::moreThanWasSold(
                $saleLine->product->sku,
                Quantity::fromMilli($quantity),
                Quantity::fromMilli($quantity - $outstanding),
            );
        }

        return $take;
    }

    /**
     * What this many units of a line are worth back.
     *
     * A sale of 1,000 with 100 off collected 900, so a full return hands
     * back 900 and not 1,000.
     *
     * Rounding is the whole difficulty. Three units at 100 with 10 off
     * collected 290, but a third of 290 is 96.666…, and 96.67 three times is
     * 290.01 — a centavo the business never took. So the line's share is
     * worked out once, and the last units off a line are given whatever is
     * left of it rather than their own rounded share. Everything then adds
     * back up exactly.
     */
    protected function refundFor(Sale $sale, SaleLine $saleLine, int $quantity): float
    {
        $lineShare = $this->proratedLineTotal($sale, $saleLine);
        $sold = Quantity::toMilli($saleLine->quantity);

        $alreadyRefunded = (float) $saleLine->salesReturnLines()->sum('line_refund');
        $nowFullyReturned = (Quantity::toMilli($saleLine->returned_quantity) + $quantity) >= $sold;

        if ($nowFullyReturned) {
            // The last of this line: hand back exactly what is left of its
            // share, so the rounding cannot drift.
            return round($lineShare - $alreadyRefunded, 2);
        }

        // Multiplied before it is divided, both in thousandths.
        return round($lineShare * $quantity / $sold, 2);
    }

    /**
     * One line's share of what the sale actually collected for its goods.
     *
     * The shares are worked out for the whole document at once, in line
     * order, and the last line absorbs the rounding. That way the lines add
     * up to the goods total exactly, however awkward the discount.
     *
     * The goods total, never the whole total: a mobilization charge (phase
     * 28.1) paid for bringing the goods to the site, and is not refunded
     * when some of them come back.
     */
    protected function proratedLineTotal(Sale $sale, SaleLine $saleLine): float
    {
        $subtotal = (float) $sale->subtotal;
        $total = (float) $sale->goodsTotal();

        if ($subtotal <= 0 || $subtotal === $total) {
            return (float) $saleLine->line_total;
        }

        $lines = $sale->lines()->orderBy('id')->get(['id', 'line_total']);
        $running = 0.0;

        foreach ($lines as $index => $line) {
            $isLast = $index === $lines->count() - 1;

            $share = $isLast
                ? round($total - $running, 2)
                : round((float) $line->line_total * $total / $subtotal, 2);

            if ((int) $line->id === (int) $saleLine->id) {
                return $share;
            }

            $running += $share;
        }

        return (float) $saleLine->line_total;
    }

    /**
     * @param  list<array{sale_line_id:int,quantity:int|float|string}>  $lines
     * @return array<int, int> Sale line id => thousandths.
     */
    protected function onlyRealQuantities(array $lines): array
    {
        $wanted = [];

        foreach ($lines as $line) {
            $quantity = Quantity::toMilli($line['quantity'] ?? 0);

            if ($quantity > 0) {
                $id = (int) $line['sale_line_id'];
                $wanted[$id] = ($wanted[$id] ?? 0) + $quantity;
            }
        }

        return $wanted;
    }

    /**
     * @param  list<InventoryMovement>  $movements
     */
    protected function copyCostsBack(SalesReturn $return, array $movements): float
    {
        $total = 0.0;
        $lines = $return->lines()->orderBy('id')->get();

        foreach ($movements as $index => $movement) {
            $line = $lines[$index] ?? null;

            if (! $line) {
                continue;
            }

            $line->forceFill(['line_cost' => $movement->total_cost])->save();

            $total += (float) $movement->total_cost;
        }

        return round($total, 2);
    }
}
