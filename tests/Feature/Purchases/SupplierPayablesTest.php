<?php

namespace Tests\Feature\Purchases;

use App\Enums\DocumentStatus;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\DashboardOverview;
use App\Services\SupplierPaymentPoster;
use App\Support\SupplierAccount;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 24: supplier payables.
 *
 * A delivery is paid on delivery or put on the supplier's account, due by
 * the supplier's terms. A supplier payment pays named bills, with money,
 * supplier credit or both. A purchase return comes off an unpaid bill first;
 * on a paid bill it stays as credit with the supplier.
 */
class SupplierPayablesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $cement;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->cement = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CEM-40']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create(['payment_terms_days' => 30]));

        $this->actingAs($this->admin);
    }

    /** A delivery of 10 bags at the given price, on account or paid. */
    protected function deliver(bool $onAccount = true, string $price = '100.00', int $quantity = 10, ?string $date = null): Purchase
    {
        $this->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => $date ?? Carbon::today()->toDateString(),
            'on_account' => $onAccount,
            'lines' => [['product_id' => $this->cement->id, 'quantity' => $quantity, 'unit_cost' => $price]],
        ])->assertSessionHasNoErrors();

        return Purchase::latest('id')->firstOrFail();
    }

    protected function pay(array $bills, string $amount, string $credit = '0', array $extra = [])
    {
        return $this->post('/supplier-payments', array_merge([
            'supplier_id' => $this->supplier->id,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method_id' => PaymentMethod::cash()->id,
            'amount' => $amount,
            'credit_used' => $credit,
            'allocations' => array_map(fn ($bill) => ['purchase_id' => $bill[0]->id, 'amount' => $bill[1]], $bills),
        ], $extra));
    }

    protected function returnGoods(Purchase $purchase, int $quantity): PurchaseReturn
    {
        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Damaged bags',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => $quantity]],
        ])->assertSessionHasNoErrors();

        return PurchaseReturn::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------- Bills */

    public function test_a_delivery_on_account_is_a_bill_due_by_the_suppliers_terms(): void
    {
        $bill = $this->deliver();

        $this->assertSame('1000.00', $bill->on_account);
        $this->assertSame('1000.00', $bill->balance_due);
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $bill->due_date->toDateString());
        $this->assertSame(100000, SupplierAccount::owed($this->supplier->id));
    }

    public function test_a_delivery_paid_on_delivery_owes_nothing(): void
    {
        $bill = $this->deliver(onAccount: false);

        $this->assertSame('0.00', $bill->on_account);
        $this->assertSame('0.00', $bill->balance_due);
        $this->assertNull($bill->due_date);
    }

    public function test_the_suppliers_terms_are_set_on_its_form_and_kept_when_left_out(): void
    {
        $this->put("/suppliers/{$this->supplier->id}", [
            'code' => $this->supplier->code,
            'name' => $this->supplier->name,
            'is_vat_registered' => false,
            'is_active' => true,
            'payment_terms_days' => 15,
        ])->assertSessionHasNoErrors();

        $this->assertSame(15, $this->supplier->fresh()->payment_terms_days);

        // A form that leaves the terms out keeps them.
        $this->put("/suppliers/{$this->supplier->id}", [
            'code' => $this->supplier->code,
            'name' => 'Renamed',
            'is_vat_registered' => false,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(15, $this->supplier->fresh()->payment_terms_days);
    }

    /* ---------------------------------------------------------- Payments */

    public function test_a_payment_pays_named_bills_and_a_void_puts_it_back(): void
    {
        $first = $this->deliver();
        $second = $this->deliver(price: '50.00');

        $this->pay([[$first, '1000.00'], [$second, '200.00']], '1200.00', extra: ['method_reference' => null])
            ->assertSessionHasNoErrors();

        $payment = SupplierPayment::firstOrFail();
        $this->assertSame('SP-'.now()->year.'-0001', $payment->reference);
        $this->assertSame('0.00', $first->fresh()->balance_due);
        $this->assertSame('300.00', $second->fresh()->balance_due);
        $this->assertSame(30000, SupplierAccount::owed($this->supplier->id));

        // A bill something was paid against cannot be voided.
        $this->post("/purchases/{$first->id}/void", ['reason' => 'Wrong supplier'])->assertSessionHasErrors('reason');

        $this->post("/supplier-payments/{$payment->id}/void", ['reason' => 'Cheque bounced'])->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Voided, $payment->fresh()->status);
        $this->assertSame('1000.00', $first->fresh()->balance_due);
        $this->assertSame('500.00', $second->fresh()->balance_due);
        $this->assertSame([], SupplierAccount::reconcile());
    }

    public function test_a_payment_is_checked_against_the_bills(): void
    {
        $bill = $this->deliver();
        $other = $this->unaudited(fn () => Supplier::factory()->create());

        // More than the bill owes.
        $this->pay([[$bill, '1200.00']], '1200.00')->assertSessionHasErrors('allocations.0.amount');

        // Money nobody placed.
        $this->pay([[$bill, '500.00']], '600.00')->assertSessionHasErrors('allocations');

        // Somebody else's bill.
        $this->pay([[$bill, '500.00']], '500.00', extra: ['supplier_id' => $other->id])
            ->assertSessionHasErrors('allocations.0.purchase_id');

        // In the future.
        $this->pay([[$bill, '500.00']], '500.00', extra: ['payment_date' => Carbon::tomorrow()->toDateString()])
            ->assertSessionHasErrors('payment_date');

        // Nothing paid at all.
        $this->pay([[$bill, '0']], '0')->assertSessionHasErrors();

        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_staff_cannot_see_or_pay_suppliers(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->get('/payables')->assertForbidden();
        $this->actingAs($staff)->get('/supplier-payments')->assertForbidden();
        $this->actingAs($staff)->get('/supplier-payments/create')->assertForbidden();
    }

    /* ------------------------------------------------- Returns and credit */

    public function test_a_return_on_an_unpaid_bill_comes_off_it(): void
    {
        $bill = $this->deliver();
        $return = $this->returnGoods($bill, 2);

        $this->assertSame('200.00', $return->credited_amount);
        $this->assertSame('0.00', $return->supplier_credit_amount);
        $this->assertSame('800.00', $bill->fresh()->balance_due);
        $this->assertSame(0, SupplierAccount::credit($this->supplier->id));
    }

    public function test_a_return_on_a_paid_bill_becomes_credit_used_on_a_later_bill(): void
    {
        $paid = $this->deliver(onAccount: false);
        $return = $this->returnGoods($paid, 3);

        $this->assertSame('0.00', $return->credited_amount);
        $this->assertSame('300.00', $return->supplier_credit_amount);
        $this->assertSame(30000, SupplierAccount::credit($this->supplier->id));

        $later = $this->deliver(price: '50.00');

        // More credit than the supplier has is refused.
        $this->pay([[$later, '500.00']], '100.00', '400.00')->assertSessionHasErrors('credit_used');

        // 300 of credit and 200 of money pay the 500 bill.
        $this->pay([[$later, '500.00']], '200.00', '300.00')->assertSessionHasNoErrors();

        $this->assertSame('0.00', $later->fresh()->balance_due);
        $this->assertSame(0, SupplierAccount::credit($this->supplier->id));

        // Voided, the credit is the supplier's to use again.
        $payment = SupplierPayment::latest('id')->firstOrFail();
        $this->post("/supplier-payments/{$payment->id}/void", ['reason' => 'Booked twice'])->assertSessionHasNoErrors();
        $this->assertSame(30000, SupplierAccount::credit($this->supplier->id));
        $this->assertSame([], SupplierAccount::reconcile());
    }

    public function test_credit_alone_needs_no_payment_method(): void
    {
        $paid = $this->deliver(onAccount: false);
        $this->returnGoods($paid, 2);
        $later = $this->deliver(price: '10.00', quantity: 20);

        $this->pay([[$later, '200.00']], '0', '200.00', ['payment_method_id' => ''])->assertSessionHasNoErrors();

        $payment = SupplierPayment::latest('id')->firstOrFail();
        $this->assertNull($payment->payment_method_id);
        $this->assertSame('200.00', $payment->credit_used);
    }

    public function test_the_poster_weighs_the_credit_again_under_the_lock(): void
    {
        $paid = $this->deliver(onAccount: false);
        $this->returnGoods($paid, 1);
        $later = $this->deliver(price: '50.00');

        $this->expectException(PaymentException::class);

        app(SupplierPaymentPoster::class)->post([
            'supplier_id' => $this->supplier->id,
            'payment_date' => Carbon::today(),
            'credit_used' => '150.00',
            'amount' => 0,
        ], [['purchase_id' => $later->id, 'amount' => '150.00']]);
    }

    /* ------------------------------------------------------------ Screens */

    public function test_payables_lists_what_is_owed_with_totals_over_the_filtered_rows(): void
    {
        $this->deliver(date: Carbon::today()->subDays(40)->toDateString());   // 10 days past due
        $this->deliver(price: '50.00');                                        // not yet due
        $this->deliver(onAccount: false);                                      // paid: not listed

        $this->get('/payables')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payables/Index')
                ->has('bills.data', 2)
                ->where('totals.total', '1500.00')
                ->where('totals.overdue', '1000.00')
                ->where('totals.bills', 2)
                ->where('totals.suppliers', 1)
                ->where('totals.buckets.0.amount', '500.00')
                ->where('totals.buckets.1.amount', '1000.00'));

        $this->get('/payables?status=overdue')
            ->assertInertia(fn ($page) => $page->has('bills.data', 1)->where('totals.total', '1000.00'));
    }

    public function test_the_supplier_page_shows_its_account(): void
    {
        $this->deliver();

        $this->get("/suppliers/{$this->supplier->id}")
            ->assertInertia(fn ($page) => $page
                ->where('account.owed', '1000.00')
                ->where('account.terms_days', 30)
                ->has('account.bills', 1)
                ->where('can.pay', true));
    }

    public function test_a_payment_is_never_edited_or_deleted(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'supplier-payments')) {
                continue;
            }

            $this->assertEmpty(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']));
        }
    }

    /* ---------------------------------------------------------- Dashboard */

    public function test_net_cash_counts_a_bill_when_it_is_paid_and_payables_shows_what_is_owed(): void
    {
        $overview = app(DashboardOverview::class);
        [$from, $to] = $overview->resolve('today');
        $may = ['sales' => true, 'purchases' => true, 'payables' => true];

        $cash = fn () => (float) collect($overview->cards($from, $to, [], $may)['cards'])->firstWhere('key', 'cash_position')['value'];
        $payables = fn () => collect($overview->cards($from, $to, [], $may)['secondary'])->firstWhere('key', 'payables');

        // Paid on delivery: 1,000 out today.
        $this->deliver(onAccount: false);
        $this->assertSame(-1000.0, $cash());

        // On account: nothing more out yet, but owed.
        $bill = $this->deliver(price: '50.00');
        $this->assertSame(-1000.0, $cash());
        $this->assertSame('500.00', $payables()['value']);

        // Paid: out now.
        $this->pay([[$bill, '500.00']], '500.00')->assertSessionHasNoErrors();
        $this->assertSame(-1500.0, $cash());
        $this->assertSame('0.00', $payables()['value']);
    }

    public function test_verify_finds_a_bill_written_by_hand(): void
    {
        $bill = $this->deliver();
        $this->artisan('inventory:verify')->assertSuccessful();

        DB::table('purchases')->where('id', $bill->id)->update(['balance_due' => 1]);

        $this->assertCount(1, SupplierAccount::reconcile());
        $this->artisan('inventory:verify')->assertFailed();
    }
}
