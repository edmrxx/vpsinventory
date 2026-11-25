<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditTrail;
use App\Models\User;
use App\Services\DashboardMetrics;
use App\Services\InventoryMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The activity feed and the shape of the dashboard page.
 *
 * The figures themselves moved to `InventoryMetricsTest` in phase 12, when
 * the audit-based cards were replaced by the real ones.
 */
class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app(DashboardMetrics::class)->flush();
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** One audit row, at a chosen moment. */
    protected function row(array $attributes = []): AuditTrail
    {
        $row = new AuditTrail;

        $row->forceFill(array_merge([
            'user_id' => null,
            'user_name' => 'Someone',
            'username' => 'someone',
            'role' => 'Staff',
            'module' => 'Users',
            'action' => AuditAction::View->value,
            'description' => 'Opened something.',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ], $attributes));

        $row->save();

        return $row;
    }

    /* ----------------------------------------------------------- The feed */

    public function test_the_recent_feed_loads_its_users_in_one_extra_query(): void
    {
        $users = $this->unaudited(fn () => User::factory()->count(6)->create());

        foreach ($users as $user) {
            $this->row(['user_id' => $user->id, 'username' => $user->username]);
        }

        DB::enableQueryLog();

        $recent = app(DashboardMetrics::class)->recentActivity(6);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(6, $recent);
        // One for the rows, one for the users. Not one per row.
        $this->assertSame(2, $queries, 'The feed must eager load its users.');
        $this->assertNotSame('··', $recent[0]['initials']);
    }

    public function test_the_feed_still_names_a_deleted_user(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create(['username' => 'gone']));

        $this->row(['user_id' => $user->id, 'user_name' => 'Gone Person', 'username' => 'gone']);

        $this->unaudited(fn () => $user->delete());

        $recent = app(DashboardMetrics::class)->recentActivity();

        $this->assertSame('Gone Person', $recent[0]['who']);
        $this->assertSame('gone', $recent[0]['username']);
    }

    public function test_the_feed_is_cached_so_a_second_read_costs_nothing(): void
    {
        $this->row();

        $metrics = app(DashboardMetrics::class);
        $metrics->recentActivity();

        DB::enableQueryLog();
        $metrics->recentActivity();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries, 'A cached feed must cost no query.');
    }

    /* ---------------------------------------------------------- The screen */

    public function test_the_page_sends_the_greeting_at_once_and_defers_the_figures(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('greeting')
                ->where('greeting.username', $admin->username)
                ->where('greeting.timezone', 'Asia/Manila')
                // Every figure is deferred, so the shell paints first.
                ->missing('cards')
                ->missing('trend')
                ->missing('topSellers')
                ->missing('lowStock')
                ->missing('recent')
            );
    }

    public function test_the_deferred_figures_arrive_on_the_follow_up_request(): void
    {
        $this->row();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/dashboard', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'overview,recent',
            ])
            ->assertOk()
            // The owner's eight: sales, collections, cash, gross profit,
            // low stock, overdue balances, purchase requests and losses.
            ->assertJsonCount(8, 'props.overview.cards')
            ->assertJsonPath('props.overview.cards.0.key', 'net_sales')
            ->assertJsonPath('props.overview.cards.3.key', 'gross_profit')
            ->assertJsonPath('props.overview.cards.7.key', 'losses')
            // And the rest, smaller: payables (phase 24), orders to approve
            // (phase 24.4), purchases, stock value, out of stock, returns
            // rate and the number of sales.
            ->assertJsonCount(7, 'props.overview.secondary')
            ->assertJsonPath('props.overview.secondary.0.key', 'payables')
            ->assertJsonPath('props.overview.secondary.1.key', 'purchase_orders')
            // Every card says what period it is being measured against.
            ->assertJsonPath('props.overview.compared_to', fn ($value) => is_string($value));
    }

    /**
     * The activity feed follows the audit permission.
     *
     * Its descriptions name documents, references and the people who posted
     * them, which is audit data wherever it is displayed. A user without
     * `audit.view` must not get the feed even when the browser asks for the
     * deferred prop by name — the gate is on the server, not on the screen.
     */
    public function test_a_user_without_the_audit_permission_gets_no_activity_feed(): void
    {
        $this->row(['description' => 'Posted sale [SI-2026-0001] for 12,500.00.']);

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->assertFalse($staff->can('audit.view'), 'Staff must not hold audit.view.');

        // The first load: the prop is there, and empty.
        $this->actingAs($staff)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recent', null));

        // And asking for it directly does not produce it either.
        $this->actingAs($staff)
            ->get('/dashboard', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'recent',
            ])
            ->assertOk()
            ->assertJsonPath('props.recent', null);

        // The same request for somebody who may read the trail does.
        $this->actingAs($this->admin())
            ->get('/dashboard', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'recent',
            ])
            ->assertOk()
            ->assertJsonCount(1, 'props.recent');
    }

    public function test_the_refresh_button_clears_both_caches(): void
    {
        $this->row();

        app(DashboardMetrics::class)->recentActivity();

        $this->assertNotNull(Cache::get('dashboard.activity_feed'));

        // The inventory figures are checked by behaviour rather than by
        // key: they are cached under a generation now, and a test that
        // spelled the key out would only be repeating the implementation.
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(InventoryMetrics::class)->topSellers();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $cold = $queries();
        $this->assertGreaterThan(0, $cold, 'The first read has to do some work.');
        $this->assertLessThan($cold, $queries(), 'The second read should come from the cache.');

        $this->actingAs($this->admin())
            ->post('/dashboard/refresh')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            $cold,
            $queries(),
            'After a refresh the figures must be worked out again.'
        );

        $this->assertNull(Cache::get('dashboard.activity_feed'));
        $this->assertNull(Cache::get('inventory_metrics.top_sellers.5'));
    }

    public function test_the_page_tells_the_front_end_which_links_to_show(): void
    {
        $limited = $this->unaudited(
            fn () => User::factory()->withPermissions('audit.view')->create()
        );

        $this->actingAs($limited)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('can.audit', true)
                ->where('can.sales', false)
                ->where('can.stock', false)
                ->where('can.purchases', false)
            );
    }
}
