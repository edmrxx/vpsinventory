<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves every action named in the phase is written to the trail.
 */
class AuditLoggingTest extends TestCase
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

    /** The newest row in the trail. */
    protected function lastTrail(): AuditTrail
    {
        $trail = AuditTrail::query()->latest('id')->first();

        $this->assertNotNull($trail, 'No audit row was written.');

        return $trail;
    }

    /* ------------------------------------------------------ Authentication */

    public function test_a_login_is_recorded(): void
    {
        User::factory()->withRole('Staff')->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $this->post('/login', ['username' => 'jdoe', 'password' => 'secret-pass']);

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Login, $trail->action);
        $this->assertSame('Authentication', $trail->module);
        $this->assertSame('jdoe', $trail->username);
        $this->assertSame('Staff', $trail->role);
        $this->assertStringContainsString('signed in', $trail->description);
        $this->assertNotNull($trail->ip_address);
        $this->assertNotNull($trail->browser);
        $this->assertNotNull($trail->created_at);
    }

    public function test_a_failed_login_is_not_recorded(): void
    {
        User::factory()->create(['username' => 'jdoe', 'password' => 'secret-pass']);

        $this->post('/login', ['username' => 'jdoe', 'password' => 'wrong-pass']);

        $this->assertSame(0, AuditTrail::where('module', 'Authentication')->count());
    }

    public function test_a_logout_is_recorded(): void
    {
        $user = User::factory()->create(['username' => 'jdoe']);

        $this->actingAs($user)->post('/logout');

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Logout, $trail->action);
        $this->assertSame('Authentication', $trail->module);
        $this->assertSame('jdoe', $trail->username);
    }

    public function test_remembering_a_login_does_not_add_an_update_row(): void
    {
        User::factory()->create(['username' => 'jdoe', 'password' => 'secret-pass']);

        // Remember Me writes a token onto the user row. That must not look
        // like somebody editing the account.
        $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
            'remember' => true,
        ]);

        $this->assertSame(1, AuditTrail::where('action', AuditAction::Login->value)->count());
        $this->assertSame(0, AuditTrail::where('action', AuditAction::Update->value)->count());
    }

    /* --------------------------------------------------------------- Users */

    public function test_creating_a_user_is_recorded_with_its_roles(): void
    {
        $this->actingAs($this->admin(['username' => 'bossman']))
            ->post('/users', [
                'name' => 'Maria Santos',
                'username' => 'msantos',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'is_active' => true,
                'roles' => ['Staff'],
            ]);

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Create, $trail->action);
        $this->assertSame('Users', $trail->module);
        $this->assertSame('bossman', $trail->username, 'The actor is recorded, not the subject.');
        $this->assertStringContainsString('msantos', $trail->description);
        $this->assertSame(['Staff'], $trail->properties['new']['roles']);
        $this->assertSame('msantos', $trail->properties['new']['username']);
    }

    public function test_the_password_never_reaches_the_trail(): void
    {
        $this->actingAs($this->admin())
            ->post('/users', [
                'name' => 'Maria Santos',
                'username' => 'msantos',
                'password' => 'super-secret-99',
                'password_confirmation' => 'super-secret-99',
                'is_active' => true,
            ]);

        $rows = AuditTrail::all();

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('super-secret-99', json_encode($row->properties) ?: '');
            $this->assertStringNotContainsString('super-secret-99', $row->description);
        }
    }

    public function test_opening_a_user_is_recorded_as_a_view(): void
    {
        $user = User::factory()->create(['username' => 'target']);

        $this->actingAs($this->admin())->get("/users/{$user->id}");

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::View, $trail->action);
        $this->assertSame('Users', $trail->module);
        $this->assertStringContainsString('target', $trail->description);
        $this->assertSame($user->id, $trail->subject_id);
    }

    public function test_updating_a_user_records_the_changed_fields(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'username' => 'target']);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => 'New Name',
                'username' => 'target',
                'is_active' => true,
                'roles' => [],
            ]);

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Update, $trail->action);
        $this->assertArrayHasKey('name', $trail->properties['changed']);
        $this->assertSame('Old Name', $trail->properties['changed']['name']['from']);
        $this->assertSame('New Name', $trail->properties['changed']['name']['to']);
    }

    public function test_changing_only_the_roles_still_writes_one_row(): void
    {
        $user = User::factory()->create(['username' => 'target']);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => $user->name,
                'username' => 'target',
                'is_active' => true,
                'roles' => ['Staff'],
            ]);

        $rows = AuditTrail::where('action', AuditAction::Update->value)->get();

        $this->assertCount(1, $rows, 'One edit must produce exactly one row.');
        $this->assertSame([], $rows[0]->properties['changed']['roles']['from']);
        $this->assertSame(['Staff'], $rows[0]->properties['changed']['roles']['to']);
    }

    public function test_an_edit_that_changes_nothing_writes_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Same', 'username' => 'same']);

        $this->actingAs($this->admin())
            ->put("/users/{$user->id}", [
                'name' => 'Same',
                'username' => 'same',
                'email' => $user->email,
                'is_active' => true,
                'roles' => [],
            ]);

        $this->assertSame(0, AuditTrail::where('action', AuditAction::Update->value)->count());
    }

    public function test_deactivating_and_activating_are_recorded_as_themselves(): void
    {
        $user = User::factory()->create(['username' => 'target', 'is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/users/{$user->id}/status");

        $off = $this->lastTrail();
        $this->assertSame(AuditAction::Deactivate, $off->action);
        $this->assertStringContainsString('Deactivated', $off->description);

        $this->actingAs($admin)->patch("/users/{$user->id}/status");

        $on = $this->lastTrail();
        $this->assertSame(AuditAction::Activate, $on->action);
        $this->assertStringContainsString('Activated', $on->description);

        // The status toggle must not also appear as an Update.
        $this->assertSame(0, AuditTrail::where('action', AuditAction::Update->value)->count());
    }

    public function test_deleting_a_user_is_recorded(): void
    {
        $user = User::factory()->create(['username' => 'target']);

        $this->actingAs($this->admin())->delete("/users/{$user->id}");

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Delete, $trail->action);
        $this->assertStringContainsString('target', $trail->description);
    }

    public function test_the_trail_outlives_the_user_it_names(): void
    {
        $actor = $this->admin(['username' => 'bossman']);

        $this->actingAs($actor)->get("/users/{$actor->id}");

        $trail = $this->lastTrail();
        $this->assertSame($actor->id, $trail->user_id);

        // A second admin, so the delete is allowed.
        $second = $this->admin();
        $this->actingAs($second)->delete("/users/{$actor->id}");

        $trail->refresh();

        $this->assertNull($trail->user_id, 'The link is cleared.');
        $this->assertSame('bossman', $trail->username, 'The name is kept.');
    }

    /* --------------------------------------------------------------- Roles */

    public function test_creating_a_role_is_recorded_with_its_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post('/roles', ['name' => 'Cashier', 'permissions' => ['users.view']]);

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Create, $trail->action);
        $this->assertSame('Roles', $trail->module);
        $this->assertStringContainsString('Cashier', $trail->description);
        $this->assertSame(['users.view'], $trail->properties['new']['permissions']);
    }

    public function test_opening_a_role_is_recorded_as_a_view(): void
    {
        $role = Role::where('name', 'Staff')->first();

        $this->actingAs($this->admin())->get("/roles/{$role->id}");

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::View, $trail->action);
        $this->assertSame('Roles', $trail->module);
        $this->assertStringContainsString('Staff', $trail->description);
    }

    public function test_changing_only_the_permissions_of_a_role_writes_one_row(): void
    {
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->syncPermissions(['users.view']);

        $this->actingAs($this->admin())
            ->put("/roles/{$role->id}", [
                'name' => 'Cashier',
                'permissions' => ['users.view', 'users.create'],
            ]);

        $rows = AuditTrail::where('action', AuditAction::Update->value)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(['users.view'], $rows[0]->properties['changed']['permissions']['from']);
        $this->assertEqualsCanonicalizing(
            ['users.create', 'users.view'],
            $rows[0]->properties['changed']['permissions']['to']
        );
    }

    public function test_renaming_and_repermissioning_a_role_lands_in_one_row(): void
    {
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $role->syncPermissions(['users.view']);

        $this->actingAs($this->admin())
            ->put("/roles/{$role->id}", [
                'name' => 'Head Cashier',
                'permissions' => ['roles.view'],
            ]);

        $rows = AuditTrail::where('action', AuditAction::Update->value)->get();

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('name', $rows[0]->properties['changed']);
        $this->assertArrayHasKey('permissions', $rows[0]->properties['changed']);
    }

    public function test_deleting_a_role_is_recorded(): void
    {
        $role = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        $this->actingAs($this->admin())->delete("/roles/{$role->id}");

        $trail = $this->lastTrail();

        $this->assertSame(AuditAction::Delete, $trail->action);
        $this->assertSame('Roles', $trail->module);
        $this->assertStringContainsString('Temporary', $trail->description);
    }

    /* ------------------------------------------------------------ Coverage */

    public function test_every_action_the_app_can_take_is_recorded(): void
    {
        $admin = $this->admin(['username' => 'bossman', 'password' => 'admin-pass-1']);
        $target = User::factory()->create(['username' => 'target']);
        $role = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        $this->post('/login', ['username' => 'bossman', 'password' => 'admin-pass-1']);
        $this->actingAs($admin)->get("/users/{$target->id}");
        $this->actingAs($admin)->post('/users', [
            'name' => 'Fresh One',
            'username' => 'fresh',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'is_active' => true,
        ]);
        $this->actingAs($admin)->put("/users/{$target->id}", [
            'name' => 'Renamed',
            'username' => 'target',
            'is_active' => true,
            'roles' => [],
        ]);
        $this->actingAs($admin)->patch("/users/{$target->id}/status");
        $this->actingAs($admin)->patch("/users/{$target->id}/status");
        $this->actingAs($admin)->delete("/roles/{$role->id}");

        // Post and Void belong to the inventory documents, so they need one.
        $product = $this->unaudited(fn () => Product::factory()->create());
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '10.00']],
        ]);

        $purchase = Purchase::firstOrFail();
        $this->actingAs($admin)->post("/purchases/{$purchase->id}/void", ['reason' => 'Booked by mistake.']);

        // Approve, Reject and Close belong to a purchase request, which
        // somebody other than the approver has to raise.
        $requester = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        foreach (['approve', 'reject', 'close'] as $step) {
            $this->actingAs($requester)->post('/purchase-requests', [
                'reason' => "Needed, to {$step}",
                'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '10.00']],
            ]);

            $request = \App\Models\PurchaseRequest::latest('id')->firstOrFail();
            $this->actingAs($admin)->post("/purchase-requests/{$request->id}/{$step}", ['reason' => 'Not needed now.']);
        }

        // Cancel belongs to a purchase order.
        $this->actingAs($admin)->post('/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '10.00']],
        ]);
        $order = \App\Models\PurchaseOrder::firstOrFail();
        $this->actingAs($admin)->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Ordered elsewhere.']);

        $this->actingAs($admin)->post('/logout');

        $recorded = AuditTrail::query()->pluck('action')->map(fn ($a) => $a->value)->unique();

        foreach (AuditAction::values() as $action) {
            $this->assertContains($action, $recorded->all(), "The [{$action}] action was never recorded.");
        }
    }
}
