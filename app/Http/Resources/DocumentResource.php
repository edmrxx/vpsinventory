<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\Vat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A purchase, a sale, either kind of return, or an adjustment.
 *
 * They all answer the same questions — what, when, from or to whom, how
 * much — so one shape serves all five rather than five that drift apart.
 *
 * Cost is gated where cost means the cost of goods sold, and nowhere else.
 * On a sale, and on a sales return that puts goods back at what they cost,
 * the figure is the margin in disguise, so it follows `sales.view_cost`
 * exactly as the screens do. On a delivery, a purchase return or an
 * adjustment it is the buying price, which anybody who may open the
 * document already sees on the web — and on a purchase return it is the
 * only value the document has, so hiding it would send a phone a return
 * worth nothing at all.
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The cost of goods sold, and so the margin, is held back. The price
        // paid to a supplier is not: see the note above the class.
        $costIsCogs = $this->resource instanceof Sale
            || $this->resource instanceof SalesReturn;

        $seesCost = ! $costIsCogs
            || ($request->user()?->can('viewCost', Sale::class) ?? false);

        // The two sides answer different questions, so they read different
        // things. Selling: do *we* charge VAT — the Tax Settings switch,
        // which is a view and stores nothing. Buying: did the supplier
        // charge VAT — the rate stamped on the delivery when it was
        // posted, which is a record and does not move afterwards.
        //
        // An adjustment splits neither way: nothing was bought or sold.
        $rate = match (true) {
            $this->resource instanceof Purchase => (float) $this->vat_rate,
            $this->resource instanceof PurchaseReturn => (float) ($this->purchase?->vat_rate ?? 0),
            $this->resource instanceof Sale,
            $this->resource instanceof SalesReturn => Vat::currentRate(),
            default => 0.0,
        };

        $vatable = $rate > 0;

        // A purchase return has no price of its own: what the supplier
        // gives back is what the goods cost.
        $gross = $this->resource instanceof PurchaseReturn
            ? $this->total_cost
            : ($this->total_amount ?? null);

        return array_filter([
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind ?? null,
            'date' => $this->documentDate()?->toDateString(),
            'status' => $this->status?->value,
            'reason' => $this->reason ?? null,
            'notes' => $this->notes ?? null,

            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier?->id,
                'name' => $this->supplier?->name,
            ]),
            // Where a delivery went on the shelf (phase 31.3); where a sale
            // left it, or an adjustment counted it (phase 31.4).
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),

            'lines_count' => $this->whenCounted('lines'),
            'subtotal' => $this->subtotal ?? null,
            'discount' => $this->discount ?? null,
            // Phase 28.1: on a quotation, sales order or sale only.
            'mobilization' => $this->mobilization ?? null,
            'total_amount' => $this->total_amount ?? null,
            'vat_rate' => $vatable ? Vat::rateLabel($rate) : null,
            'net_amount' => $vatable ? Vat::net($gross, $rate) : null,
            'vat_amount' => $vatable ? Vat::tax($gross, $rate) : null,
            'total_cost' => $seesCost ? ($this->total_cost ?? null) : null,
            'gross_profit' => $seesCost && $this->resource instanceof Sale
                ? $this->grossProfit()
                : null,

            // A sale says how it was paid: what each method put towards it,
            // and on cash what was handed over.
            'payments' => $this->resource instanceof Sale
                ? $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => array_filter([
                    'method' => $payment->method?->name,
                    'kind' => $payment->method?->kind?->value,
                    'amount' => $payment->amount,
                    'tendered' => $payment->tendered,
                    'reference' => $payment->reference,
                ], fn ($value) => $value !== null))->all())
                : null,
            'change' => $this->resource instanceof Sale && $this->resource->relationLoaded('payments')
                ? $this->changeGiven()
                : null,

            'on_account' => $this->resource instanceof Sale ? $this->on_account : null,
            'balance_due' => $this->resource instanceof Sale ? $this->balance_due : null,
            'due_date' => $this->resource instanceof Sale ? $this->due_date?->toDateString() : null,
            'terms_days' => $this->resource instanceof Sale ? $this->terms_days : null,
            'credited_amount' => $this->resource instanceof SalesReturn ? $this->credited_amount : null,

            'posted_at' => $this->posted_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason ?? null,

            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                fn ($line) => array_filter([
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    // Always in the product's own unit: what moved.
                    'quantity' => $line->quantity,
                    // A sale or purchase line also says what was typed: 1 box,
                    // priced per box. The two match on a line typed in the
                    // product's own unit.
                    'unit_quantity' => $line->unit_quantity ?? null,
                    'unit_code' => isset($line->unit_quantity)
                        ? ($line->unit?->code ?? $line->product?->unit?->code)
                        : null,
                    'returned_quantity' => $line->returned_quantity ?? null,
                    'unit_price' => $line->unit_price ?? null,
                    'unit_refund' => $line->unit_refund ?? null,
                    'line_refund' => $line->line_refund ?? null,
                    'line_total' => $line->line_total ?? null,
                    'net_total' => $vatable
                        ? Vat::net($line->line_total ?? $line->line_refund ?? $line->line_cost, $rate)
                        : null,
                    'vat_total' => $vatable
                        ? Vat::tax($line->line_total ?? $line->line_refund ?? $line->line_cost, $rate)
                        : null,
                    'unit_cost' => $seesCost ? ($line->unit_cost ?? null) : null,
                    'line_cost' => $seesCost ? ($line->line_cost ?? $line->total_cost ?? null) : null,
                ], fn ($value) => $value !== null)
            )->all()),
        ], fn ($value) => $value !== null);
    }

    /**
     * Each document names its date column differently, so this asks the
     * model rather than guessing from the class.
     */
    protected function documentDate(): ?Carbon
    {
        foreach (['purchase_date', 'sale_date', 'return_date', 'adjustment_date'] as $column) {
            if (isset($this->resource->{$column})) {
                return $this->resource->{$column};
            }
        }

        return null;
    }
}
