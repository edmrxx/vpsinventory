<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    /** A supplier who charges VAT. */
    public function vatRegistered(): static
    {
        return $this->state(fn () => ['is_vat_registered' => true]);
    }

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('SUP-####')),
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09## ### ####'),
            'tin' => fake()->numerify('###-###-###-000'),
            // Stated rather than left to the column default, so a factory
            // supplier is a complete row the moment it is made.
            'is_vat_registered' => false,
            'address' => fake()->address(),
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
