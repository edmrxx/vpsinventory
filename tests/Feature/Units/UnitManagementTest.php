<?php

namespace Tests\Feature\Units;

use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnitManagementTest extends TestCase
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
            'code' => 'pcs',
            'name' => 'Pieces',
            'is_active' => true,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/units')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('units.view')->create())
            ->get('/units')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Units/Index')
                ->has('units.data'));
    }

    public function test_a_unit_has_no_detail_screen(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());

        // The address answers PUT and DELETE, so a GET is refused as the
        // wrong method rather than as a missing page.
        $this->actingAs($this->admin())
            ->get("/units/{$unit->id}")
            ->assertMethodNotAllowed();
    }

    /* ----------------------------------------------------------------- Write */

    public function test_an_admin_can_create_a_unit(): void
    {
        $this->actingAs($this->admin())
            ->from('/units?page=2&sort=name&per_page=10')
            ->post('/units', $this->validPayload())
            ->assertRedirect('/units?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('units', ['code' => 'pcs', 'is_active' => true]);
    }

    public function test_the_code_must_be_unique_whatever_its_case(): void
    {
        $this->unaudited(fn () => Unit::factory()->create(['code' => 'pcs']));

        $this->actingAs($this->admin())
            ->from('/units?page=2&sort=name&per_page=10')
            ->post('/units', $this->validPayload(['code' => 'PCS']))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('units', 1);
    }

    public function test_an_admin_can_update_a_unit(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());

        $this->actingAs($this->admin())
            ->from('/units?page=2&sort=name&per_page=10')
            ->put("/units/{$unit->id}", $this->validPayload(['code' => 'box', 'name' => 'Box']))
            ->assertRedirect('/units?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'code' => 'box']);
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_an_empty_unit_can_be_deleted(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());

        $this->actingAs($this->admin())
            ->delete("/units/{$unit->id}")
            ->assertRedirect('/units');

        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
    }

    public function test_a_unit_used_by_products_cannot_be_deleted(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());
        $this->unaudited(fn () => Product::factory()->for($unit)->create());

        $this->actingAs($this->admin())
            ->delete("/units/{$unit->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('units', ['id' => $unit->id]);
    }

    public function test_a_unit_used_by_products_can_still_be_switched_off(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());
        $this->unaudited(fn () => Product::factory()->for($unit)->create());

        $this->actingAs($this->admin())
            ->patch("/units/{$unit->id}/status")
            ->assertRedirect();

        $this->assertFalse($unit->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ List */

    public function test_the_list_carries_the_product_count_of_each_row(): void
    {
        $unit = $this->unaudited(fn () => Unit::factory()->create());
        $this->unaudited(fn () => Product::factory()->count(2)->for($unit)->create());

        $this->actingAs($this->admin())
            ->get('/units')
            ->assertInertia(fn ($page) => $page
                ->where('units.data.0.products_count', 2)
                // The count is what stops the delete, so the row says so too.
                ->where('units.data.0.can.delete', false));
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->unaudited(function () {
            Unit::factory()->create(['code' => 'aaa', 'name' => 'Active One']);
            Unit::factory()->inactive()->create(['code' => 'zzz', 'name' => 'Switched Off']);
        });

        $this->actingAs($this->admin())
            ->get('/units?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('units.data', 1)
                ->where('units.data.0.name', 'Switched Off'));
    }
}
