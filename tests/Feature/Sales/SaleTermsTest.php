<?php

namespace Tests\Feature\Sales;

use App\Exceptions\PaymentException;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\SalePoster;
use App\Support\CustomerCredit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 18.1: the payment terms are agreed on each sale.
 *
 * Dela Cruz may owe ₱5,000 and usually has 30 days. Every sale on account
 * starts on those 30; anybody selling may give fewer, and only somebody who
 * may set credit (`customers.credit`, Admin) may give more. The days are
 * kept on the sale beside the due date they gave.
 */
class SaleTermsTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $delaCruz;

    protected Product $product;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->delaCruz = $this->unaudited(fn () => Customer::factory()->create([
            'name' => 'Dela Cruz',
            'credit_limit' => 5000,
            'payment_terms_days' => 30,
        ]));
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'CEM-40', 'selling_price' => 925])
        );

        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

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
    protected function postedBy(User $user, array $overrides = []): Sale
    {
        $this->actingAs($user)->post('/sales', $this->saleForm($overrides))->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_a_sale_sent_without_terms_takes_the_customers_usual_ones(): void
    {
        $sale = $this->postedBy($this->staff());

        $this->assertSame(30, $sale->terms_days);
        $this->assertSame(Carbon::today()->addDays(30)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_an_empty_terms_box_is_the_usual_terms_too(): void
    {
        $sale = $this->postedBy($this->staff(), ['terms_days' => '']);

        $this->assertSame(30, $sale->terms_days);
    }

    public function test_a_cashier_may_give_fewer_days_on_one_sale(): void
    {
        $sale = $this->postedBy($this->staff(), ['terms_days' => 15]);

        $this->assertSame(15, $sale->terms_days);
        $this->assertSame(Carbon::today()->addDays(15)->toDateString(), $sale->due_date->toDateString());
        // The customer's usual terms are not touched.
        $this->assertSame(30, $this->delaCruz->fresh()->payment_terms_days);
    }

    public function test_zero_days_falls_due_on_the_day_of_the_sale(): void
    {
        $sale = $this->postedBy($this->staff(), ['terms_days' => 0]);

        $this->assertSame(0, $sale->terms_days);
        $this->assertSame(Carbon::today()->toDateString(), $sale->due_date->toDateString());
    }

    public function test_the_days_count_from_the_date_on_the_sale(): void
    {
        $date = Carbon::today()->subDay()->toDateString();

        $sale = $this->postedBy($this->staff(), ['sale_date' => $date, 'terms_days' => 10]);

        $this->assertSame(Carbon::parse($date)->addDays(10)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_a_cashier_cannot_give_more_days_than_usual(): void
    {
        $this->actingAs($this->staff())
            ->post('/sales', $this->saleForm(['terms_days' => 45]))
            ->assertSessionHasErrors(['terms_days' => "Dela Cruz's usual terms are 30 days. Only somebody who may set credit can give more."]);

        $this->assertSame(0, Sale::count());
    }

    public function test_somebody_who_may_set_credit_can_give_more_days(): void
    {
        $sale = $this->postedBy($this->admin(), ['terms_days' => 45]);

        $this->assertSame(45, $sale->terms_days);
        $this->assertSame(Carbon::today()->addDays(45)->toDateString(), $sale->due_date->toDateString());
    }

    public function test_terms_are_whole_days_from_zero_to_a_year(): void
    {
        $admin = $this->admin();

        foreach ([-1, 366, 2.5, 'soon'] as $days) {
            $this->actingAs($admin)
                ->post('/sales', $this->saleForm(['terms_days' => $days]))
                ->assertSessionHasErrors('terms_days');
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_a_sale_paid_in_full_carries_no_terms_whatever_was_sent(): void
    {
        $sale = $this->postedBy($this->staff(), [
            'on_account' => 0,
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 1850]],
            // A box left over from a moment on account: ignored, not refused.
            'terms_days' => 99,
        ]);

        $this->assertNull($sale->terms_days);
        $this->assertNull($sale->due_date);
    }

    public function test_the_poster_decides_when_somebody_without_the_right_asks_for_more(): void
    {
        $this->actingAs($this->staff());

        try {
            app(SalePoster::class)->post(
                ['customer_id' => $this->delaCruz->id, 'sale_date' => Carbon::today(), 'on_account' => 1850, 'terms_days' => 60],
                [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]],
            );
            $this->fail('The sale should have been refused.');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('usual terms are 30 days', $e->getMessage());
        }

        $this->assertSame(0, Sale::count());
        $this->assertSame(100, $this->product->fresh()->on_hand);
    }

    public function test_the_poster_weighs_the_usual_terms_as_they_are_now(): void
    {
        // The form showed 30; the usual terms were cut to 7 before posting.
        $this->unaudited(fn () => $this->delaCruz->update(['payment_terms_days' => 7]));

        $this->actingAs($this->staff())
            ->post('/sales', $this->saleForm(['terms_days' => 30]))
            ->assertSessionHasErrors('terms_days');
    }

    public function test_a_retry_with_the_same_terms_finds_the_same_sale(): void
    {
        $form = $this->saleForm(['terms_days' => 15]);

        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();
        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_a_retry_with_other_terms_is_not_the_same_sale(): void
    {
        $form = $this->saleForm(['terms_days' => 15]);

        $this->actingAs($this->admin())->post('/sales', $form)->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post('/sales', [...$form, 'terms_days' => 20])
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_the_page_and_the_dialog_say_the_days_agreed(): void
    {
        $sale = $this->postedBy($this->staff(), ['terms_days' => 15]);

        $json = $this->actingAs($this->admin())->getJson("/sales/{$sale->id}/preview")->assertOk()->json('sale');

        $this->assertSame(15, $json['terms_days']);
        $this->assertSame(Carbon::today()->addDays(15)->format('d M Y'), $json['due_date']);
    }

    public function test_the_message_after_posting_names_the_days(): void
    {
        $this->actingAs($this->staff())
            ->post('/sales', $this->saleForm(['terms_days' => 15]))
            ->assertSessionHas('success', fn (string $message) => str_contains(
                $message,
                'due '.Carbon::today()->addDays(15)->format('d M Y').' (15 days)'
            ));
    }

    public function test_the_statement_names_the_days_agreed(): void
    {
        $this->postedBy($this->staff(), ['terms_days' => 15]);

        $statement = CustomerCredit::statement($this->delaCruz, Carbon::today()->startOfMonth(), Carbon::today());
        $line = collect($statement['entries'])->firstWhere('kind', 'sale');

        $this->assertStringEndsWith('(15 days)', $line['description']);
    }

    public function test_overdue_follows_the_days_agreed_not_the_usual(): void
    {
        // Sold yesterday on one day's terms: due today, so not yet overdue.
        $this->postedBy($this->staff(), ['sale_date' => Carbon::today()->subDay()->toDateString(), 'terms_days' => 1]);
        $this->assertSame(0, (int) Customer::query()->withOverdue(Carbon::today())->find($this->delaCruz->id)->overdue_cents);

        // A day later it is.
        $this->assertSame(185000, (int) Customer::query()->withOverdue(Carbon::today()->addDay())->find($this->delaCruz->id)->overdue_cents);
    }

    public function test_the_sale_form_and_the_till_know_who_may_give_more_days(): void
    {
        $this->actingAs($this->admin())->get('/sales/create')
            ->assertInertia(fn ($page) => $page->where('can.extendTerms', true));

        $this->actingAs($this->staff())->get('/sales/create')
            ->assertInertia(fn ($page) => $page->where('can.extendTerms', false));
    }
}
