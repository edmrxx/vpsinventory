<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The master forms open in a dialog on the list screen.
 *
 * Two things have to hold for that to work, and both are easy to break
 * later: the create and edit pages must be gone, and every list row must
 * carry the values its form needs, because the dialog opens with no request
 * of its own.
 */
class MasterFormModalTest extends TestCase
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

    /* ----------------------------------------------------------- No pages */

    public static function masterModules(): array
    {
        return [
            'products' => ['products'],
            'categories' => ['categories'],
            'units' => ['units'],
            'suppliers' => ['suppliers'],
            'customers' => ['customers'],
        ];
    }

    #[DataProvider('masterModules')]
    public function test_a_master_module_has_no_create_or_edit_page(string $module): void
    {
        $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName());

        $this->assertNotContains(
            "{$module}.create",
            $names,
            "[{$module}] still has a create page. Both forms open in a dialog."
        );

        $this->assertNotContains(
            "{$module}.edit",
            $names,
            "[{$module}] still has an edit page. Both forms open in a dialog."
        );
    }

    public function test_the_create_address_is_not_served(): void
    {
        $this->actingAs($this->admin())
            ->get('/suppliers/create')
            // There is no create page, so this now reads as a supplier whose
            // id happens to be the word "create".
            ->assertNotFound();
    }

    /* ------------------------------------------- The row feeds the dialog */

    public function test_a_product_row_carries_everything_its_form_needs(): void
    {
        $this->unaudited(fn () => Product::factory()->create(['description' => 'A note.']));

        $this->actingAs($this->admin())
            ->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.data.0.description')
                ->has('products.data.0.category_id')
                ->has('products.data.0.unit_id')
                ->where('products.data.0.description', 'A note.'));
    }

    public function test_a_supplier_row_carries_everything_its_form_needs(): void
    {
        $this->unaudited(fn () => Supplier::factory()->create(['notes' => 'Pays on time.']));

        $this->actingAs($this->admin())
            ->get('/suppliers')
            ->assertInertia(fn ($page) => $page
                ->has('suppliers.data.0.tin')
                ->has('suppliers.data.0.address')
                ->where('suppliers.data.0.notes', 'Pays on time.'));
    }

    public function test_a_customer_row_carries_everything_its_form_needs(): void
    {
        $this->unaudited(fn () => Customer::factory()->create(['notes' => 'Net 30.']));

        $this->actingAs($this->admin())
            ->get('/customers')
            ->assertInertia(fn ($page) => $page
                ->has('customers.data.0.tin')
                ->has('customers.data.0.address')
                ->where('customers.data.0.notes', 'Net 30.'));
    }

    /* ------------------------------------------------- The product pickers */

    public function test_the_product_options_say_which_rows_are_still_active(): void
    {
        $this->unaudited(function () {
            Category::factory()->create(['name' => 'Live']);
            Category::factory()->inactive()->create(['name' => 'Retired']);
            Unit::factory()->create(['code' => 'aaa']);
        });

        // The filter bar needs every row, including the retired one, because
        // older products still point at it. The screen tells the two apart
        // with is_active and builds the form list from that.
        $this->actingAs($this->admin())
            ->get('/products')
            ->assertInertia(fn ($page) => $page
                ->has('options.categories', 2)
                ->where('options.categories.0.label', 'Live')
                ->where('options.categories.0.is_active', true)
                ->where('options.categories.1.label', 'Retired')
                ->where('options.categories.1.is_active', false)
                ->has('options.units.0.name'));
    }

    public function test_the_product_detail_screen_carries_the_pickers_too(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create());

        // Editing from the detail screen opens the same dialog.
        $this->actingAs($this->admin())
            ->get("/products/{$product->id}")
            ->assertInertia(fn ($page) => $page
                ->has('options.categories')
                ->has('options.units')
                ->has('product.category_id')
                ->has('product.unit_id')
                ->has('product.description'));
    }

    /* ------------------------------------------------ Saving keeps the list */

    /**
     * `back()` reads the Referer header, so the browser has to send the whole
     * address and not just the origin. Three otherwise sensible values —
     * no-referrer, origin and strict-origin — would strip the path and send
     * every save to the dashboard instead.
     */
    public function test_the_referrer_policy_still_lets_a_save_find_its_way_back(): void
    {
        $policy = $this->actingAs($this->admin())
            ->get('/suppliers')
            ->headers->get('Referrer-Policy');

        $this->assertContains($policy, [
            'strict-origin-when-cross-origin',
            'same-origin',
            'origin-when-cross-origin',
            'no-referrer-when-downgrade',
        ], "Referrer-Policy is [{$policy}]. That strips the path from the Referer, so "
            .'back() cannot return to the list and every save lands on the dashboard.');
    }

    public function test_saving_returns_to_the_list_the_user_was_looking_at(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $listing = '/suppliers?page=3&sort=name&direction=desc&status=active';

        $this->actingAs($this->admin())
            ->from($listing)
            ->put("/suppliers/{$supplier->id}", [
                'code' => $supplier->code,
                'name' => 'Renamed Co.',
                'contact_person' => null,
                'email' => null,
                'phone' => null,
                'tin' => null,
                'address' => null,
                'notes' => null,
                'is_active' => true,
            ])
            ->assertRedirect($listing);

        $this->assertSame('Renamed Co.', $supplier->fresh()->name);
    }

    public function test_deleting_still_goes_back_to_the_top_of_the_list(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        // Delete is also reached from the detail screen, where going "back"
        // would land on the record that has just gone.
        $this->actingAs($this->admin())
            ->from("/suppliers/{$supplier->id}")
            ->delete("/suppliers/{$supplier->id}")
            ->assertRedirect('/suppliers');
    }
}
