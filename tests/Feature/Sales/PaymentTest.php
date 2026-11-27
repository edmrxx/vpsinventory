<?php

namespace Tests\Feature\Sales;

use App\Exceptions\PaymentException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\DashboardOverview;
use App\Services\InventoryLedger;
use App\Services\InventoryMetrics;
use App\Services\SalePoster;
use App\Support\PaymentSettlement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 16: how a sale was paid.
 *
 * A sale of ₱1,850 — two pieces at ₱925 — paid in cash, by GCash, or both.
 * Cash may be more than is owed and the rest is change; nothing else may.
 * The payment rows always add up to the sale's total, so Collections can be
 * read from them.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Product $product;

    protected PaymentMethod $cash;

    protected PaymentMethod $gcash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'CEM-40', 'selling_price' => 925])
        );

        // Put there by the migration, so a server deployed without db:seed
        // still has them.
        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->gcash = PaymentMethod::where('name', 'GCash')->firstOrFail();

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 600],
        ]);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '925.00'],
            ],
        ], $overrides);
    }

    protected function cash(string $handed): array
    {
        return ['payment_method_id' => $this->cash->id, 'amount' => $handed];
    }

    protected function gcash(string $amount, ?string $reference = '1234 5678 9012'): array
    {
        return ['payment_method_id' => $this->gcash->id, 'amount' => $amount, 'reference' => $reference];
    }

    /* -------------------------------------------------------- The methods */

    public function test_the_four_methods_arrive_with_the_migration(): void
    {
        // And Cheque, put in by phase 24.5 for paying suppliers.
        $this->assertSame(
            ['Bank transfer', 'Cash', 'Cheque', 'GCash', 'Maya'],
            PaymentMethod::orderBy('name')->pluck('name')->all()
        );

        $this->assertTrue($this->cash->isCash());
        $this->assertFalse($this->cash->requires_reference);
        $this->assertTrue($this->gcash->requires_reference);
    }

    public function test_the_form_offers_cash_first(): void
    {
        $options = PaymentMethod::options();

        $this->assertSame('Cash', $options[0]['label']);
        $this->assertTrue($options[0]['cash']);
        $this->assertSame(['Cash', 'Bank transfer', 'Cheque', 'GCash', 'Maya'], array_column($options, 'label'));
    }

    /* ------------------------------------------------------------ Cash */

    public function test_cash_handed_over_gives_change(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->cash('2000')]]))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Change: ₱150.00'));

        $sale = Sale::with('payments')->firstOrFail();
        $payment = $sale->payments->sole();

        // What the sale was owed, and what was handed over.
        $this->assertSame('1850.00', $payment->amount);
        $this->assertSame('2000.00', $payment->tendered);
        $this->assertSame('150.00', $sale->changeGiven());
    }

    public function test_exact_cash_gives_no_change(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->cash('1850')]]))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => ! str_contains($message, 'Change'));

        $this->assertSame('0.00', Sale::with('payments')->firstOrFail()->changeGiven());
    }

    public function test_too_little_cash_is_refused_and_nothing_moves(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->cash('1800')]]))
            ->assertSessionHasErrors(['payments.0.amount' => '50.00 more is needed: 1,850.00 is left to pay in cash.']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(20, $this->product->fresh()->on_hand);
    }

    public function test_a_sale_sent_without_payments_is_paid_exactly_in_cash(): void
    {
        // An old tab, open since before payments were recorded.
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload())
            ->assertRedirect();

        $payment = Sale::with('payments')->firstOrFail()->payments->sole();

        $this->assertSame($this->cash->id, $payment->payment_method_id);
        $this->assertSame('1850.00', $payment->amount);
        $this->assertNull($payment->tendered);
    }

    public function test_an_empty_payment_list_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => []]))
            ->assertSessionHasErrors(['payments' => 'Record how this sale was paid.']);

        $this->assertSame(0, Sale::count());
    }

    /* ------------------------------------------------------- Not cash */

    public function test_gcash_for_the_whole_sale_keeps_its_reference(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1850')]]))
            ->assertRedirect();

        $payment = SalePayment::sole();

        $this->assertSame($this->gcash->id, $payment->payment_method_id);
        $this->assertSame('1850.00', $payment->amount);
        $this->assertSame('1234 5678 9012', $payment->reference);
        $this->assertNull($payment->tendered);
    }

    public function test_gcash_without_its_reference_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1850', null)]]))
            ->assertSessionHasErrors(['payments.0.reference' => 'Type the GCash reference number.']);

        $this->assertSame(0, Sale::count());
    }

    public function test_gcash_never_gives_change(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('2000')]]))
            ->assertSessionHasErrors('payments');

        $this->assertStringContainsString(
            'Change is given only from cash',
            session('errors')->first('payments')
        );
        $this->assertSame(0, Sale::count());
    }

    public function test_gcash_short_of_the_total_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1000')]]))
            ->assertSessionHasErrors(['payments' => 'Still 850.00 to pay. The payments come to 1,000.00 and the sale to 1,850.00.']);
    }

    public function test_a_method_switched_off_cannot_take_a_payment(): void
    {
        $this->gcash->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1850')]]))
            ->assertSessionHasErrors('payments.0.payment_method_id');
    }

    /* ----------------------------------------------------------- Split */

    public function test_a_split_payment_takes_the_change_from_the_cash(): void
    {
        // ₱1,000 by GCash, then ₱1,000 in cash against the ₱850 left.
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1000'), $this->cash('1000')]]))
            ->assertRedirect();

        $sale = Sale::with('payments.method')->firstOrFail();
        $byMethod = $sale->payments->keyBy(fn ($p) => $p->method->name);

        $this->assertSame('1000.00', $byMethod['GCash']->amount);
        $this->assertSame('850.00', $byMethod['Cash']->amount);
        $this->assertSame('1000.00', $byMethod['Cash']->tendered);
        $this->assertSame('150.00', $sale->changeGiven());

        // The rows add up to the sale, to the centavo.
        $this->assertSame($sale->total_amount, number_format($sale->payments->sum('amount'), 2, '.', ''));
    }

    public function test_two_cash_lines_are_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->cash('1000'), $this->cash('850')]]))
            ->assertSessionHasErrors('payments.1.payment_method_id');
    }

    public function test_cash_is_refused_when_the_other_payments_already_cover_the_sale(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1850'), $this->cash('100')]]))
            ->assertSessionHasErrors('payments.1.amount');
    }

    /* ---------------------------------------------------- The awkward */

    public function test_a_sale_given_away_needs_no_payment(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['discount' => 1850, 'payments' => []]))
            ->assertRedirect();

        $this->assertSame('0.00', Sale::firstOrFail()->total_amount);
        $this->assertSame(0, SalePayment::count());
    }

    public function test_a_sale_given_away_posts_from_the_screens_as_they_send_it(): void
    {
        // The till and the sale form keep their cash box, at 0.00.
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['discount' => 1850, 'payments' => [$this->cash('0.00')]]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', Sale::firstOrFail()->total_amount);
        $this->assertSame(0, SalePayment::count());
    }

    public function test_a_zero_payment_is_refused_on_a_sale_that_owes_something(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['payments' => [$this->cash('0')]]))
            ->assertSessionHasErrors(['payments.0.amount' => 'Type the amount paid.']);

        $this->assertSame(0, Sale::count());
    }

    public function test_the_payment_is_settled_against_the_total_after_the_discount(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['discount' => '50.00', 'payments' => [$this->cash('1800')]]))
            ->assertRedirect();

        $this->assertSame('1800.00', SalePayment::sole()->amount);
    }

    public function test_the_poster_decides_even_without_the_form(): void
    {
        $this->actingAs($this->admin());

        $this->expectException(PaymentException::class);

        try {
            app(SalePoster::class)->post([
                'customer_id' => $this->customer->id,
                'sale_date' => Carbon::today(),
                'payments' => [$this->gcash('5000')],
            ], [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '925.00'],
            ]);
        } finally {
            // Refused whole: no sale, and the stock where it was.
            $this->assertSame(0, Sale::count());
            $this->assertSame(20, $this->product->fresh()->on_hand);
            $this->assertSame([], app(InventoryLedger::class)->reconcile());
        }
    }

    public function test_a_retry_with_a_corrected_reference_is_not_the_same_sale(): void
    {
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/sales', $this->payload(['idempotency_key' => $key, 'payments' => [$this->gcash('1850', 'AAA')]]))
            ->assertRedirect();

        // The very same form again: the same sale.
        $this->actingAs($admin)
            ->post('/sales', $this->payload(['idempotency_key' => $key, 'payments' => [$this->gcash('1850', 'AAA')]]))
            ->assertSessionHas('info');

        // A different number is not that sale, and is not quietly dropped.
        $this->actingAs($admin)
            ->post('/sales', $this->payload(['idempotency_key' => $key, 'payments' => [$this->gcash('1850', 'BBB')]]))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Sale::count());
    }

    /* ------------------------------------------------------- Reading */

    public function test_the_sale_page_and_its_dialog_say_how_it_was_paid(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/sales', $this->payload(['payments' => [$this->gcash('1000'), $this->cash('1000')]]));

        $sale = Sale::firstOrFail();

        $json = $this->actingAs($admin)->getJson("/sales/{$sale->id}/preview")->assertOk()->json('sale');

        $this->assertSame('150.00', $json['change']);
        $this->assertSame(['GCash', 'Cash'], array_column($json['payments'], 'method'));
        $this->assertSame('1234 5678 9012', $json['payments'][0]['reference']);
        $this->assertSame('1000.00', $json['payments'][1]['tendered']);
    }

    public function test_the_new_sale_form_and_the_till_carry_the_methods(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/sales/create')
            ->assertInertia(fn ($page) => $page->where('paymentMethods.0.label', 'Cash')->has('paymentMethods', 5));
    }

    /* ----------------------------------------------------- Collections */

    public function test_collections_are_split_by_how_they_came_in(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload(['payments' => [$this->cash('2000')]]));
        $this->actingAs($admin)->post('/sales', $this->payload([
            'idempotency_key' => (string) Str::uuid(),
            'payments' => [$this->gcash('1000'), $this->cash('850')],
        ]));

        // A voided sale took nothing.
        $this->actingAs($admin)->post('/sales', $this->payload([
            'idempotency_key' => (string) Str::uuid(),
            'payments' => [$this->gcash('1850', 'VOID-ME')],
        ]));
        app(SalePoster::class)->void(Sale::latest('id')->first(), 'Rung up twice');

        $paid = app(InventoryMetrics::class)->collections(Carbon::today(), Carbon::today());

        // Cash counts what the sales were owed, not the notes handed over.
        $this->assertSame([
            ['name' => 'Cash', 'kind' => 'cash', 'amount' => '2700.00'],
            ['name' => 'GCash', 'kind' => 'ewallet', 'amount' => '1000.00'],
        ], $paid['methods']);
        $this->assertSame(3700.0, (float) $paid['total']);
    }

    public function test_collections_cannot_be_split_by_category(): void
    {
        $this->assertNull(
            app(InventoryMetrics::class)->collections(Carbon::today(), Carbon::today(), ['category_id' => 1])
        );
    }

    public function test_the_dashboard_card_names_each_method(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload(['payments' => [$this->gcash('1000'), $this->cash('850')]]));

        $cards = app(DashboardOverview::class)->cards(
            Carbon::today(),
            Carbon::today(),
            ['period' => 'today'],
            ['sales' => true, 'purchases' => true, 'stock' => true, 'cost' => true],
        );

        $collections = collect($cards['cards'])->firstWhere('key', 'collections');

        $this->assertSame('1850.00', $collections['value']);
        $this->assertSame('Cash ₱850.00 · GCash ₱1,000.00', $collections['hint']);
    }

    public function test_the_api_says_how_a_sale_was_paid(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload(['payments' => [$this->cash('2000')]]));

        $sale = Sale::firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.method', 'Cash')
            ->assertJsonPath('data.payments.0.tendered', '2000.00')
            ->assertJsonPath('data.change', '150.00');
    }

    /* ------------------------------------------------------- Settlement */

    public function test_the_settlement_works_in_whole_centavos(): void
    {
        $methods = PaymentMethod::all()->keyBy('id');

        // 0.10 + 0.20 is 0.30 exactly, not 0.30000000000000004.
        $paid = PaymentSettlement::settle(30, [
            ['payment_method_id' => $this->gcash->id, 'amount' => '0.10', 'reference' => 'A'],
            ['payment_method_id' => PaymentMethod::where('name', 'Maya')->value('id'), 'amount' => '0.20', 'reference' => 'B'],
        ], $methods);

        $this->assertSame([], $paid['errors']);
        $this->assertSame([10, 20], array_column($paid['lines'], 'amount'));
    }
}
