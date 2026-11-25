<?php

namespace App\Http\Requests\User;

use App\Models\Role;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'min:3', 'max:255',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($user),
            ],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            // Left empty, the password stays as it is.
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_active' => ['required', 'boolean'],
            'roles' => ['array'],
            'roles.*' => [Rule::exists(Role::class, 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'The username may only hold letters, numbers, dots, dashes and underscores.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => strtolower(trim((string) $this->input('username'))),
            'is_active' => $this->boolean('is_active'),
            'email' => $this->filled('email') ? $this->input('email') : null,
        ]);
    }

    /**
     * Guard the two ways a user could lock themselves, or everybody, out.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->route('user');
                $adminRole = PermissionRegistry::adminRole();
                $roles = (array) $this->input('roles', []);

                if ($this->user()->is($user)) {
                    if (! $this->boolean('is_active')) {
                        $validator->errors()->add(
                            'is_active',
                            'You cannot deactivate your own account.'
                        );
                    }

                    if ($user->hasRole($adminRole) && ! in_array($adminRole, $roles, true)) {
                        $validator->errors()->add(
                            'roles',
                            "You cannot take the [{$adminRole}] role off your own account."
                        );
                    }
                }

                // The app must keep at least one active administrator.
                if (
                    $user->hasRole($adminRole)
                    && (! in_array($adminRole, $roles, true) || ! $this->boolean('is_active'))
                    && $this->isLastActiveAdmin($user)
                ) {
                    $validator->errors()->add(
                        'roles',
                        'This is the last active administrator. Give the role to someone else first.'
                    );
                }
            },
        ];
    }

    protected function isLastActiveAdmin($user): bool
    {
        return $user->newQuery()
            ->role(PermissionRegistry::adminRole())
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
