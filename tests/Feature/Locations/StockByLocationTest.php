<?php

namespace Tests\Feature\Locations;

use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\InventoryLayerConsumption;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use App\Support\Quantity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 31.2: the stock is counted at each location.
 *
 * Every batch and movement names a location, `location_stocks` holds each
 * product's figure at each, and the product's total stays on `on_hand`.
 * Only `post()` is told a location — the default one when none is named; a
 * void, a sales return and a purchase return follow the batches they touch.
 */
class StockByLocationTest extends TestCase
{
    use RefreshDatabase;

    protected Location $store;

    protected Location $warehouse;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function ledger(): InventoryLedger
    {
        return app(InventoryLedger::class);
    }

    protected function product(array $attributes = []): Product
    {
        return $this->unaudited(fn () => Product::factory()->create($attributes));
    }

    /** A bare document for the ledger to hang movements on. */
    protected function document(): InventoryAdjustment
    {
        return $this->unaudited(fn () => InventoryAdjustment::forceCreate([
            'reference' => 'DOC-'.Str::random(8),
            // Any place: the movements say where the stock is.
            'location_id' => Location::default()?->id ?? Location::query()->value('id'),
            'kind' => 'increase',
            'status' => 'posted',
            'adjustment_date' => today(),
        ]));
    }

    protected function bringIn(Product $product, int|float $quantity, float $cost, ?Location $at = null): InventoryAdjustment
    {
        $document = $this->document();

        $this->ledger()->post($document, $document->reference, Carbon::today(), MovementType::Purchase, [
            ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ], $at);

        return $document;
    }

    protected function takeOut(Product $product, int|float $quantity, ?Location $at = null): InventoryAdjustment
    {
        $document = $this->document();

        $this->ledger()->post($document, $document->reference, Carbon::today(), MovementType::Sale, [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ], $at);

        return $document;
    }

    protected function at(Product $product, Location $location): int|float
    {
        return Quantity::normalize(
            LocationStock::where('product_id', $product->id)->where('location_id', $location->id)->value('on_hand') ?? 0
        );
    }

    /** Every test ends here: each location matches its batches, and they add up. */
    protected function assertBooksAgree(): void
    {
        $this->assertSame([], $this->ledger()->reconcile());
        $this->assertSame([], $this->ledger()->reconcileLocations());
    }

    /* ------------------------------------------------------------ Default */

    public function test_main_store_arrives_as_the_one_default(): void
    {
        $this->assertTrue($this->store->is_default);
        $this->assertFalse($this->warehouse->is_default);
        $this->assertSame($this->store->id, Location::default()->id);
    }

    public function test_a_document_that_names_no_location_posts_at_the_default(): void
    {
        $product = $this->product();

        $this->bringIn($product, 10, 100);
        $this->takeOut($product, 4);

        $this->assertSame(6, $this->at($product, $this->store));
        $this->assertSame(0, $this->at($product, $this->warehouse));
        $this->assertSame(6, $product->fresh()->on_hand);
        $this->assertSame(
            [$this->store->id],
            InventoryMovement::where('product_id', $product->id)->distinct()->pluck('location_id')->all()
        );
        $this->assertSame($this->store->id, InventoryLayer::firstWhere('product_id', $product->id)->location_id);
        $this->assertBooksAgree();
    }

    public function test_with_no_default_the_ledger_says_what_to_do(): void
    {
        $product = $this->product();
        Location::query()->update(['is_default' => false]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('No location is set as the default');

        $this->bringIn($product, 5, 100);
    }

    /* ----------------------------------------------------------- Counting */

    public function test_each_location_keeps_its_own_figure_and_they_add_up_to_the_total(): void
    {
        $product = $this->product();

        $this->bringIn($product, 10, 100, $this->store);
        $this->bringIn($product, 25, 90, $this->warehouse);
        $this->takeOut($product, 3, $this->warehouse);

        $this->assertSame(10, $this->at($product, $this->store));
        $this->assertSame(22, $this->at($product, $this->warehouse));
        $this->assertSame(32, $product->fresh()->on_hand);
        $this->assertBooksAgree();
    }

    public function test_a_sale_eats_only_the_batches_at_its_own_location(): void
    {
        $product = $this->product();

        // The warehouse's batch is older and cheaper; a sale at the store
        // must not touch it.
        $this->bringIn($product, 10, 80, $this->warehouse);
        $this->bringIn($product, 10, 120, $this->store);

        $sale = $this->takeOut($product, 4, $this->store);

        $movement = InventoryMovement::where('source_id', $sale->id)->where('source_type', $sale->getMorphClass())->sole();
        $this->assertSame('120.00', $movement->unit_cost);
        $this->assertSame($this->store->id, $movement->location_id);
        $this->assertSame(10, InventoryLayer::where('location_id', $this->warehouse->id)->value('quantity_remaining'));
        $this->assertBooksAgree();
    }

    public function test_stock_elsewhere_is_named_when_this_location_runs_short(): void
    {
        $product = $this->product(['sku' => 'WIRE-20']);

        $this->bringIn($product, 2, 100, $this->store);
        $this->bringIn($product, 8, 100, $this->warehouse);

        try {
            $this->takeOut($product, 5, $this->store);
            $this->fail('A sale beyond the store\'s stock went through.');
        } catch (InventoryException $e) {
            $this->assertSame(
                'There is not enough [WIRE-20] at Main Store: 5 needed, 2 there. 8 more is at other locations; move it here first.',
                $e->getMessage()
            );
        }

        $this->assertSame(10, $product->fresh()->on_hand);
        $this->assertBooksAgree();
    }

    public function test_more_than_there_is_anywhere_keeps_the_old_message(): void
    {
        $product = $this->product(['sku' => 'WIRE-20']);
        $this->bringIn($product, 2, 100);

        $this->expectExceptionMessage('There is not enough [WIRE-20] in stock: 5 needed, 2 on hand.');

        $this->takeOut($product, 5);
    }

    public function test_parts_of_a_unit_are_counted_at_a_location_too(): void
    {
        $product = $this->product();
        $product->unit->forceFill(['allows_decimals' => true])->save();

        $this->bringIn($product, 75.5, 30, $this->warehouse);
        $this->takeOut($product, 0.25, $this->warehouse);

        $this->assertSame(75.25, $this->at($product, $this->warehouse));
        $this->assertBooksAgree();
    }

    /* ------------------------------------------- Following the batches */

    public function test_voiding_a_delivery_takes_it_out_of_the_location_it_came_into(): void
    {
        $product = $this->product();
        $this->bringIn($product, 5, 100, $this->store);
        $delivery = $this->bringIn($product, 12, 90, $this->warehouse);

        $this->ledger()->reverseReceipt($delivery, $delivery->reference, Carbon::today());

        $this->assertSame(5, $this->at($product, $this->store));
        $this->assertSame(0, $this->at($product, $this->warehouse));
        $this->assertSame(
            $this->warehouse->id,
            InventoryMovement::where('type', MovementType::PurchaseVoid)->value('location_id')
        );
        $this->assertBooksAgree();
    }

    public function test_voiding_a_sale_puts_the_stock_back_where_it_left(): void
    {
        $product = $this->product();
        $this->bringIn($product, 10, 100, $this->warehouse);
        $this->bringIn($product, 10, 100, $this->store);
        $sale = $this->takeOut($product, 6, $this->warehouse);

        $this->ledger()->reverseIssue($sale, $sale->reference, Carbon::today());

        $this->assertSame(10, $this->at($product, $this->warehouse));
        $this->assertSame(10, $this->at($product, $this->store));
        $this->assertSame(
            $this->warehouse->id,
            InventoryMovement::where('type', MovementType::SaleVoid)->value('location_id')
        );
        $this->assertBooksAgree();
    }

    public function test_a_sales_return_comes_back_to_the_location_it_was_sold_from(): void
    {
        $product = $this->product();
        $this->bringIn($product, 10, 100, $this->warehouse);
        $sale = $this->takeOut($product, 6, $this->warehouse);

        $taken = InventoryLayerConsumption::query()
            ->whereIn('inventory_movement_id', InventoryMovement::where('source_id', $sale->id)->where('source_type', $sale->getMorphClass())->pluck('id'))
            ->sole();

        $return = $this->document();
        $this->ledger()->restoreConsumed($return, $return->reference, Carbon::today(), [
            ['product_id' => $product->id, 'take' => [$taken->id => 2]],
        ]);

        $this->assertSame(6, $this->at($product, $this->warehouse));
        $this->assertSame(0, $this->at($product, $this->store));
        $this->assertSame(
            $this->warehouse->id,
            InventoryMovement::where('type', MovementType::SalesReturn)->value('location_id')
        );
        $this->assertBooksAgree();
    }

    public function test_a_purchase_return_leaves_from_the_location_its_delivery_sits_at(): void
    {
        $product = $this->product();
        $this->bringIn($product, 10, 100, $this->store);
        $delivery = $this->bringIn($product, 10, 90, $this->warehouse);
        $layer = InventoryLayer::where('source_id', $delivery->id)->sole();

        $return = $this->document();
        $this->ledger()->issueFromLayers($return, $return->reference, Carbon::today(), [
            ['product_id' => $product->id, 'layer_id' => $layer->id, 'quantity' => 4],
        ]);

        $this->assertSame(6, $this->at($product, $this->warehouse));
        $this->assertSame(10, $this->at($product, $this->store));
        $this->assertBooksAgree();
    }

    public function test_the_documents_the_screens_post_land_at_the_default(): void
    {
        $product = $this->product();

        $this->unaudited(fn () => app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $product->id, 'quantity' => 40, 'unit_cost' => 50],
        ]));

        $this->assertSame(40, $this->at($product, $this->store));
        $this->assertBooksAgree();
    }

    /* ----------------------------------------------------------- Checking */

    public function test_the_check_catches_a_location_figure_written_by_hand(): void
    {
        $product = $this->product(['sku' => 'TAMPER-1']);
        $this->bringIn($product, 10, 100, $this->warehouse);

        LocationStock::where('product_id', $product->id)->update(['on_hand' => 7]);

        $wrong = collect($this->ledger()->reconcileLocations());

        $this->assertTrue($wrong->contains(fn ($row) => $row['sku'] === 'TAMPER-1' && $row['location'] === 'Warehouse' && $row['recorded'] === 7 && $row['expected'] === 10));
        $this->assertTrue($wrong->contains(fn ($row) => $row['sku'] === 'TAMPER-1' && $row['location'] === 'All locations'));

        $this->artisan('inventory:verify')
            ->expectsOutputToContain('location figure(s) disagree')
            ->assertFailed();
    }

    public function test_the_check_passes_on_clean_books(): void
    {
        $product = $this->product();
        $this->bringIn($product, 10, 100, $this->warehouse);

        $this->artisan('inventory:verify')
            ->expectsOutputToContain('Every location matches the batches sitting there')
            ->assertSuccessful();
    }

    /* ------------------------------------------------------ The screens */

    public function test_the_stock_screen_narrows_to_one_location(): void
    {
        $here = $this->product(['sku' => 'A-1']);
        $there = $this->product(['sku' => 'B-1']);
        $this->bringIn($here, 4, 100, $this->warehouse);
        $this->bringIn($here, 6, 100, $this->store);
        $this->bringIn($there, 5, 100, $this->store);

        $this->actingAs($this->admin)
            ->get("/stock?location_id={$this->warehouse->id}&stock=held")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stock.data', 1)
                ->where('stock.data.0.sku', 'A-1')
                ->where('stock.data.0.at_location', 4)
                ->where('stock.data.0.on_hand', 10)
                ->where('filters.location_id', $this->warehouse->id));

        $this->actingAs($this->admin)
            ->get("/stock?location_id={$this->warehouse->id}&stock=out")
            ->assertInertia(fn ($page) => $page->has('stock.data', 1)->where('stock.data.0.sku', 'B-1'));

        $this->actingAs($this->admin)
            ->get('/stock')
            ->assertInertia(fn ($page) => $page->where('stock.data.0.at_location', null));
    }

    public function test_the_product_page_shows_its_stock_at_each_location(): void
    {
        $product = $this->product();
        $this->bringIn($product, 4, 100, $this->warehouse);
        $this->bringIn($product, 6, 100, $this->store);

        $this->actingAs($this->admin)
            ->get("/products/{$product->id}")
            ->assertInertia(fn ($page) => $page
                ->where('locations', [
                    ['location' => 'Main Store', 'type' => 'Store', 'is_default' => true, 'on_hand' => 6],
                    ['location' => 'Warehouse', 'type' => 'Warehouse', 'is_default' => false, 'on_hand' => 4],
                ]));
    }

    /* -------------------------------------------- Rules on the list */

    public function test_the_default_is_never_switched_off_or_deleted(): void
    {
        $this->actingAs($this->admin)->patch("/locations/{$this->store->id}/status")->assertForbidden();
        $this->actingAs($this->admin)->delete("/locations/{$this->store->id}")->assertForbidden();

        $this->actingAs($this->admin)
            ->put("/locations/{$this->store->id}", ['name' => 'Main Store', 'type' => 'store', 'is_active' => false])
            ->assertSessionHasErrors(['is_active' => 'This is the default location, where every document posts. It stays on.']);

        $this->assertTrue($this->store->fresh()->is_active);
    }

    public function test_a_location_holding_stock_stays_on_and_one_with_history_is_never_deleted(): void
    {
        $product = $this->product();
        $this->bringIn($product, 5, 100, $this->warehouse);

        $this->actingAs($this->admin)->patch("/locations/{$this->warehouse->id}/status")->assertForbidden();
        $this->actingAs($this->admin)
            ->put("/locations/{$this->warehouse->id}", ['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => false])
            ->assertSessionHasErrors(['is_active' => 'This location still holds stock. Move it out first, then switch it off.']);

        // Emptied, it may be switched off — but its history keeps it.
        $this->takeOut($product, 5, $this->warehouse);

        $this->actingAs($this->admin)->patch("/locations/{$this->warehouse->id}/status")->assertRedirect();
        $this->assertFalse($this->warehouse->fresh()->is_active);
        $this->actingAs($this->admin)->delete("/locations/{$this->warehouse->id}")->assertForbidden();

        $this->actingAs($this->admin)->get('/locations')
            ->assertInertia(fn ($page) => $page
                ->where('locations.data.1.name', 'Warehouse')
                ->where('locations.data.1.can.delete', false)
                ->where('locations.data.0.is_default', true)
                ->where('locations.data.0.can.makeDefault', false));
    }

    public function test_the_default_moves_only_while_it_holds_no_stock(): void
    {
        $product = $this->product();
        $this->bringIn($product, 5, 100, $this->store);

        $this->actingAs($this->admin)
            ->patch("/locations/{$this->warehouse->id}/default")
            ->assertForbidden();

        $this->takeOut($product, 5, $this->store);

        $this->actingAs($this->admin)
            ->patch("/locations/{$this->warehouse->id}/default")
            ->assertSessionHas('success', 'Warehouse is now the default location.');

        $this->assertTrue($this->warehouse->fresh()->is_default);
        $this->assertFalse($this->store->fresh()->is_default);
        $this->assertSame(1, Location::where('is_default', true)->count());

        // What the next document posts at follows.
        $this->bringIn($product, 3, 100);
        $this->assertSame(3, $this->at($product, $this->warehouse));
        $this->assertBooksAgree();
    }

    public function test_a_switched_off_location_cannot_become_the_default_and_staff_cannot_move_it(): void
    {
        $this->unaudited(fn () => $this->warehouse->update(['is_active' => false]));

        $this->actingAs($this->admin)->patch("/locations/{$this->warehouse->id}/default")->assertForbidden();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->unaudited(fn () => $this->warehouse->update(['is_active' => true]));

        $this->actingAs($staff)->patch("/locations/{$this->warehouse->id}/default")->assertForbidden();
        $this->assertTrue($this->store->fresh()->is_default);
    }

    public function test_the_first_location_on_an_empty_list_becomes_the_default(): void
    {
        Location::query()->delete();

        $this->actingAs($this->admin)
            ->post('/locations', ['name' => 'Cebu Store', 'type' => 'store', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post('/locations', ['name' => 'Cebu Warehouse', 'type' => 'warehouse', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('Cebu Store', Location::default()->name);
        $this->assertSame(1, Location::where('is_default', true)->count());
    }
}
