<?php

namespace Tests\Feature\Sales;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\SalesOrderException;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\SalePoster;
use App\Services\SalesOrderDesk;
use App\Support\Reservations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 29: sales orders with reserved stock.
 *
 * Taken pending (holding nothing); approved, an order reserves its stock
 * until a date, and no other sale may take it; it becomes a sale on the sale
 * form, or is cancelled, which releases the stock; past its date it holds
 * nothing. A live quotation can become an order.
 */
class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Customer $customer;

    protected Customer $walkIn;

    protected Product $paint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->customer = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Santos Builders']));
        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'selling_price' => 500]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->paint->id, 'quantity' => 10, 'unit_cost' => 300],
        ], Carbon::today()->subDay());
    }

    protected function take(User $by, array $overrides = []): SalesOrder
    {
        $this->actingAs($by)->post('/sales-orders', array_merge([
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 6, 'unit_price' => '480.00']],
        ], $overrides))->assertSessionHasNoErrors();

        return SalesOrder::latest('id')->firstOrFail();
    }

    protected function approve(SalesOrder $order, ?string $until = null)
    {
        return $this->actingAs($this->admin)->post("/sales-orders/{$order->id}/approve", [
            'reserve_until' => $until ?? Carbon::today()->addDays(7)->toDateString(),
        ]);
    }

    /** A walk-in sale at the counter, as the browser posts it. */
    protected function counterSale(int $quantity)
    {
        return $this->actingAs($this->admin)->post('/sales', [
            'customer_id' => $this->walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => $quantity, 'unit_price' => '500.00']],
        ]);
    }

    protected function reserved(): int
    {
        return Reservations::reserved([$this->paint->id])[$this->paint->id] ?? 0;
    }

    /* ------------------------------------------------------------ Taking */

    public function test_staff_take_an_order_that_holds_nothing_until_approved(): void
    {
        $order = $this->take($this->staff);

        $this->assertSame('SO-'.now()->year.'-0001', $order->reference);
        $this->assertSame(SalesOrderStatus::Pending, $order->status);
        $this->assertSame('2880.00', $order->total_amount);
        $this->assertSame(0, $this->reserved());
        $this->assertSame(10, $this->paint->fresh()->on_hand);

        // So all ten can still be sold at the counter.
        $this->counterSale(10)->assertSessionHasNoErrors();
    }

    public function test_staff_cannot_approve(): void
    {
        $order = $this->take($this->staff);

        $this->actingAs($this->staff)->post("/sales-orders/{$order->id}/approve", [
            'reserve_until' => Carbon::today()->addDays(3)->toDateString(),
        ])->assertForbidden();

        $this->actingAs($this->staff)->post('/sales-orders', [
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '480.00']],
        ])->assertSessionHasErrors('approve');
    }

    /* --------------------------------------------------------- Reserving */

    public function test_an_approved_order_holds_its_stock_from_everybody_else(): void
    {
        $order = $this->take($this->staff);
        $this->approve($order)->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(SalesOrderStatus::Approved, $order->status);
        $this->assertSame($this->admin->id, (int) $order->approved_by);
        $this->assertSame(6000, $this->reserved());
        $this->assertTrue(AuditTrail::where('action', AuditAction::Approve->value)->where('module', 'Sales Orders')->exists());

        // Only four are free at the counter.
        $this->counterSale(5)->assertSessionHasErrors('lines.0.quantity');
        $this->counterSale(4)->assertSessionHasNoErrors();
        $this->assertSame(6, $this->paint->fresh()->on_hand);

        // The Stock screen and the selling forms say so.
        $this->actingAs($this->admin)->get('/stock')
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.reserved', 6)
                ->where('stock.data.0.available', 0));
    }

    public function test_the_poster_refuses_reserved_stock_whatever_the_form_said(): void
    {
        $order = $this->take($this->staff);
        $this->approve($order)->assertSessionHasNoErrors();

        $this->actingAs($this->admin);
        $this->expectException(InventoryException::class);

        app(SalePoster::class)->post([
            'customer_id' => $this->walkIn->id,
            'sale_date' => Carbon::today(),
        ], [['product_id' => $this->paint->id, 'quantity' => 5, 'unit_price' => '500.00']]);
    }

    public function test_an_order_is_not_approved_beyond_the_free_stock(): void
    {
        $this->approve($this->take($this->staff))->assertSessionHasNoErrors();
        $second = $this->take($this->staff);

        // 6 of 10 are held; the second order wants 6 more.
        $this->approve($second)->assertSessionHasErrors('reserve_until');
        $this->assertSame(SalesOrderStatus::Pending, $second->fresh()->status);

        // And the desk refuses it too.
        $this->actingAs($this->admin);
        $this->expectException(SalesOrderException::class);
        app(SalesOrderDesk::class)->approve($second, $this->admin, Carbon::today()->addDays(2));
    }

    public function test_an_approver_may_approve_while_taking_the_order(): void
    {
        $order = $this->take($this->admin, ['approve' => true]);

        $this->assertSame(SalesOrderStatus::Approved, $order->status);
        $this->assertSame(6000, $this->reserved());
        $this->assertTrue(AuditTrail::where('description', 'like', 'Approved their own sales order%')->exists());

        // Too much to hold: refused whole, nothing saved.
        $this->actingAs($this->admin)->post('/sales-orders', [
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 5, 'unit_price' => '480.00']],
        ])->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('sales_orders', 1);
    }

    /* ------------------------------------------------------- Fulfilling */

    public function test_an_approved_order_becomes_a_sale_taking_its_own_reservation(): void
    {
        $order = $this->take($this->staff);
        $this->approve($order)->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get("/sales/create?order={$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromOrder.id', $order->id)
                ->where('fromOrder.lines.0.quantity', 6)
                // The order's own six are free for this sale.
                ->where('products.0.on_hand', 10));

        $this->actingAs($this->admin)->post('/sales', [
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '2880.00']],
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 6, 'unit_price' => '480.00']],
        ])->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $order->refresh();
        $this->assertSame(SalesOrderStatus::Fulfilled, $order->status);
        $this->assertSame($sale->id, (int) $order->sale_id);
        $this->assertSame(0, $this->reserved());
        $this->assertSame(4, $this->paint->fresh()->on_hand);

        $this->actingAs($this->admin)->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page->where('sale.sales_order.reference', $order->reference));
    }

    public function test_a_pending_order_cannot_become_a_sale(): void
    {
        $order = $this->take($this->staff);

        $this->actingAs($this->admin)->get("/sales/create?order={$order->id}")->assertRedirect('/sales/create');
        $this->actingAs($this->admin)->post('/sales', [
            'sales_order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 6, 'unit_price' => '480.00']],
        ])->assertSessionHasErrors('sales_order_id');
    }

    /* ------------------------------------------- Releasing and lapsing */

    public function test_cancelling_an_approved_order_releases_its_stock(): void
    {
        $order = $this->take($this->staff);
        $this->approve($order)->assertSessionHasNoErrors();

        $this->actingAs($this->staff)->post("/sales-orders/{$order->id}/cancel", ['reason' => 'Customer changed their mind'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SalesOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, $this->reserved());
        $this->assertTrue(AuditTrail::where('description', 'like', 'Released sales order%')->exists());

        $this->counterSale(10)->assertSessionHasNoErrors();
    }

    public function test_past_its_date_an_order_holds_nothing(): void
    {
        $order = $this->take($this->staff);
        $this->approve($order)->assertSessionHasNoErrors();
        $order->forceFill(['reserve_until' => Carbon::yesterday()])->save();

        $this->assertSame(0, $this->reserved());

        $this->actingAs($this->admin)->get("/sales-orders/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('order.status', 'expired')
                ->where('can.sell', true));

        $this->counterSale(10)->assertSessionHasNoErrors();
    }

    /* ------------------------------------------------------ From a quote */

    public function test_a_live_quotation_becomes_an_order_and_is_accepted(): void
    {
        $this->actingAs($this->staff)->post('/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->addDays(10)->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 3, 'unit_price' => '470.00']],
        ])->assertSessionHasNoErrors();
        $quotation = Quotation::latest('id')->firstOrFail();

        $this->actingAs($this->staff)->get("/quotations/{$quotation->id}")
            ->assertInertia(fn ($page) => $page->where('can.order', true));

        $this->actingAs($this->staff)->get("/sales-orders/create?quotation={$quotation->id}")
            ->assertInertia(fn ($page) => $page
                ->where('fromQuotation.id', $quotation->id)
                ->where('fromQuotation.lines.0.unit_price', '470.00'));

        $order = $this->take($this->staff, [
            'quotation_id' => $quotation->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 3, 'unit_price' => '470.00']],
        ]);

        $this->assertSame($quotation->id, (int) $order->quotation_id);
        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);

        // Accepted: it cannot become an order or a sale again.
        $this->actingAs($this->staff)->post('/sales-orders', [
            'quotation_id' => $quotation->id,
            'customer_id' => $this->customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 3, 'unit_price' => '470.00']],
        ])->assertSessionHasErrors('quotation_id');
    }

    /* --------------------------------------------------------------- Shape */

    public function test_an_order_is_never_edited_or_deleted(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'sales-orders')) {
                continue;
            }

            $this->assertEmpty(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']));
            $this->assertStringNotContainsString('edit', $route->uri());
        }
    }

    public function test_the_list_marks_what_this_person_may_approve(): void
    {
        $this->take($this->staff);

        $this->actingAs($this->admin)->get('/sales-orders?status=pending')
            ->assertInertia(fn ($page) => $page
                ->component('SalesOrders/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.can.approve', true)
                ->where('pending', 1));

        $this->actingAs($this->staff)->get('/sales-orders')
            ->assertInertia(fn ($page) => $page->where('orders.data.0.can.approve', false));
    }

    public function test_a_customer_or_product_on_an_order_cannot_be_deleted(): void
    {
        $this->take($this->staff);

        $this->assertFalse($this->admin->can('delete', $this->customer->fresh()));
        $this->assertFalse($this->admin->can('delete', $this->paint->fresh()));
    }
}
