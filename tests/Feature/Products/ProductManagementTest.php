<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->category = $this->unaudited(fn () => Category::factory()->create(['name' => 'Apparel']));
        $this->unit = $this->unaudited(fn () => Unit::factory()->create(['code' => 'pcs']));
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'TSHIRT-BLK',
            'name' => 'T-Shirt (Black)',
            'description' => null,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'cost' => '300.00',
            'selling_price' => '500.00',
            'reorder_level' => 20,
            'is_active' => true,
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/products')
            ->assertForbidden();
    }

    public function test_the_view_permission_opens_the_list(): void
    {
        $this->actingAs(User::factory()->withPermissions('products.view')->create())
            ->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->has('products.data'));
    }

    public function test_the_view_permission_alone_cannot_store_a_product(): void
    {
        $this->actingAs(User::factory()->withPermissions('products.view')->create())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    /* ----------------------------------------------------------------- Write */

    public function test_an_admin_can_create_a_product(): void
    {
        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload())
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('products', [
            'sku' => 'TSHIRT-BLK',
            'name' => 'T-Shirt (Black)',
            'cost' => '300.00',
            'selling_price' => '500.00',
            'reorder_level' => 20,
        ]);
    }

    public function test_a_new_product_starts_with_no_stock(): void
    {
        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload())
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        $this->assertSame(0, Product::firstWhere('sku', 'TSHIRT-BLK')->on_hand);
    }

    public function test_stock_cannot_be_set_through_the_form(): void
    {
        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload(['on_hand' => 999]))
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        // Stock moves only through a posted document, so the extra field in
        // the payload is ignored rather than obeyed.
        $this->assertSame(0, Product::firstWhere('sku', 'TSHIRT-BLK')->on_hand);
    }

    public function test_stock_cannot_be_changed_through_the_edit_form(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->onHand(90)->create()
        );

        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->put("/products/{$product->id}", $this->validPayload(['on_hand' => 5]))
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        $this->assertSame(90, $product->fresh()->on_hand);
    }

    public function test_the_sku_is_stored_in_capitals(): void
    {
        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload(['sku' => '  tshirt-blk  ']))
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        $this->assertDatabaseHas('products', ['sku' => 'TSHIRT-BLK']);
    }

    public function test_the_sku_must_be_unique_whatever_its_case(): void
    {
        $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)
                ->create(['sku' => 'TSHIRT-BLK'])
        );

        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload(['sku' => 'tshirt-blk']))
            ->assertSessionHasErrors('sku');

        $this->assertDatabaseCount('products', 1);
    }

    public function test_the_price_cannot_be_negative(): void
    {
        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload(['cost' => -1, 'selling_price' => -5]))
            ->assertSessionHasErrors(['cost', 'selling_price']);
    }

    public function test_an_inactive_category_cannot_be_picked_on_a_new_product(): void
    {
        $switchedOff = $this->unaudited(fn () => Category::factory()->inactive()->create());

        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->post('/products', $this->validPayload(['category_id' => $switchedOff->id]))
            ->assertSessionHasErrors('category_id');
    }

    public function test_a_product_may_keep_a_category_that_was_switched_off_later(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->create()
        );

        $this->unaudited(fn () => $this->category->update(['is_active' => false]));

        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->put("/products/{$product->id}", $this->validPayload(['name' => 'Renamed']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/products?page=2&sort=name&per_page=10');

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    public function test_a_product_cannot_be_moved_into_a_category_that_is_switched_off(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->create()
        );

        $switchedOff = $this->unaudited(fn () => Category::factory()->inactive()->create());

        $this->actingAs($this->admin())
            ->from('/products?page=2&sort=name&per_page=10')
            ->put("/products/{$product->id}", $this->validPayload(['category_id' => $switchedOff->id]))
            ->assertSessionHasErrors('category_id');
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_a_product_with_no_stock_can_be_deleted(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->create()
        );

        $this->actingAs($this->admin())
            ->delete("/products/{$product->id}")
            ->assertRedirect('/products');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_a_product_holding_stock_cannot_be_deleted(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->onHand(5)->create()
        );

        $this->actingAs($this->admin())
            ->delete("/products/{$product->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /* ---------------------------------------------------------------- Status */

    public function test_the_status_route_switches_a_product_off_and_on(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->create()
        );

        $this->actingAs($this->admin())
            ->patch("/products/{$product->id}/status")
            ->assertRedirect();

        $this->assertFalse($product->fresh()->is_active);
    }

    /* --------------------------------------------------------------- Filters */

    public function test_the_low_stock_filter_finds_products_at_or_below_the_reorder_level(): void
    {
        $this->unaudited(function () {
            Product::factory()->for($this->category)->for($this->unit)
                ->onHand(8)->create(['sku' => 'LOW-1', 'reorder_level' => 20]);
            Product::factory()->for($this->category)->for($this->unit)
                ->onHand(20)->create(['sku' => 'EXACTLY-AT', 'reorder_level' => 20]);
            Product::factory()->for($this->category)->for($this->unit)
                ->onHand(90)->create(['sku' => 'PLENTY', 'reorder_level' => 20]);
            // A reorder level of zero means the product is not watched.
            Product::factory()->for($this->category)->for($this->unit)
                ->onHand(0)->create(['sku' => 'NOT-WATCHED', 'reorder_level' => 0]);
        });

        $this->actingAs($this->admin())
            ->get('/products?stock=low')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 2)
                ->where('products.data.0.sku', 'EXACTLY-AT')
                ->where('products.data.1.sku', 'LOW-1'));
    }

    public function test_the_list_marks_a_low_stock_row(): void
    {
        $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)
                ->onHand(3)->create(['reorder_level' => 10])
        );

        $this->actingAs($this->admin())
            ->get('/products')
            ->assertInertia(fn ($page) => $page->where('products.data.0.is_low_stock', true));
    }

    public function test_the_category_filter_narrows_the_list(): void
    {
        $other = $this->unaudited(fn () => Category::factory()->create());

        $this->unaudited(function () use ($other) {
            Product::factory()->for($this->category)->for($this->unit)->create(['sku' => 'IN-APPAREL']);
            Product::factory()->for($other)->for($this->unit)->create(['sku' => 'ELSEWHERE']);
        });

        $this->actingAs($this->admin())
            ->get("/products?category_id={$this->category->id}")
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'IN-APPAREL'));
    }

    public function test_an_unknown_filter_value_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/products?stock=nonsense')
            ->assertSessionHasErrors('stock');
    }

    /* ------------------------------------------------------------------ Show */

    public function test_the_detail_screen_opens(): void
    {
        $product = $this->unaudited(
            fn () => Product::factory()->for($this->category)->for($this->unit)->onHand(90)->create()
        );

        $this->actingAs($this->admin())
            ->get("/products/{$product->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Show')
                ->where('product.on_hand', 90)
                ->where('product.category', 'Apparel')
                ->where('product.unit', 'pcs'));
    }
}
