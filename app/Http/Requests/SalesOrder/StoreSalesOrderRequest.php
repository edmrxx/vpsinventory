<?php

namespace App\Http\Requests\SalesOrder;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\Location;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Support\Quantity;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A sales order: who placed it, until when its stock is held once approved,
 * and the lines at the agreed prices (phase 29).
 *
 * The lines follow the sale form's rules, so the sale that fulfils it copies
 * them. Whether the stock is free is asked only when it is approved — by
 * `SalesOrderDesk`, with the products locked — because a pending order holds
 * nothing.
 */
class StoreSalesOrderRequest extends FormRequest
{
    use ValidatesQuantities;

    /** How many lines one order may carry. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', 1)],
            'attention' => ['nullable', 'string', 'max:160'],
            'reserve_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::today()->toDateString()],
            // Where it holds its stock (phase 31.4): any active location. A
            // form with no such key at all holds it at the default.
            'location_id' => ['sometimes', 'required', 'integer', Rule::exists('locations', 'id')->where('is_active', 1)],
            // Approve — and so reserve — in the same step. Only for somebody
            // who may approve; checked in after().
            'approve' => ['nullable', 'boolean'],
            // The quotation it comes from, if any.
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            // Phase 28.1: bringing the goods to the site, added after the discount.
            'mobilization' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', 1)],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => $this->quantityRules(),
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pick who placed the order.',
            'customer_id.exists' => 'Pick a customer that is still active.',
            'location_id.required' => 'Pick where the stock is held.',
            'location_id.exists' => 'Pick a location that is still active.',
            'reserve_until.required' => 'Say until when the stock is held.',
            'reserve_until.after_or_equal' => 'The stock cannot be held until a day that has already passed.',
            'lines.required' => 'Add at least one product to this order.',
            'lines.min' => 'Add at least one product to this order.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
        ] + $this->quantityMessages();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->alreadySaved()) {
                    return;
                }

                if ($this->boolean('approve') && ! $this->user()->can('sales_orders.approve')) {
                    $validator->errors()->add('approve', 'You may not approve orders. Save it pending, and somebody who may approve will.');
                }

                if ($this->filled('quotation_id')) {
                    $quotation = Quotation::find($this->integer('quotation_id'));

                    if (! $quotation->isLive()) {
                        $validator->errors()->add('quotation_id', "Quotation [{$quotation->reference}] is "
                            .strtolower($quotation->standing()['label']).', so it cannot become an order.');
                    } elseif ((int) $quotation->customer_id !== $this->integer('customer_id')) {
                        $validator->errors()->add('customer_id', "Quotation [{$quotation->reference}] was made for another customer.");
                    }
                }

                $this->convertLines($validator);

                // Line by line to the centavo, as a sale adds it up.
                $subtotal = SaleTotals::of($this->input('lines', []), 0)['subtotal'];

                if (Quantity::centavos(round((float) $this->input('discount', 0), 2)) > $subtotal) {
                    $validator->errors()->add('discount', 'The discount cannot be more than the goods come to ('.number_format($subtotal / 100, 2).').');
                }
            },
        ];
    }

    /** The order this exact form already took, found by its key. */
    public function alreadySaved(): ?SalesOrder
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return SalesOrder::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Is this form the very same one that took that order? */
    public function matchesSaved(SalesOrder $order): bool
    {
        $sameHeader = (int) $this->input('customer_id') === (int) $order->customer_id
            && (int) ($this->has('location_id') ? $this->integer('location_id') : Location::default()?->id) === (int) $order->location_id
            && (string) $this->input('reserve_until') === $order->reserve_until?->toDateString()
            && Quantity::centavos(round((float) $this->input('discount', 0), 2)) === Quantity::centavos((float) $order->discount)
            && Quantity::centavos(round((float) $this->input('mobilization', 0), 2)) === Quantity::centavos((float) $order->mobilization);

        if (! $sameHeader) {
            return false;
        }

        $lines = $this->input('lines', []);
        $units = UnitConversion::resolve($lines);

        $sent = collect($lines)
            ->map(fn (array $line, $index) => [
                (int) ($line['product_id'] ?? 0),
                $units[$index]['unit_id'],
                Quantity::toMilli($line['quantity'] ?? 0),
                number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''),
            ])
            ->values()
            ->all();

        $saved = $order->lines()
            ->orderBy('id')
            ->get(['product_id', 'unit_id', 'unit_quantity', 'unit_price'])
            ->map(fn ($line) => [
                (int) $line->product_id,
                $line->unit_id === null ? null : (int) $line->unit_id,
                Quantity::toMilli($line->unit_quantity),
                number_format((float) $line->unit_price, 2, '.', ''),
            ])
            ->all();

        return $sent === $saved;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'attention' => $this->filled('attention') ? trim((string) $this->input('attention')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'discount' => $this->filled('discount') ? $this->input('discount') : 0,
            'mobilization' => $this->filled('mobilization') ? $this->input('mobilization') : 0,
        ]);
    }
}
