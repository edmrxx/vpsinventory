<?php

namespace App\Http\Requests\HeldSale;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Models\HeldSale;
use App\Models\Sale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Putting the counter on hold.
 *
 * Lighter than `StoreSaleRequest` on purpose: nothing leaves the shelf, so
 * nothing is weighed against it here. The products must exist and the
 * quantities must be quantities, so a hold can always be read back; the
 * stock, the units and the prices are all checked again when the recalled
 * sale is posted.
 */
class StoreHeldSaleRequest extends FormRequest
{
    use ValidatesQuantities;

    /** Only somebody who could finish the sale may park it. */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sale::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Left empty, the walk-in customer.
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('is_active', true),
            ],
            'note' => ['nullable', 'string', 'max:80'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],

            'lines' => ['required', 'array', 'min:1', 'max:'.StoreSaleRequest::MAX_LINES],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
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
            'customer_id.exists' => 'Pick a customer that is still active.',
            'note.max' => 'Keep the note to 80 characters.',
            'lines.required' => 'There is nothing on the counter to hold.',
            'lines.min' => 'There is nothing on the counter to hold.',
            'lines.max' => 'A sale may carry at most '.StoreSaleRequest::MAX_LINES.' lines.',
        ] + $this->quantityMessages();
    }

    /** A full shelf of holds is refused rather than grown without end. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (HeldSale::query()->count() >= HeldSale::MAX_OPEN) {
                    $validator->errors()->add(
                        'lines',
                        'There are already '.HeldSale::MAX_OPEN.' sales on hold. Recall or discard one first.',
                    );
                }
            },
        ];
    }
}
