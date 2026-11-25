<?php

namespace App\Http\Requests\Unit;

use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9.\/-]+$/',
                Rule::unique('units', 'code'),
            ],
            'name' => ['required', 'string', 'max:80'],
            'allows_decimals' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only hold letters, numbers, dots, slashes and dashes.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'code' => 'lower',
            'name' => 'trim',
        ]));

        $this->merge([
            'allows_decimals' => $this->boolean('allows_decimals'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
