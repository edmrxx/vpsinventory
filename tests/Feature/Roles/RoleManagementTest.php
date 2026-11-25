<?php

namespace Tests\Feature\Roles;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/roles')
            ->assertForbidden();
    }

    public function test_the_seeded_roles_are_listed(): void
    {
        $this->actingAs($this->admin())
            ->get('/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roles/Index')
                ->has('roles.data', 2)
                ->where('roles.data.0.name', 'Admin')
                ->where('roles.data.0.is_protected', true)
                ->where('roles.data.0.is_admin', true)
                ->where('roles.data.1.name', 'Staff')
            );
    }

    public function test_the_create_form_carries_the_permission_registry(): void
    {
        $this->actingAs($this->admin())
            ->get('/roles/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Roles/Create')
                ->has('groups', count(PermissionRegistry::forForm()))
                ->where('groups.0.key', 'users')
                ->has('groups.0.permissions', 5)
            );
    }

    /* ---------------------------------------------------------------- Create */

    public function test_an_admin_can_create_a_role_with_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post('/roles', [
                'name' => 'Cashier',
                'permissions' => ['users.view', 'roles.view'],
            ])
            ->assertRedirect('/roles')
            ->assertSessionHas('success');

        $role = Role::where('name', 'Cashier')->first();

        $this->assertNotNull($role);
        $this->assertEqualsCanonicalizing(
            ['users.view', 'roles.view'],
            $role->permissions->pluck('name')->all()
        );
    }

    public function test_an_admin_can_create_many_roles(): void
    {
        $admin = $this->admin();

        foreach (['Cashier', 'Auditor', 'Branch Manager', 'Teller'] as $name) {
            $this->actingAs($admin)
                ->post('/roles', ['name' => $name, 'permissions' => ['users.view']])
                ->assertRedirect('/roles');
        }

        // Four new roles plus the two seeded ones.
        $this->assertSame(6, Role::count());
    }

    public function test_a_role_with_no_permissions_is_allowed(): void
    {
        $this->actingAs($this->admin())
            ->post('/roles', ['name' => 'Observer'])
            ->assertRedirect('/roles');

        $this->assertSame(0, Role::where('name', 'Observer')->first()->permissions->count());
    }

    public function test_the_role_name_must_be_unique(): void
    {
        $this->actingAs($this->admin())
            ->post('/roles', ['name' => 'Staff'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/roles', ['name' => 'Hacker', 'permissions' => ['everything.always']])
            ->assertSessionHasErrors('permissions.0');

        $this->assertDatabaseMissing('roles', ['name' => 'Hacker']);
    }

    /* ---------------------------------------------------------------- Update */

    public function test_an_admin_can_change_a_roles_permissions(): void
    {
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->syncPermissions(['users.view']);

        $this->actingAs($this->admin())
            ->put("/roles/{$role->id}", [
                'name' => 'Head Cashier',
                'permissions' => ['users.view', 'users.create', 'users.update'],
            ])
            ->assertRedirect('/roles');

        $role->refresh();

        $this->assertSame('Head Cashier', $role->name);
        $this->assertCount(3, $role->permissions);
    }

    public function test_the_admin_role_cannot_be_edited(): void
    {
        $role = Role::where('name', 'Admin')->first();

        $this->actingAs($this->admin())
            ->get("/roles/{$role->id}/edit")
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->put("/roles/{$role->id}", ['name' => 'Admin', 'permissions' => []])
            ->assertForbidden();

        // Every permission the wildcard grants — which excludes the
        // opt-in ones, ticked by hand for the roles that need them.
        $this->assertCount(
            count(PermissionRegistry::names()) - count(PermissionRegistry::optIn()),
            $role->refresh()->permissions,
        );
    }

    public function test_a_built_in_role_keeps_its_name_but_may_change_permissions(): void
    {
        $staff = Role::where('name', 'Staff')->first();

        $this->actingAs($this->admin())
            ->put("/roles/{$staff->id}", ['name' => 'Renamed', 'permissions' => ['users.view']])
            ->assertSessionHasErrors('name');

        $this->assertSame('Staff', $staff->refresh()->name);

        $this->actingAs($this->admin())
            ->put("/roles/{$staff->id}", [
                'name' => 'Staff',
                'permissions' => ['users.view', 'users.create'],
            ])
            ->assertRedirect('/roles');

        $this->assertCount(2, $staff->refresh()->permissions);
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_an_admin_can_delete_an_unused_role(): void
    {
        $role = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        $this->actingAs($this->admin())
            ->delete("/roles/{$role->id}")
            ->assertRedirect('/roles')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_a_role_still_in_use_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'Busy', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->actingAs($this->admin())
            ->delete("/roles/{$role->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_the_built_in_roles_cannot_be_deleted(): void
    {
        foreach (['Admin', 'Staff'] as $name) {
            $role = Role::where('name', $name)->first();

            $this->actingAs($this->admin())
                ->delete("/roles/{$role->id}")
                ->assertForbidden();

            $this->assertDatabaseHas('roles', ['id' => $role->id]);
        }
    }

    /* ------------------------------------------------------ Effect on a user */

    public function test_a_new_permission_reaches_a_user_through_their_role(): void
    {
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        // With no permissions on the role, the user is refused.
        $this->actingAs($user)->get('/users')->assertForbidden();

        $this->actingAs($this->admin())
            ->put("/roles/{$role->id}", ['name' => 'Cashier', 'permissions' => ['users.view']])
            ->assertRedirect('/roles');

        // The same user may now open the list.
        $this->actingAs($user)->get('/users')->assertOk();
    }

    public function test_the_row_flags_lock_the_built_in_roles(): void
    {
        $this->actingAs($this->admin())
            ->get('/roles')
            ->assertInertia(fn ($page) => $page
                // Admin: no edit, no delete.
                ->where('roles.data.0.can.update', false)
                ->where('roles.data.0.can.delete', false)
                // Staff: editable, but not deletable.
                ->where('roles.data.1.can.update', true)
                ->where('roles.data.1.can.delete', false)
            );
    }
}
