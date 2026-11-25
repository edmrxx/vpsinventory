<?php

namespace Tests\Feature\Seeders;

use App\Http\Requests\Product\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\InventoryLedger;
use Database\Seeders\HardwareProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HardwareProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_every_product_with_no_stock(): void
    {
        $this->seed(HardwareProductSeeder::class);

        $this->assertSame(count(HardwareProductSeeder::PRODUCTS), Product::active()->count());

        // Stock only arrives through a posted document.
        $this->assertSame(0, (int) Product::sum('on_hand'));
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
        $this->assertDatabaseCount('audit_trails', 0);

        $cement = Product::with('category', 'unit')->firstWhere('sku', 'CEM-PORT-40');
        $this->assertSame('Cement & Aggregates', $cement->category->name);
        $this->assertSame('bag', $cement->unit->code);
        $this->assertSame('265.00', $cement->selling_price);
    }

    public function test_every_product_would_pass_the_form_on_the_products_screen(): void
    {
        $this->seed(HardwareProductSeeder::class);

        $rules = (new StoreProductRequest)->rules();
        unset($rules['sku']); // Unique, and every one is already in.

        foreach (Product::all() as $product) {
            $this->assertSame(strtoupper($product->sku), $product->sku, "[{$product->sku}] must be upper case.");
            $this->assertMatchesRegularExpression('/^[A-Z0-9._-]+$/', $product->sku);
            $this->assertLessThanOrEqual(60, strlen($product->sku));

            $validator = Validator::make($product->only(
                'name', 'category_id', 'unit_id', 'cost', 'selling_price', 'reorder_level', 'is_active'
            ), $rules);

            $this->assertFalse($validator->fails(), "[{$product->sku}]: ".implode(' ', $validator->errors()->all()));
        }
    }

    public function test_nothing_is_sold_below_what_it_cost(): void
    {
        foreach (HardwareProductSeeder::PRODUCTS as $sku => [, , , $cost, $price]) {
            $this->assertGreaterThan($cost, $price, "[{$sku}] sells at or below its cost.");
        }
    }

    public function test_every_category_but_the_catch_all_holds_something(): void
    {
        $this->seed(HardwareProductSeeder::class);

        $empty = Category::doesntHave('products')->pluck('name')->all();

        $this->assertSame(['Miscellaneous'], $empty);
        $this->assertGreaterThan(0, Unit::has('products')->count());
    }

    public function test_running_it_twice_keeps_what_was_changed_on_the_screen(): void
    {
        $this->seed(HardwareProductSeeder::class);

        Product::firstWhere('sku', 'LGT-LED-9W')->update(['selling_price' => 79]);

        $this->seed(HardwareProductSeeder::class);

        $this->assertSame(count(HardwareProductSeeder::PRODUCTS), Product::count());
        $this->assertSame('79.00', Product::firstWhere('sku', 'LGT-LED-9W')->selling_price);
    }
}
