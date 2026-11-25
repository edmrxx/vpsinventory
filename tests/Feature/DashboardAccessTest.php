<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Who sees what on the owner's dashboard, and whose documents it adds up.
 *
 * Every check here reads the payload the server sends, not the screen. A
 * figure hidden by the browser has still been handed over.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $customer;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'BOLT-10', 'reorder_level' => 0]));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create(['name' => 'Owner']));
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create(['name' => 'Cashier']));

        // 100 in stock at 100 each.
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 100],
        ], Carbon::today()->subDays(3));
    }

    protected function sellAs(User $user, int $quantity, string $price = '250.00'): void
    {
        $this->actingAs($user)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => '0',
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ])->assertRedirect();
    }

    /** The deferred blocks, the way the browser asks for them. */
    protected function dashboard(User $user, array $query = [], string $blocks = 'overview,exceptions,trend,stockByCategory'): TestResponse
    {
        return $this->actingAs($user)->get('/dashboard?'.http_build_query($query), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => $blocks,
        ])->assertOk();
    }

    protected function card(TestResponse $response, string $key): ?array
    {
        $overview = $response->json('props.overview');

        return collect(array_merge($overview['cards'], $overview['secondary']))->firstWhere('key', $key);
    }

    /* ---------------------------------------------------- Cost and profit */

    public function test_a_role_without_cost_access_is_never_sent_cost_or_profit(): void
    {
        // Sold 4 at 250 = 1,000, costing 400: a profit of 600.
        $this->sellAs($this->staff, 4);

        $response = $this->dashboard($this->staff);

        foreach (['gross_profit', 'inventory_value'] as $key) {
            $card = $this->card($response, $key);
            $this->assertSame('restricted', $card['state'], "[{$key}] should read Restricted.");
            $this->assertNull($card['value']);
            $this->assertNull($card['previous']);
            $this->assertNull($card['sparkline']);
        }

        // Not the profit, not the stock value (96 left x 100), and not the
        // profit line of the chart.
        $payload = $response->getContent();
        $this->assertStringNotContainsString('600.00', $payload);
        $this->assertStringNotContainsString('9600', $payload);
        $this->assertArrayNotHasKey('profit', $response->json('props.trend'));
        $this->assertNull($response->json('props.stockByCategory'));

        // The sales figure itself is theirs to see.
        $this->assertSame('1000.00', $this->card($response, 'net_sales')['value']);
    }

    /**
     * Every deferred block at once, as Staff, with stock standing still.
     *
     * The dead-stock warning names what the idle stock cost, so it is a
     * cost figure too. A product that never sells makes sure it appears.
     */
    public function test_no_block_of_the_dashboard_leaks_cost_to_a_role_without_it(): void
    {
        $idle = $this->unaudited(fn () => Product::factory()->create(['sku' => 'IDLE-1', 'reorder_level' => 0]));
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $idle->id, 'quantity' => 7, 'unit_cost' => 1234.5],
        ]);

        // 4 at 250 = 1,000 costing 400: 600 profit, a 60% margin.
        $this->sellAs($this->staff, 4);

        $blocks = 'overview,exceptions,attention,insights,trend,returnsTrend,'
            .'salesByCategory,stockByCategory,movements,ranking,slowest,recent';

        $staff = $this->dashboard($this->staff, [], $blocks);
        $payload = $staff->getContent();

        // The dead-stock warning is there, without what it is worth.
        $dead = collect($staff->json('props.attention'))->firstWhere('key', 'dead_stock');
        $this->assertNotNull($dead, 'The idle product should be raised as dead stock.');
        $this->assertArrayNotHasKey('tied_up', $dead['rows'][0]);
        $this->assertStringNotContainsString('₱', $dead['detail']);

        // What the idle stock is tied up in: on hand times the product's cost.
        $tied = number_format(7 * (float) $idle->cost, 2, '.', '');

        foreach (['600.00', '60.0%', '9600', $tied, number_format((float) $tied, 2)] as $figure) {
            $this->assertStringNotContainsString($figure, $payload, "Staff was sent [{$figure}].");
        }

        foreach ($staff->json('props.ranking') as $row) {
            $this->assertArrayNotHasKey('profit', $row);
            $this->assertArrayNotHasKey('margin', $row);
        }

        // The owner gets the same warning with its value.
        $owner = $this->dashboard($this->admin, [], $blocks);
        $dead = collect($owner->json('props.attention'))->firstWhere('key', 'dead_stock');
        $this->assertSame($tied, $dead['rows'][0]['tied_up']);
    }

    public function test_a_role_with_cost_access_gets_the_figures(): void
    {
        $this->sellAs($this->staff, 4);

        $response = $this->dashboard($this->admin);

        $this->assertSame('600.00', $this->card($response, 'gross_profit')['value']);
        $this->assertSame('9600.00', $this->card($response, 'inventory_value')['value']);
        $this->assertArrayHasKey('profit', $response->json('props.trend'));
        $this->assertNotNull($response->json('props.stockByCategory'));
    }

    public function test_losses_are_shown_in_units_without_cost_access_and_at_cost_with_it(): void
    {
        $adjustment = $this->actingAs($this->admin)->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => now()->toDateString(),
            'reason' => 'write_off',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ]);
        $adjustment->assertRedirect();
        $id = InventoryAdjustment::where('kind', 'decrease')->value('id');

        $owner = $this->dashboard($this->admin);
        $losses = $this->card($owner, 'losses');
        $this->assertSame('300.00', $losses['value']);
        $this->assertSame('money', $losses['format']);
        $this->assertStringContainsString('kind=decrease', $losses['href']);

        $row = collect($owner->json('props.exceptions'))->firstWhere('key', 'losses')['rows'][0];
        $this->assertSame("/adjustments/{$id}", $row['href']);
        $this->assertStringContainsString('Written off', $row['title']);
        $this->assertSame('₱300.00', $row['figure']);

        // A Staff role may read adjustments, but not what they cost. And a
        // Staff user sees only their own documents, so the owner's write-off
        // is not theirs to count.
        $staff = $this->dashboard($this->staff);
        $this->assertSame('count', $this->card($staff, 'losses')['format']);
        $this->assertSame(0, $this->card($staff, 'losses')['value']);
    }

    /* ------------------------------------------------------- Staff filter */

    public function test_a_user_without_the_permission_sees_only_their_own_documents(): void
    {
        $this->sellAs($this->admin, 4);   // 1,000
        $this->sellAs($this->staff, 2);   //   500

        // Asking for the owner's figures by address changes nothing.
        $response = $this->dashboard($this->staff, ['staff_id' => $this->admin->id]);

        $this->assertSame('500.00', $this->card($response, 'net_sales')['value']);

        // And the filter offers nobody else.
        $page = $this->actingAs($this->staff)->get('/dashboard');
        $page->assertInertia(fn ($inertia) => $inertia
            ->where('staffLocked', true)
            ->where('filters.staff_id', $this->staff->id)
            ->has('options.staff', 1)
            ->where('options.staff.0.value', $this->staff->id)
        );
    }

    public function test_an_owner_can_narrow_to_one_staff_member_or_see_everybody(): void
    {
        $this->sellAs($this->admin, 4);
        $this->sellAs($this->staff, 2);

        $this->assertSame('1500.00', $this->card($this->dashboard($this->admin), 'net_sales')['value']);

        $narrowed = $this->dashboard($this->admin, ['staff_id' => $this->staff->id]);
        $this->assertSame('500.00', $this->card($narrowed, 'net_sales')['value']);
        $this->assertSame(1, $this->card($narrowed, 'transactions')['value']);

        // The card opens the sales list narrowed the same way.
        $this->assertStringContainsString('posted_by='.$this->staff->id, $this->card($narrowed, 'net_sales')['href']);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($inertia) => $inertia
            ->where('staffLocked', false)
            ->has('options.staff', 2)
        );
    }

    public function test_one_staff_members_cached_figures_are_never_served_to_another(): void
    {
        $this->sellAs($this->admin, 4);
        $this->sellAs($this->staff, 2);

        $this->assertSame('500.00', $this->card($this->dashboard($this->staff), 'net_sales')['value']);
        $this->assertSame('1500.00', $this->card($this->dashboard($this->admin), 'net_sales')['value']);
    }

    /* -------------------------------------------------------- Date filter */

    public function test_the_dashboard_opens_on_today_and_yesterday_means_the_manila_day_before(): void
    {
        $this->travelTo(Carbon::yesterday()->setTime(10, 0));
        $this->sellAs($this->admin, 4);   // yesterday, 1,000
        $this->travelBack();

        $this->sellAs($this->admin, 2);   // today, 500

        $today = $this->dashboard($this->admin);
        $this->assertSame('500.00', $this->card($today, 'net_sales')['value']);
        $this->assertSame("Today's sales", $this->card($today, 'net_sales')['label']);

        $yesterday = $this->dashboard($this->admin, ['period' => 'yesterday']);
        $this->assertSame('1000.00', $this->card($yesterday, 'net_sales')['value']);
        $this->assertSame('Sales', $this->card($yesterday, 'net_sales')['label']);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($inertia) => $inertia
            ->where('filters.period', 'today')
            ->where('filters.from', Carbon::today()->toDateString())
            ->where('filters.to', Carbon::today()->toDateString())
        );

        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    /* ---------------------------------------------- The lists it links to */

    public function test_the_lists_the_cards_open_accept_the_same_filters(): void
    {
        $this->sellAs($this->admin, 4);
        $this->sellAs($this->staff, 2);

        $this->actingAs($this->admin)->get('/sales?posted_by='.$this->staff->id)
            ->assertInertia(fn ($inertia) => $inertia->has('sales.data', 1));

        $this->actingAs($this->admin)->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => now()->toDateString(),
            'reason' => 'damaged',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->actingAs($this->admin)->get('/adjustments?kind=decrease&reason=damaged')
            ->assertInertia(fn ($inertia) => $inertia->has('adjustments.data', 1));
        $this->actingAs($this->admin)->get('/adjustments?reason=write_off')
            ->assertInertia(fn ($inertia) => $inertia->has('adjustments.data', 0));
    }

    /* ---------------------------------------------------------------- API */

    public function test_the_api_holds_back_the_stock_value_without_cost_access(): void
    {
        $keys = fn (User $user) => collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data.cards')
        )->pluck('key')->all();

        $this->assertNotContains('inventory', $keys($this->staff));
        $this->assertContains('low_stock', $keys($this->staff));
        $this->assertContains('inventory', $keys($this->admin));
    }
}
