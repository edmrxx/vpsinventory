<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** A fixture user that leaves no mark in the trail. */
    protected function user(array $attributes = [], string ...$permissions): User
    {
        return $this->unaudited(function () use ($attributes, $permissions) {
            $factory = User::factory();

            if ($permissions !== []) {
                $factory = $factory->withPermissions(...$permissions);
            }

            return $factory->create($attributes);
        });
    }

    /**
     * Write one row straight to the table, so a test can shape the data it
     * needs without going through a controller.
     */
    protected function row(array $attributes = []): AuditTrail
    {
        // forceFill, because `created_at` is kept out of the model's fillable
        // list on purpose: no caller should be able to choose a timestamp.
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
            'browser' => 'Chrome on Windows',
            'created_at' => now(),
        ], $attributes));

        $row->save();

        return $row;
    }

    /* -------------------------------------------------------------- Access */

    public function test_the_page_needs_its_own_permission(): void
    {
        $this->actingAs($this->user([], 'users.view'))
            ->get('/audit')
            ->assertForbidden();
    }

    public function test_the_permission_opens_the_page(): void
    {
        $this->actingAs($this->user([], 'audit.view'))
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Audit/Index')
                ->has('trails.data')
                ->has('options.actions', count(AuditAction::cases()))
            );
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/audit')->assertRedirect(route('login'));
    }

    public function test_the_trail_has_no_write_routes(): void
    {
        $admin = $this->admin();

        // Read-only means exactly one route. Everything else is refused by
        // the router, not by a policy.
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($admin)
                ->{$method}('/audit')
                ->assertStatus(405);
        }

        $row = $this->row();

        $this->actingAs($admin)->delete("/audit/{$row->id}")->assertNotFound();
        $this->actingAs($admin)->get("/audit/{$row->id}/edit")->assertNotFound();

        $this->assertDatabaseHas('audit_trails', ['id' => $row->id]);
    }

    /* ------------------------------------------------------------- Ordering */

    public function test_the_newest_row_comes_first(): void
    {
        $this->row(['description' => 'Older', 'created_at' => now()->subDay()]);
        $this->row(['description' => 'Newer', 'created_at' => now()]);

        $this->actingAs($this->admin())
            ->get('/audit')
            ->assertInertia(fn ($page) => $page
                ->where('trails.data.0.description', 'Newer')
                ->where('filters.sort', 'created_at')
                ->where('filters.direction', 'desc')
            );
    }

    /* -------------------------------------------------------------- Filters */

    public function test_it_filters_by_user(): void
    {
        $mine = $this->user(['username' => 'mine']);

        $this->row(['user_id' => $mine->id, 'username' => 'mine', 'description' => 'Mine']);
        $this->row(['description' => 'Someone else']);

        $this->actingAs($this->admin())
            ->get("/audit?user_id={$mine->id}")
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 1)
                ->where('trails.data.0.description', 'Mine')
                ->where('filters.user_id', $mine->id)
            );
    }

    public function test_it_filters_by_module(): void
    {
        $this->row(['module' => 'Users', 'description' => 'A user thing']);
        $this->row(['module' => 'Roles', 'description' => 'A role thing']);

        $this->actingAs($this->admin())
            ->get('/audit?module=Roles')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 1)
                ->where('trails.data.0.description', 'A role thing')
            );
    }

    public function test_it_filters_by_action(): void
    {
        $this->row(['action' => AuditAction::Delete->value, 'description' => 'A delete']);
        $this->row(['action' => AuditAction::View->value, 'description' => 'A view']);

        $this->actingAs($this->admin())
            ->get('/audit?action=delete')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 1)
                ->where('trails.data.0.description', 'A delete')
            );
    }

    public function test_it_filters_by_a_date_range_with_both_ends_included(): void
    {
        $this->row(['description' => 'Before', 'created_at' => '2026-03-09 23:59:00']);
        $this->row(['description' => 'On the first day', 'created_at' => '2026-03-10 00:05:00']);
        $this->row(['description' => 'On the last day', 'created_at' => '2026-03-12 23:50:00']);
        $this->row(['description' => 'After', 'created_at' => '2026-03-13 00:10:00']);

        $this->actingAs($this->admin())
            ->get('/audit?from=2026-03-10&to=2026-03-12')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 2)
                ->where('filters.from', '2026-03-10')
                ->where('filters.to', '2026-03-12')
            );
    }

    public function test_filters_can_be_combined(): void
    {
        $this->row([
            'module' => 'Users',
            'action' => AuditAction::Delete->value,
            'description' => 'Wanted',
            'created_at' => '2026-03-11 10:00:00',
        ]);
        $this->row([
            'module' => 'Roles',
            'action' => AuditAction::Delete->value,
            'description' => 'Wrong module',
            'created_at' => '2026-03-11 10:00:00',
        ]);
        $this->row([
            'module' => 'Users',
            'action' => AuditAction::View->value,
            'description' => 'Wrong action',
            'created_at' => '2026-03-11 10:00:00',
        ]);
        $this->row([
            'module' => 'Users',
            'action' => AuditAction::Delete->value,
            'description' => 'Wrong day',
            'created_at' => '2026-04-01 10:00:00',
        ]);

        $this->actingAs($this->admin())
            ->get('/audit?module=Users&action=delete&from=2026-03-11&to=2026-03-11')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 1)
                ->where('trails.data.0.description', 'Wanted')
            );
    }

    public function test_a_nonsense_filter_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/audit?action=explode')->assertSessionHasErrors('action');
        $this->actingAs($admin)->get('/audit?from=last-tuesday')->assertSessionHasErrors('from');
        $this->actingAs($admin)->get('/audit?user_id=9999')->assertSessionHasErrors('user_id');
        $this->actingAs($admin)
            ->get('/audit?from=2026-03-10&to=2026-03-01')
            ->assertSessionHasErrors('to');
    }

    /* --------------------------------------------------- Search and paging */

    public function test_the_search_looks_in_the_description_user_and_ip(): void
    {
        $this->row(['description' => 'Deleted user [ghost].', 'username' => 'alice']);
        $this->row(['description' => 'Opened role [Staff].', 'username' => 'bob', 'ip_address' => '10.0.0.9']);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/audit?search=ghost')
            ->assertInertia(fn ($page) => $page->has('trails.data', 1));

        $this->actingAs($admin)
            ->get('/audit?search=bob')
            ->assertInertia(fn ($page) => $page->has('trails.data', 1));

        $this->actingAs($admin)
            ->get('/audit?search=10.0.0.9')
            ->assertInertia(fn ($page) => $page->has('trails.data', 1));
    }

    public function test_it_pages_twenty_five_rows_by_default(): void
    {
        foreach (range(1, 30) as $n) {
            $this->row(['description' => "Row {$n}"]);
        }

        $this->actingAs($this->admin())
            ->get('/audit')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 25)
                ->where('trails.total', 30)
            );
    }

    public function test_a_filter_survives_paging(): void
    {
        foreach (range(1, 30) as $n) {
            $this->row(['module' => 'Users', 'description' => "User row {$n}"]);
        }
        $this->row(['module' => 'Roles', 'description' => 'A role row']);

        $this->actingAs($this->admin())
            ->get('/audit?module=Users&page=2')
            ->assertInertia(fn ($page) => $page
                ->has('trails.data', 5)
                ->where('trails.total', 30)
                ->where('filters.module', 'Users')
            );
    }

    /* -------------------------------------------------------------- Options */

    public function test_the_filter_lists_only_offer_what_is_in_the_trail(): void
    {
        $alice = $this->user(['username' => 'alice']);

        $this->row(['user_id' => $alice->id, 'username' => 'alice', 'module' => 'Users']);
        $this->row(['user_id' => $alice->id, 'username' => 'alice', 'module' => 'Roles']);
        $this->row(['user_id' => null, 'username' => null, 'module' => 'Users']);

        $this->actingAs($this->admin())
            ->get('/audit')
            ->assertInertia(fn ($page) => $page
                // One entry per person, not one per row.
                ->has('options.users', 1)
                ->where('options.users.0.label', 'alice')
                ->has('options.modules', 2)
            );
    }

    public function test_a_row_carries_everything_the_screen_shows(): void
    {
        $this->row([
            'action' => AuditAction::Update->value,
            'properties' => ['changed' => ['name' => ['from' => 'A', 'to' => 'B']]],
        ]);

        $this->actingAs($this->admin())
            ->get('/audit')
            ->assertInertia(fn ($page) => $page
                ->where('trails.data.0.action', 'update')
                ->where('trails.data.0.action_label', 'Update')
                ->where('trails.data.0.action_tone', 'info')
                ->where('trails.data.0.role', 'Staff')
                ->where('trails.data.0.browser', 'Chrome on Windows')
                ->where('trails.data.0.properties.changed.name.to', 'B')
                ->has('trails.data.0.created_at')
            );
    }
}
