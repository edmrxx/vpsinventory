<?php

namespace Tests\Feature\Suppliers;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
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
            'code' => 'ABC-TRADING',
            'name' => 'ABC Trading Co.',
            'contact_person' => 'Maria Santos',
            'email' => 'sales@abc.test',
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
            ->get('/suppliers')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('suppliers.view')->create())
            ->get('/suppliers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Suppliers/Index')
                ->has('suppliers.data'));
    }

    public function test_the_view_permission_alone_cannot_store_a_supplier(): void
    {
        $this->actingAs(User::factory()->withPermissions('suppliers.view')->create())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('suppliers', 0);
    }

    /* ----------------------------------------------------------------- Write */

    public function test_an_admin_can_create_a_supplier(): void
    {
        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload())
            ->assertRedirect('/suppliers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('suppliers', [
            'code' => 'ABC-TRADING',
            'name' => 'ABC Trading Co.',
            'is_active' => true,
        ]);
    }

    public function test_the_code_is_stored_in_capitals(): void
    {
        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload(['code' => '  abc-trading  ']))
            ->assertRedirect('/suppliers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('suppliers', ['code' => 'ABC-TRADING']);
    }

    public function test_the_code_must_be_unique_whatever_its_case(): void
    {
        $this->unaudited(fn () => Supplier::factory()->create(['code' => 'ABC-TRADING']));

        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload(['code' => 'abc-trading']))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_an_empty_box_is_stored_as_nothing_rather_than_as_blank_text(): void
    {
        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload(['contact_person' => '   ', 'notes' => '']))
            ->assertRedirect('/suppliers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('suppliers', [
            'code' => 'ABC-TRADING',
            'contact_person' => null,
            'notes' => null,
        ]);
    }

    public function test_the_name_and_the_code_are_required(): void
    {
        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->post('/suppliers', $this->validPayload(['code' => '', 'name' => '']))
            ->assertSessionHasErrors(['code', 'name']);
    }

    public function test_an_admin_can_update_a_supplier(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->put("/suppliers/{$supplier->id}", $this->validPayload(['name' => 'Renamed Co.']))
            ->assertRedirect('/suppliers?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Renamed Co.']);
    }

    public function test_a_supplier_keeps_its_own_code_on_an_edit(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create(['code' => 'KEEP-ME']));

        $this->actingAs($this->admin())
            ->from('/suppliers?page=2&sort=name&per_page=10')
            ->put("/suppliers/{$supplier->id}", $this->validPayload(['code' => 'KEEP-ME']))
            ->assertSessionHasNoErrors();
    }

    public function test_an_admin_can_delete_a_supplier(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin())
            ->delete("/suppliers/{$supplier->id}")
            ->assertRedirect('/suppliers');

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_a_supplier_named_on_a_purchase_cannot_be_deleted(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $product = $this->unaudited(fn () => Product::factory()->create());

        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00']],
        ]);

        $this->actingAs($this->admin())
            ->delete("/suppliers/{$supplier->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_a_supplier_named_on_a_purchase_can_still_be_switched_off(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $product = $this->unaudited(fn () => Product::factory()->create());

        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '10.00']],
        ]);

        $this->actingAs($this->admin())
            ->patch("/suppliers/{$supplier->id}/status")
            ->assertRedirect();

        $this->assertFalse($supplier->fresh()->is_active);
    }

    /* ---------------------------------------------------------------- Status */

    public function test_the_status_route_switches_a_supplier_off_and_on(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin())
            ->patch("/suppliers/{$supplier->id}/status")
            ->assertRedirect();

        $this->assertFalse($supplier->fresh()->is_active);

        $this->actingAs($this->admin())
            ->patch("/suppliers/{$supplier->id}/status")
            ->assertRedirect();

        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_the_status_route_needs_the_status_permission(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs(User::factory()->withPermissions('suppliers.update')->create())
            ->patch("/suppliers/{$supplier->id}/status")
            ->assertForbidden();

        $this->assertTrue($supplier->fresh()->is_active);
    }

    /* --------------------------------------------------------------- Filters */

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->unaudited(function () {
            Supplier::factory()->create(['name' => 'Active One']);
            Supplier::factory()->inactive()->create(['name' => 'Switched Off']);
        });

        $this->actingAs($this->admin())
            ->get('/suppliers?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('suppliers.data', 1)
                ->where('suppliers.data.0.name', 'Switched Off'));
    }

    public function test_an_unknown_status_filter_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/suppliers?status=nonsense')
            ->assertSessionHasErrors('status');
    }

    public function test_the_list_tells_the_front_end_what_each_row_allows(): void
    {
        $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs(User::factory()->withPermissions('suppliers.view')->create())
            ->get('/suppliers')
            ->assertInertia(fn ($page) => $page
                ->where('suppliers.data.0.can.update', false)
                ->where('suppliers.data.0.can.delete', false)
                ->where('can.create', false));
    }
}
