<?php

namespace Tests\Feature\Locations;

use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use App\Services\InventoryValuation;
use App\Services\TransferPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 31.5: stock moved from one location to another, at the cost it
 * already carries.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $store;

    protected Location $warehouse;

    protected Product $paint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();
        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'selling_price' => 500]));

        $this->actingAs($this->admin);

        // Two batches at the warehouse at two costs: 6 at 300, then 4 at 320.
        $this->bringIn(6, $this->warehouse, 300);
        $this->bringIn(4, $this->warehouse, 320);
    }

    protected function bringIn(int|float $quantity, Location $at, float $cost, ?Product $product = null): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => ($product ?? $this->paint)->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ], Carbon::today(), 'count', locationId: $at->id);
    }

    protected function at(Location $location, ?Product $product = null): int|float
    {
        return LocationStock::where('product_id', ($product ?? $this->paint)->id)
            ->where('location_id', $location->id)->value('on_hand') ?? 0;
    }

    /** @return array<string, mixed> */
    protected function form(int|float $quantity, array $overrides = []): array
    {
        return array_merge([
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $this->store->id,
            'transfer_date' => Carbon::today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    protected function assertBooksBalance(): void
    {
        $ledger = app(InventoryLedger::class);

        $this->assertSame([], $ledger->reconcile());
        $this->assertSame([], $ledger->reconcileLocations());
    }

    /* ------------------------------------------------------------- Moving */

    public function test_a_transfer_moves_the_goods_and_changes_neither_the_total_nor_the_value(): void
    {
        $worth = app(InventoryValuation::class)->total();

        $this->post('/transfers', $this->form(8))->assertSessionHasNoErrors();

        $transfer = StockTransfer::sole();
        $this->assertStringStartsWith('TR-', $transfer->reference);
        $this->assertSame('posted', $transfer->status->value);

        $this->assertSame(2, $this->at($this->warehouse));
        $this->assertSame(8, $this->at($this->store));
        $this->assertSame(10, $this->paint->fresh()->on_hand);
        $this->assertSame($worth, app(InventoryValuation::class)->total());

        // 6 at 300 and 2 at 320, each its own batch at the store, at its own
        // cost — never one averaged batch.
        $arrived = InventoryLayer::where('location_id', $this->store->id)->orderBy('id')->get();
        $this->assertSame([[6, '300.00'], [2, '320.00']], $arrived->map(fn ($l) => [$l->quantity_received, $l->unit_cost])->all());
        $this->assertNotNull($arrived[0]->transferred_from_layer_id);
        $this->assertSame($this->warehouse->id, InventoryLayer::find($arrived[0]->transferred_from_layer_id)->location_id);

        // 1,800 + 640.
        $this->assertSame('2440.00', $transfer->total_cost);
        $this->assertSame('2440.00', $transfer->lines()->sole()->total_cost);

        // One out at the source and one in at the destination.
        $movements = InventoryMovement::where('source_type', $transfer->getMorphClass())->orderBy('id')->get();
        $this->assertSame([MovementType::TransferOut, MovementType::TransferIn], $movements->pluck('type')->all());
        $this->assertSame([$this->warehouse->id, $this->store->id], $movements->pluck('location_id')->all());
        $this->assertSame(['2440.00', '2440.00'], $movements->pluck('total_cost')->all());

        $this->assertSame(1, AuditTrail::where('module', 'Stock Transfers')->where('action', 'post')->count());
        $this->assertBooksBalance();
    }

    public function test_a_sale_at_the_destination_is_costed_from_the_batches_that_arrived(): void
    {
        $this->post('/transfers', $this->form(8))->assertSessionHasNoErrors();
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->post('/sales', [
            'customer_id' => $walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 7, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        // 6 at 300 and 1 at 320: the warehouse's own order, carried over.
        $this->assertSame('2120.00', Sale::sole()->total_cost);
        $this->assertSame(1, $this->at($this->store));
        $this->assertBooksBalance();
    }

    public function test_parts_move_where_the_unit_allows_them(): void
    {
        $metre = $this->unaudited(fn () => Unit::factory()->create(['code' => 'mtr', 'allows_decimals' => true]));
        $wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE', 'unit_id' => $metre->id]));
        $this->bringIn(75.5, $this->warehouse, 20, $wire);

        $this->post('/transfers', $this->form(0, [
            'lines' => [['product_id' => $wire->id, 'quantity' => 30.25]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(45.25, $this->at($this->warehouse, $wire));
        $this->assertSame(30.25, $this->at($this->store, $wire));

        // 2.5 tins is refused.
        $this->post('/transfers', $this->form(2.5))->assertSessionHasErrors('lines.0.quantity');
        $this->assertBooksBalance();
    }

    /* ----------------------------------------------------------- Refusing */

    public function test_the_two_places_must_be_different_active_locations(): void
    {
        $this->post('/transfers', $this->form(1, ['to_location_id' => $this->warehouse->id]))
            ->assertSessionHasErrors(['to_location_id' => 'Pick a destination other than where the goods are now.']);

        $this->post('/transfers', $this->form(1, ['from_location_id' => '']))
            ->assertSessionHasErrors(['from_location_id' => 'Pick where the goods are now.']);

        $closed = $this->unaudited(fn () => Location::factory()->inactive()->create(['name' => 'Old Yard']));
        $this->post('/transfers', $this->form(1, ['to_location_id' => $closed->id]))
            ->assertSessionHasErrors(['to_location_id' => 'Pick a location that is still active.']);

        // The ledger refuses the same place too, whoever calls it.
        $this->expectException(InventoryException::class);
        app(TransferPoster::class)->post([['product_id' => $this->paint->id, 'quantity' => 1]], $this->store->id, $this->store->id);
    }

    public function test_it_cannot_move_more_than_is_at_the_source_and_says_where_the_rest_is(): void
    {
        $this->bringIn(5, $this->store, 300);

        // Two lines of the same product are added up.
        $this->post('/transfers', $this->form(0, ['lines' => [
            ['product_id' => $this->paint->id, 'quantity' => 6],
            ['product_id' => $this->paint->id, 'quantity' => 5],
        ]]))->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 10 of [PNT-1G] is on hand at Warehouse, and this asks for 11. 5 more is at other locations.',
        ]);

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame(10, $this->at($this->warehouse));
    }

    public function test_stock_reserved_at_the_source_is_not_moved(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'location_id' => $this->warehouse->id,
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 7, 'unit_price' => '480.00']],
        ])->assertSessionHasNoErrors();

        $this->post('/transfers', $this->form(4))->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 3 of [PNT-1G] at Warehouse is free to move — 7 reserved for customer orders there — and this asks for 4.',
        ]);

        // The poster refuses it again, past the form.
        try {
            app(TransferPoster::class)->post([['product_id' => $this->paint->id, 'quantity' => 4]], $this->warehouse->id, $this->store->id);
            $this->fail('A transfer took reserved stock.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('Only 3 of [PNT-1G] at Warehouse is free to move', $e->getMessage());
        }

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame(10, $this->at($this->warehouse));

        // What is free still moves.
        $this->post('/transfers', $this->form(3))->assertSessionHasNoErrors();
        $this->assertBooksBalance();
    }

    public function test_it_cannot_be_dated_before_the_last_posted_document_or_in_the_future(): void
    {
        $this->post('/transfers', $this->form(1, ['transfer_date' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHasErrors('transfer_date');

        $this->post('/transfers', $this->form(1, ['transfer_date' => Carbon::tomorrow()->toDateString()]))
            ->assertSessionHasErrors('transfer_date');
    }

    public function test_the_same_form_sent_twice_lands_on_the_first(): void
    {
        $form = $this->form(2);

        $this->post('/transfers', $form)->assertSessionHasNoErrors();
        $this->post('/transfers', $form)->assertSessionHas('info');
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertSame(8, $this->at($this->warehouse));

        $this->post('/transfers', array_merge($form, ['lines' => [['product_id' => $this->paint->id, 'quantity' => 3]]]))
            ->assertSessionHasErrors('idempotency_key');
    }

    /* ------------------------------------------------------------ Voiding */

    public function test_a_void_puts_the_goods_back_into_the_batches_they_left(): void
    {
        $before = InventoryLayer::where('location_id', $this->warehouse->id)->orderBy('id')->pluck('quantity_remaining', 'id')->all();
        $worth = app(InventoryValuation::class)->total();

        $this->post('/transfers', $this->form(8))->assertSessionHasNoErrors();
        $transfer = StockTransfer::sole();

        $this->post("/transfers/{$transfer->id}/void", ['reason' => 'Wrong destination'])->assertSessionHasNoErrors();

        $transfer->refresh();
        $this->assertSame('voided', $transfer->status->value);
        $this->assertSame('Wrong destination', $transfer->void_reason);
        $this->assertSame(10, $this->at($this->warehouse));
        $this->assertSame(0, $this->at($this->store));
        $this->assertSame($before, InventoryLayer::where('location_id', $this->warehouse->id)->orderBy('id')->pluck('quantity_remaining', 'id')->all());
        $this->assertSame($worth, app(InventoryValuation::class)->total());

        $types = InventoryMovement::where('source_type', $transfer->getMorphClass())->orderBy('id')->pluck('type')->all();
        $this->assertSame([MovementType::TransferOut, MovementType::TransferIn, MovementType::TransferVoidOut, MovementType::TransferVoidOut, MovementType::TransferVoidIn], $types);

        // Once.
        $this->post("/transfers/{$transfer->id}/void", ['reason' => 'Again'])->assertForbidden();
        $this->assertBooksBalance();
    }

    public function test_a_transfer_whose_goods_were_sold_at_the_destination_is_not_voided(): void
    {
        $this->post('/transfers', $this->form(8))->assertSessionHasNoErrors();
        $transfer = StockTransfer::sole();
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->post('/sales', [
            'customer_id' => $walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $this->post("/transfers/{$transfer->id}/void", ['reason' => 'Wrong destination'])
            ->assertSessionHasErrors(['reason' => '1 of the [PNT-1G] this transfer brought to Main Store has already been sold or moved on, so the transfer can no longer be voided. Move what is left back with another transfer instead.']);

        $this->assertSame('posted', $transfer->fresh()->status->value);
        $this->assertSame(7, $this->at($this->store));

        // Moving the rest back is another transfer.
        $this->post('/transfers', $this->form(7, ['from_location_id' => $this->store->id, 'to_location_id' => $this->warehouse->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame(9, $this->at($this->warehouse));
        $this->assertBooksBalance();
    }

    public function test_a_void_does_not_take_stock_reserved_at_the_destination(): void
    {
        $this->post('/transfers', $this->form(8))->assertSessionHasNoErrors();
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'location_id' => $this->store->id,
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 5, 'unit_price' => '480.00']],
        ])->assertSessionHasNoErrors();

        $this->post('/transfers/'.StockTransfer::value('id').'/void', ['reason' => 'Wrong destination'])
            ->assertSessionHasErrors(['reason' => 'Only 3 of [PNT-1G] at Main Store is free to move: the rest is reserved there for customer orders or held for a loss report. Fulfil or release those orders, or have the loss report answered first, or move less.']);

        $this->assertSame(8, $this->at($this->store));
        $this->assertBooksBalance();
    }

    /* ---------------------------------------- The delivery behind a batch */

    public function test_a_delivery_moved_away_is_returned_or_voided_only_once_the_transfer_is_voided(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 20, 'unit_cost' => '310.00']],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::sole();

        // The two older batches first, then 5 of the delivery's own.
        $this->post('/transfers', $this->form(15))->assertSessionHasNoErrors();
        $transfer = StockTransfer::sole();

        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 20]],
        ])->assertSessionHasErrors([
            'lines.0.quantity' => "Only 15 of [PNT-1G] from this delivery is still on the shelf. Some of it was moved to another location by transfer [{$transfer->reference}]; void that transfer first to return it.",
        ]);

        $this->post("/purchases/{$purchase->id}/void", ['reason' => 'Wrong supplier'])
            ->assertSessionHasErrors('reason');
        $this->assertStringContainsString("transfer [{$transfer->reference}]", session('errors')->first('reason'));

        // Voided, the goods are back in the delivery's own batch.
        $this->post("/transfers/{$transfer->id}/void", ['reason' => 'Goods are going back'])->assertSessionHasNoErrors();

        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 20]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $this->at($this->warehouse));
        $this->assertBooksBalance();
    }

    public function test_a_delivery_whose_moved_goods_were_sold_or_brought_back_is_no_longer_returned_whole(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 20, 'unit_cost' => '310.00']],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::sole();
        $return = fn () => $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 20]],
        ]);

        // 15 to the store (10 older, then 5 of the delivery's own), and all
        // 15 back: the transfer back opens new batches, it does not refill
        // the delivery's, and the first transfer can no longer be voided.
        $this->post('/transfers', $this->form(15))->assertSessionHasNoErrors();
        $out = StockTransfer::sole();
        $this->post('/transfers', $this->form(15, ['from_location_id' => $this->store->id, 'to_location_id' => $this->warehouse->id]))
            ->assertSessionHasNoErrors();

        $return()->assertSessionHasErrors([
            'lines.0.quantity' => "Only 15 of [PNT-1G] from this delivery is still on the shelf. Some of it was moved to another location by transfer [{$out->reference}] and has since been sold or moved on there, so it can no longer be returned or voided against this delivery.",
        ]);

        $this->post("/transfers/{$out->id}/void", ['reason' => 'Undo'])->assertSessionHasErrors('reason');

        // What is still in the delivery's own batch goes back as usual.
        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 15]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(15, $this->paint->fresh()->on_hand);
        $this->assertBooksBalance();

        // Sold at the destination reads the same way.
        $this->post('/transfers', $this->form(1))->assertSessionHasNoErrors();
        $this->post('/sales', [
            'customer_id' => $walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame([['reference' => $out->reference, 'voidable' => false]],
            app(InventoryLedger::class)->transfersHolding([$purchase->lines->first()->inventory_layer_id]));
    }

    /* ---------------------------------------------------------- Screens */

    public function test_the_form_starts_at_the_warehouse_and_offers_what_is_free_there(): void
    {
        $this->get('/transfers/create')
            ->assertInertia(fn ($page) => $page
                ->component('Transfers/Create')
                ->where('fromLocationId', $this->warehouse->id)
                ->where('toLocationId', $this->store->id)
                ->where('products.0.on_hand', 10));

        // Another source: its own figures, and the destination moves off it.
        $this->get("/transfers/create?from={$this->store->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromLocationId', $this->store->id)
                ->where('toLocationId', $this->warehouse->id)
                ->where('products.0.on_hand', 0));
    }

    public function test_the_list_page_dialog_and_movements_name_both_places(): void
    {
        $this->post('/transfers', $this->form(3))->assertSessionHasNoErrors();
        $transfer = StockTransfer::sole();

        $this->get("/transfers?from_location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->has('transfers.data', 1)
                ->where('transfers.data.0.from', 'Warehouse')
                ->where('transfers.data.0.to', 'Main Store'));

        $this->get("/transfers?to_location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page->has('transfers.data', 0));

        $this->get("/transfers/{$transfer->id}")
            ->assertInertia(fn ($page) => $page
                ->where('transfer.from', 'Warehouse')
                ->where('transfer.to', 'Main Store')
                ->where('transfer.lines.0.quantity', 3)
                ->where('can.void', true));

        $this->getJson("/transfers/{$transfer->id}/preview")->assertJsonPath('transfer.to', 'Main Store');

        // A movement row opens the transfer behind it.
        $this->get('/movements?type=transfer_in')
            ->assertInertia(fn ($page) => $page
                ->has('movements.data', 1)
                ->where('movements.data.0.document_url', "/transfers/{$transfer->id}"));
    }

    public function test_staff_move_stock_but_do_not_void_a_transfer(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->get('/transfers')->assertOk();
        $this->actingAs($staff)->post('/transfers', $this->form(2))->assertSessionHasNoErrors();

        $transfer = StockTransfer::sole();
        $this->actingAs($staff)->get("/transfers/{$transfer->id}")
            ->assertInertia(fn ($page) => $page->where('can.void', false));
        $this->actingAs($staff)->post("/transfers/{$transfer->id}/void", ['reason' => 'Mistake'])->assertForbidden();

        $nobody = $this->unaudited(fn () => User::factory()->create());
        $this->actingAs($nobody)->get('/transfers')->assertForbidden();
    }
}
