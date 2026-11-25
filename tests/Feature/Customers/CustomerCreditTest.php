<?php

namespace Tests\Feature\Customers;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\SalePoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 18: a customer's credit limit, terms and balance.
 *
 * Setting a limit needs `customers.credit`, more than an ordinary edit. An
 * edit without it must leave the limit exactly as it was.
 */
class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** May edit customers, but not credit. */
    protected function clerk(): User
    {
        return $this->unaudited(function () {
            $user = User::factory()->create();
            $user->givePermissionTo(Permission::findByName('customers.view', 'web'));
            $user->givePermissionTo(Permission::findByName('customers.update', 'web'));
            $user->givePermissionTo(Permission::findByName('customers.create', 'web'));

            return $user;
        });
    }

    /** @return array<string, mixed> */
    protected function form(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'code' => $customer->code,
            'name' => $customer->name,
            'is_active' => true,
        ], $overrides);
    }

    protected function customer(array $attributes = []): Customer
    {
        return $this->unaudited(fn () => Customer::factory()->create($attributes));
    }

    public function test_every_customer_starts_with_no_credit(): void
    {
        $customer = $this->customer();

        $this->assertSame('0.00', $customer->fresh()->credit_limit);
        $this->assertSame(0, $customer->fresh()->payment_terms_days);
        $this->assertFalse($customer->fresh()->takesCredit());
    }

    public function test_someone_with_the_permission_sets_the_limit_and_terms(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->put("/customers/{$customer->id}", $this->form($customer, ['credit_limit' => 5000, 'payment_terms_days' => 30]))
            ->assertSessionHasNoErrors();

        $this->assertSame('5000.00', $customer->fresh()->credit_limit);
        $this->assertSame(30, $customer->fresh()->payment_terms_days);
    }

    public function test_an_edit_without_the_permission_leaves_the_limit_alone(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);

        $this->actingAs($this->clerk())
            ->put("/customers/{$customer->id}", $this->form($customer, [
                'name' => 'Renamed',
                'credit_limit' => 999999,
                'payment_terms_days' => 365,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $customer->fresh()->name);
        $this->assertSame('5000.00', $customer->fresh()->credit_limit);
        $this->assertSame(30, $customer->fresh()->payment_terms_days);
    }

    public function test_a_form_that_leaves_the_fields_out_keeps_them(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);

        $this->actingAs($this->admin())
            ->put("/customers/{$customer->id}", $this->form($customer))
            ->assertSessionHasNoErrors();

        $this->assertSame('5000.00', $customer->fresh()->credit_limit);
        $this->assertSame(30, $customer->fresh()->payment_terms_days);
    }

    public function test_a_new_customer_made_without_the_permission_has_no_credit(): void
    {
        $this->actingAs($this->clerk())
            ->post('/customers', ['code' => 'C-1', 'name' => 'New', 'is_active' => true, 'credit_limit' => 5000, 'payment_terms_days' => 30])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', Customer::firstWhere('code', 'C-1')->credit_limit);
    }

    public function test_a_limit_needs_terms(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->put("/customers/{$customer->id}", $this->form($customer, ['credit_limit' => 5000, 'payment_terms_days' => 0]))
            ->assertSessionHasErrors('payment_terms_days');
    }

    public function test_a_limit_cannot_be_negative(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->put("/customers/{$customer->id}", $this->form($customer, ['credit_limit' => -1]))
            ->assertSessionHasErrors('credit_limit');
    }

    public function test_walk_in_cannot_be_given_credit(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->put("/customers/{$walkIn->id}", $this->form($walkIn, ['credit_limit' => 5000, 'payment_terms_days' => 30]))
            ->assertSessionHasErrors('credit_limit');
    }

    public function test_changing_a_limit_is_on_the_trail(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin())
            ->put("/customers/{$customer->id}", $this->form($customer, ['credit_limit' => 5000, 'payment_terms_days' => 30]));

        $row = AuditTrail::latest('id')->firstOrFail();

        $this->assertSame('update', strtolower((string) ($row->action->value ?? $row->action)));
        $this->assertStringContainsString('5000', json_encode($row->properties ?? $row->new_values ?? $row->getAttributes()));
    }

    /** A sale of ₱1,000 on this customer's account. */
    protected function charge(Customer $customer, float $amount = 1000): Sale
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->unaudited(fn () => Product::factory()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 1],
        ]);

        return app(SalePoster::class)->post(
            ['customer_id' => $customer->id, 'sale_date' => Carbon::today(), 'on_account' => $amount],
            [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]],
        );
    }

    public function test_the_list_shows_what_each_customer_owes(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);
        $this->charge($customer);

        $this->actingAs($this->admin())
            ->get('/customers')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('customers.data.0.balance', '1000.00')
                ->where('can.credit', true));
    }

    public function test_the_list_can_be_narrowed_to_those_owing(): void
    {
        $owing = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);
        $this->customer();
        $this->charge($owing);

        $this->actingAs($this->admin())
            ->get('/customers?balance=owing')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $owing->id));
    }

    public function test_the_list_sorts_by_what_each_customer_owes(): void
    {
        $small = $this->customer(['name' => 'Ana', 'credit_limit' => 5000, 'payment_terms_days' => 30]);
        $big = $this->customer(['name' => 'Ben', 'credit_limit' => 5000, 'payment_terms_days' => 30]);
        $none = $this->customer(['name' => 'Cora']);
        $this->charge($small, 250);
        $this->charge($big, 3000);

        $ids = fn (string $query) => collect(
            $this->actingAs($this->admin())->get("/customers?{$query}")->viewData('page')['props']['customers']['data']
        )->pluck('id')->all();

        $this->assertSame([$big->id, $small->id, $none->id], $ids('sort=balance&direction=desc'));
        $this->assertSame([$none->id, $small->id, $big->id], $ids('sort=balance&direction=asc'));

        // Said back to the page, so the header shows the arrow.
        $this->actingAs($this->admin())->get('/customers?sort=balance&direction=desc')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.sort', 'balance')
                ->where('filters.direction', 'desc'));
    }

    public function test_the_page_shows_the_account_and_what_is_unpaid(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);
        $sale = $this->charge($customer);

        $this->actingAs($this->admin())
            ->get("/customers/{$customer->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('account.limit', '5000.00')
                ->where('account.balance', '1000.00')
                ->where('account.available', '4000.00')
                ->where('account.terms_days', 30)
                ->has('unpaid', 1)
                ->where('unpaid.0.id', $sale->id)
                ->where('unpaid.0.balance_due', '1000.00')
                ->where('unpaid.0.due_date', Carbon::today()->addDays(30)->format('d M Y')));
    }

    public function test_walk_in_has_no_account_on_its_page(): void
    {
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())
            ->get("/customers/{$walkIn->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('account', null));
    }

    public function test_a_limit_lowered_under_what_is_owed_leaves_nothing_not_a_negative(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);
        $this->charge($customer);
        $this->unaudited(fn () => $customer->update(['credit_limit' => 500]));

        $this->actingAs($this->admin())
            ->get("/customers/{$customer->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('account.available', '0.00'));
    }

    public function test_the_two_sale_screens_are_told_who_has_credit_and_the_filter_is_not(): void
    {
        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $credit = $this->customer(['name' => 'Has Credit', 'credit_limit' => 5000, 'payment_terms_days' => 30]);
        $none = $this->customer(['name' => 'No Credit']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/sales/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('customers', function ($customers) use ($credit, $none) {
                $byId = collect($customers)->keyBy('value');

                return $byId[$credit->id]['credit']['available'] === '5000.00'
                    && $byId[$none->id]['credit'] === null;
            }));

        $this->actingAs($admin)
            ->get('/sales')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('options.customers', fn ($customers) => ! array_key_exists('credit', collect($customers)->first())));
    }

    public function test_the_api_says_what_a_customer_owes(): void
    {
        $customer = $this->customer(['credit_limit' => 5000, 'payment_terms_days' => 30]);
        $this->charge($customer);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.credit_limit', '5000.00')
            ->assertJsonPath('data.payment_terms_days', 30)
            ->assertJsonPath('data.balance', '1000.00');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonPath('data.0.balance', '1000.00');
    }
}
