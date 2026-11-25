<?php

namespace Tests\Feature\Seeders;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\AdjustmentPoster;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demonstration seeder.
 *
 * Building the whole year here would make the suite crawl — it posts some
 * four hundred documents through the real ledger on purpose. What is worth
 * checking cheaply is the guard: this seeder must refuse a database that is
 * already trading, rather than fail halfway through and leave a mess.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_it_refuses_a_database_that_already_has_movements(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);

        $before = InventoryMovement::count();
        $products = Product::count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, InventoryMovement::count(), 'The seeder posted anyway.');
        $this->assertSame($products, Product::count(), 'The seeder planted products anyway.');
    }

    /**
     * It is a demonstration, not part of a normal install.
     *
     * A real setup must never plant invented sales, so this seeder has to
     * stay out of `DatabaseSeeder` and be run by hand.
     */
    public function test_it_is_not_part_of_the_normal_seed(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString(
            'DemoDataSeeder',
            $source,
            'The demo seeder must not run on a normal install.'
        );
    }
}
