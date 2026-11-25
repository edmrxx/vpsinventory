<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use App\Services\InventoryValuation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK', 'reorder_level' => 20])
        );
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function valuation(): InventoryValuation
    {
        return app(InventoryValuation::class);
    }

    protected function stock(int $quantity, float $cost, ?Product $product = null): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => ($product ?? $this->product)->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/stock')
            ->assertForbidden();
    }

    public function test_staff_may_read_it(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->get('/stock')->assertOk();
    }

    /* -------------------------------------------------------------- The value */

    public function test_a_products_value_is_what_is_left_of_each_batch(): void
    {
        $this->stock(10, 300);
        $this->stock(5, 350);

        // Four sold: all four out of the first, cheaper batch.
        app(AdjustmentPoster::class)->post('decrease', [
            ['product_id' => $this->product->id, 'quantity' => 4],
        ]);

        // Six left at 300 and five at 350.
        $this->assertSame('3550.00', $this->valuation()->forProduct($this->product));
    }

    public function test_the_total_is_the_sum_of_every_row(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create());

        $this->stock(10, 300);
        $this->stock(4, 100, $other);

        $this->assertSame('3400.00', $this->valuation()->total());

        $this->actingAs($this->admin())
            ->get('/stock')
            ->assertInertia(fn ($page) => $page
                ->component('Stock/Index')
                ->where('summary.value', '3400.00')
                ->where('summary.units', 14)
                ->where('summary.products', 2));
    }

    public function test_the_value_follows_a_sale(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->stock(10, 300);
        $this->assertSame('3000.00', $this->valuation()->total());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);

        $this->assertSame('1800.00', $this->valuation()->total());
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    public function test_the_value_comes_back_with_a_return(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $admin = $this->admin();

        $this->stock(10, 300);
        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);

        $sale = Sale::firstOrFail();

        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 4]],
        ]);

        // Back where it started, in the batch it left.
        $this->assertSame('3000.00', $this->valuation()->total());
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    public function test_units_and_value_never_disagree_about_switched_off_stock(): void
    {
        $retired = $this->unaudited(fn () => Product::factory()->create(['sku' => 'RETIRED']));

        $this->stock(10, 300);
        $this->stock(4, 100, $retired);

        // Switched off with stock still on the shelf. Counting units over
        // the active products alone would leave these four out while their
        // 400 stayed in the value — two cards disagreeing side by side.
        $this->unaudited(fn () => $retired->update(['is_active' => false]));

        $summary = $this->valuation()->summary();

        $this->assertSame(14, $summary['units']);
        $this->assertSame('3400.00', $summary['value']);
        $this->assertSame($this->valuation()->total(), $summary['value']);
        // The product count is about the catalogue, and says so on screen.
        $this->assertSame(1, $summary['products']);
    }

    public function test_a_product_with_no_stock_is_worth_nothing_rather_than_breaking(): void
    {
        $this->assertSame('0.00', $this->valuation()->forProduct($this->product));
        $this->assertSame('0.00', $this->valuation()->total());
    }

    /* ---------------------------------------------------------------- Screen */

    public function test_each_row_carries_its_own_value(): void
    {
        $this->stock(10, 300);

        $this->actingAs($this->admin())
            ->get('/stock')
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.sku', 'TSHIRT-BLK')
                ->where('stock.data.0.on_hand', 10)
                ->where('stock.data.0.stock_value', '3000.00'));
    }

    public function test_the_low_stock_filter_narrows_the_list(): void
    {
        $plenty = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'PLENTY', 'reorder_level' => 2])
        );

        $this->stock(5, 300);
        $this->stock(50, 300, $plenty);

        $this->actingAs($this->admin())
            ->get('/stock?stock=low')
            ->assertInertia(fn ($page) => $page
                ->has('stock.data', 1)
                ->where('stock.data.0.sku', 'TSHIRT-BLK')
                ->where('stock.data.0.is_low_stock', true));
    }

    public function test_the_out_of_stock_filter_narrows_the_list(): void
    {
        $empty = $this->unaudited(fn () => Product::factory()->create(['sku' => 'EMPTY']));

        $this->stock(5, 300);

        $this->actingAs($this->admin())
            ->get('/stock?stock=out')
            ->assertInertia(fn ($page) => $page
                ->has('stock.data', 1)
                ->where('stock.data.0.sku', 'EMPTY'));
    }

    public function test_an_unknown_filter_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/stock?stock=nonsense')
            ->assertSessionHasErrors('stock');
    }

    public function test_the_list_can_be_sorted_by_value(): void
    {
        $dearer = $this->unaudited(fn () => Product::factory()->create(['sku' => 'ZZZ-DEAR']));

        $this->stock(1, 100);
        $this->stock(1, 900, $dearer);

        $this->actingAs($this->admin())
            ->get('/stock?sort=stock_value&direction=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stock.data.0.sku', 'ZZZ-DEAR'));
    }

    public function test_the_summary_counts_the_whole_warehouse_not_one_page(): void
    {
        $this->unaudited(function () {
            Product::factory()->count(30)->create(['reorder_level' => 5]);
        });

        $this->stock(10, 300);

        $this->actingAs($this->admin())
            ->get('/stock?per_page=10')
            ->assertInertia(fn ($page) => $page
                ->has('stock.data', 10)
                // Thirty-one products, not the ten on screen.
                ->where('summary.products', 31));
    }
}
