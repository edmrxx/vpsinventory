<?php

namespace Tests\Feature\Purchases;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 24.5 (CR-5): how a delivery is paid, and the Paid side of Payables.
 *
 * The client's request after the canvass meeting: which suppliers were paid
 * in cash and which are payables. A delivery is paid in full, part now with
 * the rest on account (a down payment), or all on account; whatever is paid
 * today names its method and, where the method asks, its reference. The Paid
 * tab, the Purchases list, an order and Reports read it — behind
 * `supplier_payments.view`, Admin only.
 *
 * Ten bags of cement at ₱100: a ₱1,000 delivery.
 */
class DeliveryPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Supplier $alpha;

    protected Supplier $bravo;

    protected Product $cement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->cement = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CEM-40']));
        $this->alpha = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Alpha Hardware', 'payment_terms_days' => 30]));
        $this->bravo = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Bravo Supply', 'payment_terms_days' => 15]));

        $this->actingAs($this->admin);
    }

    protected function method(string $name): PaymentMethod
    {
        return PaymentMethod::where('name', $name)->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    protected function deliver(Supplier $supplier, array $extra = [], ?Carbon $date = null)
    {
        return $this->post('/purchases', array_merge([
            'supplier_id' => $supplier->id,
            'purchase_date' => ($date ?? Carbon::today())->toDateString(),
            'lines' => [['product_id' => $this->cement->id, 'quantity' => 10, 'unit_cost' => '100.00']],
        ], $extra));
    }

    protected function delivered(Supplier $supplier, array $extra = [], ?Carbon $date = null): Purchase
    {
        $this->deliver($supplier, $extra, $date)->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------- Paying a delivery */

    public function test_a_cheque_method_is_there_and_asks_for_its_number(): void
    {
        $cheque = $this->method('Cheque');

        $this->assertTrue($cheque->is_active);
        $this->assertTrue($cheque->requires_reference);
    }

    public function test_paid_in_full_names_how_and_the_reference_the_method_asks_for(): void
    {
        $this->deliver($this->alpha, ['payment_method_id' => $this->method('Cheque')->id])
            ->assertSessionHasErrors('payment_reference');

        $purchase = $this->delivered($this->alpha, [
            'payment_method_id' => $this->method('Cheque')->id,
            'payment_reference' => ' BDO-004512 ',
        ]);

        $this->assertSame('1000.00', $purchase->paidOnDelivery());
        $this->assertSame('0.00', $purchase->on_account);
        $this->assertSame('0.00', $purchase->balance_due);
        $this->assertSame($this->method('Cheque')->id, $purchase->payment_method_id);
        $this->assertSame('BDO-004512', $purchase->payment_reference);

        $this->get("/purchases/{$purchase->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('purchase.paid_on_delivery', '1000.00')
                ->where('purchase.payment_method', 'Cheque')
                ->where('purchase.payment_reference', 'BDO-004512'));
    }

    public function test_a_form_with_no_method_at_all_is_paid_in_cash(): void
    {
        // A page opened before this phase: the rule a sale has for `payments`.
        $purchase = $this->delivered($this->alpha);

        $this->assertSame(PaymentMethod::cash()->id, $purchase->payment_method_id);
    }

    public function test_a_down_payment_pays_part_now_and_puts_the_rest_on_account(): void
    {
        $purchase = $this->delivered($this->bravo, [
            'on_account' => true,
            'paid_now' => '300.00',
            'payment_method_id' => PaymentMethod::cash()->id,
        ]);

        $this->assertSame('300.00', $purchase->paidOnDelivery());
        $this->assertSame('700.00', $purchase->on_account);
        $this->assertSame('700.00', $purchase->balance_due);
        $this->assertSame(Carbon::today()->addDays(15)->toDateString(), $purchase->due_date->toDateString());
        $this->assertSame(PaymentMethod::cash()->id, $purchase->payment_method_id);

        $this->assertSame(0, Artisan::call('inventory:verify'));
    }

    public function test_all_on_account_names_no_method(): void
    {
        $purchase = $this->delivered($this->bravo, [
            'on_account' => true,
            'payment_method_id' => $this->method('GCash')->id,
        ]);

        $this->assertSame('1000.00', $purchase->balance_due);
        $this->assertNull($purchase->payment_method_id);
        $this->assertNull($purchase->payment_reference);
    }

    public function test_more_than_the_bill_a_missing_method_or_one_switched_off_is_refused(): void
    {
        $this->deliver($this->bravo, ['on_account' => true, 'paid_now' => '1000.01', 'payment_method_id' => PaymentMethod::cash()->id])
            ->assertSessionHasErrors('paid_now');

        $this->deliver($this->bravo, ['on_account' => true, 'paid_now' => '300', 'payment_method_id' => ''])
            ->assertSessionHasErrors('payment_method_id');

        $maya = $this->method('Maya');
        $maya->forceFill(['is_active' => false])->save();

        $this->deliver($this->alpha, ['payment_method_id' => $maya->id, 'payment_reference' => 'M-1'])
            ->assertSessionHasErrors('payment_method_id');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_method_a_delivery_used_cannot_be_deleted(): void
    {
        $this->delivered($this->alpha, ['payment_method_id' => $this->method('Cheque')->id, 'payment_reference' => 'C-1']);

        $this->delete('/payment-methods/'.$this->method('Cheque')->id)->assertForbidden();
    }

    /* ------------------------------------------------------- Monitoring */

    public function test_the_paid_tab_shows_each_supplier_paid_and_owing_and_how(): void
    {
        // Alpha: paid in full in cash. Bravo: 300 down by cheque, 700 on
        // account, 200 of it paid later by bank transfer.
        $this->delivered($this->alpha);
        $bill = $this->delivered($this->bravo, [
            'on_account' => true,
            'paid_now' => '300.00',
            'payment_method_id' => $this->method('Cheque')->id,
            'payment_reference' => 'C-77',
        ]);

        $this->post('/supplier-payments', [
            'supplier_id' => $this->bravo->id,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method_id' => $this->method('Bank transfer')->id,
            'method_reference' => 'BT-1',
            'reference' => 'BT-1',
            'amount' => '200.00',
            'credit_used' => '0',
            'allocations' => [['purchase_id' => $bill->id, 'amount' => '200.00']],
        ])->assertSessionHasNoErrors();

        $this->get('/payables/paid')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Payables/Paid')
                ->where('summary.totals.bought', '2000.00')
                ->where('summary.totals.on_delivery', '1300.00')
                ->where('summary.totals.later', '200.00')
                ->where('summary.totals.paid', '1500.00')
                ->where('summary.totals.owed', '500.00')
                ->where('summary.rows', fn ($rows) => collect($rows)->firstWhere('supplier', 'Alpha Hardware') == [
                    'supplier_id' => $this->alpha->id, 'supplier' => 'Alpha Hardware',
                    'bought' => '1000.00', 'on_delivery' => '1000.00', 'later' => '0.00', 'owed' => '0.00', 'overdue' => '0.00', 'paid' => '1000.00',
                ] && collect($rows)->firstWhere('supplier', 'Bravo Supply')['owed'] === '500.00')
                ->where('summary.methods', fn ($methods) => collect($methods)->pluck('total', 'name')->all() == [
                    'Cash' => '1000.00', 'Cheque' => '300.00', 'Bank transfer' => '200.00',
                ]));

        // Narrowed to one supplier.
        $this->get('/payables/paid?supplier_id='.$this->alpha->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.totals.paid', '1000.00')
                ->has('summary.rows', 1));
    }

    public function test_a_delivery_before_the_period_counts_only_as_still_owed(): void
    {
        $this->delivered($this->bravo, ['on_account' => true], Carbon::today()->subMonthNoOverflow()->startOfMonth());

        $this->get('/payables/paid')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.totals.bought', '0.00')
                ->where('summary.totals.paid', '0.00')
                ->where('summary.totals.owed', '1000.00'));
    }

    public function test_the_purchases_list_says_paid_or_owing_and_filters_by_it(): void
    {
        $this->delivered($this->alpha);
        $this->delivered($this->bravo, ['on_account' => true]);

        $this->get('/purchases')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.seePayment', true)
                ->where('purchases.data', fn ($rows) => collect($rows)->pluck('payment.label')->sort()->values()->all() === ['Owing', 'Paid']));

        $this->get('/purchases?payment=owing')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('purchases.data', 1)
                ->where('purchases.data.0.supplier', 'Bravo Supply'));

        $this->get('/purchases?payment=paid')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('purchases.data', 1)
                ->where('purchases.data.0.payment.detail', 'Cash'));
    }

    public function test_an_order_says_what_its_deliveries_were_paid_and_still_owe(): void
    {
        $this->post('/purchase-orders', [
            'supplier_id' => $this->bravo->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->cement->id, 'quantity' => 10, 'unit_cost' => '100.00']],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::latest('id')->firstOrFail()->load('lines');

        $this->delivered($this->bravo, [
            'purchase_order_id' => $order->id,
            'on_account' => true,
            'paid_now' => '400.00',
            'payment_method_id' => PaymentMethod::cash()->id,
            'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'product_id' => $this->cement->id, 'quantity' => 10, 'unit_cost' => '100.00']],
        ]);

        $this->get("/purchase-orders/{$order->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.payment.paid', '400.00')
                ->where('order.payment.owed', '600.00'));
    }

    public function test_reports_show_the_money_paid_to_suppliers(): void
    {
        $this->delivered($this->alpha);

        $this->get('/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('payouts.totals.paid', '1000.00')
                ->where('payouts.methods.0.name', 'Cash'));
    }

    /* ---------------------------------------------------------- Who sees */

    public function test_staff_see_none_of_it(): void
    {
        $this->delivered($this->bravo, ['on_account' => true]);
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->get('/payables/paid')->assertForbidden();

        $this->actingAs($staff)->get('/purchases?payment=owing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.seePayment', false)
                ->where('purchases.data.0.payment', null));

        $this->actingAs($staff)->get('/reports')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('payouts', null));
    }
}
