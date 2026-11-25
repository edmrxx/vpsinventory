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
use App\Services\InventoryMetrics;
use App\Services\SalePoster;
use App\Services\SalesReturnPoster;
use App\Support\CustomerCredit;
use App\Support\PaymentSettlement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 18: a sale charged to a customer's account.
 *
 * Two pieces at ₱925 come to ₱1,850. Dela Cruz may owe ₱5,000 and has 30
 * days. The payment rows stay real money only, so a sale's rows plus what
 * went on account come to its total.
 */
class CreditSaleTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $walkIn;

    protected Customer $delaCruz;

    protected Product $product;

    protected PaymentMethod $cash;

    protected PaymentMethod $gcash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->delaCruz = $this->unaudited(fn () => Customer::factory()->create([
            'name' => 'Dela Cruz',
            'credit_limit' => 5000,
            'payment_terms_days' => 30,
        ]));
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'CEM-40', 'selling_price' => 925])
        );

        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->gcash = PaymentMethod::where('name', 'GCash')->firstOrFail();

        // Dated back, so a sale may be dated yesterday.
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 500],
        ], Carbon::today()->subDays(2));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Staff')));
    }

    /** @param array<string, mixed> $overrides */
    protected function saleForm(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->delaCruz->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]],
            'payments' => [],
            'on_account' => 1850,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function posted(array $overrides = []): Sale
    {
        $this->actingAs($this->admin())->post('/sales', $this->saleForm($overrides))->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_a_whole_sale_can_go_on_account(): void
    {
        $sale = $this->posted();

        $this->assertSame('1850.00', $sale->on_account);
        $this->assertSame('1850.00', $sale->balance_due);
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $sale->due_date->toDateString());
        $this->assertSame(0, $sale->payments()->count());
        $this->assertSame(185000, CustomerCredit::owed($this->delaCruz->id));
    }

    public function test_part_can_go_on_account_and_the_rows_plus_the_account_make_the_total(): void
    {
        $sale = $this->posted([
            'on_account' => 850,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
        ]);

        $this->assertSame('850.00', $sale->balance_due);
        $this->assertEquals(1850, (float) SalePayment::where('sale_id', $sale->id)->sum('amount') + (float) $sale->on_account);
    }

    public function test_a_shortfall_is_never_taken_as_credit(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm([
                'on_account' => 0,
                'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
            ]))
            ->assertSessionHasErrors('payments.0.amount');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_sale_on_account_gives_no_change(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm([
                'on_account' => 850,
                'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1500]],
            ]))
            ->assertSessionHasErrors('payments.0.amount');
    }

    public function test_on_account_with_no_payments_key_is_refused_unless_it_covers_everything(): void
    {
        $form = $this->saleForm(['on_account' => 850]);
        unset($form['payments']);

        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasErrors('payments');

        $whole = $this->saleForm();
        unset($whole['payments']);

        $this->actingAs($this->admin())->post('/sales', $whole)->assertSessionHasNoErrors();
    }

    public function test_money_taken_on_a_sale_wholly_on_account_is_refused_not_lost(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm([
                'payments' => [['payment_method_id' => $this->gcash->id, 'amount' => 500, 'reference' => 'X1']],
            ]))
            ->assertSessionHasErrors('on_account');
    }

    public function test_the_cash_box_left_at_zero_is_fine_on_a_sale_wholly_on_account(): void
    {
        $sale = $this->posted(['payments' => [['payment_method_id' => $this->cash->id, 'amount' => '0.00']]]);

        $this->assertSame(0, $sale->payments()->count());
    }

    public function test_walk_in_cannot_buy_on_account(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm(['customer_id' => $this->walkIn->id]))
            ->assertSessionHasErrors('on_account');
    }

    public function test_a_customer_with_no_limit_cannot_buy_on_account(): void
    {
        $none = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm(['customer_id' => $none->id]))
            ->assertSessionHasErrors('on_account');
    }

    public function test_on_account_cannot_be_more_than_the_sale(): void
    {
        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm(['on_account' => 1850.01]))
            ->assertSessionHasErrors('on_account');
    }

    public function test_exactly_the_limit_is_allowed_and_one_centavo_more_is_not(): void
    {
        $this->unaudited(fn () => $this->delaCruz->update(['credit_limit' => 1850]));

        $this->posted();

        $this->actingAs($this->admin())
            ->post('/sales', $this->saleForm([
                'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 0.01]],
                'on_account' => 0.01,
            ]))
            ->assertSessionHasErrors('on_account');

        $this->assertSame(1, Sale::count());
    }

    public function test_the_poster_decides_when_the_limit_filled_up_after_the_form_passed(): void
    {
        $this->actingAs($this->admin());
        $this->unaudited(fn () => $this->delaCruz->update(['credit_limit' => 2000]));
        $this->posted();

        $this->expectException(PaymentException::class);

        app(SalePoster::class)->post(
            ['customer_id' => $this->delaCruz->id, 'sale_date' => Carbon::today(), 'on_account' => 1850],
            [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]],
        );
    }

    public function test_a_refused_sale_takes_no_stock(): void
    {
        $this->actingAs($this->admin());
        $this->unaudited(fn () => $this->delaCruz->update(['credit_limit' => 100]));
        $before = $this->product->fresh()->on_hand;

        try {
            app(SalePoster::class)->post(
                ['customer_id' => $this->delaCruz->id, 'sale_date' => Carbon::today(), 'on_account' => 1850],
                [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]],
            );
            $this->fail('The sale should have been refused.');
        } catch (PaymentException) {
        }

        $this->assertSame($before, $this->product->fresh()->on_hand);
        $this->assertSame(0, Sale::count());
    }

    public function test_changing_the_terms_never_moves_an_old_invoice(): void
    {
        $sale = $this->posted();

        $this->unaudited(fn () => $this->delaCruz->update(['payment_terms_days' => 7]));

        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $sale->fresh()->due_date->toDateString());
    }

    public function test_the_due_date_counts_from_the_date_on_the_sale(): void
    {
        $date = Carbon::today()->subDay()->toDateString();

        $sale = $this->posted(['sale_date' => $date]);

        $this->assertSame(Carbon::parse($date)->addDays(30)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_voiding_clears_what_was_owed(): void
    {
        $sale = $this->posted();

        $this->actingAs($this->admin())
            ->post("/sales/{$sale->id}/void", ['reason' => 'Wrong customer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', $sale->fresh()->balance_due);
        $this->assertSame('1850.00', $sale->fresh()->on_account);
        $this->assertSame(0, CustomerCredit::owed($this->delaCruz->id));
    }

    public function test_a_retry_with_the_same_key_finds_the_same_sale(): void
    {
        $form = $this->saleForm(['payments' => [['payment_method_id' => $this->cash->id, 'amount' => '0.00']]]);

        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_a_retry_with_a_different_amount_on_account_is_not_the_same_sale(): void
    {
        $form = $this->saleForm();

        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales', [...$form, 'on_account' => 850, 'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]]])
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_staff_may_charge_an_account_within_its_limit(): void
    {
        $this->actingAs($this->staff())->post('/sales', $this->saleForm())->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_collections_counts_only_what_was_paid_at_the_counter(): void
    {
        $this->posted([
            'on_account' => 850,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
        ]);

        $today = Carbon::today();
        $collections = app(InventoryMetrics::class)->collections($today, $today);

        $this->assertEquals(1000, $collections['total']);
    }

    public function test_net_cash_leaves_out_what_was_not_paid(): void
    {
        $this->posted([
            'on_account' => 850,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
        ]);

        $today = Carbon::today();
        $figures = app(InventoryMetrics::class)->periodFigures($today, $today);

        $this->assertEquals(1850, $figures['net_sales']);
        $this->assertEquals(850, round($figures['on_account_net'], 2));

        $cards = app(DashboardOverview::class)->cards(
            $today,
            $today,
            ['period' => 'today'],
            ['sales' => true, 'purchases' => true, 'stock' => true, 'cost' => true],
        );

        $cash = collect($cards['cards'])->firstWhere('key', 'cash_position');

        // The opening stock is no purchase, so what came in is the ₱1,000.
        $this->assertEquals(1000, (float) $cash['value']);
    }

    public function test_a_narrowed_figure_carries_each_lines_share_of_the_account(): void
    {
        $this->posted([
            'on_account' => 925,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 925]],
        ]);

        $today = Carbon::today();
        $figures = app(InventoryMetrics::class)->periodFigures($today, $today, ['product_id' => $this->product->id]);

        $this->assertEquals(925, round($figures['on_account_net'], 2));
    }

    public function test_a_return_on_an_unpaid_sale_comes_off_the_balance_and_moves_no_cash(): void
    {
        $sale = $this->posted();
        $this->actingAs($this->admin());

        $return = app(SalesReturnPoster::class)->post(
            ['sale_id' => $sale->id, 'return_date' => Carbon::today(), 'reason' => 'Damaged'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
        );

        $this->assertSame('925.00', $return->credited_amount);
        $this->assertSame('0.00', $return->cashRefund());
        $this->assertSame('925.00', $sale->fresh()->balance_due);

        $today = Carbon::today();
        $this->assertEquals(0, round(
            app(InventoryMetrics::class)->periodFigures($today, $today)['net_sales']
                - app(InventoryMetrics::class)->periodFigures($today, $today)['on_account_net'],
            2
        ));
    }

    public function test_a_return_worth_more_than_is_owed_hands_back_only_the_paid_part(): void
    {
        $sale = $this->posted([
            'on_account' => 850,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1000]],
        ]);
        $this->actingAs($this->admin());

        $return = app(SalesReturnPoster::class)->post(
            ['sale_id' => $sale->id, 'return_date' => Carbon::today(), 'reason' => 'Damaged'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 2]],
        );

        $this->assertSame('850.00', $return->credited_amount);
        $this->assertSame('1000.00', $return->cashRefund());
        $this->assertSame('0.00', $sale->fresh()->balance_due);
    }

    public function test_a_return_on_a_paid_sale_is_all_cash_as_before(): void
    {
        $sale = $this->posted([
            'on_account' => 0,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1850]],
        ]);
        $this->actingAs($this->admin());

        $return = app(SalesReturnPoster::class)->post(
            ['sale_id' => $sale->id, 'return_date' => Carbon::today(), 'reason' => 'Damaged'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 2]],
        );

        $this->assertSame('0.00', $return->credited_amount);
        $this->assertSame('1850.00', $return->cashRefund());
    }

    public function test_the_page_and_the_dialog_say_the_same_about_the_account(): void
    {
        $sale = $this->posted();

        $json = $this->actingAs($this->admin())->getJson("/sales/{$sale->id}/preview")->assertOk()->json('sale');

        $this->assertSame('1850.00', $json['on_account']);
        $this->assertSame('1850.00', $json['balance_due']);
        $this->assertSame(Carbon::today()->addDays(30)->format('d M Y'), $json['due_date']);
    }

    public function test_settlement_refuses_nothing_when_no_credit_was_asked_for(): void
    {
        $paid = PaymentSettlement::settleSale(185000, 0, null);

        $this->assertSame([], $paid['errors']);
        $this->assertSame(185000, $paid['lines'][0]['amount']);
    }

    public function test_the_ledger_is_still_whole_after_a_credit_sale(): void
    {
        $this->posted();

        $this->artisan('inventory:verify')->assertExitCode(0);
    }
}
