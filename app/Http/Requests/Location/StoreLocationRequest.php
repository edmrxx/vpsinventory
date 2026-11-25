<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationType;
use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('locations', 'name')],
            'type' => ['required', Rule::in(LocationType::values())],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a location with this name.',
            'type.in' => 'Pick Store or Warehouse.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'name' => 'trim',
            'type' => 'lower',
            'address' => 'trim',
            'description' => 'trim',
        ]));

        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
