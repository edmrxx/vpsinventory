<?php

namespace Tests\Feature\Locations;

use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 31.4: a sale, the till, a sales order and an adjustment each name
 * their location, and a return goes back to — or leaves from — the place
 * its document is. What is reserved is held at a location.
 */
class SellAtLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Location $store;

    protected Location $warehouse;

    protected Customer $walkIn;

    protected Customer $customer;

    protected Product $paint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();
        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->customer = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Santos Builders']));
        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'selling_price' => 500]));

        // 10 at the store, 8 at the warehouse.
        $this->actingAs($this->admin);
        $this->bringIn(10, $this->store);
        $this->bringIn(8, $this->warehouse);
    }

    protected function bringIn(int $quantity, Location $at): InventoryAdjustment
    {
        return app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->paint->id, 'quantity' => $quantity, 'unit_cost' => 300],
        ], Carbon::today(), 'count', locationId: $at->id);
    }

    protected function at(Location $location): int|float
    {
        return LocationStock::where('product_id', $this->paint->id)->where('location_id', $location->id)->value('on_hand') ?? 0;
    }

    /** @return array<string, mixed> */
    protected function sale(int $quantity, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => $quantity, 'unit_price' => '500.00']],
        ], $overrides);
    }

    protected function order(int $quantity, Location $at, bool $approve = true): SalesOrder
    {
        $this->post('/sales-orders', [
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'location_id' => $at->id,
            'approve' => $approve,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => $quantity, 'unit_price' => '480.00']],
        ])->assertSessionHasNoErrors();

        return SalesOrder::latest('id')->firstOrFail();
    }

    /* --------------------------------------------------------------- Sales */

    public function test_a_sale_leaves_the_shelf_at_the_location_picked(): void
    {
        $this->post('/sales', $this->sale(5))->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame($this->warehouse->id, (int) $sale->location_id);
        $this->assertSame(3, $this->at($this->warehouse));
        $this->assertSame(10, $this->at($this->store));
        $this->assertSame(13, $this->paint->fresh()->on_hand);
        $this->assertSame([], app(InventoryLedger::class)->reconcileLocations());

        // Voided, it goes back where it came from.
        $this->post("/sales/{$sale->id}/void", ['reason' => 'Rang up twice'])->assertSessionHasNoErrors();
        $this->assertSame(8, $this->at($this->warehouse));
    }

    public function test_a_form_with_no_location_at_all_sells_from_the_default(): void
    {
        // A tab opened before phase 31.4 sends no such key.
        $this->post('/sales', collect($this->sale(4))->except('location_id')->all())->assertSessionHasNoErrors();

        $this->assertSame($this->store->id, (int) Sale::sole()->location_id);
        $this->assertSame(6, $this->at($this->store));
    }

    public function test_a_stale_tab_sent_twice_lands_on_its_first_sale(): void
    {
        $form = collect($this->sale(1))->except('location_id')->all();

        $this->post('/sales', $form)->assertSessionHasNoErrors();
        $this->post('/sales', $form)->assertSessionHas('info');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_an_empty_or_switched_off_location_is_refused(): void
    {
        $this->post('/sales', $this->sale(1, ['location_id' => '']))
            ->assertSessionHasErrors(['location_id' => 'Pick where the goods are sold from.']);

        $this->unaudited(fn () => Location::factory()->inactive()->create(['name' => 'Old Stockroom']));
        $closed = Location::where('name', 'Old Stockroom')->sole();

        $this->post('/sales', $this->sale(1, ['location_id' => $closed->id]))
            ->assertSessionHasErrors(['location_id' => 'Pick a location that is still active.']);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_the_form_weighs_the_line_against_that_location_and_names_the_rest(): void
    {
        $code = $this->paint->unit->code;

        $this->post('/sales', $this->sale(9))->assertSessionHasErrors([
            'lines.0.quantity' => "Only 8 {$code} of [PNT-1G] is on hand at Warehouse, and this sale asks for 9 {$code}. 10 {$code} more is at other locations.",
        ]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_the_same_form_sent_twice_lands_on_the_first_but_another_place_is_another_sale(): void
    {
        $form = $this->sale(1);

        $this->post('/sales', $form)->assertSessionHasNoErrors();
        $this->post('/sales', $form)->assertSessionHas('info');
        $this->assertDatabaseCount('sales', 1);

        $this->post('/sales', array_merge($form, ['location_id' => $this->store->id]))
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_the_sale_form_shows_the_figures_of_the_location_it_sells_from(): void
    {
        $this->get('/sales/create')
            ->assertInertia(fn ($page) => $page
                ->where('locationId', $this->store->id)
                ->has('locations', 2)
                ->where('products.0.shelf', 10));

        $this->get("/sales/create?location={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->where('locationId', $this->warehouse->id)
                ->where('products.0.shelf', 8)
                ->where('products.0.on_hand', 8));
    }

    public function test_the_list_page_dialog_and_api_say_where_it_was_sold(): void
    {
        $this->post('/sales', $this->sale(1))->assertSessionHasNoErrors();
        $this->post('/sales', $this->sale(1, ['location_id' => $this->store->id]))->assertSessionHasNoErrors();
        $sold = Sale::where('location_id', $this->warehouse->id)->sole();

        $this->get("/sales?location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.location', 'Warehouse')
                ->where('filters.location_id', $this->warehouse->id));

        $this->get("/sales/{$sold->id}")->assertInertia(fn ($page) => $page->where('sale.location', 'Warehouse'));
        $this->getJson("/sales/{$sold->id}/preview")->assertJsonPath('sale.location', 'Warehouse');

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/sales/{$sold->id}")->assertOk()->assertJsonPath('data.location.name', 'Warehouse');
    }

    /* ---------------------------------------------------------- Reservations */

    public function test_an_order_holds_its_stock_at_its_own_location_only(): void
    {
        // All 10 at the store are held for the customer.
        $order = $this->order(10, $this->store);
        $this->assertSame($this->store->id, (int) $order->location_id);
        $this->assertSame('approved', $order->status->value);

        // A store sale cannot take it…
        $this->post('/sales', $this->sale(1, ['location_id' => $this->store->id]))
            ->assertSessionHasErrors('lines.0.quantity');

        // …but the warehouse's own stock is free.
        $this->post('/sales', $this->sale(8))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->at($this->warehouse));
    }

    public function test_approving_weighs_what_is_free_at_the_orders_location(): void
    {
        // 12 is more than either place holds, though 18 are on the books.
        $this->post('/sales-orders', [
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'location_id' => $this->warehouse->id,
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 12, 'unit_price' => '480.00']],
        ])->assertSessionHasErrors();

        $order = $this->order(8, $this->warehouse);
        $this->assertSame('approved', $order->status->value);

        $this->get("/sales-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('order.location', 'Warehouse')
                ->where('order.lines.0.free', 8)
                ->where('order.lines.0.short', false));
    }

    public function test_an_order_at_a_place_switched_off_since_cannot_be_approved(): void
    {
        $order = $this->order(2, $this->warehouse, approve: false);

        // Empty it first: a location holding stock stays on.
        $this->post('/sales', $this->sale(8))->assertSessionHasNoErrors();
        $this->unaudited(fn () => $this->warehouse->update(['is_active' => false]));

        $this->post("/sales-orders/{$order->id}/approve", [
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
        ])->assertSessionHasErrors();

        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_the_sale_that_fulfils_an_order_starts_at_its_location_and_takes_its_stock(): void
    {
        $order = $this->order(8, $this->warehouse);

        $this->get("/sales/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromOrder.location_id', $this->warehouse->id)
                ->where('locationId', $this->warehouse->id)
                ->where('products.0.on_hand', 8));

        $this->post('/sales', $this->sale(8, [
            'customer_id' => $this->customer->id,
            'sales_order_id' => $order->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('fulfilled', $order->fresh()->status->value);
    }

    public function test_the_stock_screen_counts_reserved_and_available_at_the_location_shown(): void
    {
        $this->order(6, $this->warehouse);

        $this->get("/stock?location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.at_location', 8)
                ->where('stock.data.0.reserved', 6)
                ->where('stock.data.0.available', 2));

        $this->get("/stock?location_id={$this->store->id}")
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.reserved', 0)
                ->where('stock.data.0.available', 10));

        $this->get('/stock')
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.reserved', 6)
                ->where('stock.data.0.available', 12));
    }

    /* ------------------------------------------------------------- The till */

    public function test_the_till_sells_from_a_store_and_never_a_warehouse(): void
    {
        $cashier = $this->unaudited(fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create());

        // Asked for the warehouse, it stays at the store.
        $this->actingAs($cashier)->get("/pos?location={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->where('locationId', $this->store->id)
                ->where('locations', [['value' => $this->store->id, 'label' => 'Main Store', 'type' => 'store']])
                ->where('products.0.on_hand', 10));

        // A second store can be picked, and shows its own shelf.
        $branch = $this->unaudited(fn () => Location::factory()->asStore()->create(['name' => 'Branch Store']));
        $this->actingAs($cashier)->get("/pos?location={$branch->id}")
            ->assertInertia(fn ($page) => $page
                ->where('locationId', $branch->id)
                ->has('locations', 2)
                ->where('products.0.on_hand', 0));

        // A till sale goes back to the till at the same place.
        $this->actingAs($cashier)->post('/sales', $this->sale(2, ['location_id' => $this->store->id, 'from' => 'pos']))
            ->assertRedirect(route('pos', ['location' => $this->store->id]));
        $this->assertSame(8, $this->at($this->store));
    }

    /* ---------------------------------------------------------- Adjustments */

    public function test_an_adjustment_counts_at_the_location_picked(): void
    {
        $this->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'reason' => 'damaged',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 3]],
        ])->assertSessionHasNoErrors();

        $adjustment = InventoryAdjustment::latest('id')->firstOrFail();
        $this->assertSame($this->warehouse->id, (int) $adjustment->location_id);
        $this->assertSame(5, $this->at($this->warehouse));
        $this->assertSame(10, $this->at($this->store));

        // A decrease is weighed at its place, and says where the rest is.
        $this->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'reason' => 'lost',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 6]],
        ])->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 5 of [PNT-1G] is on hand at Warehouse, and this asks for 6. 10 more is at other locations.',
        ]);

        $this->get("/adjustments/{$adjustment->id}")->assertInertia(fn ($page) => $page->where('adjustment.location', 'Warehouse'));
        $this->get("/adjustments?location_id={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page->where('adjustments.data.0.location', 'Warehouse'));

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/adjustments/{$adjustment->id}")->assertJsonPath('data.location.name', 'Warehouse');
    }

    public function test_opening_stock_goes_where_it_is_counted_and_a_form_with_no_place_goes_to_the_default(): void
    {
        $this->post('/adjustments', [
            'kind' => 'opening',
            'adjustment_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'reason' => 'opening',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 4, 'unit_cost' => 300]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(12, $this->at($this->warehouse));

        $this->post('/adjustments', [
            'kind' => 'increase',
            'adjustment_date' => Carbon::today()->toDateString(),
            'reason' => 'count',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_cost' => 300]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(11, $this->at($this->store));
        $this->assertSame([], app(InventoryLedger::class)->reconcileLocations());
    }

    public function test_the_adjustment_form_shows_on_hand_at_the_location_picked(): void
    {
        $this->get("/adjustments/create?location={$this->warehouse->id}")
            ->assertInertia(fn ($page) => $page
                ->where('locationId', $this->warehouse->id)
                ->where('products.0.on_hand', 8));
    }

    /* -------------------------------------------------------------- Returns */

    public function test_a_sales_return_goes_back_where_the_goods_were_sold(): void
    {
        $this->post('/sales', $this->sale(5))->assertSessionHasNoErrors();
        $sale = Sale::sole()->load('lines');

        $this->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Wrong colour',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 2]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(5, $this->at($this->warehouse));
        $this->assertSame(10, $this->at($this->store));

        $return = SalesReturn::sole();
        $this->getJson("/sales-returns/{$return->id}/preview")->assertJsonPath('salesReturn.location', 'Warehouse');
    }

    public function test_a_purchase_return_says_where_it_leaves_from(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'location_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 4, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::sole()->load('lines');

        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Dented',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(11, $this->at($this->warehouse));
        $return = PurchaseReturn::sole();
        $this->getJson("/purchase-returns/{$return->id}/preview")->assertJsonPath('purchaseReturn.location', 'Warehouse');
    }

    /* ------------------------------------------------------ The location list */

    public function test_a_location_named_only_by_an_order_is_never_deleted(): void
    {
        // Named only by a pending sales order: no stock ever moved there.
        $yard = $this->unaudited(fn () => Location::factory()->asWarehouse()->create(['name' => 'Yard']));
        $this->order(1, $yard, approve: false);

        $this->assertTrue($yard->fresh()->hasHistory());
        $this->delete("/locations/{$yard->id}")->assertForbidden();

        // Named only by a purchase order's Deliver to (phase 31.3).
        $depot = $this->unaudited(fn () => Location::factory()->asWarehouse()->create(['name' => 'Depot']));
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $this->post('/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'deliver_to_location_id' => $depot->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame($depot->id, (int) PurchaseOrder::sole()->deliver_to_location_id);

        $this->delete("/locations/{$depot->id}")->assertForbidden();

        // And the list, read in one go, says the same.
        $this->get('/locations')->assertInertia(fn ($page) => $page
            ->where('locations.data', fn ($rows) => collect($rows)
                ->whereIn('name', ['Yard', 'Depot'])
                ->every(fn ($row) => $row['can']['delete'] === false)));

        $this->assertDatabaseHas('locations', ['id' => $yard->id]);
        $this->assertDatabaseHas('locations', ['id' => $depot->id]);
    }
}
