<?php

namespace App\Http\Requests\Department;

use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($this->route('department'))],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'There is already a department with this name.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'name' => 'trim',
            'description' => 'trim',
        ]));

        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
