<?php

namespace Tests\Feature\Products;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 30: a product's brand, model or size, barcode and preferred
 * supplier. All four are optional; the barcode is one per product, and the
 * supplier is a suggestion that a deleted supplier only clears.
 */
class ProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Unit $unit;

    protected Supplier $supplier;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->category = $this->unaudited(fn () => Category::factory()->create(['name' => 'Paint']));
        $this->unit = $this->unaudited(fn () => Unit::factory()->create(['code' => 'can']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Metro Paints']));
        $this->admin = User::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'PNT-LTX-4L',
            'barcode' => '4800016123456',
            'name' => 'Permacoat Latex',
            'brand' => 'Boysen',
            'model' => '4 L',
            'description' => null,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'preferred_supplier_id' => $this->supplier->id,
            'cost' => '600.00',
            'selling_price' => '850.00',
            'reorder_level' => 5,
            'is_active' => true,
        ], $overrides);
    }

    protected function product(array $overrides = []): Product
    {
        return $this->unaudited(fn () => Product::factory()->create(array_merge([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ], $overrides)));
    }

    /* ------------------------------------------------------------- Saving */

    public function test_a_product_is_saved_with_its_brand_model_barcode_and_supplier(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'sku' => 'PNT-LTX-4L',
            'barcode' => '4800016123456',
            'brand' => 'Boysen',
            'model' => '4 L',
            'preferred_supplier_id' => $this->supplier->id,
        ]);
    }

    public function test_all_four_are_optional_and_blank_is_stored_as_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', $this->payload([
                'barcode' => '  ', 'brand' => '', 'model' => '', 'preferred_supplier_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::firstWhere('sku', 'PNT-LTX-4L');

        $this->assertNull($product->barcode);
        $this->assertNull($product->brand);
        $this->assertNull($product->model);
        $this->assertNull($product->preferred_supplier_id);
    }

    public function test_an_edit_that_leaves_the_new_fields_out_keeps_them(): void
    {
        $product = $this->product(['brand' => 'Boysen', 'barcode' => '111', 'preferred_supplier_id' => $this->supplier->id]);

        $this->actingAs($this->admin)
            ->put("/products/{$product->id}", collect($this->payload(['sku' => $product->sku]))
                ->except(['barcode', 'brand', 'model', 'preferred_supplier_id'])
                ->all())
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Boysen', $product->brand);
        $this->assertSame('111', $product->barcode);
        $this->assertSame($this->supplier->id, $product->preferred_supplier_id);
    }

    /* ------------------------------------------------------------ Barcode */

    public function test_two_products_cannot_share_a_barcode(): void
    {
        $this->product(['barcode' => '4800016123456']);

        $this->actingAs($this->admin)
            ->post('/products', $this->payload())
            ->assertSessionHasErrors(['barcode' => 'Another product already has this barcode.']);
    }

    public function test_the_barcode_is_kept_in_capitals_so_case_never_makes_two(): void
    {
        $this->product(['barcode' => 'AB-100']);

        $this->actingAs($this->admin)
            ->post('/products', $this->payload(['barcode' => ' ab-100 ']))
            ->assertSessionHasErrors('barcode');

        $this->actingAs($this->admin)
            ->post('/products', $this->payload(['barcode' => 'cd-200']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['sku' => 'PNT-LTX-4L', 'barcode' => 'CD-200']);
    }

    public function test_a_barcode_with_a_space_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/products', $this->payload(['barcode' => '4800 016']))
            ->assertSessionHasErrors(['barcode' => 'The barcode may not hold spaces or special characters.']);
    }

    public function test_a_product_keeps_its_own_barcode_on_an_edit(): void
    {
        $product = $this->product(['barcode' => '4800016123456']);

        $this->actingAs($this->admin)
            ->put("/products/{$product->id}", $this->payload(['sku' => $product->sku]))
            ->assertSessionHasNoErrors();
    }

    public function test_any_number_of_products_may_have_no_barcode(): void
    {
        $this->product(['barcode' => null]);
        $this->product(['barcode' => null]);

        $this->actingAs($this->admin)
            ->post('/products', $this->payload(['barcode' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Product::whereNull('barcode')->count());
    }

    /* -------------------------------------------------- Preferred supplier */

    public function test_a_new_product_cannot_name_a_supplier_that_is_switched_off(): void
    {
        $this->unaudited(fn () => $this->supplier->update(['is_active' => false]));

        $this->actingAs($this->admin)
            ->post('/products', $this->payload())
            ->assertSessionHasErrors(['preferred_supplier_id' => 'Pick a supplier that is still active.']);
    }

    public function test_an_edit_keeps_a_supplier_switched_off_since_but_cannot_move_onto_one(): void
    {
        $product = $this->product(['preferred_supplier_id' => $this->supplier->id]);
        $this->unaudited(fn () => $this->supplier->update(['is_active' => false]));

        $this->actingAs($this->admin)
            ->put("/products/{$product->id}", $this->payload(['sku' => $product->sku, 'barcode' => null]))
            ->assertSessionHasNoErrors();

        $other = $this->product();

        $this->actingAs($this->admin)
            ->put("/products/{$other->id}", $this->payload(['sku' => $other->sku, 'barcode' => null]))
            ->assertSessionHasErrors(['preferred_supplier_id' => 'Pick a supplier that is still active.']);
    }

    public function test_deleting_the_supplier_only_clears_the_preference(): void
    {
        $product = $this->product(['preferred_supplier_id' => $this->supplier->id]);

        $this->actingAs($this->admin)
            ->delete("/suppliers/{$this->supplier->id}")
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($this->supplier);
        $this->assertNull($product->fresh()->preferred_supplier_id);
    }

    /* ------------------------------------------------- Finding a product */

    public function test_the_list_finds_a_product_by_barcode_brand_or_model(): void
    {
        $this->product(['sku' => 'A-1', 'name' => 'Latex', 'brand' => 'Boysen', 'model' => '4 L', 'barcode' => '4800016']);
        $this->product(['sku' => 'B-1', 'name' => 'Enamel', 'brand' => 'Davies', 'model' => '1 L', 'barcode' => '4800099']);

        foreach (['4800016', 'boysen', '4 L'] as $search) {
            $this->actingAs($this->admin)
                ->get('/products?search='.urlencode($search))
                ->assertInertia(fn ($page) => $page
                    ->has('products.data', 1)
                    ->where('products.data.0.sku', 'A-1'));
        }
    }

    public function test_the_list_filters_by_brand_and_by_preferred_supplier(): void
    {
        $this->product(['sku' => 'A-1', 'brand' => 'Boysen', 'preferred_supplier_id' => $this->supplier->id]);
        $this->product(['sku' => 'B-1', 'brand' => 'Davies']);

        $this->actingAs($this->admin)
            ->get('/products?brand=Boysen')
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'A-1')
                ->where('products.data.0.preferred_supplier', 'Metro Paints')
                ->where('filters.brand', 'Boysen')
                ->where('options.brands', [
                    ['value' => 'Boysen', 'label' => 'Boysen'],
                    ['value' => 'Davies', 'label' => 'Davies'],
                ]));

        $this->actingAs($this->admin)
            ->get("/products?supplier_id={$this->supplier->id}")
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'A-1'));
    }

    public function test_the_list_sorts_by_brand(): void
    {
        $this->product(['sku' => 'A-1', 'brand' => 'Davies']);
        $this->product(['sku' => 'B-1', 'brand' => 'Boysen']);

        $this->actingAs($this->admin)
            ->get('/products?sort=brand&direction=asc')
            ->assertInertia(fn ($page) => $page->where('products.data.0.sku', 'B-1'));
    }

    public function test_every_product_picker_can_search_by_brand_model_and_barcode(): void
    {
        $this->product(['sku' => 'A-1', 'brand' => 'Boysen', 'model' => '4 L', 'barcode' => '4800016', 'preferred_supplier_id' => $this->supplier->id]);

        $selling = Product::sellingOptions()[0];
        $buying = Product::buyingOptions()[0];

        $this->assertSame('Boysen 4 L 4800016', $selling['keywords']);
        $this->assertSame('4800016', $selling['barcode']);
        $this->assertSame('Boysen 4 L 4800016', $buying['keywords']);
        $this->assertSame($this->supplier->id, $buying['preferred_supplier_id']);
    }

    public function test_the_till_receives_the_barcode_brand_and_model(): void
    {
        $this->product(['sku' => 'A-1', 'brand' => 'Boysen', 'model' => '4 L', 'barcode' => '4800016']);
        $cashier = $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.barcode', '4800016')
                ->where('products.0.brand', 'Boysen')
                ->where('products.0.model', '4 L'));
    }

    public function test_a_new_purchase_request_knows_each_products_usual_supplier(): void
    {
        $this->product(['sku' => 'A-1', 'preferred_supplier_id' => $this->supplier->id]);

        $this->actingAs($this->admin)
            ->get('/purchase-requests/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.0.preferred_supplier_id', $this->supplier->id));
    }

    public function test_the_product_page_shows_all_four(): void
    {
        $product = $this->product(['brand' => 'Boysen', 'model' => '4 L', 'barcode' => '4800016', 'preferred_supplier_id' => $this->supplier->id]);

        $this->actingAs($this->admin)
            ->get("/products/{$product->id}")
            ->assertInertia(fn ($page) => $page
                ->where('product.brand', 'Boysen')
                ->where('product.model', '4 L')
                ->where('product.barcode', '4800016')
                ->where('product.preferred_supplier', 'Metro Paints')
                ->where('product.preferred_supplier_active', true));
    }

    /* ---------------------------------------------------------------- API */

    public function test_the_api_sends_the_four_and_finds_a_product_by_its_exact_barcode(): void
    {
        $this->product(['sku' => 'A-1', 'brand' => 'Boysen', 'model' => '4 L', 'barcode' => 'AB-4800016', 'preferred_supplier_id' => $this->supplier->id]);
        $this->product(['sku' => 'B-1', 'barcode' => 'AB-48000']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/products?barcode=ab-4800016')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'A-1')
            ->assertJsonPath('data.0.barcode', 'AB-4800016')
            ->assertJsonPath('data.0.brand', 'Boysen')
            ->assertJsonPath('data.0.model', '4 L')
            ->assertJsonPath('data.0.preferred_supplier.name', 'Metro Paints');
    }

    /* -------------------------------------------------------------- Trail */

    public function test_changing_the_brand_is_written_to_the_audit_trail(): void
    {
        $product = $this->product(['brand' => 'Boysen']);

        $this->actingAs($this->admin)
            ->put("/products/{$product->id}", $this->payload(['sku' => $product->sku, 'brand' => 'Davies']))
            ->assertSessionHasNoErrors();

        $row = AuditTrail::where('action', AuditAction::Update->value)->sole();

        $this->assertSame('Boysen', $row->properties['changed']['brand']['from']);
        $this->assertSame('Davies', $row->properties['changed']['brand']['to']);
    }
}
