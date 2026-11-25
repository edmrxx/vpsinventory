<?php

namespace App\Http\Requests\Supplier;

use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('suppliers', 'code'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'string', 'max:40'],
            // Whether this supplier charges VAT. A fact about them, stored,
            // unlike the Settings switch, which only redraws the screens.
            'is_vat_registered' => ['required', 'boolean'],
            // Days the supplier gives to pay a bill (phase 24). 0 is due on
            // delivery. A form that leaves it out keeps what is there.
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only hold capital letters, numbers, dots, dashes and underscores.',
            'payment_terms_days.max' => 'Payment terms can be at most 365 days.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'code' => 'upper',
            'name' => 'trim',
            'contact_person' => 'trim',
            'email' => 'lower',
            'phone' => 'trim',
            'tin' => 'upper',
            'address' => 'trim',
            'notes' => 'trim',
        ]));

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_vat_registered' => $this->boolean('is_vat_registered'),
        ]);
    }
}
