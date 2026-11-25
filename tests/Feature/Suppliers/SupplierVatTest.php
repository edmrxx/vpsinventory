<?php

namespace Tests\Feature\Suppliers;

use App\Models\AuditTrail;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether a supplier charges VAT.
 *
 * Not to be confused with `tax.vat_registered` in Settings. That one is a
 * view: it redraws every document so the two ways of trading can be
 * compared, and nothing is stored. This is a record — a fact about the
 * supplier, written down and kept.
 *
 * It is an ordinary field, so it is gated by `suppliers.update` and needs
 * no permission of its own. These tests hold that line, because a write
 * route with no permission is exactly what `SecurityTest` is there to
 * catch.
 */
class SupplierVatTest extends TestCase
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

    protected function supplier(array $overrides = []): Supplier
    {
        return $this->unaudited(fn () => Supplier::factory()->create($overrides));
    }

    /** @return array<string, mixed> */
    protected function payload(Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'tin' => $supplier->tin,
            'is_vat_registered' => $supplier->is_vat_registered,
            'address' => $supplier->address,
            'notes' => $supplier->notes,
            'is_active' => $supplier->is_active,
        ], $overrides);
    }

    /* ------------------------------------------------------------ The field */

    public function test_a_supplier_starts_as_non_vat(): void
    {
        $this->assertFalse($this->supplier()->is_vat_registered);
        $this->assertSame('Non-VAT', $this->supplier()->vatLabel());
    }

    public function test_it_can_be_set_when_the_supplier_is_created(): void
    {
        $this->actingAs($this->admin())
            ->post('/suppliers', [
                'code' => 'SUP-9001',
                'name' => 'Solane Depot',
                'contact_person' => null,
                'email' => null,
                'phone' => null,
                'tin' => null,
                'is_vat_registered' => true,
                'address' => null,
                'notes' => null,
                'is_active' => true,
            ])
            ->assertRedirect();

        $supplier = Supplier::where('code', 'SUP-9001')->firstOrFail();

        $this->assertTrue($supplier->is_vat_registered);
        $this->assertSame('VAT', $supplier->vatLabel());
    }

    public function test_it_can_be_changed_from_the_edit_dialog(): void
    {
        $supplier = $this->supplier(['is_vat_registered' => false]);

        $this->actingAs($this->admin())
            ->put("/suppliers/{$supplier->id}", $this->payload($supplier, ['is_vat_registered' => true]))
            ->assertRedirect();

        $this->assertTrue($supplier->refresh()->is_vat_registered);
    }

    /* ----------------------------------------------------------- The switch */

    public function test_the_pill_switches_it_both_ways(): void
    {
        $supplier = $this->supplier(['is_vat_registered' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/suppliers/{$supplier->id}/vat")
            ->assertRedirect();

        $this->assertTrue($supplier->refresh()->is_vat_registered);

        $this->actingAs($admin)
            ->patch("/suppliers/{$supplier->id}/vat")
            ->assertRedirect();

        $this->assertFalse($supplier->refresh()->is_vat_registered);
    }

    public function test_the_switch_writes_one_row_to_the_trail(): void
    {
        $supplier = $this->supplier(['is_vat_registered' => false]);

        $this->actingAs($this->admin())
            ->patch("/suppliers/{$supplier->id}/vat")
            ->assertRedirect();

        $rows = AuditTrail::where('module', 'Suppliers')->get();

        $this->assertCount(1, $rows, 'One click should leave one row, not two.');
        $this->assertStringContainsString('is now VAT', $rows->first()->description);
    }

    public function test_the_switch_needs_the_update_permission(): void
    {
        $supplier = $this->supplier();

        $viewer = $this->unaudited(
            fn () => User::factory()->withPermissions('suppliers.view')->create()
        );

        $this->actingAs($viewer)
            ->patch("/suppliers/{$supplier->id}/vat")
            ->assertForbidden();

        $this->assertFalse($supplier->refresh()->is_vat_registered);
    }

    public function test_a_guest_cannot_switch_it(): void
    {
        $supplier = $this->supplier();

        $this->patch("/suppliers/{$supplier->id}/vat")->assertRedirect('/login');

        $this->assertFalse($supplier->refresh()->is_vat_registered);
    }

    /* ---------------------------------------------------------- The screens */

    public function test_every_screen_is_told(): void
    {
        $supplier = $this->supplier(['is_vat_registered' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/suppliers')
            ->assertInertia(fn ($page) => $page
                ->where('suppliers.data.0.is_vat_registered', true)
                ->where('suppliers.data.0.vat_label', 'VAT'));

        $this->actingAs($admin)
            ->get("/suppliers/{$supplier->id}")
            ->assertInertia(fn ($page) => $page
                ->where('supplier.is_vat_registered', true)
                ->where('supplier.vat_label', 'VAT'));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.is_vat_registered', true);
    }

    public function test_a_customer_is_never_given_a_vat_flag(): void
    {
        // The API resource serves both, and a VAT registration on a
        // customer would mean nothing.
        $customer = $this->unaudited(fn () => \App\Models\Customer::factory()->create());

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.is_vat_registered');
    }

    /* ----------------------------------------------------------- The filter */

    public function test_the_list_can_be_filtered_either_way(): void
    {
        $this->supplier(['code' => 'SUP-AAA', 'name' => 'Alpha', 'is_vat_registered' => true]);
        $this->supplier(['code' => 'SUP-BBB', 'name' => 'Bravo', 'is_vat_registered' => false]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/suppliers?vat=registered')
            ->assertInertia(fn ($page) => $page
                ->has('suppliers.data', 1)
                ->where('suppliers.data.0.name', 'Alpha'));

        $this->actingAs($admin)
            ->get('/suppliers?vat=not-registered')
            ->assertInertia(fn ($page) => $page
                ->has('suppliers.data', 1)
                ->where('suppliers.data.0.name', 'Bravo'));

        $this->actingAs($admin)
            ->get('/suppliers')
            ->assertInertia(fn ($page) => $page->has('suppliers.data', 2));
    }

    public function test_an_unknown_filter_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/suppliers?vat=maybe')
            ->assertSessionHasErrors('vat');
    }
}
