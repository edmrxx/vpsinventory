<?php

namespace Tests\Feature\Seeders;

use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\Product;
use App\Services\InventoryLedger;
use Database\Seeders\HardwareOpeningStockSeeder;
use Database\Seeders\HardwareProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareOpeningStockSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HardwareProductSeeder::class);
    }

    public function test_every_product_gets_stock_from_one_opening_adjustment(): void
    {
        $this->seed(HardwareOpeningStockSeeder::class);

        $this->assertSame(1, InventoryAdjustment::count());
        $adjustment = InventoryAdjustment::with('lines')->first();
        $this->assertSame(InventoryAdjustment::KIND_OPENING, $adjustment->kind);
        $this->assertSame(HardwareOpeningStockSeeder::NOTE, $adjustment->notes);
        $this->assertCount(count(HardwareProductSeeder::PRODUCTS), $adjustment->lines);

        $this->assertSame(0, Product::where('on_hand', '<=', 0)->count());
        $this->assertSame(count(HardwareProductSeeder::PRODUCTS), InventoryLayer::count());

        // The batch costs what the product costs, so a sale earns its margin.
        $cement = Product::firstWhere('sku', 'CEM-PORT-40');
        $this->assertSame(200, $cement->on_hand);
        $this->assertEquals($cement->cost, $cement->layers()->first()->unit_cost);

        // On hand equals the batches behind it.
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
        $this->assertDatabaseHas('audit_trails', ['module' => 'Inventory Adjustments']);
    }

    public function test_only_the_chosen_few_show_as_low_stock(): void
    {
        $this->seed(HardwareOpeningStockSeeder::class);

        $low = Product::whereColumn('on_hand', '<=', 'reorder_level')->orderBy('sku')->pluck('sku')->all();

        $expected = HardwareOpeningStockSeeder::LOW;
        sort($expected);

        $this->assertSame($expected, $low);
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->seed(HardwareOpeningStockSeeder::class);
        $before = Product::sum('on_hand');

        $this->seed(HardwareOpeningStockSeeder::class);

        $this->assertSame(1, InventoryAdjustment::count());
        $this->assertEquals($before, Product::sum('on_hand'));
    }
}
