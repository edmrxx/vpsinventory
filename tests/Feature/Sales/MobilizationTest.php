<?php

namespace Tests\Feature\Sales;

use App\Enums\QuotationStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\DashboardOverview;
use App\Services\InventoryMetrics;
use App\Support\SaleTotals;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 28.1 (CR-4): mobilization — what bringing the goods to the site
 * costs, charged on a quotation, a sales order and a sale.
 *
 * total = subtotal − discount + mobilization; the discount never takes more
 * than the goods, so the charge is never discounted. It is carried from a
 * quotation to the order and the sale, paid with the rest, never refunded
 * by a return, counted in net sales and in its own Reports line, and left
 * out of every figure narrowed to a category or a product.
 *
 * Four tins of paint at ₱500 (cost ₱300) and two bags of cement at ₱250
 * (cost ₱200), in two categories; ₱100 off, ₱500 of mobilization. Round
 * figures on purpose: SQLite divides a DECIMAL in whole numbers (CLAUDE.md),
 * and a line-built figure must land on the peso to be compared.
 */
class MobilizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected Product $paint;

    protected Product $cement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->customer = $this->unaudited(fn () => Customer::factory()->create([
            'name' => 'Santos Builders', 'credit_limit' => 20000, 'payment_terms_days' => 30,
        ]));
        $this->paint = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'PNT-1G', 'selling_price' => 500, 'category_id' => Category::factory()->create(['name' => 'Paint'])->id,
        ]));
        $this->cement = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'CEM-40', 'selling_price' => 250, 'category_id' => Category::factory()->create(['name' => 'Cement'])->id,
        ]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->paint->id, 'quantity' => 20, 'unit_cost' => 300],
            ['product_id' => $this->cement->id, 'quantity' => 20, 'unit_cost' => 200],
        ], Carbon::today()->subDay());

        $this->actingAs($this->admin);
    }

    /** @return list<array<string, mixed>> */
    protected function lines(): array
    {
        return [
            ['product_id' => $this->paint->id, 'quantity' => 4, 'unit_price' => '500.00'],
            ['product_id' => $this->cement->id, 'quantity' => 2, 'unit_price' => '250.00'],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    protected function quote(array $overrides = []): Quotation
    {
        $this->post('/quotations', array_merge([
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->addDays(15)->toDateString(),
            'mobilization' => '500.00',
            'discount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $this->lines(),
        ], $overrides))->assertSessionHasNoErrors();

        return Quotation::latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $overrides */
    protected function sell(array $overrides = []): Sale
    {
        $this->post('/sales', array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'mobilization' => '500.00',
            'discount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $this->lines(),
            'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '2900.00']],
        ], $overrides))->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    /* --------------------------------------------------------- The sum */

    public function test_the_charge_is_added_after_the_discount_and_never_discounted(): void
    {
        $totals = SaleTotals::of($this->lines(), '100.00', '500.00');

        // 2,000 + 500 of goods = 2,500; less 100 = 2,400 of goods; plus 500.
        $this->assertSame(250000, $totals['subtotal']);
        $this->assertSame(10000, $totals['discount']);
        $this->assertSame(240000, $totals['goods']);
        $this->assertSame(50000, $totals['mobilization']);
        $this->assertSame(290000, $totals['total']);

        // The discount can take every peso of the goods, never the charge.
        $this->assertSame(50000, SaleTotals::of($this->lines(), '99999', '500.00')['total']);
        // No charge sent is no charge: the sum every caller had before.
        $this->assertSame(240000, SaleTotals::of($this->lines(), '100.00')['total']);
    }

    public function test_a_quotation_carries_the_charge(): void
    {
        $quotation = $this->quote();

        $this->assertSame('2500.00', $quotation->subtotal);
        $this->assertSame('100.00', $quotation->discount);
        $this->assertSame('500.00', $quotation->mobilization);
        $this->assertSame('2900.00', $quotation->total_amount);

        $this->get("/quotations/{$quotation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('quotation.mobilization', '500.00'));
    }

    public function test_a_negative_charge_is_refused(): void
    {
        $this->post('/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->addDays(15)->toDateString(),
            'mobilization' => '-50',
            'lines' => $this->lines(),
        ])->assertSessionHasErrors('mobilization');

        $this->assertSame(0, Quotation::count());
    }

    /* ---------------------------------------------- Carried, and paid */

    public function test_the_sale_form_from_a_quotation_starts_on_its_charge_and_the_sale_bills_it(): void
    {
        $quotation = $this->quote();

        $this->get("/sales/create?quotation={$quotation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fromQuotation.mobilization', '500.00'));

        // Paying only the goods is short by the charge.
        $this->post('/sales', [
            'quotation_id' => $quotation->id,
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'mobilization' => '500.00',
            'discount' => '100.00',
            'lines' => $this->lines(),
            'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '2400.00']],
        ])->assertSessionHasErrors();

        $sale = $this->sell(['quotation_id' => $quotation->id]);

        $this->assertSame('500.00', $sale->mobilization);
        $this->assertSame('2900.00', $sale->total_amount);
        $this->assertSame('2400.00', $sale->goodsTotal());
        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);
        // All of the charge is profit: it has no cost. 2,900 less 1,600.
        $this->assertSame('1300.00', $sale->grossProfit());
    }

    public function test_an_order_from_a_quotation_and_a_sale_from_the_order_carry_it_too(): void
    {
        $quotation = $this->quote();

        $this->get("/sales-orders/create?quotation={$quotation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fromQuotation.mobilization', '500.00'));

        $this->post('/sales-orders', [
            'quotation_id' => $quotation->id,
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            // Approved as it is taken, so a sale can be made from it.
            'approve' => true,
            'mobilization' => '500.00',
            'discount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $this->lines(),
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->firstOrFail();
        $this->assertSame('500.00', $order->mobilization);
        $this->assertSame('2900.00', $order->total_amount);

        $this->get("/sales/create?order={$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fromOrder.mobilization', '500.00'));
    }

    public function test_quoting_again_keeps_the_charge(): void
    {
        $quotation = $this->quote();

        $this->get("/quotations/create?from={$quotation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fromQuotation.mobilization', '500.00'));
    }

    public function test_a_resent_form_with_another_charge_is_not_taken_for_the_first(): void
    {
        $key = (string) Str::uuid();
        $this->sell(['idempotency_key' => $key]);

        $this->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'mobilization' => '300.00',
            'discount' => '100.00',
            'idempotency_key' => $key,
            'lines' => $this->lines(),
            'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '2700.00']],
        ])->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Sale::count());
    }

    /* ------------------------------------------------------- Returns */

    public function test_a_return_gives_back_goods_only_and_the_charge_stays_owed(): void
    {
        // All on account: 2,900 owed.
        $sale = $this->sell(['on_account' => '2900.00', 'payments' => []]);
        $this->assertSame('2900.00', $sale->balance_due);

        $this->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Wrong colour',
            'lines' => $sale->lines->map(fn ($line) => ['sale_line_id' => $line->id, 'quantity' => $line->quantity])->all(),
        ])->assertSessionHasNoErrors();

        $return = $sale->returns()->firstOrFail();

        // Every line back: exactly the goods after the discount, not a peso of the charge.
        $this->assertSame('2400.00', $return->total_amount);
        $this->assertSame('500.00', $sale->fresh()->balance_due);

        $this->assertSame(0, Artisan::call('inventory:verify'));
    }

    /* ------------------------------------------- Dashboard and Reports */

    public function test_the_dashboard_and_reports_agree_and_a_narrowed_figure_leaves_the_charge_out(): void
    {
        $this->sell();
        $today = Carbon::today();
        $metrics = app(InventoryMetrics::class);

        $summary = $metrics->salesSummary($today, $today, true);
        $this->assertSame('2500.00', $summary['subtotal']);
        $this->assertSame('100.00', $summary['discount']);
        $this->assertSame('500.00', $summary['mobilization']);
        $this->assertSame('2900.00', $summary['net']);
        // Paint 1,200 + cement 400 of cost.
        $this->assertSame('1300.00', $summary['gross_profit']);

        // Unfiltered, the dashboard says what Reports says.
        $figures = $metrics->periodFigures($today, $today);
        $this->assertEqualsWithDelta(2900, $figures['net_sales'], 0.01);
        $this->assertEqualsWithDelta(1300, $figures['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(500, $figures['mobilization'], 0.01);

        // Narrowed to paint: its share of the goods after the discount
        // (2,000 of 2,500, of 2,400) and none of the charge.
        $paint = $metrics->periodFigures($today, $today, ['category_id' => $this->paint->category_id]);
        $this->assertEqualsWithDelta(1920, $paint['net_sales'], 0.01);
        $this->assertEquals(0, $paint['mobilization']);

        // Top sellers and the product breakdown never count the charge.
        $this->assertEqualsWithDelta(2400, (float) $summary['by_product']['total']['amount'], 0.01);
    }

    public function test_a_narrowed_figure_of_a_sale_on_account_owes_only_its_goods(): void
    {
        $this->sell(['on_account' => '2900.00', 'payments' => []]);
        $today = Carbon::today();
        $metrics = app(InventoryMetrics::class);

        // Unnarrowed, all of it went on account; nothing came in.
        $all = $metrics->periodFigures($today, $today);
        $this->assertEqualsWithDelta(2900, $all['on_account_net'], 0.02);
        $this->assertEqualsWithDelta(0, $all['net_sales'] - $all['on_account_net'], 0.02);

        // Narrowed to cement: what it sold is what it owes — never more.
        $cement = $metrics->periodFigures($today, $today, ['category_id' => $this->cement->category_id]);
        $this->assertEqualsWithDelta($cement['net_sales'], $cement['on_account_net'], 0.02);
        $this->assertGreaterThanOrEqual(-0.02, $cement['net_sales'] - $cement['on_account_net']);

        $cards = app(DashboardOverview::class)->cards(
            $today,
            $today,
            ['period' => 'today', 'category_id' => $this->cement->category_id],
            ['sales' => true, 'purchases' => true, 'stock' => true, 'cost' => true],
        );
        $cash = collect($cards['cards'])->firstWhere('key', 'cash_position');
        $this->assertGreaterThanOrEqual(-0.02, (float) $cash['value']);
    }

    public function test_the_reports_screen_and_spreadsheet_show_the_charge(): void
    {
        $this->sell();

        $this->get('/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sales.mobilization', '500.00'));
    }
}
