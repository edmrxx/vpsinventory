<?php

namespace Database\Factories;

use App\Support\PermissionRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Give the user the Admin role once it is saved.
     *
     * Roles live in their own table, so they are attached after the insert.
     */
    public function admin(): static
    {
        return $this->afterCreating(
            fn ($user) => $user->syncRoles([PermissionRegistry::adminRole()])
        );
    }

    /**
     * Give the user a role by name. The role must already exist.
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(fn ($user) => $user->syncRoles([$role]));
    }

    /**
     * Give the user single permissions, with no role.
     */
    public function withPermissions(string ...$permissions): static
    {
        return $this->afterCreating(
            fn ($user) => $user->syncPermissions($permissions)
        );
    }

    /**
     * Indicate that the user is disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
