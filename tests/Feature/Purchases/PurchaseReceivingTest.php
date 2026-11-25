<?php

namespace Tests\Feature\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Support\OrderReceiving;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 23: receiving against a purchase order.
 *
 * A delivery names the order it fills; each of its lines the order line it
 * fills. Partial deliveries leave the order partly received; the last one
 * makes it received. More than is still to come on a line is refused, goods
 * the order did not ask for are allowed and shown, and a cost different
 * from the one agreed is allowed and flagged. A void gives the goods back to
 * the order. A partly received order whose rest is not coming is closed
 * short.
 */
class PurchaseReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $wire;

    protected Product $tape;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20']));
        $this->tape = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TAPE-01']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());

        $this->actingAs($this->admin);
    }

    /** An order for 20 wire at 44.00 and 10 tape at 30.00. */
    protected function order(): PurchaseOrder
    {
        $this->post('/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [
                ['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '44.00'],
                ['product_id' => $this->tape->id, 'quantity' => 10, 'unit_cost' => '30.00'],
            ],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail()->load('lines');
    }

    /** @param  list<array<string, mixed>>  $lines */
    protected function deliver(PurchaseOrder $order, array $lines, array $extra = [])
    {
        return $this->post('/purchases', array_merge([
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'lines' => $lines,
        ], $extra));
    }

    protected function line(PurchaseOrder $order, Product $product, $quantity, string $cost): array
    {
        return [
            'purchase_order_line_id' => $order->lines->firstWhere('product_id', $product->id)->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $cost,
        ];
    }

    public function test_a_delivery_in_full_receives_the_order(): void
    {
        $order = $this->order();

        $this->deliver($order, [
            $this->line($order, $this->wire, 20, '44.00'),
            $this->line($order, $this->tape, 10, '30.00'),
        ])->assertSessionHasNoErrors();

        $order->refresh()->load('lines');
        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(20, $order->lines->firstWhere('product_id', $this->wire->id)->received_quantity);

        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertSame($order->id, (int) $purchase->purchase_order_id);
        $this->assertTrue($purchase->lines->every(fn ($line) => $line->purchase_order_line_id !== null));

        // The stock arrived through the ordinary delivery.
        $this->assertSame(20, $this->wire->fresh()->on_hand);
        $this->assertSame([], OrderReceiving::reconcile());
    }

    public function test_partial_deliveries_leave_the_order_open_until_the_last(): void
    {
        $order = $this->order();

        $this->deliver($order, [$this->line($order, $this->wire, 12, '44.00')])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::PartlyReceived, $order->refresh()->status);

        // The next delivery is offered only what is still to come.
        $this->get("/purchases/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromOrder.id', $order->id)
                ->has('fromOrder.lines', 2)
                ->where('fromOrder.lines.0.quantity', 8)
                ->where('fromOrder.lines.1.quantity', 10));

        $this->deliver($order, [
            $this->line($order, $this->wire, 8, '44.00'),
            $this->line($order, $this->tape, 10, '30.00'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);

        // Received in full: nothing more can come in against it.
        $this->get("/purchases/create?order={$order->id}")->assertRedirect('/purchases/create');
    }

    public function test_more_than_is_still_to_come_is_refused_but_extra_goods_may_come_on_their_own_line(): void
    {
        $order = $this->order();

        $this->deliver($order, [$this->line($order, $this->wire, 21, '44.00')])
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertDatabaseCount('purchases', 0);

        // The extra piece as a line of its own: goods the order did not ask for.
        $this->deliver($order, [
            $this->line($order, $this->wire, 20, '44.00'),
            ['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '44.00'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(21, $this->wire->fresh()->on_hand);
        $this->assertSame(PurchaseOrderStatus::PartlyReceived, $order->refresh()->status);

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->has('order.unordered', 1)
                ->where('order.unordered.0.quantity', 1)
                ->where('order.lines.0.outstanding', 0)
                ->where('order.lines.1.outstanding', 10));
    }

    public function test_a_delivery_must_belong_to_its_order(): void
    {
        $order = $this->order();
        $other = $this->unaudited(fn () => Supplier::factory()->create());

        // Another supplier.
        $this->deliver($order, [$this->line($order, $this->wire, 1, '44.00')], ['supplier_id' => $other->id])
            ->assertSessionHasErrors('supplier_id');

        // The order line names wire; the line says tape.
        $this->deliver($order, [array_merge($this->line($order, $this->wire, 1, '44.00'), ['product_id' => $this->tape->id])])
            ->assertSessionHasErrors('lines.0.product_id');

        // Nothing on it is tied to the order.
        $this->deliver($order, [['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '44.00']])
            ->assertSessionHasErrors('lines');

        // A line tied to an order, with no order picked.
        $this->post('/purchases', [
            'supplier_id' => $order->supplier_id,
            'purchase_date' => Carbon::today()->toDateString(),
            'lines' => [$this->line($order, $this->wire, 1, '44.00')],
        ])->assertSessionHasErrors('purchase_order_id');

        // A line from a different order.
        $second = $this->order();
        $this->deliver($order, [$this->line($second, $this->wire, 1, '44.00')])
            ->assertSessionHasErrors('lines.0.product_id');

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_a_cost_different_from_the_one_agreed_is_allowed_and_flagged(): void
    {
        $order = $this->order();

        $this->deliver($order, [$this->line($order, $this->wire, 20, '46.50')])->assertSessionHasNoErrors();

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('order.lines.0.agreed_piece_cost', '44.00')
                ->has('order.lines.0.cost_changes', 1)
                ->where('order.lines.0.cost_changes.0.piece_cost', '46.50')
                ->has('order.lines.1.cost_changes', 0));

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->get("/purchases/{$purchase->id}")
            ->assertInertia(fn ($page) => $page
                ->where('purchase.order.reference', $order->reference)
                ->where('purchase.lines.0.on_order', true)
                ->where('purchase.lines.0.agreed_piece_cost', '44.00'));
    }

    public function test_voiding_a_delivery_gives_its_goods_back_to_the_order(): void
    {
        $order = $this->order();

        $this->deliver($order, [
            $this->line($order, $this->wire, 20, '44.00'),
            $this->line($order, $this->tape, 10, '30.00'),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->refresh()->status);

        $purchase = Purchase::latest('id')->firstOrFail();
        $this->post("/purchases/{$purchase->id}/void", ['reason' => 'Booked twice'])->assertSessionHasNoErrors();

        $order->refresh()->load('lines');
        $this->assertSame(PurchaseOrderStatus::Open, $order->status);
        $this->assertSame(0, $order->lines->first()->received_quantity);
        $this->assertSame([], OrderReceiving::reconcile());

        // Nothing received again, so it may be cancelled once more.
        $this->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Ordered elsewhere'])->assertSessionHasNoErrors();
    }

    public function test_a_partly_received_order_is_closed_short_and_takes_nothing_more(): void
    {
        $order = $this->order();

        // Nothing received: it is cancelled, not closed short.
        $this->post("/purchase-orders/{$order->id}/close", ['reason' => 'Not coming'])->assertForbidden();

        $this->deliver($order, [$this->line($order, $this->wire, 20, '44.00')])->assertSessionHasNoErrors();

        // Goods are in: it can no longer be cancelled.
        $this->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertForbidden();

        $this->post("/purchase-orders/{$order->id}/close", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/purchase-orders/{$order->id}/close", ['reason' => 'Supplier stopped selling the tape'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->status);
        $this->assertSame('Supplier stopped selling the tape', $order->close_reason);

        $this->deliver($order, [$this->line($order, $this->tape, 10, '30.00')])
            ->assertSessionHasErrors('purchase_order_id');

        // Closed short is a decision: voiding the delivery keeps it closed.
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->post("/purchases/{$purchase->id}/void", ['reason' => 'Wrong invoice'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Closed, $order->refresh()->status);
    }

    public function test_a_box_ordered_is_offered_back_in_boxes_only_when_whole(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->wire->id, 'unit_id' => $box->id, 'factor' => 12]));

        $this->post('/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'unit_id' => $box->id, 'quantity' => 3, 'unit_cost' => '480.00']],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::latest('id')->firstOrFail()->load('lines');

        $this->get("/purchases/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromOrder.lines.0.unit_id', $box->id)
                ->where('fromOrder.lines.0.quantity', 3)
                ->where('fromOrder.lines.0.unit_cost', '480.00'));

        // 18 pieces arrive, typed by the piece at the agreed piece cost.
        $this->deliver($order, [[
            'purchase_order_line_id' => $order->lines->first()->id,
            'product_id' => $this->wire->id,
            'quantity' => 18,
            'unit_cost' => '40.00',
        ]])->assertSessionHasNoErrors();

        // 18 left: not whole boxes, so offered by the piece.
        $this->get("/purchases/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromOrder.lines.0.unit_id', '')
                ->where('fromOrder.lines.0.quantity', 18)
                ->where('fromOrder.lines.0.unit_cost', '40.00'));
    }

    public function test_the_outstanding_filter_and_late_mark_include_partly_received_orders(): void
    {
        $order = $this->order();
        $order->forceFill(['expected_date' => Carbon::yesterday()])->save();
        $this->deliver($order, [$this->line($order, $this->wire, 5, '44.00')])->assertSessionHasNoErrors();

        $this->get('/purchase-orders?status=outstanding')
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.status', 'partly_received')
                ->where('orders.data.0.late', true)
                ->where('outstanding', 1));
    }

    public function test_verify_finds_an_order_line_written_by_hand(): void
    {
        $order = $this->order();
        $this->deliver($order, [$this->line($order, $this->wire, 5, '44.00')])->assertSessionHasNoErrors();

        $this->artisan('inventory:verify')->assertSuccessful();

        DB::table('purchase_order_lines')->where('id', $order->lines->first()->id)->update(['received_quantity' => 7]);

        $this->assertCount(1, OrderReceiving::reconcile());
        $this->artisan('inventory:verify')->assertFailed();
    }
}
