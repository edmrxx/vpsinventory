<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 50, 900);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'name' => fake()->words(3, true),
            'description' => null,
            'category_id' => Category::factory(),
            'unit_id' => Unit::factory(),
            'cost' => $cost,
            'selling_price' => round($cost * 1.6, 2),
            'reorder_level' => 10,
            'is_active' => true,
        ];
    }

    /**
     * Put stock on the product.
     *
     * `on_hand` is outside `$fillable`, so a test that needs stock sets it
     * straight on the model the way the posting service will.
     */
    public function onHand(int $quantity): static
    {
        return $this->afterCreating(function ($product) use ($quantity) {
            // The query builder fires no model event, so the product writes
            // no audit row for stock it was simply set up with.
            $product->newQuery()->whereKey($product->getKey())->update(['on_hand' => $quantity]);

            $product->setAttribute('on_hand', $quantity)->syncOriginal();
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
