<?php

namespace Tests\Feature\Categories;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
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
            'name' => 'Apparel',
            'description' => 'Shirts, trousers and the like.',
            'is_active' => true,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/categories')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('categories.view')->create())
            ->get('/categories')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Categories/Index')
                ->has('categories.data'));
    }

    public function test_a_category_has_no_detail_screen(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());

        // The address answers PUT and DELETE, so a GET is refused as the
        // wrong method rather than as a missing page.
        $this->actingAs($this->admin())
            ->get("/categories/{$category->id}")
            ->assertMethodNotAllowed();
    }

    /* ----------------------------------------------------------------- Write */

    public function test_an_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin())
            ->from('/categories?page=2&sort=name&per_page=10')
            ->post('/categories', $this->validPayload())
            ->assertRedirect('/categories?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('categories', ['name' => 'Apparel', 'is_active' => true]);
    }

    public function test_the_name_must_be_unique(): void
    {
        $this->unaudited(fn () => Category::factory()->create(['name' => 'Apparel']));

        $this->actingAs($this->admin())
            ->from('/categories?page=2&sort=name&per_page=10')
            ->post('/categories', $this->validPayload())
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_an_admin_can_update_a_category(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());

        $this->actingAs($this->admin())
            ->from('/categories?page=2&sort=name&per_page=10')
            ->put("/categories/{$category->id}", $this->validPayload(['name' => 'Accessories']))
            ->assertRedirect('/categories?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Accessories']);
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());

        $this->actingAs($this->admin())
            ->delete("/categories/{$category->id}")
            ->assertRedirect('/categories');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_category_holding_products_cannot_be_deleted(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());
        $this->unaudited(fn () => Product::factory()->for($category)->create());

        $this->actingAs($this->admin())
            ->delete("/categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_a_category_holding_products_can_still_be_switched_off(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());
        $this->unaudited(fn () => Product::factory()->for($category)->create());

        $this->actingAs($this->admin())
            ->patch("/categories/{$category->id}/status")
            ->assertRedirect();

        $this->assertFalse($category->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ List */

    public function test_the_list_carries_the_product_count_of_each_row(): void
    {
        $category = $this->unaudited(fn () => Category::factory()->create());
        $this->unaudited(fn () => Product::factory()->count(2)->for($category)->create());

        $this->actingAs($this->admin())
            ->get('/categories')
            ->assertInertia(fn ($page) => $page
                ->where('categories.data.0.products_count', 2)
                // The count is what stops the delete, so the row says so too.
                ->where('categories.data.0.can.delete', false));
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->unaudited(function () {
            Category::factory()->create(['name' => 'Active One']);
            Category::factory()->inactive()->create(['name' => 'Switched Off']);
        });

        $this->actingAs($this->admin())
            ->get('/categories?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('categories.data', 1)
                ->where('categories.data.0.name', 'Switched Off'));
    }
}
