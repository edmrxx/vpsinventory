<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the figures mean.
 *
 * These are the definitions the dashboard and the reports both read, so a
 * change here changes both screens at once — which is the point.
 */
class InventoryMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $customer;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function metrics(): InventoryMetrics
    {
        return app(InventoryMetrics::class);
    }

    protected function stock(int $quantity, float $cost = 100): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    protected function buy(int $quantity, string $cost = '100.00'): Purchase
    {
        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost]],
        ]);

        return Purchase::latest('id')->firstOrFail();
    }

    protected function sell(int $quantity, string $price = '500.00', string $discount = '0.00'): Sale
    {
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => $discount,
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ]);

        return Sale::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------ Net sales */

    public function test_net_sales_is_what_was_charged(): void
    {
        $this->stock(20);
        $this->sell(4, '500.00', '200.00');

        // 2,000 less 200 off.
        $this->assertSame('1800.00', $this->metrics()->netSales());
    }

    public function test_a_voided_sale_counts_for_nothing(): void
    {
        $this->stock(20);
        $sale = $this->sell(4);

        $this->actingAs($this->admin())->post("/sales/{$sale->id}/void", ['reason' => 'Mistake.']);

        $this->assertSame('0.00', $this->metrics()->netSales());
    }

    public function test_a_return_takes_away_from_net_sales(): void
    {
        $this->stock(20);
        $sale = $this->sell(4);

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ]);

        // 2,000 sold, 500 back.
        $this->assertSame('1500.00', $this->metrics()->netSales());
    }

    public function test_a_return_counts_against_the_month_it_happened_in(): void
    {
        $this->stock(20);
        $sale = $this->sell(4);

        // The money goes back when it goes back, not when the sale was made.
        $this->travel(40)->days();

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 4]],
        ]);

        $saleMonth = Carbon::today()->subDays(40);

        // Nothing is left of that month's sales once it comes back, but the
        // month of the sale itself still shows what it sold.
        $this->assertSame('2000.00', $this->metrics()->netSales(
            $saleMonth->copy()->startOfMonth(),
            $saleMonth->copy()->endOfMonth(),
        ));

        $this->travelBack();
    }

    /* -------------------------------------------------------- Net purchases */

    public function test_net_purchases_excludes_a_voided_delivery(): void
    {
        $first = $this->buy(10, '100.00');
        $this->buy(5, '200.00');

        $this->actingAs($this->admin())->post("/purchases/{$first->id}/void", ['reason' => 'Wrong order.']);

        // Only the second delivery stands.
        $this->assertSame('1000.00', $this->metrics()->netPurchases());
    }

    public function test_opening_stock_is_not_a_purchase(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        // Nothing was bought: the stock was already there.
        $this->assertSame('0.00', $this->metrics()->netPurchases());
    }

    public function test_an_adjustment_is_not_a_purchase_either(): void
    {
        $this->stock(20, 100);

        $this->assertSame('0.00', $this->metrics()->netPurchases());
    }

    public function test_a_purchase_return_takes_away_from_net_purchases(): void
    {
        $purchase = $this->buy(10, '100.00');

        $this->actingAs($this->admin())->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 3]],
        ]);

        // 1,000 bought, 300 back.
        $this->assertSame('700.00', $this->metrics()->netPurchases());
    }

    /* ----------------------------------------------------------- The chart */

    public function test_the_chart_has_one_point_per_month_with_no_gaps(): void
    {
        $this->stock(20);
        $this->sell(4);

        $series = $this->metrics()->monthlySeries(true, true);

        $months = Carbon::today()->month;

        $this->assertCount($months, $series['categories']);
        $this->assertSame('Jan', $series['categories'][0]);
        $this->assertCount($months, $series['series'][0]['data']);
        // This month holds the sale; the rest are zero rather than missing.
        $this->assertSame(2000.0, $series['series'][0]['data'][$months - 1]);
        $this->assertSame(0.0, $series['series'][0]['data'][0]);
    }

    public function test_the_chart_leaves_out_a_series_the_user_may_not_see(): void
    {
        $series = $this->metrics()->monthlySeries(false, true);

        $this->assertCount(1, $series['series']);
        $this->assertSame('Purchases', $series['series'][0]['name']);
    }

    public function test_the_chart_adds_up_to_the_card(): void
    {
        $this->stock(20);
        $this->sell(4, '500.00', '200.00');
        $this->buy(10, '100.00');

        $series = $this->metrics()->monthlySeries(true, true);

        // The same figure, read two ways.
        $this->assertSame(
            (float) $this->metrics()->netSales(),
            round(array_sum($series['series'][0]['data']), 2),
        );
        $this->assertSame(
            (float) $this->metrics()->netPurchases(),
            round(array_sum($series['series'][1]['data']), 2),
        );
    }

    /* ------------------------------------------------------- Top sellers */

    public function test_top_sellers_are_net_of_returns(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CAP-NVY']));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
            ['product_id' => $other->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $admin = $this->admin();

        // Ten of one, five of the other.
        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '500.00'],
                ['product_id' => $other->id, 'quantity' => 5, 'unit_price' => '300.00'],
            ],
        ]);

        $sale = Sale::latest('id')->firstOrFail();

        // Nine of the first come back, so it is no longer the best seller.
        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 9]],
        ]);

        $top = $this->metrics()->topSellers();

        $this->assertSame('CAP-NVY', $top[0]['sku']);
        $this->assertSame(5, $top[0]['quantity']);
        $this->assertSame('TSHIRT-BLK', $top[1]['sku']);
        $this->assertSame(1, $top[1]['quantity']);
    }

    public function test_a_product_returned_in_full_drops_off_the_list(): void
    {
        $this->stock(20);
        $sale = $this->sell(4);

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 4]],
        ]);

        // Nothing net was sold, so it is not a seller at all.
        $this->assertSame([], $this->metrics()->topSellers());
    }

    public function test_top_sellers_ignore_a_voided_sale(): void
    {
        $this->stock(20);
        $sale = $this->sell(4);

        $this->actingAs($this->admin())->post("/sales/{$sale->id}/void", ['reason' => 'Mistake.']);

        $this->assertSame([], $this->metrics()->topSellers());
    }

    public function test_top_sellers_count_a_discounted_sale_at_what_it_collected(): void
    {
        $this->stock(20);
        $this->sell(4, '500.00', '200.00');

        $top = $this->metrics()->topSellers();

        $this->assertSame('1800.00', $top[0]['amount']);
    }

    public function test_top_sellers_stop_at_the_limit(): void
    {
        $products = $this->unaudited(fn () => Product::factory()->count(8)->create());

        app(AdjustmentPoster::class)->post('increase', $products->map(fn ($p) => [
            'product_id' => $p->id, 'quantity' => 50, 'unit_cost' => 100,
        ])->all());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => $products->values()->map(fn ($p, $i) => [
                'product_id' => $p->id,
                'quantity' => $i + 1,
                'unit_price' => '100.00',
            ])->all(),
        ]);

        $this->assertCount(5, $this->metrics()->topSellers());
        // Most sold first.
        $this->assertSame(8, $this->metrics()->topSellers()[0]['quantity']);
    }

    /* --------------------------------------------------------- Low stock */

    public function test_low_stock_leaves_out_a_switched_off_product(): void
    {
        // A reorder level of zero means the product is not watched, so the
        // one from setUp stays out of the way.
        $this->unaudited(fn () => $this->product->update(['reorder_level' => 0]));

        $retired = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'RETIRED', 'reorder_level' => 50])
        );

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $retired->id, 'quantity' => 1, 'unit_cost' => 100],
        ]);

        $this->assertCount(1, $this->metrics()->lowStock());

        $this->unaudited(fn () => $retired->update(['is_active' => false]));
        $this->metrics()->flush();

        $this->assertSame([], $this->metrics()->lowStock());
    }

    public function test_low_stock_puts_the_shortest_first(): void
    {
        $worse = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'WORSE', 'reorder_level' => 100])
        );

        $this->unaudited(fn () => $this->product->update(['reorder_level' => 5]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 4, 'unit_cost' => 100],
            ['product_id' => $worse->id, 'quantity' => 1, 'unit_cost' => 100],
        ]);

        $low = $this->metrics()->lowStock();

        // Ninety-nine short beats one short.
        $this->assertSame('WORSE', $low[0]['sku']);
    }

    /* ------------------------------------------------------------- Cache */

    public function test_a_posting_makes_the_figures_right_again_at_once(): void
    {
        $this->stock(20);

        // Read once, so the figure is cached.
        $this->assertSame('0.00', $this->metrics()->netSales());

        $this->sell(4);

        // Without the flush this would still read 0.00 for five minutes.
        $this->assertSame('2000.00', $this->metrics()->netSales());
    }

    public function test_a_refused_document_leaves_the_cache_alone(): void
    {
        $this->stock(2);
        $this->metrics()->netSales();

        // More than there is, so the whole thing rolls back.
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 99, 'unit_price' => '500.00']],
        ]);

        $this->assertSame('0.00', $this->metrics()->netSales());
    }

    /* ----------------------------------------------------------- Reports */

    public function test_the_report_total_matches_the_card(): void
    {
        $this->stock(20);
        $this->sell(4, '500.00', '200.00');

        [$from, $to] = $this->metrics()->range();

        $this->assertSame(
            $this->metrics()->netSales(),
            $this->metrics()->salesSummary($from, $to)['net'],
        );
    }

    public function test_the_report_holds_the_cost_back_unless_asked(): void
    {
        $this->stock(20, 100);
        $this->sell(4);

        [$from, $to] = $this->metrics()->range();

        $this->assertArrayNotHasKey('gross_profit', $this->metrics()->salesSummary($from, $to));
        $this->assertSame('1600.00', $this->metrics()->salesSummary($from, $to, true)['gross_profit']);
    }
}
