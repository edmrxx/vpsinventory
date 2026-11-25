<?php

namespace App\Http\Requests\PendingPayment;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Models\PendingPayment;
use App\Support\Quantity;
use App\Support\SaleTotals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sending the counter to the cashier: Pending payment (CR-2).
 *
 * The products must be active and the quantities quantities; the discount
 * cannot be more than the goods. Whether the goods are free is decided by
 * `PendingPaymentDesk`, with the product rows locked, and said back under
 * the basket.
 */
class StorePendingPaymentRequest extends FormRequest
{
    use ValidatesQuantities;

    public function authorize(): bool
    {
        return $this->user()?->can('create', PendingPayment::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')->where('is_active', true),
            ],
            // The till's store: where the goods are held and where it is paid.
            'location_id' => [
                'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'note' => ['nullable', 'string', 'max:80'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:'.StoreSaleRequest::MAX_LINES],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
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
            'customer_id.required' => 'Pick who this order is for.',
            'customer_id.exists' => 'Pick a customer that is still active.',
            'location_id.required' => 'This till has no store to hold the goods at.',
            'location_id.exists' => 'This till\'s store has been switched off.',
            'note.max' => 'Keep the note to 80 characters.',
            'lines.required' => 'There is nothing on the counter to send.',
            'lines.min' => 'There is nothing on the counter to send.',
            'lines.max' => 'An order may carry at most '.StoreSaleRequest::MAX_LINES.' lines.',
            'lines.*.product_id.exists' => 'A product on the counter has been switched off.',
        ] + $this->quantityMessages();
    }

    /** The discount cannot take more than the goods come to. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $subtotal = SaleTotals::of($this->input('lines', []), 0)['subtotal'];

                if (Quantity::centavos(round((float) $this->input('discount', 0), 2)) > $subtotal) {
                    $validator->errors()->add('discount', 'The discount cannot be more than the goods come to.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->filled('discount') ? $this->input('discount') : 0,
            'note' => $this->filled('note') ? trim((string) $this->input('note')) : null,
        ]);
    }
}
