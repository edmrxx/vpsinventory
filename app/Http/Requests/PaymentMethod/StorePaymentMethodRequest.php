<?php

namespace App\Http\Requests\PaymentMethod;

use App\Enums\PaymentKind;
use App\Models\PaymentMethod;
use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaymentMethodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('payment_methods', 'name')->ignore($this->route('payment_method')),
            ],
            'kind' => ['required', 'string', Rule::in(PaymentKind::values())],
            'requires_reference' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a payment method with this name.',
            'kind.in' => 'Pick what kind of payment this is.',
        ];
    }

    /**
     * There is one cash drawer, so there is one cash method: change is given
     * from it, and a sale posted with no payment named is recorded on it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            // "GCash" and "gcash" are the same button at the till. MySQL
            // compares names that way already; SQLite does not, so it is
            // asked the same way on both.
            function (Validator $validator) {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $taken = PaymentMethod::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $this->input('name'))])
                    ->when($this->route('payment_method'), fn ($q, $self) => $q->whereKeyNot($self->getKey()))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('name', 'There is already a payment method with this name.');
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->has('kind') || $this->input('kind') !== PaymentKind::Cash->value) {
                    return;
                }

                $other = PaymentMethod::query()
                    ->where('kind', PaymentKind::Cash->value)
                    ->when($this->route('payment_method'), fn ($q, $self) => $q->whereKeyNot($self->getKey()))
                    ->value('name');

                if ($other !== null) {
                    $validator->errors()->add(
                        'kind',
                        "There is already a cash method ({$other}). Change is given from one drawer, so there is only one."
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), ['name' => 'trim']));

        $this->merge([
            'requires_reference' => $this->boolean('requires_reference'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
