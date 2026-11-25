<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Concerns\ValidatesCredit;
use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    use ValidatesCredit;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:40',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('customers', 'code'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'tin' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ] + $this->creditRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only hold capital letters, numbers, dots, dashes and underscores.',
        ] + $this->creditMessages();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->refuseUnusableCredit($validator, null),
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

        $this->merge(['is_active' => $this->boolean('is_active')]);

        $this->prepareCredit();
    }
}
