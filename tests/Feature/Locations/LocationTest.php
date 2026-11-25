<?php

namespace Tests\Feature\Locations;

use App\Enums\AuditAction;
use App\Enums\LocationType;
use App\Models\AuditTrail;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 31.1: the list of locations — the store and the warehouse. A master
 * list shaped like Departments; nothing counts stock per location yet.
 */
class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lahug Branch',
            'type' => 'store',
            'address' => 'Gorordo Avenue, Lahug',
            'description' => null,
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------ The two places */

    public function test_the_store_and_the_warehouse_arrive_with_the_migration(): void
    {
        $this->assertSame(
            [['Main Store', LocationType::Store], ['Warehouse', LocationType::Warehouse]],
            Location::orderBy('id')->get()->map(fn (Location $l) => [$l->name, $l->type])->all(),
        );

        $this->assertTrue(Location::where('name', 'Main Store')->value('is_active'));
    }

    /* ------------------------------------------------------------- Access */

    public function test_staff_may_read_the_list_but_not_change_it(): void
    {
        $warehouse = Location::firstWhere('name', 'Warehouse');

        $this->actingAs($this->staff)->get('/locations')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Locations/Index')
                ->has('locations.data', 2)
                ->where('can.create', false)
                ->where('locations.data.0.can.update', false));

        $this->actingAs($this->staff)->post('/locations', $this->payload())->assertForbidden();
        $this->actingAs($this->staff)->put("/locations/{$warehouse->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->staff)->patch("/locations/{$warehouse->id}/status")->assertForbidden();
        $this->actingAs($this->staff)->delete("/locations/{$warehouse->id}")->assertForbidden();

        $this->assertDatabaseCount('locations', 2);
    }

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get('/locations')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/locations')->assertRedirect(route('login'));
    }

    /* -------------------------------------------------------------- Write */

    public function test_an_admin_adds_a_location_tidied_up(): void
    {
        $this->actingAs($this->admin)
            ->from('/locations')
            ->post('/locations', $this->payload(['name' => '  Lahug Branch ', 'type' => 'STORE', 'address' => '']))
            ->assertRedirect('/locations')
            ->assertSessionHas('success', 'Location [Lahug Branch] was created.');

        $this->assertDatabaseHas('locations', [
            'name' => 'Lahug Branch',
            'type' => 'store',
            'address' => null,
            'is_active' => true,
        ]);
    }

    public function test_an_admin_renames_a_location_and_changes_its_type(): void
    {
        $warehouse = Location::firstWhere('name', 'Warehouse');

        $this->actingAs($this->admin)
            ->put("/locations/{$warehouse->id}", $this->payload(['name' => 'Mandaue Warehouse', 'type' => 'warehouse']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Mandaue Warehouse', $warehouse->fresh()->name);
        $this->assertSame('Gorordo Avenue, Lahug', $warehouse->fresh()->address);
    }

    public function test_two_locations_cannot_share_a_name_but_one_keeps_its_own(): void
    {
        $store = Location::firstWhere('name', 'Main Store');

        $this->actingAs($this->admin)
            ->post('/locations', $this->payload(['name' => 'Warehouse']))
            ->assertSessionHasErrors(['name' => 'There is already a location with this name.']);

        $this->actingAs($this->admin)
            ->put("/locations/{$store->id}", $this->payload(['name' => 'Main Store']))
            ->assertSessionHasNoErrors();
    }

    public function test_the_type_must_be_store_or_warehouse_and_the_name_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/locations', $this->payload(['name' => '', 'type' => 'truck']))
            ->assertSessionHasErrors(['name', 'type' => 'Pick Store or Warehouse.']);

        $this->assertDatabaseCount('locations', 2);
    }

    public function test_a_location_is_switched_off_and_on_and_the_trail_says_so(): void
    {
        $warehouse = Location::firstWhere('name', 'Warehouse');

        $this->actingAs($this->admin)->patch("/locations/{$warehouse->id}/status")
            ->assertSessionHas('success', 'Location [Warehouse] is now inactive.');
        $this->assertFalse($warehouse->fresh()->is_active);

        $this->actingAs($this->admin)->patch("/locations/{$warehouse->id}/status")
            ->assertSessionHas('success', 'Location [Warehouse] is active again.');
        $this->assertTrue($warehouse->fresh()->is_active);

        $this->assertSame(
            [AuditAction::Deactivate->value, AuditAction::Activate->value],
            AuditTrail::orderBy('id')->pluck('action')->map(fn ($a) => $a instanceof AuditAction ? $a->value : $a)->all(),
        );
    }

    public function test_a_location_nothing_names_yet_may_be_deleted(): void
    {
        $extra = $this->unaudited(fn () => Location::factory()->create(['name' => 'Old Stockroom']));

        $this->actingAs($this->admin)
            ->delete("/locations/{$extra->id}")
            ->assertRedirect(route('locations.index'))
            ->assertSessionHas('success', 'Location [Old Stockroom] was deleted.');

        $this->assertModelMissing($extra);
    }

    public function test_creating_one_is_written_to_the_audit_trail(): void
    {
        $this->actingAs($this->admin)->post('/locations', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(1, AuditTrail::where('action', AuditAction::Create->value)->count());
    }

    /* ------------------------------------------------------------- Read */

    public function test_the_list_filters_by_type_and_status_and_searches_the_address(): void
    {
        $this->unaudited(fn () => Location::factory()->asStore()->inactive()->create(['name' => 'Closed Kiosk', 'address' => 'Ayala Center']));

        $this->actingAs($this->admin)->get('/locations?type=warehouse')
            ->assertInertia(fn ($page) => $page
                ->has('locations.data', 1)
                ->where('locations.data.0.name', 'Warehouse')
                ->where('locations.data.0.type_label', 'Warehouse')
                ->where('filters.type', 'warehouse')
                ->where('types', LocationType::options()));

        $this->actingAs($this->admin)->get('/locations?status=inactive')
            ->assertInertia(fn ($page) => $page->has('locations.data', 1)->where('locations.data.0.name', 'Closed Kiosk'));

        $this->actingAs($this->admin)->get('/locations?search=ayala')
            ->assertInertia(fn ($page) => $page->has('locations.data', 1)->where('locations.data.0.name', 'Closed Kiosk'));

        $this->actingAs($this->admin)->get('/locations?type=truck')->assertSessionHasErrors('type');
    }

    public function test_the_pick_list_offers_active_ones_and_keeps_an_old_choice(): void
    {
        $warehouse = Location::firstWhere('name', 'Warehouse');
        $this->unaudited(fn () => $warehouse->update(['is_active' => false]));

        $this->assertSame(['Main Store'], array_column(Location::options(), 'label'));
        $this->assertSame(['Main Store', 'Warehouse'], array_column(Location::options($warehouse->id), 'label'));
        $this->assertSame('store', Location::options()[0]['type']);
    }
}
