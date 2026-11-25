<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\InventoryException;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14: a quantity may carry three decimals, where its unit allows it.
 *
 * 2.5 m of wire and 0.125 kg of nails are sold, bought, returned, voided
 * and adjusted the same way 10 pieces always were, and on hand still equals
 * the sum of its batches to the thousandth. A unit counted whole still
 * refuses 2.5, on every form and in the ledger.
 */
class DecimalQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected Unit $metre;

    protected Unit $pieces;

    protected Product $wire;

    protected Product $hammer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->metre = $this->unaudited(fn () => Unit::factory()->create(['code' => 'm', 'allows_decimals' => true]));
        $this->pieces = $this->unaudited(fn () => Unit::factory()->create(['code' => 'pcs', 'allows_decimals' => false]));

        $this->wire = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'WIRE-THHN-12', 'unit_id' => $this->metre->id, 'selling_price' => '18.35',
        ]));
        $this->hammer = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'HAMMER-16', 'unit_id' => $this->pieces->id, 'selling_price' => '450.00',
        ]));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function ledger(): InventoryLedger
    {
        return app(InventoryLedger::class);
    }

    protected function stock(Product $product, int|float|string $quantity, float $cost = 10): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], $this->ledger()->reconcile(), 'A product has come apart from its batches.');
    }

    protected function sell(Product $product, int|float|string $quantity, string $price): Sale
    {
        $customer = Customer::first() ?? $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------- The ledger */

    public function test_three_tenths_add_up_to_exactly_three_tenths(): void
    {
        // The classic float trap: 0.1 + 0.1 + 0.1 is not 0.3 in floats.
        $this->stock($this->wire, '0.1');
        $this->stock($this->wire, '0.1');
        $this->stock($this->wire, '0.1');

        $this->assertSame(0.3, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();

        $this->sell($this->wire, '0.3', '18.35');

        $this->assertSame(0, $this->wire->fresh()->on_hand);
        $this->assertSame(0, (int) InventoryLayer::where('product_id', $this->wire->id)->where('quantity_remaining', '>', 0)->count());
        $this->assertStockMatchesItsLayers();
    }

    public function test_fifo_takes_parts_of_batches_at_their_own_cost(): void
    {
        $this->stock($this->wire, '1.5', 10);
        $this->stock($this->wire, '2.25', 20);

        $sale = $this->sell($this->wire, 2, '18.35');

        // 1.5 m at 10 and 0.5 m at 20.
        $this->assertSame('25.00', $sale->lines->first()->fresh()->line_cost);
        $this->assertSame([0, 1.75], InventoryLayer::where('product_id', $this->wire->id)
            ->orderBy('id')->get()->pluck('quantity_remaining')->all());
        $this->assertSame(1.75, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_part_of_a_whole_unit_is_refused_by_the_ledger(): void
    {
        $this->stock($this->hammer, 5);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('counted in whole numbers');

        $this->stock($this->hammer, '2.5');
    }

    public function test_a_whole_quantity_still_reads_as_a_whole_number(): void
    {
        $this->stock($this->hammer, 10);

        // 10, not "10.000" or 10.0: every screen, payload and test that read
        // a whole number before reads the same now.
        $this->assertSame(10, $this->hammer->fresh()->on_hand);
        // Past the cast, the column itself holds the same number: "10.000"
        // on MySQL, 10 on SQLite.
        $this->assertEquals(10, DB::table('products')->where('id', $this->hammer->id)->value('on_hand'));
    }

    /* ------------------------------------------------------------ The sale */

    public function test_a_line_is_priced_to_the_centavo_half_up(): void
    {
        $this->stock($this->wire, 10);

        $sale = $this->sell($this->wire, '2.5', '18.35');
        $line = $sale->lines->first();

        // 2.5 x 18.35 = 45.875, which is 45.88.
        $this->assertSame(2.5, $line->quantity);
        $this->assertSame('45.88', $line->line_total);
        $this->assertSame('45.88', $sale->subtotal);
        $this->assertSame('45.88', $sale->total_amount);
        $this->assertSame(7.5, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_sale_form_refuses_part_of_a_piece_against_its_line(): void
    {
        $this->stock($this->hammer, 5);
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->hammer->id, 'quantity' => '2.5', 'unit_price' => '450.00']],
        ])->assertSessionHasErrors(['lines.0.quantity']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(5, $this->hammer->fresh()->on_hand);
    }

    public function test_a_fourth_decimal_is_refused(): void
    {
        $this->stock($this->wire, 10);
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->wire->id, 'quantity' => '1.0005', 'unit_price' => '18.35']],
        ])->assertSessionHasErrors(['lines.0.quantity']);
    }

    public function test_the_sale_form_weighs_parts_against_what_is_on_hand(): void
    {
        $this->stock($this->wire, '2.5');
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [
                ['product_id' => $this->wire->id, 'quantity' => '1.25', 'unit_price' => '18.35'],
                ['product_id' => $this->wire->id, 'quantity' => '1.251', 'unit_price' => '18.35'],
            ],
        ])->assertSessionHasErrors(['lines.0.quantity' => 'Only 2.5 m of [WIRE-THHN-12] is on hand at Main Store, and this sale asks for 2.501 m.']);
    }

    public function test_voiding_a_part_sale_puts_the_part_back(): void
    {
        $this->stock($this->wire, 10);
        $sale = $this->sell($this->wire, '2.75', '18.35');

        $this->actingAs($this->admin())
            ->post("/sales/{$sale->id}/void", ['reason' => 'Rang up the wrong reel.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------------------ Returns */

    public function test_part_of_a_part_sale_can_come_back(): void
    {
        $this->stock($this->wire, 10);
        $sale = $this->sell($this->wire, '2.5', '18.35');

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Cut too long',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => '0.75']],
        ])->assertSessionHasNoErrors();

        $line = $sale->lines->first()->fresh();

        $this->assertSame(0.75, $line->returned_quantity);
        $this->assertSame(1.75, $line->returnable());
        $this->assertSame(8.25, $this->wire->fresh()->on_hand);
        // 45.88 x 0.75 / 2.5 = 13.764, which is 13.76.
        $this->assertSame('13.76', $line->salesReturnLines()->first()->line_refund);
        $this->assertStockMatchesItsLayers();

        // The rest comes back for exactly what is left of the line.
        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Cut too long',
            'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.75']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('45.88', number_format((float) $line->salesReturnLines()->sum('line_refund'), 2, '.', ''));
        $this->assertSame(10, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_return_refuses_more_than_is_left_by_a_thousandth(): void
    {
        $this->stock($this->wire, 10);
        $sale = $this->sell($this->wire, '2.5', '18.35');

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Cut too long',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => '2.501']],
        ])->assertSessionHasErrors(['lines.0.quantity']);
    }

    public function test_part_of_a_delivery_goes_back_to_the_supplier_and_a_whole_one_voids(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => '1.5', 'unit_cost' => '12.35']],
        ])->assertSessionHasNoErrors();

        $purchase = Purchase::latest('id')->firstOrFail();

        // 1.5 x 12.35 = 18.525, which is 18.53.
        $this->assertSame('18.53', $purchase->total_amount);
        $this->assertSame(1.5, $this->wire->fresh()->on_hand);

        $this->actingAs($admin)->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '0.5']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->wire->fresh()->on_hand);
        $this->assertSame(0.5, $purchase->lines->first()->fresh()->returned_quantity);
        $this->assertStockMatchesItsLayers();

        // A second delivery, untouched, voids cleanly.
        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => '0.125', 'unit_cost' => '12.35']],
        ])->assertSessionHasNoErrors();

        $second = Purchase::latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post("/purchases/{$second->id}/void", ['reason' => 'Entered twice.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->wire->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ---------------------------------------------- Units and the product form */

    public function test_a_unit_cannot_stop_allowing_decimals_while_a_product_holds_a_part(): void
    {
        $this->stock($this->wire, '0.5');

        $this->actingAs($this->admin())->put("/units/{$this->metre->id}", [
            'code' => 'm', 'name' => 'Meter', 'allows_decimals' => false, 'is_active' => true,
        ])->assertSessionHasErrors('allows_decimals');

        $this->assertTrue($this->metre->fresh()->allows_decimals);

        // Brought to a whole number, the switch may go off.
        $this->stock($this->wire, '0.5');

        $this->actingAs($this->admin())->put("/units/{$this->metre->id}", [
            'code' => 'm', 'name' => 'Meter', 'allows_decimals' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($this->metre->fresh()->allows_decimals);
    }

    public function test_a_product_holding_a_part_cannot_move_onto_a_whole_unit(): void
    {
        $this->stock($this->wire, '0.5');

        $this->actingAs($this->admin())->put("/products/{$this->wire->id}", [
            'sku' => $this->wire->sku,
            'name' => $this->wire->name,
            'category_id' => $this->wire->category_id,
            'unit_id' => $this->pieces->id,
            'cost' => '10.00',
            'selling_price' => '18.35',
            'reorder_level' => 0,
            'is_active' => true,
        ])->assertSessionHasErrors('unit_id');

        $this->assertSame($this->metre->id, $this->wire->fresh()->unit_id);
    }

    public function test_a_reorder_level_takes_decimals_only_on_a_unit_that_allows_them(): void
    {
        $form = fn (Product $product, $level) => [
            'sku' => $product->sku,
            'name' => $product->name,
            'category_id' => $product->category_id,
            'unit_id' => $product->unit_id,
            'cost' => '10.00',
            'selling_price' => '18.35',
            'reorder_level' => $level,
            'is_active' => true,
        ];

        $this->actingAs($this->admin())
            ->put("/products/{$this->hammer->id}", $form($this->hammer, '2.5'))
            ->assertSessionHasErrors('reorder_level');

        $this->actingAs($this->admin())
            ->put("/products/{$this->wire->id}", $form($this->wire, '2.5'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2.5, $this->wire->fresh()->reorder_level);
    }

    public function test_the_forms_are_told_which_products_take_decimals(): void
    {
        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertInertia(fn ($page) => $page
                ->where('products', fn ($products) => collect($products)->firstWhere('sku', 'WIRE-THHN-12')['decimals'] === true
                    && collect($products)->firstWhere('sku', 'HAMMER-16')['decimals'] === false)
            );
    }

    public function test_verify_stays_clean_after_part_quantities(): void
    {
        $this->stock($this->wire, '3.333');
        $this->sell($this->wire, '1.111', '18.35');

        $this->artisan('inventory:verify')->assertSuccessful();
        $this->assertSame(2.222, $this->wire->fresh()->on_hand);
    }
}
