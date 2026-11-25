<?php

namespace Tests\Feature\Purchases;

use App\Enums\AuditAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\PurchaseOrderException;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrderDesk;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 22: purchase orders.
 *
 * An approved request becomes an order, and closes saying so; an order may
 * also be placed directly. An order moves no stock and owes nothing. It is
 * never edited: it is cancelled with a reason.
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Product $wire;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20', 'cost' => 45]));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
    }

    /** @param  array<string, mixed>  $overrides */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'expected_date' => Carbon::today()->addDays(5)->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '44.00']],
        ], $overrides);
    }

    protected function place(array $overrides = []): PurchaseOrder
    {
        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form($overrides))
            ->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    /** A request raised by Staff and approved by Admin. */
    protected function approvedRequest(): PurchaseRequest
    {
        $this->actingAs($this->staff)->post('/purchase-requests', [
            'supplier_id' => $this->supplier->id,
            'reason' => 'Running low',
            'needed_by' => Carbon::today()->addDays(3)->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 30, 'unit_cost' => '45.00']],
        ])->assertSessionHasNoErrors();

        $request = PurchaseRequest::latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertSessionHasNoErrors();

        return $request->fresh();
    }

    /* ------------------------------------------------------------- Placing */

    public function test_an_order_is_placed_directly_and_moves_nothing(): void
    {
        $order = $this->place();

        $this->assertSame(PurchaseOrderStatus::Open, $order->status);
        $this->assertSame('PO-'.now()->year.'-0001', $order->reference);
        $this->assertSame($this->admin->id, (int) $order->ordered_by);
        $this->assertNull($order->purchase_request_id);
        $this->assertSame('880.00', $order->total_amount);
        $this->assertSame(20, $order->lines->first()->quantity);

        $this->assertSame(0, $this->wire->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('purchases', 0);

        $this->assertTrue(AuditTrail::where('action', AuditAction::Create->value)
            ->where('module', 'Purchase Orders')
            ->where('description', 'like', "%[{$order->reference}]%")
            ->exists());
    }

    public function test_the_vat_rate_is_stamped_from_the_supplier(): void
    {
        $vat = $this->unaudited(fn () => Supplier::factory()->create(['is_vat_registered' => true]));

        $this->assertSame('0.00', $this->place()->vat_rate);
        $this->assertSame('12.00', $this->place(['supplier_id' => $vat->id])->vat_rate);
    }

    public function test_a_line_typed_by_the_box_keeps_both_figures(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->wire->id, 'unit_id' => $box->id, 'factor' => 12]));

        $line = $this->place(['lines' => [['product_id' => $this->wire->id, 'unit_id' => $box->id, 'quantity' => 3, 'unit_cost' => '500.00']]])
            ->lines->first();

        $this->assertSame(3, $line->unit_quantity);
        $this->assertSame(36, $line->quantity);
        $this->assertSame('1500.00', $line->line_total);
    }

    public function test_the_form_is_checked(): void
    {
        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['supplier_id' => '', 'lines' => []]))
            ->assertSessionHasErrors(['supplier_id', 'lines']);

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['order_date' => Carbon::tomorrow()->toDateString()]))
            ->assertSessionHasErrors('order_date');

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['expected_date' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHasErrors('expected_date');

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['lines' => [['product_id' => $this->wire->id, 'quantity' => 2.5, 'unit_cost' => '1.00']]]))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_the_same_form_sent_twice_places_one_order(): void
    {
        $key = (string) Str::uuid();

        $first = $this->place(['idempotency_key' => $key]);

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['idempotency_key' => $key]))
            ->assertRedirect("/purchase-orders/{$first->id}");

        $this->assertDatabaseCount('purchase_orders', 1);
    }

    public function test_staff_read_orders_but_do_not_place_or_cancel_them(): void
    {
        $order = $this->place();

        $this->actingAs($this->staff)->get('/purchase-orders')->assertOk();
        $this->actingAs($this->staff)->get("/purchase-orders/{$order->id}")->assertOk();
        $this->actingAs($this->staff)->get('/purchase-orders/create')->assertForbidden();
        $this->actingAs($this->staff)->post('/purchase-orders', $this->form())->assertForbidden();
        $this->actingAs($this->staff)->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Mine to stop'])->assertForbidden();
    }

    /* ------------------------------------------------------ From a request */

    public function test_an_approved_request_fills_the_form_and_closes_when_ordered(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->admin)
            ->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.order', true));

        $this->actingAs($this->admin)
            ->get("/purchase-orders/create?request={$request->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseOrders/Create')
                ->where('fromRequest.id', $request->id)
                ->where('fromRequest.supplier_id', $this->supplier->id)
                ->where('fromRequest.lines.0.quantity', 30)
                ->where('fromRequest.lines.0.unit_cost', '45.00'));

        // The supplier agreed a lower price.
        $order = $this->place([
            'purchase_request_id' => $request->id,
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 30, 'unit_cost' => '43.50']],
        ]);

        $this->assertSame($request->id, (int) $order->purchase_request_id);
        $this->assertSame('1305.00', $order->total_amount);

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Closed, $request->status);
        $this->assertSame("Ordered on [{$order->reference}].", $request->close_reason);

        $this->actingAs($this->admin)
            ->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->where('request.orders.0.reference', $order->reference)
                ->where('can.order', false));
    }

    public function test_a_request_becomes_one_order_at_most(): void
    {
        $request = $this->approvedRequest();
        $this->place(['purchase_request_id' => $request->id]);

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['purchase_request_id' => $request->id]))
            ->assertSessionHasErrors('purchase_request_id');

        $this->actingAs($this->admin)
            ->get("/purchase-orders/create?request={$request->id}")
            ->assertRedirect('/purchase-orders/create');

        // And the desk refuses it under the lock, whatever the form said.
        $this->actingAs($this->admin);
        $this->expectException(PurchaseOrderException::class);
        app(PurchaseOrderDesk::class)->place(
            $this->form(['purchase_request_id' => $request->id]),
            $this->form()['lines'],
            $this->admin,
        );
    }

    public function test_only_an_approved_request_becomes_an_order(): void
    {
        $this->actingAs($this->staff)->post('/purchase-requests', [
            'reason' => 'Not decided yet',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '45.00']],
        ]);
        $pending = PurchaseRequest::latest('id')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/purchase-orders', $this->form(['purchase_request_id' => $pending->id]))
            ->assertSessionHasErrors('purchase_request_id');

        $this->assertSame(PurchaseRequestStatus::Pending, $pending->fresh()->status);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /* ---------------------------------------------------------- Cancelling */

    public function test_an_open_order_is_cancelled_with_a_reason_once(): void
    {
        $order = $this->place();

        $this->actingAs($this->admin)
            ->post("/purchase-orders/{$order->id}/cancel", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Supplier out of stock'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->status);
        $this->assertSame($this->admin->id, (int) $order->cancelled_by);
        $this->assertSame('Supplier out of stock', $order->cancel_reason);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Cancel->value)->exists());

        $this->actingAs($this->admin)
            ->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Again'])
            ->assertForbidden();
    }

    public function test_cancelling_an_order_leaves_its_request_closed(): void
    {
        $request = $this->approvedRequest();
        $order = $this->place(['purchase_request_id' => $request->id]);

        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Ordered elsewhere']);

        $this->assertSame(PurchaseRequestStatus::Closed, $request->fresh()->status);
    }

    /* --------------------------------------------------------------- Shape */

    public function test_an_order_is_never_edited_or_deleted(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'purchase-orders')) {
                continue;
            }

            $this->assertEmpty(
                array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']),
                "[{$route->uri()}] would edit or delete a purchase order."
            );
            $this->assertStringNotContainsString('edit', $route->uri());
        }
    }

    public function test_the_list_shows_what_is_still_open_and_what_is_late(): void
    {
        $late = $this->place(['order_date' => Carbon::today()->subDays(10)->toDateString(), 'expected_date' => Carbon::today()->subDay()->toDateString()]);
        $cancelled = $this->place();
        $this->actingAs($this->admin)->post("/purchase-orders/{$cancelled->id}/cancel", ['reason' => 'Not needed']);

        $this->actingAs($this->admin)
            ->get('/purchase-orders?status=open')
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseOrders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $late->id)
                ->where('orders.data.0.late', true)
                ->where('outstanding', 1));
    }

    public function test_a_supplier_product_or_unit_on_an_order_cannot_be_deleted(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->wire->id, 'unit_id' => $box->id, 'factor' => 12]));

        $this->place(['lines' => [['product_id' => $this->wire->id, 'unit_id' => $box->id, 'quantity' => 1, 'unit_cost' => '500.00']]]);

        $this->assertFalse($this->admin->can('delete', $this->supplier->fresh()));
        $this->assertFalse($this->admin->can('delete', $this->wire->fresh()));

        ProductUnit::query()->delete();
        $this->assertFalse($this->admin->can('delete', $box->fresh()));
    }
}
