<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
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

    /** @return array<string, mixed> */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CUST-001',
            'name' => 'Dela Cruz Enterprises',
            'contact_person' => 'Maria Santos',
            'email' => 'buyer@example.test',
            'phone' => '0917 000 0000',
            'tin' => '000-000-000-000',
            'address' => 'Manila',
            'notes' => null,
            'is_active' => true,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/customers')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('customers.view')->create())
            ->get('/customers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->has('customers.data'));
    }

    public function test_the_view_permission_alone_cannot_store_a_customer(): void
    {
        $this->actingAs(User::factory()->withPermissions('customers.view')->create())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    /* ----------------------------------------------------------------- Write */

    public function test_an_admin_can_create_a_customer(): void
    {
        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload())
            ->assertRedirect('/customers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('customers', [
            'code' => 'CUST-001',
            'name' => 'Dela Cruz Enterprises',
            'is_active' => true,
        ]);
    }

    public function test_the_code_is_stored_in_capitals(): void
    {
        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload(['code' => '  cust-001  ']))
            ->assertRedirect('/customers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('customers', ['code' => 'CUST-001']);
    }

    public function test_the_code_must_be_unique_whatever_its_case(): void
    {
        $this->unaudited(fn () => Customer::factory()->create(['code' => 'CUST-001']));

        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload(['code' => 'cust-001']))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_an_empty_box_is_stored_as_nothing_rather_than_as_blank_text(): void
    {
        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload(['contact_person' => '   ', 'notes' => '']))
            ->assertRedirect('/customers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('customers', [
            'code' => 'CUST-001',
            'contact_person' => null,
            'notes' => null,
        ]);
    }

    public function test_the_name_and_the_code_are_required(): void
    {
        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->post('/customers', $this->validPayload(['code' => '', 'name' => '']))
            ->assertSessionHasErrors(['code', 'name']);
    }

    public function test_an_admin_can_update_a_customer(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->put("/customers/{$customer->id}", $this->validPayload(['name' => 'Renamed Co.']))
            ->assertRedirect('/customers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Renamed Co.']);
    }

    public function test_a_customer_keeps_its_own_code_on_an_edit(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create(['code' => 'KEEP-ME']));

        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->put("/customers/{$customer->id}", $this->validPayload(['code' => 'KEEP-ME']))
            ->assertSessionHasNoErrors();
    }

    public function test_an_admin_can_delete_a_customer(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->admin())
            ->delete("/customers/{$customer->id}")
            ->assertRedirect('/customers');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_a_customer_named_on_a_sale_cannot_be_deleted(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());
        $product = $this->unaudited(fn () => Product::factory()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10],
        ]);

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '20.00']],
        ]);

        $this->actingAs($this->admin())
            ->delete("/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    /* ---------------------------------------------------------------- Status */

    public function test_the_status_route_switches_a_customer_off_and_on(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}/status")
            ->assertRedirect();

        $this->assertFalse($customer->fresh()->is_active);

        $this->actingAs($this->admin())
            ->patch("/customers/{$customer->id}/status")
            ->assertRedirect();

        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_the_status_route_needs_the_status_permission(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs(User::factory()->withPermissions('customers.update')->create())
            ->patch("/customers/{$customer->id}/status")
            ->assertForbidden();

        $this->assertTrue($customer->fresh()->is_active);
    }

    /* --------------------------------------------------------------- Filters */

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->unaudited(function () {
            Customer::factory()->create(['name' => 'Active One']);
            Customer::factory()->inactive()->create(['name' => 'Switched Off']);
        });

        $this->actingAs($this->admin())
            ->get('/customers?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Switched Off'));
    }

    public function test_an_unknown_status_filter_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/customers?status=nonsense')
            ->assertSessionHasErrors('status');
    }

    /* --------------------------------------------------------------- Walk-in */

    public function test_the_walk_in_customer_cannot_be_deleted(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->delete("/customers/{$walkIn->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $walkIn->id]);
    }

    public function test_the_walk_in_customer_cannot_be_switched_off(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->patch("/customers/{$walkIn->id}/status")
            ->assertForbidden();

        $this->assertTrue($walkIn->fresh()->is_active);
    }

    public function test_the_walk_in_customer_cannot_be_switched_off_through_the_edit_form(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        // The status route is already refused by the policy. The edit form
        // reaches the same field, so it is refused here too.
        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->put("/customers/{$walkIn->id}", $this->validPayload([
                'code' => 'WALK-IN',
                'name' => 'Walk-in Customer',
                'is_active' => false,
            ]))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($walkIn->fresh()->is_active);
    }

    public function test_an_ordinary_customer_can_be_switched_off_through_the_edit_form(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->put("/customers/{$customer->id}", $this->validPayload(['is_active' => false]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);
    }

    public function test_the_walk_in_customer_can_still_be_renamed(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->from('/customers?page=2&sort=name&per_page=10')
            ->put("/customers/{$walkIn->id}", $this->validPayload([
                'code' => 'WALK-IN',
                'name' => 'Counter Sale',
            ]))
            ->assertRedirect('/customers?page=2&sort=name&per_page=10');

        $walkIn->refresh();

        $this->assertSame('Counter Sale', $walkIn->name);
        // The flag is what the app looks for, so renaming must not clear it.
        $this->assertTrue($walkIn->is_walk_in);
    }

    public function test_the_list_marks_the_walk_in_row(): void
    {
        $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->get('/customers')
            ->assertInertia(fn ($page) => $page->where('customers.data.0.is_walk_in', true));
    }

    public function test_the_list_tells_the_front_end_what_each_row_allows(): void
    {
        $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs(User::factory()->withPermissions('customers.view')->create())
            ->get('/customers')
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.can.update', false)
                ->where('customers.data.0.can.delete', false)
                ->where('can.create', false));
    }
}
