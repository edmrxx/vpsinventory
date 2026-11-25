<?php

namespace Tests\Feature\Customers;

use App\Enums\DocumentStatus;
use App\Exceptions\PaymentException;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\CustomerPaymentPoster;
use App\Services\InventoryMetrics;
use App\Services\SalePoster;
use App\Support\CustomerCredit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 19: money a customer brings in against what they owe.
 *
 * Dela Cruz may owe ₱10,000 over 30 days. Each sale is two pieces at ₱925,
 * ₱1,850, all of it on account. A payment is placed against named invoices
 * and must add up to them exactly.
 */
class CustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $delaCruz;

    protected Product $product;

    protected PaymentMethod $cash;

    protected PaymentMethod $gcash;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->delaCruz = $this->unaudited(fn () => Customer::factory()->create([
            'name' => 'Dela Cruz',
            'credit_limit' => 10000,
            'payment_terms_days' => 30,
        ]));
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'CEM-40', 'selling_price' => 925])
        );

        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->gcash = PaymentMethod::where('name', 'GCash')->firstOrFail();
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 500],
        ], Carbon::today()->subDays(2));
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Staff')));
    }

    /** A sale of ₱1,850, all of it on account. */
    protected function sale(?Customer $customer = null): Sale
    {
        $this->actingAs($this->admin);

        return app(SalePoster::class)->post([
            'customer_id' => ($customer ?? $this->delaCruz)->id,
            'sale_date' => Carbon::today(),
            'on_account' => 1850,
            'payments' => [],
        ], [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]]);
    }

    /**
     * @param  list<array{0:Sale, 1:int|float|string}>  $against
     * @param  array<string, mixed>  $overrides
     */
    protected function form(array $against, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->delaCruz->id,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_method_id' => $this->cash->id,
            'amount' => array_sum(array_map(fn ($row) => (float) $row[1], $against)),
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => array_map(fn ($row) => ['sale_id' => $row[0]->id, 'amount' => $row[1]], $against),
        ], $overrides);
    }

    protected function pay(array $form, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->post('/customer-payments', $form);
    }

    /* ---------------------------------------------------------- Posting */

    public function test_a_partial_payment_comes_off_the_invoice(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500]]))->assertSessionHasNoErrors()->assertRedirect();

        $payment = CustomerPayment::firstOrFail();

        $this->assertSame('500.00', $payment->amount);
        $this->assertSame(DocumentStatus::Posted, $payment->status);
        $this->assertStringStartsWith('CP-', $payment->reference);
        $this->assertSame('1350.00', $sale->fresh()->balance_due);
        // The sale itself is untouched: what went on account stays as posted.
        $this->assertSame('1850.00', $sale->fresh()->on_account);
        $this->assertSame(135000, CustomerCredit::owed($this->delaCruz->id));
    }

    public function test_one_payment_can_pay_several_invoices_in_full(): void
    {
        $first = $this->sale();
        $second = $this->sale();

        $this->pay($this->form([[$first, 1850], [$second, 1850]]))->assertSessionHasNoErrors();

        $this->assertSame('0.00', $first->fresh()->balance_due);
        $this->assertSame('0.00', $second->fresh()->balance_due);
        $this->assertSame(0, CustomerCredit::owed($this->delaCruz->id));
        $this->assertSame(2, CustomerPayment::firstOrFail()->allocations()->count());
    }

    public function test_the_post_is_on_the_trail_with_the_invoices_it_paid(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500]]));

        $row = AuditTrail::where('action', 'post')->where('module', 'Customer Payments')->firstOrFail();

        $this->assertStringContainsString('Dela Cruz', $row->description);
        $this->assertStringContainsString($sale->reference.' 500.00', json_encode($row->properties));
    }

    public function test_a_payment_flushes_the_dashboard_figures(): void
    {
        $sale = $this->sale();
        $metrics = app(InventoryMetrics::class);

        $before = Cache::get('inventory_metrics.generation');

        $this->pay($this->form([[$sale, 500]]))->assertSessionHasNoErrors();

        $this->assertNotSame($before, Cache::get('inventory_metrics.generation'));
        $this->assertNotNull($metrics);
    }

    /* ---------------------------------------------------------- Refusals */

    public function test_an_invoice_cannot_take_more_than_it_owes(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 2000]]))->assertSessionHasErrors('allocations.0.amount');

        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame('1850.00', $sale->fresh()->balance_due);
    }

    public function test_money_not_placed_against_an_invoice_is_refused(): void
    {
        $sale = $this->sale();

        // Customer credit for the rest is stage 6, so it is refused.
        $this->pay($this->form([[$sale, 500]], ['amount' => 800]))->assertSessionHasErrors('allocations');
        $this->pay($this->form([[$sale, 500]], ['amount' => 300]))->assertSessionHasErrors('allocations');

        $this->assertSame(0, CustomerPayment::count());
    }

    public function test_another_customers_invoice_is_refused(): void
    {
        $other = $this->unaudited(fn () => Customer::factory()->create(['credit_limit' => 5000, 'payment_terms_days' => 7]));
        $theirs = $this->sale($other);

        $this->pay($this->form([[$theirs, 500]]))->assertSessionHasErrors('allocations.0.sale_id');
    }

    public function test_a_voided_or_paid_invoice_is_refused(): void
    {
        $voided = $this->sale();
        $this->actingAs($this->admin);
        app(SalePoster::class)->void($voided, 'Keyed twice');

        $this->pay($this->form([[$voided, 100]]))->assertSessionHasErrors('allocations.0.sale_id');

        $paid = $this->sale();
        $this->pay($this->form([[$paid, 1850]]))->assertSessionHasNoErrors();
        $this->pay($this->form([[$paid, 10]]))->assertSessionHasErrors('allocations.0.amount');
    }

    public function test_the_same_invoice_cannot_be_named_twice(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500], [$sale, 500]]))->assertSessionHasErrors('allocations.1.sale_id');
    }

    public function test_walk_in_cannot_pay_on_account(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500]], ['customer_id' => Customer::walkIn()->id]))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_the_date_cannot_be_in_the_future_or_before_the_invoice(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500]], ['payment_date' => Carbon::tomorrow()->toDateString()]))
            ->assertSessionHasErrors('payment_date');

        $this->pay($this->form([[$sale, 500]], ['payment_date' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHasErrors('payment_date');
    }

    public function test_a_method_that_needs_a_reference_gets_one(): void
    {
        $sale = $this->sale();

        $this->pay($this->form([[$sale, 500]], ['payment_method_id' => $this->gcash->id]))
            ->assertSessionHasErrors('method_reference');

        $this->pay($this->form([[$sale, 500]], ['payment_method_id' => $this->gcash->id, 'method_reference' => '0917-5551234']))
            ->assertSessionHasNoErrors();

        $this->assertSame('0917-5551234', CustomerPayment::firstOrFail()->method_reference);
    }

    public function test_a_switched_off_method_is_refused(): void
    {
        $sale = $this->sale();
        $this->unaudited(fn () => $this->gcash->update(['is_active' => false]));

        $this->pay($this->form([[$sale, 500]], ['payment_method_id' => $this->gcash->id, 'method_reference' => '1']))
            ->assertSessionHasErrors('payment_method_id');
    }

    /**
     * The form request weighed it against a plain read. A return taken off
     * the invoice in between must still stop the poster, which reads the
     * locked rows.
     */
    public function test_the_poster_weighs_it_again_against_the_locked_rows(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->admin);

        $sale->forceFill(['balance_due' => 100])->saveQuietly();

        $this->expectException(PaymentException::class);

        app(CustomerPaymentPoster::class)->post([
            'customer_id' => $this->delaCruz->id,
            'payment_date' => Carbon::today(),
            'payment_method_id' => $this->cash->id,
            'amount' => 500,
        ], [['sale_id' => $sale->id, 'amount' => 500]]);
    }

    /* ---------------------------------------------------------- A repeat */

    public function test_a_double_clicked_save_records_one_payment(): void
    {
        $sale = $this->sale();
        $form = $this->form([[$sale, 500]]);

        $this->pay($form)->assertSessionHasNoErrors();
        $this->pay($form)->assertSessionHasNoErrors()->assertSessionHas('info');

        $this->assertSame(1, CustomerPayment::count());
        $this->assertSame('1350.00', $sale->fresh()->balance_due);
    }

    public function test_the_same_key_with_a_different_payment_is_refused(): void
    {
        $sale = $this->sale();
        $form = $this->form([[$sale, 500]]);

        $this->pay($form)->assertSessionHasNoErrors();
        $this->pay(array_merge($form, ['amount' => 600, 'allocations' => [['sale_id' => $sale->id, 'amount' => 600]]]))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, CustomerPayment::count());
    }

    /* ---------------------------------------------------------- Voids */

    public function test_a_void_puts_the_balance_back(): void
    {
        $first = $this->sale();
        $second = $this->sale();
        $this->pay($this->form([[$first, 1850], [$second, 400]]));
        $payment = CustomerPayment::firstOrFail();

        $this->actingAs($this->admin)
            ->post("/customer-payments/{$payment->id}/void", ['reason' => 'Cheque bounced'])
            ->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Voided, $payment->fresh()->status);
        $this->assertSame('Cheque bounced', $payment->fresh()->void_reason);
        $this->assertSame('1850.00', $first->fresh()->balance_due);
        $this->assertSame('1850.00', $second->fresh()->balance_due);
        $this->assertSame([], CustomerCredit::reconcile());
    }

    public function test_a_payment_is_voided_once(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]]));
        $payment = CustomerPayment::firstOrFail();

        $this->actingAs($this->admin)->post("/customer-payments/{$payment->id}/void", ['reason' => 'Wrong customer']);
        $this->actingAs($this->admin)->post("/customer-payments/{$payment->id}/void", ['reason' => 'Again'])->assertForbidden();

        $this->assertSame('1850.00', $sale->fresh()->balance_due);
    }

    public function test_a_void_needs_a_reason(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]]));

        $this->actingAs($this->admin)
            ->post('/customer-payments/'.CustomerPayment::value('id').'/void', ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_sale_with_a_payment_against_it_cannot_be_voided(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]]));

        $this->actingAs($this->admin)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Keyed twice'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($sale->fresh()->isPosted());

        // The sale's own page says so by leaving the button off.
        $this->actingAs($this->admin)->getJson("/sales/{$sale->id}/preview")->assertJsonPath('can.void', false);

        // Once the payment is voided, the sale can be.
        $this->actingAs($this->admin)->post('/customer-payments/'.CustomerPayment::value('id').'/void', ['reason' => 'Wrong one']);
        $this->actingAs($this->admin)->post("/sales/{$sale->id}/void", ['reason' => 'Keyed twice'])->assertSessionHasNoErrors();

        $this->assertFalse($sale->fresh()->isPosted());
        $this->assertSame([], CustomerCredit::reconcile());
    }

    /**
     * A return after a part payment comes off what is still owed, and only
     * the rest goes back as cash: the payment is not handed back twice.
     */
    public function test_a_return_after_a_part_payment_takes_what_is_left_first(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 1500]]));

        // One piece back, ₱925: ₱350 was still owed, so ₱575 is cash.
        $this->actingAs($this->admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Wrong size',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', $sale->fresh()->balance_due);
        $this->assertEquals(350.0, (float) DB::table('sales_returns')->value('credited_amount'));
        $this->assertSame([], CustomerCredit::reconcile());
    }

    /* ---------------------------------------------------------- Who may */

    public function test_staff_may_record_a_payment_but_not_void_one(): void
    {
        $sale = $this->sale();
        $staff = $this->staff();

        $this->pay($this->form([[$sale, 500]]), $staff)->assertSessionHasNoErrors();

        $this->actingAs($staff)
            ->post('/customer-payments/'.CustomerPayment::value('id').'/void', ['reason' => 'Mistake'])
            ->assertForbidden();
    }

    public function test_without_the_permission_nothing_is_shown_or_recorded(): void
    {
        $sale = $this->sale();
        $nobody = $this->unaudited(fn () => User::factory()->create());

        $this->actingAs($nobody)->get('/customer-payments')->assertForbidden();
        $this->actingAs($nobody)->get('/aging')->assertForbidden();
        $this->actingAs($nobody)->get("/customers/{$this->delaCruz->id}/statement")->assertForbidden();
        $this->pay($this->form([[$sale, 500]]), $nobody)->assertForbidden();
    }

    public function test_a_method_a_customer_paid_with_cannot_be_deleted(): void
    {
        $bank = PaymentMethod::where('name', 'Bank transfer')->firstOrFail();
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]], ['payment_method_id' => $bank->id, 'method_reference' => 'BDO-1']));

        $this->actingAs($this->admin)->delete("/payment-methods/{$bank->id}")->assertForbidden();
        $this->assertNotNull($bank->fresh());
    }

    /* ---------------------------------------------------------- The screens */

    public function test_the_list_and_the_form_open(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]]));

        $this->actingAs($this->admin)->get('/customer-payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CustomerPayments/Index')->has('payments.data', 1));

        // Only customers who owe something can be picked; their invoices
        // arrive soonest due first.
        $this->actingAs($this->admin)->get("/customer-payments/create?customer={$this->delaCruz->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CustomerPayments/Create')
                ->has('customers', 1)
                ->where('invoices.0.id', $sale->id)
                ->where('invoices.0.balance_due', '1350.00'));
    }

    /* ---------------------------------------------------------- The check */

    public function test_the_verify_command_catches_a_balance_written_directly(): void
    {
        $sale = $this->sale();
        $this->pay($this->form([[$sale, 500]]));

        $this->artisan('inventory:verify')->assertSuccessful();

        DB::table('sales')->where('id', $sale->id)->update(['balance_due' => 1000]);

        $this->assertCount(1, CustomerCredit::reconcile());
        $this->artisan('inventory:verify')->assertFailed();
    }
}
