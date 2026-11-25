<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\SidebarBadges;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The red counts on the sidebar (24 November 2026): every module with a
 * step waiting on staff. An approver counts everything waiting for
 * approval; anybody else counts their own; nobody counts what they cannot
 * open.
 */
class SidebarBadgesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->product = $this->unaudited(fn () => Product::factory()->create(['selling_price' => 100]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 60],
        ], Carbon::today()->subDay());
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Staff')));
    }

    /** One of everything a member of Staff may raise, each waiting. */
    protected function raise(User $by, bool $withCount = true): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($by)->post('/purchase-requests', [
            'supplier_id' => $supplier->id,
            'reason' => 'Running low',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '60.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($by)->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '100.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($by)->post('/loss-reports', [
            'location_id' => Location::default()->id,
            'reason' => 'theft',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        // One open count per location (phase 32), so only when asked.
        if ($withCount) {
            $this->actingAs($by)->post('/stock-counts', [
                'location_id' => Location::default()->id,
            ])->assertSessionHasNoErrors();
        }
    }

    public function test_an_approver_counts_everything_waiting_for_approval(): void
    {
        $this->raise($this->staff());

        $badges = SidebarBadges::for($this->admin);

        $this->assertSame(1, $badges['purchase_requests']);
        $this->assertSame(1, $badges['sales_orders']);
        $this->assertSame(1, $badges['loss_reports']);
        // Still being counted, so not yet waiting for the approver.
        $this->assertSame(0, $badges['stock_counts']);
        $this->assertSame(0, $badges['purchase_orders']);
        $this->assertSame(0, $badges['pending_payments']);
    }

    public function test_anybody_else_counts_only_their_own(): void
    {
        $this->raise($mine = $this->staff());
        $this->raise($this->staff(), withCount: false);

        $badges = SidebarBadges::for($mine);

        $this->assertSame(1, $badges['purchase_requests']);
        $this->assertSame(1, $badges['sales_orders']);
        $this->assertSame(1, $badges['loss_reports']);
        // A counter's own sheet still being counted.
        $this->assertSame(1, $badges['stock_counts']);
    }

    public function test_answering_it_takes_it_off_the_count(): void
    {
        $this->raise($this->staff());

        $request = PurchaseRequest::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(0, SidebarBadges::for($this->admin)['purchase_requests']);
    }

    public function test_an_order_waiting_for_approval_is_counted_for_the_approver(): void
    {
        $placer = $this->unaudited(fn () => User::factory()->withPermissions('purchase_orders.view', 'purchase_orders.create')->create());

        $this->actingAs($placer)->post('/purchase-orders', [
            'supplier_id' => $this->unaudited(fn () => Supplier::factory()->create())->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '60.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SidebarBadges::for($this->admin)['purchase_orders']);
        $this->assertSame(1, SidebarBadges::for($placer)['purchase_orders']);
    }

    public function test_nobody_is_counted_a_screen_they_cannot_open(): void
    {
        $nobody = $this->unaudited(fn () => User::factory()->withPermissions('sales.view')->create());

        $this->assertSame([], SidebarBadges::for($nobody));
        $this->assertSame([], SidebarBadges::for(null));
    }

    /**
     * The bell lists the same, only what is waiting, each with a link to the
     * list narrowed to exactly those rows.
     */
    public function test_the_bell_lists_what_waits_with_a_link_to_exactly_those(): void
    {
        $this->raise($mine = $this->staff());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.0.key', 'purchase_requests')
            ->where('notifications.0.count', 1)
            ->where('notifications.0.text', ['purchase request waiting for your approval', 'purchase requests waiting for your approval'])
            ->where('notifications.0.href', '/purchase-requests?status=pending')
            // Nothing at zero: the purchase orders and the counts are not listed.
            ->has('notifications', 3));

        // Somebody who cannot approve is pointed at their own.
        $this->actingAs($mine)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.0.href', '/purchase-requests?status=pending&requested_by='.$mine->id));
    }

    public function test_the_bell_is_empty_when_nothing_waits(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications', []));
    }

    public function test_every_page_carries_them(): void
    {
        $this->raise($this->staff());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('badges.purchase_requests', 1)
            ->where('badges.loss_reports', 1));
    }
}
