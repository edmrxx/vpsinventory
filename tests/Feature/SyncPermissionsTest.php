<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `permissions:sync` tops a seeded role up and never trims it.
 *
 * Both halves matter. Without the first, every new module strands Staff on
 * an existing database, because only Admin used to be refreshed. Without the
 * second, running the command would throw away whatever an admin granted by
 * hand on the Roles screen.
 */
class SyncPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function staff(): Role
    {
        return Role::where('name', 'Staff')->firstOrFail();
    }

    /**
     * The admin role ends up with everything the wildcard grants.
     *
     * Which is everything except the opt-in permissions. Those say where
     * an account works rather than what it may do, and handing them to
     * Admin along with the rest would defeat the only thing they are for.
     */
    public function test_the_admin_role_ends_up_with_every_permission(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $admin = Role::where('name', PermissionRegistry::adminRole())->firstOrFail();

        $this->assertEqualsCanonicalizing(
            PermissionRegistry::forRole(PermissionRegistry::adminRole()),
            $admin->permissions->pluck('name')->all()
        );
    }

    /**
     * And it never picks up an opt-in one by accident.
     *
     * The command used to grant `PermissionRegistry::names()` straight to
     * Admin, which walked past this rule entirely. It went unnoticed
     * because the seeder took a different path, and only showed when
     * somebody tried the real thing. The command asks `forRole()` now, so
     * the rule lives in one place; this is the test that says so.
     */
    public function test_the_sync_never_grants_admin_an_opt_in_permission(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $admin = Role::where('name', PermissionRegistry::adminRole())->firstOrFail();
        $held = $admin->permissions->pluck('name')->all();

        $this->assertNotEmpty(
            PermissionRegistry::optIn(),
            'There is no opt-in permission left for this test to guard.'
        );

        foreach (PermissionRegistry::optIn() as $name) {
            $this->assertNotContains(
                $name,
                $held,
                "Admin was granted [{$name}], which is opt-in and must be ticked by hand."
            );
        }
    }

    public function test_a_seeded_role_is_given_what_the_config_says_it_is_missing(): void
    {
        $staff = $this->staff();

        // The state an existing database is in after a new module ships.
        $staff->revokePermissionTo('products.view');
        $this->assertFalse($staff->fresh()->hasPermissionTo('products.view'));

        $this->artisan('permissions:sync')
            ->expectsOutputToContain('products.view')
            ->assertSuccessful();

        $this->assertTrue($staff->fresh()->hasPermissionTo('products.view'));
    }

    public function test_a_permission_granted_by_hand_survives_a_sync(): void
    {
        $staff = $this->staff();

        // An admin widened the role on the Roles screen. The config knows
        // nothing about it, and it must still be there afterwards.
        $staff->givePermissionTo('users.create');

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertTrue(
            $staff->fresh()->hasPermissionTo('users.create'),
            'The sync threw away a permission an admin granted by hand.'
        );
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $before = $this->staff()->permissions->pluck('name')->sort()->values()->all();

        $this->artisan('permissions:sync')
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();

        $this->assertSame($before, $this->staff()->fresh()->permissions->pluck('name')->sort()->values()->all());
    }

    public function test_a_new_permission_in_the_registry_is_created(): void
    {
        Permission::where('name', 'products.view')->delete();

        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['name' => 'products.view']);
    }
}
