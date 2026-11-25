<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'type' => fake()->randomElement(LocationType::values()),
            'address' => fake()->optional()->streetAddress(),
            'description' => null,
            'is_active' => true,
        ];
    }

    public function asStore(): static
    {
        return $this->state(fn () => ['type' => LocationType::Store->value]);
    }

    public function asWarehouse(): static
    {
        return $this->state(fn () => ['type' => LocationType::Warehouse->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
