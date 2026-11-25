<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\CustomerPaymentPoster;
use App\Services\SalePoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 19.1: the Collectibles list — every invoice still owed, filtered by
 * month, customer, status and staff, with totals over the filtered rows.
 *
 * Dela Cruz has 30 days, Bautista 7. Each sale is ₱1,850 on account.
 */
class CollectiblesTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $delaCruz;

    protected Customer $bautista;

    protected Product $product;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->delaCruz = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Dela Cruz', 'credit_limit' => 20000, 'payment_terms_days' => 30]));
        $this->bautista = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Bautista', 'credit_limit' => 20000, 'payment_terms_days' => 7]));
        $this->product = $this->unaudited(fn () => Product::factory()->create(['selling_price' => 925]));
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 500],
        ], Carbon::today()->subDays(2));

        $this->actingAs($this->admin);
    }

    protected function sale(Customer $customer): Sale
    {
        return app(SalePoster::class)->post([
            'customer_id' => $customer->id,
            'sale_date' => Carbon::today(),
            'on_account' => 1850,
            'payments' => [],
        ], [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 925]]);
    }

    protected function list(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->get('/collectibles'.($query ? "?{$query}" : ''))->assertOk();
    }

    public function test_it_lists_every_invoice_still_owed_with_its_totals(): void
    {
        $first = $this->sale($this->delaCruz);
        $this->sale($this->bautista);

        // A part payment leaves it on the list, owing the rest.
        app(CustomerPaymentPoster::class)->post([
            'customer_id' => $this->delaCruz->id,
            'payment_date' => Carbon::today(),
            'payment_method_id' => PaymentMethod::cash()->id,
            'amount' => 500,
        ], [['sale_id' => $first->id, 'amount' => 500]]);

        $this->list()->assertInertia(fn ($page) => $page
            ->component('Collectibles/Index')
            ->has('invoices.data', 2)
            ->where('totals.total', '3200.00')
            ->where('totals.invoices', 2)
            ->where('totals.customers', 2));

        $this->list("customer_id={$this->delaCruz->id}")->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.balance_due', '1350.00')
            ->where('invoices.data.0.settled', '500.00')
            ->where('totals.total', '1350.00'));
    }

    public function test_paid_and_voided_invoices_are_not_collectible(): void
    {
        $paid = $this->sale($this->delaCruz);
        $voided = $this->sale($this->bautista);

        app(CustomerPaymentPoster::class)->post([
            'customer_id' => $this->delaCruz->id,
            'payment_date' => Carbon::today(),
            'payment_method_id' => PaymentMethod::cash()->id,
            'amount' => 1850,
        ], [['sale_id' => $paid->id, 'amount' => 1850]]);
        app(SalePoster::class)->void($voided, 'Keyed twice');

        $this->list()->assertInertia(fn ($page) => $page->has('invoices.data', 0)->where('totals.total', '0.00'));
    }

    public function test_it_filters_by_the_month_it_falls_due_or_was_sold(): void
    {
        $this->sale($this->delaCruz);   // due in 30 days
        $this->sale($this->bautista);   // due in 7 days

        $sold = Carbon::today()->format('Y-m');
        $dueLater = Carbon::today()->addDays(30)->format('Y-m');
        $dueSoon = Carbon::today()->addDays(7)->format('Y-m');

        $this->list("month={$sold}&by=sold")->assertInertia(fn ($page) => $page->has('invoices.data', 2));

        $this->list("month={$dueLater}")->assertInertia(fn ($page) => $page
            ->where('invoices.data', fn ($rows) => collect($rows)->contains('customer', 'Dela Cruz')));

        if ($dueLater !== $dueSoon) {
            $this->list("month={$dueLater}")->assertInertia(fn ($page) => $page->has('invoices.data', 1));
        }

        // A month with nothing in it.
        $this->list('month='.Carbon::today()->subYear()->format('Y-m'))
            ->assertInertia(fn ($page) => $page->has('invoices.data', 0)->where('totals.total', '0.00'));
    }

    public function test_it_filters_what_is_past_due(): void
    {
        $this->sale($this->delaCruz);
        $this->sale($this->bautista);

        // Eight days on: Bautista's 7 days have run out, Dela Cruz's 30 have not.
        $this->travelTo(Carbon::today()->addDays(8));

        $this->list('status=overdue')->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.customer', 'Bautista')
            ->where('invoices.data.0.days_overdue', 1)
            ->where('totals.overdue', '1850.00'));

        $this->list('status=current')->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.customer', 'Dela Cruz')
            ->where('totals.overdue', '0.00'));

        $this->list()->assertInertia(fn ($page) => $page->where('totals.total', '3700.00')->where('totals.overdue', '1850.00'));
    }

    public function test_a_bad_filter_is_refused(): void
    {
        $this->get('/collectibles?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/collectibles?status=lost')->assertSessionHasErrors('status');
    }

    public function test_it_needs_the_permission_to_read_customer_payments(): void
    {
        $nobody = $this->unaudited(fn () => User::factory()->create());

        $this->actingAs($nobody)->get('/collectibles')->assertForbidden();

        $staff = $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Staff')));
        $this->actingAs($staff)->get('/collectibles')->assertOk();
    }
}
