<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Exceptions\CanvassException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\SupplierQuoteLine;
use App\Models\User;
use App\Support\Quantity;
use App\Support\QuoteAbstract;
use Illuminate\Support\Facades\DB;

/**
 * The canvass on an approved purchase request (phase 24.2): suppliers'
 * prices recorded, a quote removed, the award made, the award reopened.
 *
 * Every step locks the request row first and reads its state again under
 * that lock — approved, not yet ordered, awarded or not — so two people
 * cannot award the same request, and nobody can slip a quote in after the
 * award. A quote is working paper until the award: it may be removed and
 * entered again. Once awarded, the quotes are locked, because they are the
 * record of why a supplier won; the approver may reopen the award with a
 * reason until an order is made from it (phase 24.3).
 *
 * Nothing here moves stock or money, so no cache is flushed. The trail is
 * written on the request, one line per step.
 */
class CanvassDesk
{
    /**
     * @param  array{supplier_id:int, delivery_days?:int|null, payment_terms?:string|null, notes?:string|null, prices: array<int|string, string|float|null>}  $data
     */
    public function recordQuote(PurchaseRequest $request, array $data, User $by): SupplierQuote
    {
        return DB::transaction(function () use ($request, $data, $by) {
            $locked = $this->lockOpen($request);

            $supplier = Supplier::whereKey($data['supplier_id'])->where('is_active', true)->first()
                ?? throw CanvassException::inactiveSupplier();

            if (SupplierQuote::where('purchase_request_id', $locked->id)->where('supplier_id', $supplier->id)->exists()) {
                throw CanvassException::alreadyQuoted($supplier->name);
            }

            $lines = PurchaseRequestLine::where('purchase_request_id', $locked->id)->get()->keyBy('id');
            $priced = [];

            foreach ($data['prices'] as $lineId => $price) {
                if ($price === null || $price === '') {
                    continue;
                }

                $line = $lines->get((int) $lineId) ?? throw CanvassException::foreignLine();
                $unitPrice = round((float) $price, 2);

                $priced[] = [
                    'purchase_request_line_id' => $line->id,
                    'unit_price' => $unitPrice,
                    'line_total' => Quantity::lineTotal($line->unit_quantity, $unitPrice),
                ];
            }

            if ($priced === []) {
                throw CanvassException::nothingPriced();
            }

            $quote = SupplierQuote::create([
                'purchase_request_id' => $locked->id,
                'supplier_id' => $supplier->id,
                'delivery_days' => $data['delivery_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $by->id,
            ]);

            foreach ($priced as $line) {
                SupplierQuoteLine::create(['supplier_quote_id' => $quote->id] + $line);
            }

            $total = array_sum(array_map(fn ($line) => Quantity::centavos($line['line_total']), $priced));

            $locked->auditCustom(
                AuditAction::Create,
                "Recorded a quote from {$supplier->name} on request [{$locked->reference}]: "
                    .count($priced).' of '.$lines->count().' item(s), '.number_format($total / 100, 2).'.',
                ['supplier' => $supplier->name, 'items' => count($priced), 'total' => $total / 100],
            );

            return $quote;
        }, attempts: 3);
    }

    public function removeQuote(PurchaseRequest $request, SupplierQuote $quote, User $by): void
    {
        DB::transaction(function () use ($request, $quote, $by) {
            $locked = $this->lockOpen($request);

            $quote = SupplierQuote::whereKey($quote->id)->where('purchase_request_id', $locked->id)->with('supplier:id,name')->firstOrFail();
            $name = $quote->supplier?->name;

            $quote->delete();

            $locked->auditCustom(
                AuditAction::Delete,
                "Removed the quote from {$name} on request [{$locked->reference}].",
                ['supplier' => $name, 'by' => $by->username],
            );
        }, attempts: 3);
    }

    /**
     * Choose the supplier for each item.
     *
     * @param  array<int|string, int|string|null>  $choices  request line id => quote line id
     * @param  array<int|string, string|null>  $reasons  request line id => why not the lowest
     */
    public function award(PurchaseRequest $request, array $choices, array $reasons, User $by): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $choices, $reasons, $by) {
            $locked = $this->lockOpen($request);
            $abstract = QuoteAbstract::of($locked);

            if ($errors = QuoteAbstract::weighAward($abstract, $choices, $reasons)) {
                throw CanvassException::invalidAward($errors);
            }

            $won = [];
            $dearer = [];

            foreach ($abstract['lines'] as $line) {
                if (! $line['quoted']) {
                    continue;
                }

                $chosen = (int) ($choices[$line['line_id']] ?? $choices[(string) $line['line_id']]);
                $column = collect($line['cells'])->search(fn ($cell) => $cell !== null && (int) $cell['quote_line_id'] === $chosen);
                $lowest = $line['cells'][$column]['lowest'];
                $supplier = $abstract['suppliers'][$column]['name'];
                $reason = $lowest ? null : trim((string) ($reasons[$line['line_id']] ?? $reasons[(string) $line['line_id']]));

                PurchaseRequestLine::whereKey($line['line_id'])->update([
                    'awarded_quote_line_id' => $chosen,
                    'award_reason' => $reason,
                ]);

                $won[$supplier] = ($won[$supplier] ?? 0) + 1;

                if (! $lowest) {
                    $dearer[] = "{$line['name']} to {$supplier} ({$reason})";
                }
            }

            $locked->forceFill(['awarded_at' => now(), 'awarded_by' => $by->id])->saveQuietly();

            $fresh = QuoteAbstract::of($locked->fresh());
            $split = collect($won)->map(fn ($n, $name) => "{$name} ({$n})")->implode(', ');

            $locked->auditCustom(
                AuditAction::Approve,
                "Awarded request [{$locked->reference}]: {$split}; total ".number_format((float) $fresh['awarded_total'], 2).'.'
                    .($dearer ? ' Not the lowest: '.implode('; ', $dearer).'.' : ''),
                ['suppliers' => $won, 'total' => $fresh['awarded_total']],
            );

            return $locked->fresh();
        }, attempts: 3);
    }

    /** Take the award back, with a reason, until an order is made from it. */
    public function reopen(PurchaseRequest $request, string $reason, User $by): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $reason, $by) {
            $locked = PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAwarded()) {
                throw CanvassException::notAwarded($locked->reference);
            }

            $this->refuseOrdered($locked);

            PurchaseRequestLine::where('purchase_request_id', $locked->id)
                ->update(['awarded_quote_line_id' => null, 'award_reason' => null]);

            $locked->forceFill(['awarded_at' => null, 'awarded_by' => null])->saveQuietly();

            $locked->auditCustom(
                AuditAction::Update,
                "Reopened the award on request [{$locked->reference}]: {$reason}",
                ['reason' => $reason, 'by' => $by->username],
            );

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * The request row, locked, and still open to a canvass: approved, not
     * awarded, not ordered.
     */
    protected function lockOpen(PurchaseRequest $request): PurchaseRequest
    {
        $locked = PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isApproved()) {
            throw CanvassException::notApproved($locked->reference, strtolower($locked->status->label()));
        }

        if ($locked->isAwarded()) {
            throw CanvassException::alreadyAwarded($locked->reference);
        }

        $this->refuseOrdered($locked);

        return $locked;
    }

    protected function refuseOrdered(PurchaseRequest $request): void
    {
        if ($order = PurchaseOrder::where('purchase_request_id', $request->id)->value('reference')) {
            throw CanvassException::alreadyOrdered($request->reference, $order);
        }
    }
}
