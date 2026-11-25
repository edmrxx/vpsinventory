<?php

namespace Tests\Feature\Inventory;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportScreenTest extends TestCase
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

    protected function trade(): void
    {
        $admin = $this->admin();

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '120.00']],
        ]);

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => '100.00',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/reports')
            ->assertForbidden();
    }

    public function test_reports_have_no_write_routes(): void
    {
        $writes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'reports'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

        // Nothing is stored: a report is a question, answered fresh.
        $this->assertCount(0, $writes);
    }

    public function test_staff_may_read_it(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->get('/reports')->assertOk();
    }

    /* ------------------------------------------------------ The breakdowns */

    /**
     * A breakdown states the whole of the period, not only the rows sent.
     *
     * The screen shows ten rows and gathers the rest into one "Other" line.
     * It can only work that line out correctly if the total covers every
     * group — including any beyond the rows the server sends. Without this
     * the table would quietly stop agreeing with the net figure above it.
     */
    public function test_a_breakdown_states_the_whole_period_not_only_its_rows(): void
    {
        $this->trade();

        // A second product, so there is more than one group to total.
        $other = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CAP-NVY']));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $other->id, 'quantity' => 20, 'unit_cost' => 50],
        ]);

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $other->id, 'quantity' => 3, 'unit_price' => '100.00']],
        ])->assertRedirect();

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertOk()
            ->assertInertia(function ($page) {
                $breakdown = $page->toArray()['props']['sales']['by_product'];

                $this->assertSame(2, $breakdown['total']['count'], 'Two products were sold.');

                $sent = round(array_sum(array_map(
                    fn ($row) => (float) $row['amount'],
                    $breakdown['rows'],
                )), 2);

                // The total is never less than what was sent. It is more
                // whenever rows were left out, and the screen takes the
                // difference as its "Other" line.
                $this->assertGreaterThanOrEqual(
                    $sent,
                    round((float) $breakdown['total']['amount'], 2),
                    'The total must cover at least the rows it sent.'
                );

                $this->assertGreaterThanOrEqual(
                    array_sum(array_column($breakdown['rows'], 'quantity')),
                    $breakdown['total']['quantity'],
                );

                // Here nothing was left out, so they agree exactly.
                $this->assertSame(round((float) $breakdown['total']['amount'], 2), $sent);
            });
    }

    /**
     * And that total is the same figure the summary above it states.
     *
     * This is the check the "Other" line exists to keep true.
     */
    public function test_the_product_breakdown_totals_to_net_sales(): void
    {
        $this->trade();

        // With something returned, so net and gross differ. Without a
        // return this check would pass on a breakdown that ignored them.
        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertOk()
            ->assertInertia(function ($page) {
                $sales = $page->toArray()['props']['sales'];

                $this->assertSame(
                    round((float) $sales['net'], 2),
                    round((float) $sales['by_product']['total']['amount'], 2),
                    'Sales by product does not add up to net sales.'
                );
            });
    }

    /* ----------------------------------------------------------- The chart */

    /**
     * The chart must add up to the summary printed beside it.
     *
     * Both come from `InventoryMetrics`, which is the whole reason that
     * service exists. If these two ever disagree, somebody has worked a
     * figure out twice.
     */
    public function test_the_chart_adds_up_to_the_summary_beside_it(): void
    {
        $this->trade();

        // Something comes back, so net and gross differ. Without this the
        // check would pass even if the chart ignored returns entirely.
        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->actingAs($this->admin())
            ->get('/reports?from='.now()->startOfYear()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertInertia(function ($page) {
                $trend = $page->toArray()['props']['trend'];
                $sales = $page->toArray()['props']['sales'];
                $purchases = $page->toArray()['props']['purchases'];

                $plotted = fn (string $name) => array_sum(
                    collect($trend['series'])->firstWhere('name', $name)['data'] ?? []
                );

                $this->assertSame(
                    round((float) $sales['net'], 2),
                    round($plotted('Sales'), 2),
                    'The Sales line does not add up to net sales.'
                );

                $this->assertSame(
                    round((float) $purchases['net'], 2),
                    round($plotted('Purchases'), 2),
                    'The Purchases line does not add up to net purchases.'
                );
            });
    }

    /**
     * A period inside one month is plotted day by day.
     *
     * Drawn monthly it would be a single point, which is not a chart.
     */
    public function test_a_single_month_is_plotted_day_by_day(): void
    {
        $this->trade();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $this->actingAs($this->admin())
            ->get("/reports?from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $categories = $page->toArray()['props']['trend']['categories'];

                $this->assertSame(
                    now()->daysInMonth,
                    count($categories),
                    'A one-month report should be plotted a day at a time.'
                );
                // The month is named too: an explicit daily grain can span
                // more than one, and a bare "1" would not say which.
                $this->assertSame(now()->startOfMonth()->format('j M'), $categories[0]);
            });
    }

    /** A longer period is plotted month by month, as the dashboard is. */
    public function test_a_longer_period_is_plotted_month_by_month(): void
    {
        $this->trade();

        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->admin())
            ->get("/reports?from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $categories = $page->toArray()['props']['trend']['categories'];

                $this->assertSame(now()->month, count($categories));
                $this->assertSame('Jan', $categories[0]);
            });
    }

    /** One line, not an empty panel, for somebody who may read only one. */
    public function test_a_user_who_may_read_only_sales_gets_one_line(): void
    {
        $this->trade();

        $limited = $this->unaudited(
            fn () => User::factory()->withPermissions('reports.view', 'sales.view')->create()
        );

        $this->actingAs($limited)
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('trend.series', 1)
                ->where('trend.series.0.name', 'Sales'));
    }

    /* --------------------------------------------------------------- Figures */

    public function test_the_summaries_add_up(): void
    {
        $this->trade();

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Index')
                // 4 at 500 is 2,000, less 100 off.
                ->where('sales.net', '1900.00')
                ->where('sales.discount', '100.00')
                ->where('sales.documents', 1)
                // The purchase, but not the opening adjustment.
                ->where('purchases.net', '1200.00')
                ->where('purchases.documents', 1));
    }

    public function test_the_figures_match_the_dashboard(): void
    {
        $this->trade();

        $metrics = app(InventoryMetrics::class);

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                // One service behind both screens, so they cannot disagree.
                ->where('sales.net', $metrics->netSales())
                ->where('purchases.net', $metrics->netPurchases()));
    }

    public function test_the_period_can_be_narrowed(): void
    {
        $this->trade();

        // A window that ended before any of it happened.
        $this->actingAs($this->admin())
            ->get('/reports?from='.now()->subYear()->toDateString().'&to='.now()->subMonths(6)->toDateString())
            ->assertInertia(fn ($page) => $page
                ->where('sales.net', '0.00')
                ->where('purchases.net', '0.00'));
    }

    public function test_a_backwards_period_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?from=2026-10-10&to=2026-10-01')
            ->assertSessionHasErrors('to');
    }

    public function test_the_breakdowns_name_the_product_and_the_customer(): void
    {
        $this->trade();

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('sales.by_product.rows.0.sku', 'TSHIRT-BLK')
                ->where('sales.by_product.rows.0.quantity', 4)
                // Prorated: the discount comes off the line's share too.
                ->where('sales.by_product.rows.0.amount', '1900.00')
                ->where('sales.by_customer.rows.0.name', 'Walk-in Customer')
                ->where('purchases.by_supplier.rows.0.documents', 1));
    }

    /* ----------------------------------------------------------- Permissions */

    public function test_staff_never_receive_the_profit(): void
    {
        $this->trade();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', false)
                ->missing('sales.gross_profit')
                ->missing('sales.cost'));
    }

    public function test_an_admin_sees_the_profit(): void
    {
        $this->trade();

        $this->actingAs($this->admin())
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', true)
                // Four units out of the 100 batch: 400 of cost.
                ->where('sales.cost', '400.00')
                ->where('sales.gross_profit', '1500.00'));
    }

    public function test_a_user_who_may_not_read_sales_gets_no_sales_block(): void
    {
        $this->trade();

        $limited = $this->unaudited(
            fn () => User::factory()->withPermissions('reports.view', 'purchases.view')->create()
        );

        $this->actingAs($limited)
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('sales', null)
                ->where('inventory', null)
                ->has('purchases'));
    }

    /* ------------------------------------------------------------ Dashboard */

    public function test_the_dashboard_leaves_out_what_a_user_may_not_see(): void
    {
        $this->trade();

        $limited = $this->unaudited(
            fn () => User::factory()->withPermissions('purchases.view')->create()
        );

        $this->actingAs($limited)
            ->get('/dashboard', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'overview,topSellers,lowStock',
            ])
            ->assertOk()
            // Only the purchases card. A user who may not read sales is
            // never sent the sales figure, the profit or the returns rate —
            // they are left out of the payload, not hidden on screen.
            // The purchase-requests card has its own permission since
            // phase 21, so none of the main cards is sent here, and the
            // purchases figure sits in the smaller row.
            ->assertJsonCount(0, 'props.overview.cards')
            ->assertJsonCount(1, 'props.overview.secondary')
            ->assertJsonPath('props.overview.secondary.0.key', 'net_purchases')
            ->assertJsonPath('props.topSellers', null)
            ->assertJsonPath('props.lowStock', null);
    }
}
