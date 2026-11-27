<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Exceptions\QuotationException;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Quantity;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Makes a quotation, cancels one, and marks one accepted when it becomes a
 * sale (phase 28).
 *
 * Nothing is posted: no stock moves, nobody owes anything, nothing is
 * reserved. What it shares with the posters is the shape — one transaction,
 * the document number taken inside it, every figure worked out here (the
 * total through `SaleTotals`, as a sale's is) whatever the form sent.
 *
 * Accepting is done inside the sale's own transaction by `SalePoster`, which
 * locks the quotation row first and asks `lockedForSale()` here, so the
 * check and the sale are one step.
 */
class QuotationDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{customer_id:int, attention?:string|null, valid_until:string|Carbon, discount?:float|string|null, notes?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array{product_id:int, unit_id?:int|null, quantity:int|float|string, unit_price:float|string}>  $lines
     */
    public function create(array $header, array $lines, User $by): Quotation
    {
        $lines = array_values($lines);
        $today = Carbon::today();

        return DB::transaction(function () use ($header, $lines, $today, $by) {
            $priced = $this->price($lines);
            $totals = SaleTotals::of($lines, $header['discount'] ?? 0, $header['mobilization'] ?? 0);
            $reference = $this->numbers->next('documents.quotation_prefix', $today);

            $quotation = new Quotation([
                'reference' => $reference,
                'customer_id' => $header['customer_id'],
                'attention' => $header['attention'] ?? null,
                'quote_date' => $today,
                'valid_until' => $header['valid_until'],
                'status' => QuotationStatus::Open,
                'subtotal' => $totals['subtotal'] / 100,
                'discount' => $totals['discount'] / 100,
                'mobilization' => $totals['mobilization'] / 100,
                'total_amount' => $totals['total'] / 100,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'prepared_by' => $by->id,
            ]);

            $this->audit->withoutAuditing(fn () => $quotation->save());

            foreach ($priced as $line) {
                QuotationLine::create(['quotation_id' => $quotation->id] + $line);
            }

            $quotation->auditCustom(
                AuditAction::Create,
                "Prepared quotation [{$reference}]: ".count($priced).' line(s), '
                    .number_format($totals['total'] / 100, 2).', valid until '
                    .Carbon::parse($header['valid_until'])->format('d M Y').'.',
                ['lines' => count($priced), 'total_amount' => $totals['total'] / 100],
            );

            return $quotation->fresh(['lines', 'customer']);
        }, attempts: 3);
    }

    /** Withdraw an open quotation, with a reason. An expired one may be cancelled too. */
    public function cancel(Quotation $quotation, User $by, string $reason): Quotation
    {
        return DB::transaction(function () use ($quotation, $by, $reason) {
            $locked = Quotation::whereKey($quotation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw QuotationException::notOpen($locked->reference, strtolower($locked->standing()['label']));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => QuotationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                "Cancelled quotation [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * The quotation a sale is being made from, locked and checked: open,
     * within its date, and for this customer. Called by `SalePoster` inside
     * its transaction, before anything else is locked.
     */
    public function lockedForSale(int $quotationId, int $customerId, Carbon $saleDate): Quotation
    {
        $quotation = Quotation::whereKey($quotationId)->lockForUpdate()->firstOrFail();

        if (! $quotation->isLive($saleDate)) {
            throw QuotationException::notLive($quotation->reference, strtolower($quotation->standing($saleDate)['label']));
        }

        if ((int) $quotation->customer_id !== $customerId) {
            throw QuotationException::otherCustomer($quotation->reference);
        }

        return $quotation;
    }

    /** Mark a locked quotation accepted: it became this sale. */
    public function accept(Quotation $quotation, Sale $sale): void
    {
        $this->audit->withoutAuditing(fn () => $quotation->forceFill([
            'status' => QuotationStatus::Accepted,
            'sale_id' => $sale->id,
            'accepted_at' => now(),
        ])->save());

        $quotation->auditCustom(
            AuditAction::Close,
            "Accepted quotation [{$quotation->reference}]: it became sale [{$sale->reference}].",
            ['sale' => $sale->reference],
        );
    }

    /** Mark a locked quotation accepted: it became this sales order (phase 29). */
    public function acceptAsOrder(Quotation $quotation, SalesOrder $order): void
    {
        $this->audit->withoutAuditing(fn () => $quotation->forceFill([
            'status' => QuotationStatus::Accepted,
            'accepted_at' => now(),
        ])->save());

        $quotation->auditCustom(
            AuditAction::Close,
            "Accepted quotation [{$quotation->reference}]: it became sales order [{$order->reference}].",
            ['sales_order' => $order->reference],
        );
    }

    /**
     * Every line as it is stored: what was typed, and what that is in the
     * product's own unit.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id:int, unit_id:?int, unit_factor:int|float, unit_quantity:int|float, quantity:string, unit_price:float, line_total:float}>
     */
    protected function price(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);
        $priced = [];

        foreach ($lines as $index => $line) {
            $unit = $converted[$index];

            if ($unit['error'] !== null || $unit['base'] === null || $unit['base'] <= 0) {
                throw QuotationException::cannotConvert(
                    $unit['error'] ?? 'A line could not be read in its product\'s own unit.'
                );
            }

            $typed = Quantity::normalize($line['quantity']);
            $price = round((float) $line['unit_price'], 2);

            $priced[] = [
                'product_id' => (int) $line['product_id'],
                'unit_id' => $unit['unit_id'],
                'unit_factor' => $unit['factor'],
                'unit_quantity' => $typed,
                'quantity' => Quantity::toDb($unit['base']),
                'unit_price' => $price,
                'line_total' => Quantity::lineTotal($typed, $price),
            ];
        }

        return $priced;
    }
}
