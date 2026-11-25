<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CUS-####')),
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('09## ### ####'),
            'tin' => fake()->numerify('###-###-###-000'),
            'address' => fake()->address(),
            'notes' => null,
            'is_walk_in' => false,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** The counter-sale row the app protects. */
    public function walkIn(): static
    {
        return $this->state(fn () => [
            'code' => 'WALK-IN',
            'name' => 'Walk-in Customer',
            'is_walk_in' => true,
            'is_active' => true,
        ]);
    }
}
