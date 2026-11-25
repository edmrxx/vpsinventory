<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/users')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('users.view')->create())
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Users/Index')->has('users.data'));
    }

    public function test_the_view_permission_alone_cannot_reach_the_create_form(): void
    {
        $this->actingAs(User::factory()->withPermissions('users.view')->create())
            ->get('/users/create')
            ->assertForbidden();
    }

    public function test_the_view_permission_alone_cannot_store_a_user(): void
    {
        $this->actingAs(User::factory()->withPermissions('users.view')->create())
            ->post('/users', [
                'name' => 'Nope',
                'username' => 'nope',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['username' => 'nope']);
    }

    /* ---------------------------------------------------------------- Create */

    public function test_an_admin_can_create_a_user_with_roles(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Juan dela Cruz',
                'username' => 'JDelaCruz',
                'email' => 'juan@example.test',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
                'roles' => ['Staff'],
            ])
            ->assertRedirect('/users')
            ->assertSessionHas('success');

        $user = User::where('username', 'jdelacruz')->first();

        $this->assertNotNull($user, 'The username should be stored in lower case.');
        $this->assertTrue($user->hasRole('Staff'));
        $this->assertTrue(Hash::check('password-123', $user->password));
        $this->assertTrue($user->is_active);
    }

    public function test_the_username_must_be_unique_and_well_formed(): void
    {
        User::factory()->create(['username' => 'taken']);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/users', [
                'name' => 'Copy',
                'username' => 'taken',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('username');

        $this->actingAs($admin)
            ->post('/users', [
                'name' => 'Spaces',
                'username' => 'has spaces',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('username');
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Mismatch',
                'username' => 'mismatch',
                'password' => 'password-123',
                'password_confirmation' => 'something-else',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Ghost',
                'username' => 'ghost',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
                'roles' => ['Wizard'],
            ])
            ->assertSessionHasErrors('roles.0');
    }

    /* ---------------------------------------------------------------- Update */

    public function test_an_admin_can_update_a_user_and_keep_the_password(): void
    {
        $user = User::factory()->withRole('Staff')->create([
            'username' => 'before',
            'password' => 'original-pass',
        ]);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => 'After Name',
                'username' => 'after',
                'email' => null,
                'password' => '',
                'password_confirmation' => '',
                'is_active' => true,
                'roles' => [],
            ])
            ->assertRedirect('/users');

        $user->refresh();

        $this->assertSame('after', $user->username);
        $this->assertSame('After Name', $user->name);
        $this->assertTrue(Hash::check('original-pass', $user->password));
        $this->assertCount(0, $user->roles);
    }

    public function test_an_admin_can_change_a_password(): void
    {
        $user = User::factory()->create(['password' => 'original-pass']);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => $user->name,
                'username' => $user->username,
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
                'is_active' => true,
                'roles' => [],
            ])
            ->assertRedirect('/users');

        $this->assertTrue(Hash::check('brand-new-pass', $user->refresh()->password));
    }

    public function test_a_user_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create(); // a second admin, so that rule is not the blocker

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => $admin->name,
                'username' => $admin->username,
                'is_active' => false,
                'roles' => ['Admin'],
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_a_user_cannot_take_the_admin_role_off_themselves(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => $admin->name,
                'username' => $admin->username,
                'is_active' => true,
                'roles' => ['Staff'],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertTrue($admin->refresh()->hasRole('Admin'));
    }

    public function test_the_last_admin_cannot_lose_the_admin_role(): void
    {
        $admin = $this->admin();
        $other = User::factory()->admin()->create();

        // Only $admin is left holding the role once $other gives it up.
        $this->actingAs($admin)
            ->put("/users/{$other->id}", [
                'name' => $other->name,
                'username' => $other->username,
                'is_active' => true,
                'roles' => ['Staff'],
            ])
            ->assertRedirect('/users');

        $this->assertTrue($other->refresh()->hasRole('Staff'));

        // Now $admin is the only one, so the same change is refused.
        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => $admin->name,
                'username' => $admin->username,
                'is_active' => true,
                'roles' => ['Staff'],
            ])
            ->assertSessionHasErrors('roles');
    }

    /* ---------------------------------------------------------------- Status */

    public function test_the_status_permission_toggles_an_account(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $actor = User::factory()->withPermissions('users.view', 'users.status')->create();

        $this->actingAs($actor)
            ->patch("/users/{$user->id}/status")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($user->refresh()->is_active);

        $this->actingAs($actor)->patch("/users/{$user->id}/status");

        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_the_status_route_needs_the_status_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs(User::factory()->withPermissions('users.update')->create())
            ->patch("/users/{$user->id}/status")
            ->assertForbidden();

        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_nobody_can_switch_off_their_own_account(): void
    {
        $actor = User::factory()->withPermissions('users.status')->create();

        $this->actingAs($actor)
            ->patch("/users/{$actor->id}/status")
            ->assertForbidden();

        $this->assertTrue($actor->refresh()->is_active);
    }

    public function test_the_last_active_admin_cannot_be_switched_off(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->withPermissions('users.status')->create();

        $this->actingAs($actor)
            ->patch("/users/{$admin->id}/status")
            ->assertForbidden();

        $this->assertTrue($admin->refresh()->is_active);
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_an_admin_can_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/users/{$user->id}")
            ->assertRedirect('/users')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_nobody_can_delete_their_own_account(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->withPermissions('users.delete')->create();

        $this->actingAs($actor)
            ->delete("/users/{$admin->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /* ------------------------------------------------------------- Row flags */

    public function test_the_list_tells_the_front_end_what_each_row_allows(): void
    {
        $admin = $this->admin(['name' => 'Aaa Admin']);
        User::factory()->create(['name' => 'Zzz Other']);

        $this->actingAs($admin)
            ->get('/users')
            ->assertInertia(fn ($page) => $page
                // The admin's own row: no delete, no status change.
                ->where('users.data.0.can.delete', false)
                ->where('users.data.0.can.status', false)
                ->where('users.data.0.can.update', true)
                // Somebody else's row: everything allowed.
                ->where('users.data.1.can.delete', true)
                ->where('users.data.1.can.status', true)
            );
    }
}
