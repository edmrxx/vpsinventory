<?php

namespace Tests\Feature\Seeders;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_fills_the_three_empty_screens(): void
    {
        $this->seed(SampleDataSeeder::class);

        $this->assertSame(2, Category::count());
        $this->assertSame(6, Product::count());
        $this->assertSame(4, Supplier::count());
    }

    public function test_the_seeder_brings_its_own_units_when_none_exist(): void
    {
        // It is run on an empty database here, so it has to call the master
        // seeder itself rather than fail on a missing [pcs].
        $this->seed(SampleDataSeeder::class);

        $this->assertSame('pcs', Product::firstWhere('sku', 'TSHIRT-BLK')->unit->code);
    }

    public function test_the_products_carry_the_figures_from_the_mock_up(): void
    {
        $this->seed(SampleDataSeeder::class);

        $shirt = Product::firstWhere('sku', 'TSHIRT-BLK');

        $this->assertSame('T-Shirt (Black)', $shirt->name);
        $this->assertSame('Apparel', $shirt->category->name);
        $this->assertSame('300.00', $shirt->cost);
        $this->assertSame('500.00', $shirt->selling_price);
        $this->assertSame(20, $shirt->reorder_level);
        $this->assertSame(90, $shirt->on_hand);
        $this->assertFalse($shirt->isLowStock());
    }

    public function test_the_five_low_rows_of_the_mock_up_read_as_low_stock(): void
    {
        $this->seed(SampleDataSeeder::class);

        $low = Product::lowStock()->orderBy('sku')->pluck('sku')->all();

        $this->assertSame(['CAP-NVY', 'JACKET-L', 'POLO-M', 'SOCKS-WHT', 'TSHIRT-WHT'], $low);
    }

    public function test_the_seeder_writes_no_audit_rows(): void
    {
        $this->seed(SampleDataSeeder::class);

        // Sample scenery is not something a person did.
        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $this->seed(SampleDataSeeder::class);
        $this->seed(SampleDataSeeder::class);

        $this->assertSame(2, Category::count());
        $this->assertSame(6, Product::count());
        $this->assertSame(4, Supplier::count());
    }

    public function test_a_second_run_does_not_overwrite_stock_that_has_since_moved(): void
    {
        $this->seed(SampleDataSeeder::class);

        Product::where('sku', 'TSHIRT-BLK')->update(['on_hand' => 41]);

        $this->seed(SampleDataSeeder::class);

        $this->assertSame(41, Product::firstWhere('sku', 'TSHIRT-BLK')->on_hand);
    }

    /* ------------------------------------------------------------- The ledger */

    public function test_the_sample_stock_came_through_a_posted_document(): void
    {
        $this->seed(SampleDataSeeder::class);

        $opening = InventoryAdjustment::firstWhere('kind', 'opening');

        $this->assertNotNull($opening, 'The sample stock was written with no document behind it.');
        $this->assertSame('posted', $opening->status->value);
        $this->assertCount(6, $opening->lines);
        $this->assertStringStartsWith('ADJ-', $opening->reference);
    }

    public function test_every_sample_product_has_batches_behind_its_stock(): void
    {
        $this->seed(SampleDataSeeder::class);

        // The rule the whole ledger rests on.
        $this->assertSame([], app(InventoryLedger::class)->reconcile());

        $this->assertSame(6, InventoryLayer::count());
        $this->assertSame(6, InventoryMovement::count());

        $shirt = Product::firstWhere('sku', 'TSHIRT-BLK');
        $layer = InventoryLayer::firstWhere('product_id', $shirt->id);

        $this->assertSame(90, $layer->quantity_remaining);
        $this->assertSame('300.00', $layer->unit_cost);
    }

    public function test_the_sample_stock_can_actually_be_sold(): void
    {
        $this->seed(SampleDataSeeder::class);

        $shirt = Product::firstWhere('sku', 'TSHIRT-BLK');

        // Before this phase the stock had no batches, so this would have
        // failed with nothing to cost against.
        app(AdjustmentPoster::class)->post('decrease', [
            ['product_id' => $shirt->id, 'quantity' => 2],
        ]);

        $this->assertSame(88, $shirt->fresh()->on_hand);
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    /* ----------------------------------------------------------------- Clear */

    public function test_the_clear_command_removes_everything_it_added(): void
    {
        $this->seed(SampleDataSeeder::class);

        $this->artisan('sample:clear --force')->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Supplier::count());

        // The ledger goes with them, or the foreign keys would have refused
        // the delete in the first place.
        $this->assertSame(0, InventoryLayer::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryAdjustment::count());
    }

    public function test_the_clear_command_keeps_a_category_the_user_is_still_using(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->seed(SampleDataSeeder::class);

        $apparel = Category::firstWhere('name', 'Apparel');

        $this->unaudited(fn () => Product::factory()
            ->for($apparel)
            ->for($apparel->products()->first()->unit)
            ->create(['sku' => 'MINE-001']));

        $this->artisan('sample:clear --force')->assertSuccessful();

        $this->assertDatabaseHas('products', ['sku' => 'MINE-001']);
        $this->assertDatabaseHas('categories', ['name' => 'Apparel']);
        $this->assertDatabaseMissing('categories', ['name' => 'Accessories']);
    }

    public function test_the_clear_command_refuses_while_a_purchase_names_the_samples(): void
    {
        // This class does not seed roles, because the seeder under test
        // does not need them. Posting a purchase does.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SampleDataSeeder::class);

        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => Supplier::firstWhere('code', 'ABC-TRADING')->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => Product::firstWhere('sku', 'TSHIRT-BLK')->id,
                'quantity' => 1,
                'unit_cost' => '300.00',
            ]],
        ]);

        // The purchase is the user's own document. Removing the rows it
        // names would either break the foreign keys or quietly take real
        // records away, so the command stops and says which.
        $this->artisan('sample:clear --force')->assertFailed();

        $this->assertSame(6, Product::count());
        $this->assertSame(4, Supplier::count());
    }

    public function test_the_clear_command_refuses_rather_than_delete_a_users_adjustment(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SampleDataSeeder::class);

        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        // The user corrects a sample product's stock. That document is
        // theirs, and removing it quietly would be the worst outcome.
        $this->actingAs($admin)->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => now()->toDateString(),
            'reason' => 'damaged',
            'lines' => [[
                'product_id' => Product::firstWhere('sku', 'TSHIRT-BLK')->id,
                'quantity' => 1,
            ]],
        ]);

        $this->artisan('sample:clear --force')->assertFailed();

        $this->assertSame(6, Product::count());
        $this->assertSame(1, InventoryAdjustment::where('kind', 'decrease')->count());
    }

    public function test_the_clear_command_is_not_blocked_by_its_own_opening_document(): void
    {
        $this->seed(SampleDataSeeder::class);

        // The seeder's own opening adjustment has nobody behind it, so it
        // must not stand in the way of removing what the seeder made.
        $this->artisan('sample:clear --force')->assertSuccessful();

        $this->assertSame(0, Product::count());
    }

    public function test_the_clear_command_says_so_when_there_is_nothing_to_remove(): void
    {
        $this->artisan('sample:clear --force')->assertSuccessful();

        $this->assertSame(0, Product::count());
    }
}
