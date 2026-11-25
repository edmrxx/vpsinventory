<?php

namespace App\Http\Requests\PurchaseRequest;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\PurchaseRequest;
use App\Support\Quantity;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A purchase request: what is needed, how much, roughly what it costs, and
 * why.
 *
 * The lines follow the purchase form's rules — the same products, units and
 * quantities — so the order made from an approved request can copy them.
 * The cost is an estimate: zero is allowed, for something nobody has priced
 * yet.
 */
class StorePurchaseRequestRequest extends FormRequest
{
    use ValidatesQuantities;

    /** How many lines one request may carry. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional, and only a suggestion: who the requester would buy from.
            'supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('is_active', 1),
            ],
            // Who the goods are for (phase 24.1). Optional: a request raised
            // before departments existed has none, and a small shop may not
            // use them.
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('is_active', 1),
            ],
            'needed_by' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::today()->toDateString()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', 1),
            ],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => $this->quantityRules(),
            // Estimated, per typed unit: per piece, or per box.
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Pick a supplier that is still active, or leave it empty.',
            'department_id.exists' => 'Pick a department that is still active, or leave it empty.',
            'needed_by.after_or_equal' => 'The date it is needed by cannot be in the past.',
            'reason.required' => 'Say why these goods are needed.',
            'reason.min' => 'Say why these goods are needed, in a few words.',
            'lines.required' => 'Add at least one product to this request.',
            'lines.min' => 'Add at least one product to this request.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
            'lines.*.unit_cost.required' => 'Type an estimated cost, or 0 if nobody knows yet.',
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
            },
        ];
    }

    /** The request this exact form already raised, found by its key. */
    public function alreadySaved(): ?PurchaseRequest
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return PurchaseRequest::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /**
     * Is this form the very same one that raised that request?
     *
     * A double click sends the same thing twice. A create screen brought back
     * from the browser's history carries the old key with new contents, and
     * must not land on the old request.
     */
    public function matchesSaved(PurchaseRequest $request): bool
    {
        $sameHeader = (int) $this->input('supplier_id') === (int) $request->supplier_id
            && (int) $this->input('department_id') === (int) $request->department_id
            && (string) $this->input('reason') === (string) $request->reason
            && ((string) $this->input('needed_by')) === (string) $request->needed_by?->toDateString();

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
                number_format((float) ($line['unit_cost'] ?? 0), 2, '.', ''),
            ])
            ->values()
            ->all();

        $saved = $request->lines()
            ->orderBy('id')
            ->get(['product_id', 'unit_id', 'unit_quantity', 'unit_cost'])
            ->map(fn ($line) => [
                (int) $line->product_id,
                $line->unit_id === null ? null : (int) $line->unit_id,
                Quantity::toMilli($line->unit_quantity),
                number_format((float) $line->unit_cost, 2, '.', ''),
            ])
            ->all();

        return $sent === $saved;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'supplier_id' => $this->filled('supplier_id') ? $this->input('supplier_id') : null,
            'department_id' => $this->filled('department_id') ? $this->input('department_id') : null,
            'needed_by' => $this->filled('needed_by') ? $this->input('needed_by') : null,
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
