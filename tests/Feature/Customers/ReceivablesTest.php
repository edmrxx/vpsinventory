<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\CustomerPaymentPoster;
use App\Services\DashboardOverview;
use App\Services\InventoryMetrics;
use App\Services\SalePoster;
use App\Services\SalesReturnPoster;
use App\Support\CustomerCredit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 19: what customers owe, read every way — the statement of account,
 * the aging buckets, the overdue and over-limit flags, and the dashboard.
 *
 * All of it is read by `CustomerCredit`, so these hold the one place to the
 * figures the documents add up to.
 */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $delaCruz;

    protected Product $product;

    protected PaymentMethod $cash;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->delaCruz = $this->unaudited(fn () => Customer::factory()->create([
            'name' => 'Dela Cruz',
            'credit_limit' => 20000,
            'payment_terms_days' => 30,
        ]));
        $this->product = $this->unaudited(fn () => Product::factory()->create(['selling_price' => 925]));
        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 500],
        ], Carbon::today()->subDays(3));

        $this->actingAs($this->admin);
    }

    protected function sale(Carbon $on, int|float $onAccount = 1850): Sale
    {
        return app(SalePoster::class)->post([
            'customer_id' => $this->delaCruz->id,
            'sale_date' => $on,
            'on_account' => $onAccount,
            'payments' => $onAccount < 1850 ? [['payment_method_id' => $this->cash->id, 'amount' => 1850 - $onAccount]] : [],
        ], [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]]);
    }

    protected function pay(Carbon $on, Sale $sale, int|float $amount): CustomerPayment
    {
        return app(CustomerPaymentPoster::class)->post([
            'customer_id' => $this->delaCruz->id,
            'payment_date' => $on,
            'payment_method_id' => $this->cash->id,
            'amount' => $amount,
        ], [['sale_id' => $sale->id, 'amount' => $amount]]);
    }

    /* ---------------------------------------------------- The statement */

    public function test_the_statement_runs_from_the_balance_brought_forward_to_what_is_owed_today(): void
    {
        $twoDaysAgo = Carbon::today()->subDays(2);
        $yesterday = Carbon::today()->subDay();

        $first = $this->sale($twoDaysAgo);             // +1,850
        $this->pay($yesterday, $first, 500);           // −500
        $second = $this->sale(Carbon::today(), 1000);  // +1,000 of 1,850
        app(SalesReturnPoster::class)->post([          // one piece, ₱925, all off the balance
            'sale_id' => $second->id,
            'return_date' => Carbon::today(),
            'reason' => 'Wrong size',
        ], [['sale_line_id' => $second->lines->first()->id, 'quantity' => 1]]);

        $statement = CustomerCredit::statement($this->delaCruz, $yesterday, Carbon::today());

        $this->assertSame('1850.00', $statement['opening']);
        $this->assertSame(['payment', 'sale', 'return'], array_column($statement['entries'], 'kind'));
        $this->assertSame(['1350.00', '2350.00', '1425.00'], array_column($statement['entries'], 'balance'));
        $this->assertSame('1000.00', $statement['charged']);
        $this->assertSame('1425.00', $statement['credited']);

        // Run to today, it closes on exactly what the customer owes.
        $this->assertSame(CustomerCredit::figure(CustomerCredit::owed($this->delaCruz->id)), $statement['closing']);
    }

    /**
     * SQLite keeps the time a `date` cast writes, MySQL does not: a period
     * ending on a day must still hold what was posted that day.
     */
    public function test_a_period_holds_what_happened_on_its_last_day(): void
    {
        $this->sale(Carbon::today());

        $statement = CustomerCredit::statement($this->delaCruz, Carbon::today(), Carbon::today());

        $this->assertCount(1, $statement['entries']);
        $this->assertSame('0.00', $statement['opening']);
        $this->assertSame('1850.00', $statement['closing']);
    }

    public function test_a_voided_payment_is_not_on_the_statement(): void
    {
        $sale = $this->sale(Carbon::today());
        $payment = $this->pay(Carbon::today(), $sale, 500);
        app(CustomerPaymentPoster::class)->void($payment, 'Cheque bounced');

        $statement = CustomerCredit::statement($this->delaCruz, Carbon::today(), Carbon::today());

        $this->assertSame(['sale'], array_column($statement['entries'], 'kind'));
        $this->assertSame('1850.00', $statement['closing']);
    }

    public function test_the_statement_page_opens_for_a_period(): void
    {
        $this->sale(Carbon::today());

        $this->get("/customers/{$this->delaCruz->id}/statement?from=".Carbon::today()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Statement')
                ->where('statement.closing', '1850.00')
                ->where('aging.rows.0.current', '1850.00'));

        // Walk-in never owes anything, so it has no statement.
        $this->get('/customers/'.Customer::walkIn()->id.'/statement')->assertNotFound();
    }

    /* ---------------------------------------------------- Aging */

    public function test_aging_puts_each_invoice_in_its_bucket(): void
    {
        $dues = [
            'current' => 0,     // falls due today: not yet past due
            'days_30' => 30,    // 30 days past due, the last day of 1–30
            'days_60' => 31,    // the first day of 31–60
            'days_90' => 90,    // the last day of 61–90
            'over_90' => 91,
        ];

        foreach ($dues as $back) {
            $this->sale(Carbon::today())->forceFill(['due_date' => Carbon::today()->subDays($back)])->saveQuietly();
        }

        $aging = CustomerCredit::aging(Carbon::today());
        $row = $aging['rows'][0];

        foreach (array_keys($dues) as $bucket) {
            $this->assertSame('1850.00', $row[$bucket], "The {$bucket} bucket.");
        }

        $this->assertSame('9250.00', $row['total']);
        $this->assertSame('9250.00', $aging['totals']['total']);
        $this->assertTrue($row['overdue']);
        $this->assertSame(5, $row['invoices']);
    }

    public function test_nothing_is_overdue_until_the_day_after_it_falls_due(): void
    {
        $this->sale(Carbon::today());

        $this->travelTo(Carbon::today()->addDays(30));
        $this->assertSame(0, CustomerCredit::overdue(Carbon::today())['cents']);

        $this->travelTo(Carbon::today()->addDay());
        $this->assertSame(185000, CustomerCredit::overdue(Carbon::today())['cents']);
        $this->assertSame('1850.00', CustomerCredit::aging(Carbon::today())['rows'][0]['days_30']);
    }

    public function test_a_payment_takes_an_invoice_out_of_aging(): void
    {
        $sale = $this->sale(Carbon::today());
        $this->pay(Carbon::today(), $sale, 1850);

        $this->assertSame([], CustomerCredit::aging(Carbon::today())['rows']);
        $this->get('/aging')->assertOk()->assertInertia(fn ($page) => $page->component('Aging/Index')->where('aging.rows', []));
    }

    /* ---------------------------------------------------- The flags */

    public function test_the_customer_list_flags_and_filters_who_is_behind(): void
    {
        $this->sale(Carbon::today());
        $other = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Bautista', 'credit_limit' => 5000, 'payment_terms_days' => 7]));

        $this->travelTo(Carbon::today()->addDays(31));

        $this->get('/customers?balance=overdue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Dela Cruz')
                ->where('customers.data.0.flags.overdue', true)
                ->where('customers.data.0.flags.over_limit', false)
                ->where('customers.data.0.overdue', '1850.00'));

        // A limit lowered under what is owed puts them over it.
        $this->unaudited(fn () => $this->delaCruz->update(['credit_limit' => 1000]));

        $this->get('/customers?balance=over_limit')
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.flags.over_limit', true));

        $this->assertNotNull($other);
    }

    public function test_the_customer_page_and_the_till_say_what_is_overdue(): void
    {
        $this->sale(Carbon::today());
        $this->travelTo(Carbon::today()->addDays(31));

        $this->get("/customers/{$this->delaCruz->id}")
            ->assertInertia(fn ($page) => $page
                ->where('flags.overdue', true)
                ->where('flags.overdue_amount', '1850.00')
                ->where('unpaid.0.overdue', true)
                ->where('can.pay', true));

        $options = collect(Customer::options(activeOnly: true, withCredit: true))->firstWhere('value', $this->delaCruz->id);

        $this->assertSame('1850.00', $options['credit']['overdue']);
    }

    /* ---------------------------------------------------- The dashboard */

    public function test_the_overdue_card_counts_what_is_past_due_today(): void
    {
        $this->sale(Carbon::today());
        $this->sale(Carbon::today());

        $this->travelTo(Carbon::today()->addDays(31));

        $overview = app(DashboardOverview::class);
        app(InventoryMetrics::class)->flush();

        $cards = $overview->cards(Carbon::today(), Carbon::today(), [], ['sales' => true, 'receivables' => true]);
        $card = collect($cards['cards'])->firstWhere('key', 'overdue_balances');

        $this->assertSame('ok', $card['state']);
        $this->assertEquals(3700, $card['value']);
        $this->assertSame('/aging', $card['href']);

        // Without the permission to open the report, the card links nowhere.
        app(InventoryMetrics::class)->flush();
        $card = collect($overview->cards(Carbon::today(), Carbon::today(), [], ['sales' => true])['cards'])
            ->firstWhere('key', 'overdue_balances');
        $this->assertNull($card['href']);

        $list = collect($overview->exceptions(Carbon::today(), Carbon::today(), [], ['sales' => true, 'customers' => true]))
            ->firstWhere('key', 'overdue_balances');

        $this->assertSame('ok', $list['state']);
        $this->assertSame('Dela Cruz', $list['rows'][0]['title']);
        $this->assertSame('1 day overdue', $list['rows'][0]['figure_note']);
    }

    public function test_money_paid_against_invoices_is_collected_on_the_day_it_came_in(): void
    {
        $yesterday = Carbon::today()->subDay();
        $sale = $this->sale($yesterday);
        $this->pay(Carbon::today(), $sale, 600);

        $metrics = app(InventoryMetrics::class);

        // Yesterday the sale took nothing; today the customer paid ₱600.
        $this->assertEquals(0, $metrics->collections($yesterday, $yesterday)['total']);
        $today = $metrics->collections(Carbon::today(), Carbon::today());
        $this->assertEquals(600, $today['total']);
        $this->assertEquals(600, $today['account']);

        // The till's day adds up to its own sales only.
        $this->assertSame([], $metrics->collections(Carbon::today(), Carbon::today(), accountPayments: false)['methods']);

        // Net cash from trading counts it today, as money in.
        app(InventoryMetrics::class)->flush();
        $cash = collect(app(DashboardOverview::class)->cards(Carbon::today(), Carbon::today(), [], ['sales' => true, 'purchases' => true])['cards'])
            ->firstWhere('key', 'cash_position');
        $this->assertEquals(600, $cash['value']);

        // A voided payment counts for nothing.
        app(CustomerPaymentPoster::class)->void(CustomerPayment::firstOrFail(), 'Wrong customer');
        $this->assertEquals(0, $metrics->collections(Carbon::today(), Carbon::today())['total']);
    }
}
