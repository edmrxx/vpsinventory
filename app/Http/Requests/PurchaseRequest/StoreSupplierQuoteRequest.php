<?php

namespace App\Http\Requests\PurchaseRequest;

use App\Models\PurchaseRequest;
use App\Models\SupplierQuote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One supplier's prices for an approved request (phase 24.2).
 *
 * `prices` is keyed by the request line, per the unit the line was typed
 * in. An item left empty is one the supplier does not carry. `CanvassDesk`
 * checks everything here again with the request row locked.
 */
class StoreSupplierQuoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', 1)],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pick the supplier these prices came from.',
            'supplier_id.exists' => 'Pick a supplier that is still active.',
            'prices.required' => 'Type a price for at least one item.',
            'prices.*.numeric' => 'Type the price as a number.',
            'prices.*.min' => 'A price must be more than zero. Leave the item empty if the supplier does not carry it.',
            'delivery_days.max' => 'Delivery days cannot be more than 365.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var PurchaseRequest $request */
                $request = $this->route('purchaseRequest');
                $lineIds = $request->lines()->pluck('id')->map(fn ($id) => (int) $id)->all();
                $priced = array_filter($this->input('prices', []), fn ($price) => $price !== null && $price !== '');

                if ($priced === []) {
                    $validator->errors()->add('prices', 'Type a price for at least one item. Leave an item empty if the supplier does not carry it.');
                }

                foreach (array_keys($priced) as $lineId) {
                    if (! in_array((int) $lineId, $lineIds, true)) {
                        $validator->errors()->add('prices', 'A price was given for an item that is not on this request.');
                        break;
                    }
                }

                if (SupplierQuote::where('purchase_request_id', $request->id)->where('supplier_id', $this->integer('supplier_id'))->exists()) {
                    $validator->errors()->add('supplier_id', 'This supplier has already quoted on this request. Remove that quote first to enter it again.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $prices = collect($this->input('prices', []))
            ->map(fn ($price) => is_string($price) ? (trim($price) === '' ? null : trim($price)) : $price)
            ->all();

        $this->merge([
            'prices' => $prices,
            'delivery_days' => $this->filled('delivery_days') ? $this->input('delivery_days') : null,
            'payment_terms' => $this->filled('payment_terms') ? trim((string) $this->input('payment_terms')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
