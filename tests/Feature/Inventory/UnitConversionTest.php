<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use App\Services\InventoryMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 15: a product is bought and sold in other units.
 *
 * A box of 100 screws sells as one line at the box price and takes 100
 * pieces off the shelf. The stock, the batches, the costs and the returns
 * all stay in pieces, and on hand still equals the sum of its batches.
 */
class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    protected Unit $pieces;

    protected Unit $box;

    protected Unit $pack;

    protected Unit $metre;

    protected Unit $roll;

    protected Product $screws;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->pieces = $this->unaudited(fn () => Unit::factory()->create(['code' => 'pcs', 'allows_decimals' => false]));
        $this->box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box', 'allows_decimals' => false]));
        $this->pack = $this->unaudited(fn () => Unit::factory()->create(['code' => 'pack', 'allows_decimals' => false]));
        $this->metre = $this->unaudited(fn () => Unit::factory()->create(['code' => 'm', 'allows_decimals' => true]));
        $this->roll = $this->unaudited(fn () => Unit::factory()->create(['code' => 'roll', 'allows_decimals' => true]));

        $this->screws = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'SCREW-6', 'unit_id' => $this->pieces->id,
            'cost' => '3.00', 'selling_price' => '5.00',
        ]));

        // A box of 100 at 450, cheaper than 100 loose pieces at 5.00.
        ProductUnit::create([
            'product_id' => $this->screws->id, 'unit_id' => $this->box->id,
            'factor' => 100, 'selling_price' => '450.00',
        ]);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function stock(Product $product, int|float|string $quantity, float $cost = 3): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], app(InventoryLedger::class)->reconcile(), 'A product has come apart from its batches.');
    }

    protected function customer(): Customer
    {
        return Customer::first() ?? $this->unaudited(fn () => Customer::factory()->walkIn()->create());
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function saleForm(array $lines, array $extra = []): array
    {
        return [
            'customer_id' => $this->customer()->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => $lines,
            ...$extra,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function buy(array $lines): Purchase
    {
        $supplier = Supplier::first() ?? $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => $lines,
        ])->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    /* ---------------------------------------------------------------- Sales */

    public function test_a_box_sells_at_the_box_price_and_takes_its_pieces_off_the_shelf(): void
    {
        $this->stock($this->screws, 250);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
        ]))->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $line = $sale->lines->first();

        $this->assertSame('450.00', $sale->total_amount);
        $this->assertSame((int) $this->box->id, (int) $line->unit_id);
        $this->assertSame(100, $line->unit_factor);
        $this->assertSame(1, $line->unit_quantity);
        $this->assertSame(100, $line->quantity);
        $this->assertSame('450.00', $line->unit_price);
        $this->assertSame('450.00', $line->line_total);
        // 100 pieces from a batch at 3.00.
        $this->assertSame('300.00', $line->line_cost);
        $this->assertSame(150, $this->screws->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();

        $this->actingAs($this->admin)->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page
                ->where('sale.lines.0.converted', true)
                ->where('sale.lines.0.unit_code', 'box')
                ->where('sale.lines.0.quantity_label', '1 box (100 pcs)'));
    }

    public function test_a_line_in_the_products_own_unit_reads_as_before(): void
    {
        $this->stock($this->screws, 10);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            // Naming the product's own unit is the same as naming none.
            ['product_id' => $this->screws->id, 'unit_id' => $this->pieces->id, 'quantity' => 4, 'unit_price' => '5.00'],
        ]))->assertSessionHasNoErrors();

        $line = Sale::latest('id')->firstOrFail()->lines->first();

        $this->assertNull($line->unit_id);
        $this->assertSame(1, $line->unit_factor);
        $this->assertSame(4, $line->unit_quantity);
        $this->assertSame(4, $line->quantity);
        $this->assertSame(6, $this->screws->fresh()->on_hand);
    }

    public function test_a_box_and_loose_pieces_are_weighed_together_against_the_shelf(): void
    {
        $this->stock($this->screws, 104);

        $lines = [
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
            ['product_id' => $this->screws->id, 'quantity' => 5, 'unit_price' => '5.00'],
        ];

        $this->actingAs($this->admin)->post('/sales', $this->saleForm($lines))
            ->assertSessionHasErrors(['lines.0.quantity' => 'Only 104 pcs of [SCREW-6] is on hand at Main Store, and this sale asks for 105 pcs.']);

        $this->stock($this->screws, 1);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm($lines))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->screws->fresh()->on_hand);
        $this->assertSame('475.00', Sale::latest('id')->first()->total_amount);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_unit_the_product_is_not_sold_in_is_refused(): void
    {
        $this->stock($this->screws, 500);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->pack->id, 'quantity' => 1, 'unit_price' => '50.00'],
        ]))->assertSessionHasErrors(['lines.0.unit_id' => '[SCREW-6] is not sold in that unit. Pick one of: pcs, box.']);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_part_of_a_box_is_refused_unless_it_comes_to_whole_pieces(): void
    {
        $this->stock($this->screws, 500);

        // A box is counted whole.
        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => '1.5', 'unit_price' => '450.00'],
        ]))->assertSessionHasErrors(['lines.0.quantity' => '[SCREW-6] is counted in whole numbers (box), so the quantity cannot have decimals.']);

        // A dozen may be split, but only into whole pieces.
        $dozen = $this->unaudited(fn () => Unit::factory()->create(['code' => 'doz', 'allows_decimals' => true]));
        ProductUnit::create(['product_id' => $this->screws->id, 'unit_id' => $dozen->id, 'factor' => 12]);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $dozen->id, 'quantity' => '1.1', 'unit_price' => '60.00'],
        ]))->assertSessionHasErrors(['lines.0.quantity' => '1.1 doz of [SCREW-6] is 13.2 pcs, and pcs is counted in whole numbers.']);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $dozen->id, 'quantity' => '1.25', 'unit_price' => '60.00'],
        ]))->assertSessionHasNoErrors();

        // 1.25 dozen is 15 pieces; the dozen price had no own figure, so
        // the form's 60.00 a dozen stands: 75.00.
        $this->assertSame(15, Sale::latest('id')->first()->lines->first()->quantity);
        $this->assertSame('75.00', Sale::latest('id')->first()->total_amount);
        $this->assertSame(485, $this->screws->fresh()->on_hand);
    }

    public function test_a_roll_of_wire_converts_to_metres_with_decimals(): void
    {
        $wire = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'WIRE-12', 'unit_id' => $this->metre->id, 'selling_price' => '18.35',
        ]));
        ProductUnit::create(['product_id' => $wire->id, 'unit_id' => $this->roll->id, 'factor' => '75.5']);
        $this->stock($wire, 200);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $wire->id, 'unit_id' => $this->roll->id, 'quantity' => '1.5', 'unit_price' => '1300.00'],
        ]))->assertSessionHasNoErrors();

        // 1.5 x 75.5 = 113.25 m.
        $this->assertSame(113.25, Sale::latest('id')->first()->lines->first()->quantity);
        $this->assertSame(86.75, $wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_same_form_sent_twice_is_one_sale(): void
    {
        $this->stock($this->screws, 300);

        $form = $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
        ], ['idempotency_key' => (string) Str::uuid()]);

        $this->actingAs($this->admin)->post('/sales', $form)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/sales', $form)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('info');

        $this->assertSame(1, Sale::count());
        $this->assertSame(200, $this->screws->fresh()->on_hand);
    }

    public function test_changing_a_box_later_leaves_old_documents_as_they_were(): void
    {
        $this->stock($this->screws, 300);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
        ]))->assertSessionHasNoErrors();

        ProductUnit::where('unit_id', $this->box->id)->update(['factor' => 50]);

        $line = Sale::latest('id')->first()->lines->first();

        $this->assertSame(100, $line->unit_factor);
        $this->assertSame(100, $line->returnable());
    }

    public function test_voiding_a_box_sale_puts_every_piece_back(): void
    {
        $this->stock($this->screws, 300);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 2, 'unit_price' => '450.00'],
        ]))->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->first();
        $this->assertSame(100, $this->screws->fresh()->on_hand);

        $this->actingAs($this->admin)->post("/sales/{$sale->id}/void", ['reason' => 'Rang up twice.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(300, $this->screws->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_pieces_from_a_box_come_back_at_their_share_of_the_box_price(): void
    {
        $this->stock($this->screws, 300);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
        ]))->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->first();
        $line = $sale->lines->first();

        // The return screen counts in pieces and says how the line was sold.
        $this->actingAs($this->admin)->get("/sales-returns/create?sale={$sale->id}")
            ->assertInertia(fn ($page) => $page
                ->where('sale.lines.0.returnable', 100)
                ->where('sale.lines.0.unit_price', '4.50')
                ->where('sale.lines.0.typed_as', '1 box (100 pcs)'));

        $this->actingAs($this->admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Changed their mind',
            'lines' => [['sale_line_id' => $line->id, 'quantity' => 30]],
        ])->assertSessionHasNoErrors();

        // 450.00 x 30 / 100.
        $this->assertSame('135.00', $line->salesReturnLines()->first()->line_refund);
        $this->assertSame(230, $this->screws->fresh()->on_hand);
        $this->assertSame(70, $line->fresh()->returnable());
        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------------------ Purchases */

    public function test_boxes_bought_put_their_pieces_on_the_shelf_at_the_piece_cost(): void
    {
        $purchase = $this->buy([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 5, 'unit_cost' => '400.00'],
        ]);

        $line = $purchase->lines->first();

        $this->assertSame('2000.00', $purchase->total_amount);
        $this->assertSame(5, $line->unit_quantity);
        $this->assertSame('400.00', $line->unit_price);
        $this->assertSame(500, $line->quantity);
        $this->assertSame('4.00', $line->unit_cost);
        $this->assertSame(500, $this->screws->fresh()->on_hand);
        $this->assertSame('4.00', InventoryLayer::where('product_id', $this->screws->id)->latest('id')->value('unit_cost'));
        // The product's cost follows the piece, not the box.
        $this->assertSame('4.00', $this->screws->fresh()->cost);
        $this->assertStockMatchesItsLayers();

        $this->actingAs($this->admin)->get("/purchases/{$purchase->id}")
            ->assertInertia(fn ($page) => $page
                ->where('purchase.lines.0.quantity_label', '5 box (500 pcs)')
                ->where('purchase.lines.0.unit_price', '400.00')
                ->where('purchase.lines.0.unit_cost', '4.00'));
    }

    public function test_a_box_returned_whole_gives_back_exactly_what_was_paid(): void
    {
        $dozen = $this->unaudited(fn () => Unit::factory()->create(['code' => 'doz']));
        ProductUnit::create(['product_id' => $this->screws->id, 'unit_id' => $dozen->id, 'factor' => 12]);

        // 100.00 for 12 is 8.333... a piece; the batch can only hold 8.33.
        $purchase = $this->buy([
            ['product_id' => $this->screws->id, 'unit_id' => $dozen->id, 'quantity' => 1, 'unit_cost' => '100.00'],
        ]);
        $line = $purchase->lines->first();

        $this->assertSame('8.33', $line->unit_cost);

        $return = fn (int $quantity) => $this->actingAs($this->admin)->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();

        $return(6);
        $this->assertSame('50.00', $line->purchaseReturnLines()->first()->line_cost);

        $return(6);
        $this->assertSame('100.00', number_format((float) $line->purchaseReturnLines()->sum('line_cost'), 2, '.', ''));

        // Nothing bought, nothing on the shelf, nothing left over.
        $this->assertSame('0.00', app(InventoryMetrics::class)->netPurchases(now()->startOfMonth(), now()));
        $this->assertSame(0, $this->screws->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_an_adjustment_counts_in_the_products_own_unit_only(): void
    {
        $this->actingAs($this->admin)->post('/adjustments', [
            'kind' => 'increase',
            'adjustment_date' => now()->toDateString(),
            'reason' => 'count',
            'lines' => [[
                'product_id' => $this->screws->id, 'unit_id' => $this->box->id,
                'quantity' => 3, 'unit_cost' => '3.00',
            ]],
        ])->assertSessionHasNoErrors();

        // The unit is not read: 3 is 3 pieces, not 3 boxes.
        $this->assertSame(3, $this->screws->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* -------------------------------------------------------------- Product */

    public function test_the_product_form_saves_changes_and_removes_other_units(): void
    {
        $category = $this->screws->category_id;

        $this->actingAs($this->admin)->post('/products', [
            'sku' => 'NAIL-4', 'name' => 'Common nail 4"', 'category_id' => $category,
            'unit_id' => $this->pieces->id, 'cost' => '1.00', 'selling_price' => '2.00',
            'reorder_level' => 0, 'is_active' => true,
            'units' => [
                ['unit_id' => $this->box->id, 'factor' => 200, 'selling_price' => '350.00'],
                ['unit_id' => $this->pack->id, 'factor' => 10, 'selling_price' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $nail = Product::where('sku', 'NAIL-4')->firstOrFail();
        $this->assertSame(2, $nail->otherUnits()->count());
        $this->assertNull($nail->otherUnits()->where('unit_id', $this->pack->id)->value('selling_price'));

        $named = fn () => AuditTrail::where('description', 'like', '%other units%')->count();
        $before = $named();

        $this->actingAs($this->admin)->put("/products/{$nail->id}", [
            'sku' => 'NAIL-4', 'name' => 'Common nail 4"', 'category_id' => $category,
            'unit_id' => $this->pieces->id, 'cost' => '1.00', 'selling_price' => '2.00',
            'reorder_level' => 0, 'is_active' => true,
            'units' => [['unit_id' => $this->box->id, 'factor' => 200, 'selling_price' => '340.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $nail->otherUnits()->count());
        $this->assertSame('340.00', $nail->otherUnits()->first()->selling_price);
        // One row names the change.
        $this->assertSame($before + 1, $named());
        $this->assertStringContainsString('other units', AuditTrail::latest('id')->first()->description);

        // The list row carries them for the edit dialog.
        $this->actingAs($this->admin)->get('/products')
            ->assertInertia(fn ($page) => $page->where('products.data.0.sku', 'NAIL-4')
                ->where('products.data.0.other_units.0.price', '340.00'));
    }

    public function test_the_product_form_refuses_odd_other_units(): void
    {
        $form = fn (array $units) => $this->actingAs($this->admin)->put("/products/{$this->screws->id}", [
            'sku' => 'SCREW-6', 'name' => $this->screws->name, 'category_id' => $this->screws->category_id,
            'unit_id' => $this->pieces->id, 'cost' => '3.00', 'selling_price' => '5.00',
            'reorder_level' => 0, 'is_active' => true, 'units' => $units,
        ]);

        $form([['unit_id' => $this->pieces->id, 'factor' => 10]])
            ->assertSessionHasErrors(['units.0.unit_id' => "This is already the product's own unit (pcs)."]);

        $form([['unit_id' => $this->box->id, 'factor' => 10], ['unit_id' => $this->box->id, 'factor' => 20]])
            ->assertSessionHasErrors(['units.1.unit_id']);

        $form([['unit_id' => $this->box->id, 'factor' => 1]])
            ->assertSessionHasErrors(['units.0.factor']);

        $form([['unit_id' => $this->box->id, 'factor' => '12.5']])
            ->assertSessionHasErrors(['units.0.factor' => 'pcs is counted in whole numbers, so this must hold a whole number of them.']);

        // Nothing changed: the box of 100 is still there.
        $this->assertSame(100, $this->screws->otherUnits()->first()->factor);
    }

    public function test_metres_stay_in_parts_while_a_roll_holds_part_of_one(): void
    {
        $wire = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'WIRE-12', 'unit_id' => $this->metre->id,
        ]));
        ProductUnit::create(['product_id' => $wire->id, 'unit_id' => $this->roll->id, 'factor' => '75.5']);

        $this->actingAs($this->admin)->put("/units/{$this->metre->id}", [
            'code' => 'm', 'name' => $this->metre->name, 'allows_decimals' => false, 'is_active' => true,
        ])->assertSessionHasErrors(['allows_decimals']);

        $this->assertTrue($this->metre->fresh()->allows_decimals);

        // A roll of a whole 75 m is no obstacle.
        ProductUnit::query()->update(['factor' => 75]);

        $this->actingAs($this->admin)->put("/units/{$this->metre->id}", [
            'code' => 'm', 'name' => $this->metre->name, 'allows_decimals' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->metre->fresh()->allows_decimals);
    }

    public function test_a_unit_in_use_as_another_unit_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)->delete("/units/{$this->box->id}")->assertForbidden();

        ProductUnit::query()->delete();
        $this->stock($this->screws, 10);
        ProductUnit::create(['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'factor' => 5]);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '20.00'],
        ]))->assertSessionHasNoErrors();

        // Taken off the product, it still names an old sale line.
        ProductUnit::query()->delete();

        $this->actingAs($this->admin)->delete("/units/{$this->box->id}")->assertForbidden();
        $this->assertNotNull($this->box->fresh());
    }

    public function test_the_forms_are_sent_each_products_units_and_prices(): void
    {
        $this->actingAs($this->admin)->get('/sales/create')
            ->assertInertia(fn ($page) => $page
                ->where('products.0.units.0.label', 'pcs')
                ->where('products.0.units.1.label', 'box')
                ->where('products.0.units.1.factor', 100)
                ->where('products.0.units.1.price', '450.00'));

        $this->actingAs($this->admin)->get('/purchases/create')
            ->assertInertia(fn ($page) => $page
                // A box costs 100 pieces at the piece cost.
                ->where('products.0.units.1.cost', '300.00'));
    }

    public function test_the_api_says_what_was_typed_and_what_moved(): void
    {
        $this->stock($this->screws, 300);

        $this->actingAs($this->admin)->post('/sales', $this->saleForm([
            ['product_id' => $this->screws->id, 'unit_id' => $this->box->id, 'quantity' => 1, 'unit_price' => '450.00'],
        ]))->assertSessionHasNoErrors();

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/sales/'.Sale::latest('id')->first()->id)
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', 100)
            ->assertJsonPath('data.lines.0.unit_quantity', 1)
            ->assertJsonPath('data.lines.0.unit_code', 'box');

        $this->getJson("/api/v1/products/{$this->screws->id}")
            ->assertOk()
            ->assertJsonPath('data.other_units.0.unit.code', 'box')
            ->assertJsonPath('data.other_units.0.selling_price', '450.00');
    }
}
