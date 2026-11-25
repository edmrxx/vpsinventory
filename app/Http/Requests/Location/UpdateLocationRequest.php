<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationType;
use App\Policies\LocationPolicy;
use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('locations', 'name')->ignore($this->route('location'))],
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

    /**
     * The form's "active" switch obeys the same rule as the list's button:
     * the default location and one holding stock stay on (phase 31.2).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $location = $this->route('location');

                if ($this->boolean('is_active') || ! $location->is_active) {
                    return;
                }

                $refusal = app(LocationPolicy::class)->refuseSwitchingOff($location);

                if ($refusal !== null) {
                    $validator->errors()->add('is_active', $refusal->message());
                }
            },
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
