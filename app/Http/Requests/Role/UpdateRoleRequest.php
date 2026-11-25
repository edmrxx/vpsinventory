<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Permission;

class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                'regex:/^[A-Za-z0-9 &\/-]+$/',
                Rule::unique('roles', 'name')
                    ->where('guard_name', 'web')
                    ->ignore($this->route('role')),
            ],
            'permissions' => ['array'],
            'permissions.*' => [Rule::exists(Permission::class, 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The role name may only hold letters, numbers, spaces, &, / and -.',
            'permissions.*.exists' => 'One of the chosen permissions does not exist.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name'))),
        ]);
    }

    /**
     * A built-in role keeps its name. Its permissions may still change.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $role = $this->route('role');

                if ($role->isProtected() && $this->input('name') !== $role->name) {
                    $validator->errors()->add(
                        'name',
                        "The [{$role->name}] role is built in and cannot be renamed."
                    );
                }
            },
        ];
    }
}
