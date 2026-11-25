<?php

namespace Tests\Feature\Locations;

use App\Models\InventoryLayer;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 31.3: a delivery is received at a location (*Receive at*), and a
 * purchase order says where it is to be delivered (*Deliver to*).
 */
class ReceiveAtLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $store;

    protected Location $warehouse;

    protected Product $wire;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin);
    }

    /** @return array<string, mixed> */
    protected function delivery(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '45.00']],
        ], $overrides);
    }

    protected function at(Location $location): int|float
    {
        return LocationStock::where('product_id', $this->wire->id)->where('location_id', $location->id)->value('on_hand') ?? 0;
    }

    protected function order(array $overrides = []): PurchaseOrder
    {
        $this->post('/purchase-orders', array_merge([
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'deliver_to_location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '44.00']],
        ], $overrides))->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail()->load('lines');
    }

    /* -------------------------------------------------------- Receive at */

    public function test_a_delivery_goes_on_the_shelf_at_the_location_picked(): void
    {
        $this->post('/purchases', $this->delivery())->assertSessionHasNoErrors();

        $purchase = Purchase::sole();
        $this->assertSame($this->warehouse->id, (int) $purchase->location_id);
        $this->assertSame(20, $this->at($this->warehouse));
        $this->assertSame(0, $this->at($this->store));
        $this->assertSame($this->warehouse->id, InventoryLayer::sole()->location_id);
        $this->assertSame(20, $this->wire->fresh()->on_hand);
        $this->assertSame([], app(InventoryLedger::class)->reconcileLocations());
    }

    public function test_a_form_with_no_location_at_all_posts_at_the_default(): void
    {
        // A tab opened before phase 31.3 sends no such key.
        $this->post('/purchases', collect($this->delivery())->except('location_id')->all())
            ->assertSessionHasNoErrors();

        $this->assertSame($this->store->id, (int) Purchase::sole()->location_id);
        $this->assertSame(20, $this->at($this->store));
    }

    public function test_an_empty_or_switched_off_location_is_refused(): void
    {
        $this->post('/purchases', $this->delivery(['location_id' => '']))
            ->assertSessionHasErrors(['location_id' => 'Pick where the goods are received.']);

        $this->unaudited(fn () => $this->warehouse->update(['is_active' => false]));

        $this->post('/purchases', $this->delivery())
            ->assertSessionHasErrors(['location_id' => 'Pick a location that is still active.']);

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_voiding_the_delivery_takes_it_back_out_of_that_location(): void
    {
        $this->post('/purchases', $this->delivery())->assertSessionHasNoErrors();
        $purchase = Purchase::sole();

        $this->post("/purchases/{$purchase->id}/void", ['reason' => 'Wrong supplier'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->at($this->warehouse));
        $this->assertSame(0, $this->wire->fresh()->on_hand);
        $this->assertSame([], app(InventoryLedger::class)->reconcileLocations());
    }

    public function test_the_same_form_sent_twice_lands_on_the_first_but_another_place_is_another_delivery(): void
    {
        $form = $this->delivery();

        $this->post('/purchases', $form)->assertSessionHasNoErrors();
        $this->post('/purchases', $form)->assertSessionHas('info');
        $this->assertDatabaseCount('purchases', 1);

        $this->post('/purchases', array_merge($form, ['location_id' => $this->store->id]))
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_a_stale_tab_sent_twice_lands_on_its_first_delivery(): void
    {
        // No location key at all: posted at the default, and the retry with
        // the same key is the same delivery, not a clash.
        $form = collect($this->delivery())->except('location_id')->all();

        $this->post('/purchases', $form)->assertSessionHasNoErrors();
        $this->post('/purchases', $form)->assertSessionHas('info');
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_the_list_page_and_dialog_say_where_it_was_received(): void
    {
        $this->post('/purchases', $this->delivery())->assertSessionHasNoErrors();
        $this->post('/purchases', $this->delivery(['location_id' => $this->store->id]))->assertSessionHasNoErrors();
        $warehoused = Purchase::where('location_id', $this->warehouse->id)->sole();

        $this->get("/purchases?location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->has('purchases.data', 1)
                ->where('purchases.data.0.location', 'Warehouse')
                ->where('filters.location_id', $this->warehouse->id));

        $this->get("/purchases/{$warehoused->id}")
            ->assertInertia(fn ($page) => $page->where('purchase.location', 'Warehouse'));

        $this->getJson("/purchases/{$warehoused->id}/preview")
            ->assertJsonPath('purchase.location', 'Warehouse');
    }

    public function test_the_form_offers_the_active_locations_and_starts_on_the_default(): void
    {
        $this->unaudited(fn () => Location::factory()->inactive()->create(['name' => 'Closed Stockroom']));

        $this->get('/purchases/create')
            ->assertInertia(fn ($page) => $page
                ->where('defaultLocationId', $this->store->id)
                ->where('locations', [
                    ['value' => $this->store->id, 'label' => 'Main Store', 'type' => 'store'],
                    ['value' => $this->warehouse->id, 'label' => 'Warehouse', 'type' => 'warehouse'],
                ]));
    }

    public function test_the_api_says_where_a_delivery_was_received(): void
    {
        $this->post('/purchases', $this->delivery())->assertSessionHasNoErrors();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/purchases/'.Purchase::sole()->id)
            ->assertOk()
            ->assertJsonPath('data.location.name', 'Warehouse');
    }

    /* -------------------------------------------------------- Deliver to */

    public function test_an_order_names_where_to_deliver_and_receiving_starts_there(): void
    {
        $order = $this->order();

        $this->assertSame($this->warehouse->id, (int) $order->deliver_to_location_id);

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page->where('order.deliver_to.name', 'Warehouse'));

        $this->get("/purchases/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page->where('fromOrder.deliver_to_location_id', $this->warehouse->id));

        // The receiver may still send it elsewhere: the truck went to the store.
        $this->post('/purchases', $this->delivery([
            'purchase_order_id' => $order->id,
            'location_id' => $this->store->id,
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->wire->id,
                'quantity' => 20,
                'unit_cost' => '44.00',
            ]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(20, $this->at($this->store));
        $this->assertSame('received', $order->fresh()->status->value);
    }

    public function test_the_order_says_where_its_deliveries_were_received(): void
    {
        // An order with no Deliver to, as one placed before phase 31.3 reads.
        $order = $this->order(['deliver_to_location_id' => '']);
        $line = $order->lines->first();
        $part = fn (Location $at, int $quantity) => $this->delivery([
            'purchase_order_id' => $order->id,
            'location_id' => $at->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'product_id' => $this->wire->id,
                'quantity' => $quantity,
                'unit_cost' => '44.00',
            ]],
        ]);

        $this->post('/purchases', $part($this->warehouse, 5))->assertSessionHasNoErrors();
        $this->post('/purchases', $part($this->store, 5))->assertSessionHasNoErrors();
        $this->post('/purchases', $part($this->warehouse, 5))->assertSessionHasNoErrors();

        // A voided delivery gave its goods back: it no longer says where they went.
        $voided = Purchase::where('location_id', $this->store->id)->sole();
        $this->post("/purchases/{$voided->id}/void", ['reason' => 'Counted twice'])->assertSessionHasNoErrors();

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('order.deliver_to', null)
                ->where('order.received_at', ['Warehouse'])
                ->where('order.deliveries.0.location', 'Warehouse')
                ->where('order.deliveries.1.location', 'Main Store'));

        $this->getJson("/purchase-orders/{$order->id}/preview")
            ->assertJsonPath('order.received_at', ['Warehouse']);

        // Nothing received yet: an empty list, and the dialog leaves the row out.
        $this->get("/purchase-orders/{$this->order()->id}")
            ->assertInertia(fn ($page) => $page->where('order.received_at', []));
    }

    public function test_deliver_to_is_optional_and_must_be_active_when_named(): void
    {
        $order = $this->order(['deliver_to_location_id' => '']);
        $this->assertNull($order->deliver_to_location_id);

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page->where('order.deliver_to', null));

        $this->unaudited(fn () => $this->warehouse->update(['is_active' => false]));

        $this->post('/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'deliver_to_location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '44.00']],
        ])->assertSessionHasErrors(['deliver_to_location_id' => 'Pick a location that is still active.']);
    }

    public function test_receiving_falls_back_to_the_default_when_the_orders_place_was_switched_off(): void
    {
        $order = $this->order();
        $this->unaudited(fn () => $this->warehouse->update(['is_active' => false]));

        $this->get("/purchases/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page->where('fromOrder.deliver_to_location_id', null));
    }

    public function test_the_order_form_offers_the_locations_and_starts_on_the_default(): void
    {
        $this->get('/purchase-orders/create')
            ->assertInertia(fn ($page) => $page
                ->where('defaultLocationId', $this->store->id)
                ->has('locations', 2));
    }
}
