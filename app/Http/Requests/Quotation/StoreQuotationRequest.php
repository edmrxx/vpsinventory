<?php

namespace App\Http\Requests\Quotation;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\Quotation;
use App\Support\Quantity;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A quotation: who it is for, until when it holds, and the lines at the
 * prices offered (phase 28).
 *
 * The lines follow the sale form's rules — the same products, units and
 * quantities — so the sale made from it can copy them. Stock is not asked:
 * a quotation reserves nothing, and the sale checks the shelf when it posts.
 */
class StoreQuotationRequest extends FormRequest
{
    use ValidatesQuantities;

    /** How many lines one quotation may carry. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', 1)],
            'attention' => ['nullable', 'string', 'max:160'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::today()->toDateString()],
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
            'customer_id.required' => 'Pick who the quotation is for.',
            'customer_id.exists' => 'Pick a customer that is still active.',
            'valid_until.required' => 'Say until when the prices hold.',
            'valid_until.after_or_equal' => 'A quotation cannot have expired before it is made.',
            'lines.required' => 'Add at least one product to this quotation.',
            'lines.min' => 'Add at least one product to this quotation.',
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

                $this->convertLines($validator);

                // Line by line to the centavo, as a sale adds it up.
                $subtotal = SaleTotals::of($this->input('lines', []), 0)['subtotal'];

                if (Quantity::centavos(round((float) $this->input('discount', 0), 2)) > $subtotal) {
                    $validator->errors()->add('discount', 'The discount cannot be more than the goods come to ('.number_format($subtotal / 100, 2).').');
                }
            },
        ];
    }

    /** The quotation this exact form already made, found by its key. */
    public function alreadySaved(): ?Quotation
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return Quotation::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Is this form the very same one that made that quotation? */
    public function matchesSaved(Quotation $quotation): bool
    {
        $sameHeader = (int) $this->input('customer_id') === (int) $quotation->customer_id
            && (string) $this->input('valid_until') === $quotation->valid_until?->toDateString()
            && Quantity::centavos(round((float) $this->input('discount', 0), 2)) === Quantity::centavos((float) $quotation->discount)
            && Quantity::centavos(round((float) $this->input('mobilization', 0), 2)) === Quantity::centavos((float) $quotation->mobilization);

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

        $saved = $quotation->lines()
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
