<?php

namespace Tests\Feature\Sales;

use App\Enums\PendingPaymentStatus;
use App\Exceptions\PendingPaymentException;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PendingPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\SalePoster;
use App\Support\Reservations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CR-2 (the client's meeting of 24 November 2026): the kiosk takes the
 * order, the cashier takes the money.
 *
 * Ten tins of cement at ₱250 are on the shelf at Main Store. The kiosk sends
 * an order for some to the cashier; until it is paid or cancelled — or the
 * day ends — nobody else may sell those tins, and the sale that pays it
 * must be exactly what was sent.
 */
class PendingPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $walkIn;

    protected Product $product;

    protected Location $store;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'CEM-40', 'name' => 'Cement 40 kg', 'selling_price' => 250])
        );
        $this->store = Location::default();
        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 200],
        ], Carbon::today()->subDay());
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Staff')));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** A tablet that only sends orders, and never takes money: the kiosk, and nothing else. */
    protected function kioskOnly(): User
    {
        $role = Role::findOrCreate('Kiosk', 'web');
        $role->syncPermissions(['kiosk.use']);

        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Kiosk')));
    }

    /** @param array<string, mixed> $overrides */
    protected function order(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->walkIn->id,
            'location_id' => $this->store->id,
            'discount' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '250.00']],
        ], $overrides);
    }

    protected function sent(?User $by = null, array $overrides = []): PendingPayment
    {
        $this->actingAs($by ?? $this->encoder())->post('/pending-payments', $this->order($overrides))->assertSessionHasNoErrors();

        return PendingPayment::latest('id')->firstOrFail();
    }

    /** The cashier's sale, paying a pending payment as the till sends it. */
    protected function payment(PendingPayment $pending, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $pending->customer_id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $pending->location_id,
            'discount' => $pending->discount,
            'idempotency_key' => (string) Str::uuid(),
            'from' => 'pos',
            'pending_payment_id' => $pending->id,
            'lines' => $pending->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'quantity' => $line->unit_quantity,
                'unit_price' => $line->unit_price,
            ])->all(),
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => (string) $pending->total_amount]],
        ], $overrides);
    }

    public function test_a_sale_paying_a_pending_payment_adds_no_mobilization(): void
    {
        // Phase 28.1: the till sends no mobilization charge, so the sale that
        // pays what it sent adds none — refused by the request, and by the
        // poster again with the pending payment locked.
        $pending = $this->sent();

        $this->actingAs($this->admin())
            ->post('/sales', $this->payment($pending, [
                'mobilization' => '200.00',
                'payments' => [['payment_method_id' => $this->cash->id, 'amount' => '1200.00']],
            ]))
            ->assertSessionHasErrors('mobilization');

        $this->assertSame(PendingPaymentStatus::Pending, $pending->fresh()->status);

        $this->expectException(PendingPaymentException::class);
        app(SalePoster::class)->post(
            ['customer_id' => $pending->customer_id, 'sale_date' => Carbon::today(), 'location_id' => $pending->location_id,
                'pending_payment_id' => $pending->id, 'mobilization' => '200.00'],
            $pending->lines->map(fn ($line) => ['product_id' => $line->product_id, 'quantity' => $line->unit_quantity, 'unit_price' => $line->unit_price])->all(),
        );
    }

    public function test_the_kiosk_sends_an_order_that_holds_its_goods_and_moves_nothing(): void
    {
        $pending = $this->sent();

        $this->assertSame(PendingPaymentStatus::Pending, $pending->status);
        $this->assertSame('1000.00', $pending->total_amount);
        $this->assertStringStartsWith('PP-', $pending->reference);
        $this->assertSame(Carbon::today()->toDateString(), $pending->hold_until->toDateString());
        // Nothing left the shelf; four tins are held.
        $this->assertSame(10, $this->product->fresh()->on_hand);
        $this->assertSame([$this->product->id => 4000], Reservations::heldForPendingPayments([$this->product->id], $this->store->id));
    }

    public function test_nobody_else_may_sell_the_goods_it_holds(): void
    {
        $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 8, 'unit_price' => '250.00']]]);

        $this->actingAs($this->staff())->post('/sales', [
            'customer_id' => $this->walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 250]],
            'payments' => [['payment_method_id' => $this->cash->id, 'amount' => 750]],
        ])->assertSessionHasErrors('lines.0.quantity');

        $message = session('errors')->first('lines.0.quantity');

        $this->assertStringStartsWith('Only 2 ', $message);
        $this->assertStringContainsString('8 '.$this->product->unit->code.' waiting at the cashier', $message);
    }

    public function test_an_order_for_more_than_is_free_is_refused_whole(): void
    {
        $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 7, 'unit_price' => '250.00']]]);

        $this->actingAs($this->encoder())
            ->post('/pending-payments', $this->order())
            ->assertSessionHasErrors('lines');

        $this->assertSame(1, PendingPayment::count());
    }

    public function test_a_second_tap_sends_it_once(): void
    {
        $form = $this->order();
        $kiosk = $this->encoder();

        $this->actingAs($kiosk)->post('/pending-payments', $form)->assertSessionHasNoErrors();
        $this->actingAs($kiosk)->post('/pending-payments', $form)->assertSessionHasNoErrors();

        $this->assertSame(1, PendingPayment::count());
    }

    public function test_the_cashier_takes_the_money_and_it_becomes_the_sale(): void
    {
        // All ten held, so only its own hold lets this sale through.
        $pending = $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '250.00']]]);

        $this->actingAs($cashier = $this->staff())
            ->post('/sales', $this->payment($pending))
            ->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $pending->refresh();

        $this->assertSame(PendingPaymentStatus::Paid, $pending->status);
        $this->assertSame($sale->id, $pending->sale_id);
        $this->assertSame('2500.00', $sale->total_amount);
        $this->assertSame($cashier->id, $sale->posted_by);
        $this->assertSame(0, $this->product->fresh()->on_hand);
        $this->assertSame([], Reservations::heldForPendingPayments([$this->product->id]));
    }

    public function test_it_may_go_on_the_customers_account_at_the_cashier(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create(['credit_limit' => 5000, 'payment_terms_days' => 30]));
        $pending = $this->sent(null, ['customer_id' => $customer->id]);

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending, ['payments' => [], 'on_account' => '1000.00']))
            ->assertSessionHasNoErrors();

        $this->assertSame('1000.00', Sale::latest('id')->first()->balance_due);
    }

    public function test_it_is_paid_exactly_as_it_was_sent(): void
    {
        $pending = $this->sent();
        $cashier = $this->staff();
        $lines = $this->payment($pending)['lines'];

        $cheaper = $lines;
        $cheaper[0]['unit_price'] = '200.00';
        $fewer = $lines;
        $fewer[0]['quantity'] = 3;

        foreach ([['lines' => $cheaper], ['lines' => $fewer], ['discount' => '50.00']] as $change) {
            $this->actingAs($cashier)
                ->post('/sales', $this->payment($pending, $change))
                ->assertSessionHasErrors(['pending_payment_id' => "The items or prices differ from pending payment [{$pending->reference}]. It is paid exactly as it was sent; cancel it to change anything."]);
        }

        $this->assertSame(0, Sale::count());
    }

    public function test_it_is_paid_once_and_for_its_own_customer(): void
    {
        $pending = $this->sent();
        $other = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending, ['customer_id' => $other->id]))
            ->assertSessionHasErrors('pending_payment_id');

        $this->actingAs($this->staff())->post('/sales', $this->payment($pending))->assertSessionHasNoErrors();

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending->fresh('lines')))
            ->assertSessionHasErrors(['pending_payment_id' => "Pending payment [{$pending->reference}] is paid, so it cannot be paid."]);

        $this->assertSame(1, Sale::count());
    }

    public function test_it_is_paid_only_where_its_goods_are_held(): void
    {
        $warehouse = $this->unaudited(fn () => Location::create(['name' => 'Back Warehouse', 'type' => 'warehouse', 'is_active' => true]));
        $pending = $this->sent();

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending, ['location_id' => $warehouse->id]))
            ->assertSessionHasErrors('pending_payment_id');
    }

    public function test_cancelling_frees_the_goods_and_needs_a_reason(): void
    {
        $pending = $this->sent($sender = $this->encoder());

        $this->actingAs($sender)->post("/pending-payments/{$pending->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');

        $this->actingAs($sender)
            ->post("/pending-payments/{$pending->id}/cancel", ['reason' => 'Customer left'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PendingPaymentStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame([], Reservations::heldForPendingPayments([$this->product->id]));

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending->fresh('lines')))
            ->assertSessionHasErrors('pending_payment_id');
    }

    public function test_a_sender_cancels_their_own_and_whoever_takes_payment_cancels_any(): void
    {
        $pending = $this->sent($this->encoder());

        $this->actingAs($this->kioskOnly())
            ->post("/pending-payments/{$pending->id}/cancel", ['reason' => 'Not mine'])
            ->assertForbidden();

        $mine = $this->sent($kiosk = $this->kioskOnly(), ['lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '250.00']]]);

        $this->actingAs($kiosk)
            ->post("/pending-payments/{$mine->id}/cancel", ['reason' => 'Customer left'])
            ->assertSessionHasNoErrors();

        // The cashier faces the customer, so may cancel anybody's.
        $this->actingAs($this->encoder())
            ->post("/pending-payments/{$pending->id}/cancel", ['reason' => 'The customer changed their mind'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PendingPaymentStatus::Cancelled, $pending->fresh()->status);
    }

    /**
     * A role made on the Roles screen with the kiosk and Record a sale —
     * the client's Sales Encoder — has both buttons and the Pending tab,
     * with nothing more ticked (the user's GO! of 24 November 2026).
     */
    protected function encoder(): User
    {
        $role = Role::findOrCreate('Sales Encoder', 'web');
        $role->syncPermissions(['kiosk.use', 'sales.create', 'sales.view']);

        return $this->unaudited(fn () => tap(User::factory()->create(), fn (User $u) => $u->assignRole('Sales Encoder')));
    }

    public function test_anyone_at_the_kiosk_has_pending_payment_beside_take_payment_with_nothing_ticked(): void
    {
        $this->sent($this->encoder());

        $this->actingAs($encoder = $this->encoder())->get('/pos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.sendPending', true)
            ->where('can.takePayment', true)
            ->where('can.seePending', true)
            // Every order waiting at the store, not only their own.
            ->has('pending', 1)
            ->where('pending.0.can.pay', true)
            ->where('pending.0.can.cancel', true));

        $this->sent($encoder, ['lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '250.00']]]);

        $this->assertSame(2, PendingPayment::count());
    }

    public function test_somebody_who_may_not_use_the_kiosk_cannot_send_one(): void
    {
        $clerk = $this->unaudited(fn () => User::factory()->withPermissions('sales.create', 'sales.view')->create());

        $this->actingAs($clerk)->post('/pending-payments', $this->order())->assertForbidden();
    }

    public function test_at_closing_it_expires_and_holds_nothing_with_nothing_running(): void
    {
        $pending = $this->sent();

        Carbon::setTestNow(Carbon::tomorrow()->setTime(8, 0));

        $this->assertSame('expired', $pending->fresh()->standing()['value']);
        $this->assertSame([], Reservations::heldForPendingPayments([$this->product->id]));

        $this->actingAs($this->staff())
            ->post('/sales', $this->payment($pending->fresh('lines'), ['sale_date' => Carbon::today()->toDateString()]))
            ->assertSessionHasErrors(['pending_payment_id' => "Pending payment [{$pending->reference}] is expired, so it cannot be paid."]);

        Carbon::setTestNow();
    }

    public function test_a_hold_at_one_store_does_not_block_another_place(): void
    {
        $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => '250.00']]]);

        $this->assertSame([], Reservations::heldForPendingPayments([$this->product->id], $this->store->id + 999));
    }

    public function test_a_kiosk_that_only_sends_may_send_but_never_take_money(): void
    {
        $kiosk = $this->kioskOnly();

        $this->sent($kiosk);

        $this->actingAs($kiosk)->post('/sales', [
            'customer_id' => $this->walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 250]],
        ])->assertForbidden();

        $this->actingAs($kiosk)->get('/pos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.takePayment', false)
            ->where('can.sendPending', true)
            ->where('can.seePending', false)
            // Its own order is listed, so it can be cancelled.
            ->has('pending', 1));
    }

    public function test_the_till_lists_what_waits_at_its_store_for_the_cashier(): void
    {
        $pending = $this->sent();

        $cashier = $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );

        $this->actingAs($cashier)->get('/pos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can.takePayment', true)
            ->has('pending', 1)
            ->where('pending.0.reference', $pending->reference)
            ->where('pending.0.lines.0.product_id', $this->product->id)
            ->where('pending.0.can.pay', true));
    }

    public function test_the_list_and_its_dialog_read_the_same(): void
    {
        $pending = $this->sent();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/pending-payments')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments.data', 1)
            ->where('payments.data.0.status_label', 'Awaiting payment')
            ->where('waiting', 1));

        $page = $this->actingAs($admin)->get("/pending-payments/{$pending->id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/pending-payments/{$pending->id}/preview")->assertOk()->json();

        $this->assertSame($page['pending'], $dialog['pending']);
        $this->assertSame($page['can'], $dialog['can']);
    }

    /**
     * The person who really takes the money may sit at a desktop, not at the
     * kiosk (the user's GO! of 24 November 2026): Admin — who has no kiosk —
     * receives it from Sales → Pending Payments, with Record a sale alone.
     */
    public function test_the_back_office_receives_the_payment_without_the_kiosk(): void
    {
        $pending = $this->sent();
        $admin = $this->admin();

        $dialog = $this->actingAs($admin)->getJson("/pending-payments/{$pending->id}/preview")->assertOk()->json();

        $this->assertTrue($dialog['can']['receive']);
        // No kiosk, so no till to open it on.
        $this->assertFalse($dialog['can']['pay']);
        $this->assertNotEmpty($dialog['receive']['methods']);
        $this->assertSame(Carbon::today()->toDateString(), $dialog['receive']['today']);

        $this->actingAs($admin)->get('/pending-payments')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payments.data.0.can.receive', true));

        // Posted from the dialog: not from the till, so it lands on the sale.
        $form = $this->payment($pending);
        unset($form['from']);

        $response = $this->actingAs($admin)->post('/sales', $form)->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $response->assertRedirect(route('sales.show', $sale));

        $this->assertSame(PendingPaymentStatus::Paid, $pending->fresh()->status);
        $this->assertSame($sale->id, $pending->fresh()->sale_id);
    }

    public function test_somebody_who_may_not_record_a_sale_is_offered_no_payment_box(): void
    {
        $pending = $this->sent();
        $viewer = $this->unaudited(fn () => User::factory()->withPermissions('pending_payments.view')->create());

        $dialog = $this->actingAs($viewer)->getJson("/pending-payments/{$pending->id}/preview")->assertOk()->json();

        $this->assertFalse($dialog['can']['receive']);
        $this->assertNull($dialog['receive']);
    }

    /** The sidebar's red badge: how many wait, for whoever may open the list. */
    public function test_the_sidebar_counts_what_waits_to_be_paid(): void
    {
        $this->sent();
        $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '250.00']]]);
        $paid = $this->sent(null, ['lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '250.00']]]);
        $this->actingAs($this->staff())->post('/sales', $this->payment($paid))->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('badges.pending_payments', 2));

        // Somebody who cannot open the list is told nothing.
        $this->actingAs($this->encoder())->get('/pos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('badges', []));

        // Past closing they hold nothing, so they are not counted.
        Carbon::setTestNow(Carbon::tomorrow()->setTime(8, 0));
        $this->actingAs($this->admin())->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('badges.pending_payments', 0));
        Carbon::setTestNow();
    }

    public function test_a_product_or_customer_on_one_is_never_deleted(): void
    {
        $customer = $this->unaudited(fn () => Customer::factory()->create());
        $this->sent(null, ['customer_id' => $customer->id]);
        $admin = $this->admin();

        $this->assertFalse($admin->can('delete', $this->product->fresh()));
        $this->assertFalse($admin->can('delete', $customer->fresh()));
    }

    public function test_a_retry_of_the_paying_sale_finds_the_same_sale(): void
    {
        $pending = $this->sent();
        $form = $this->payment($pending);
        $cashier = $this->staff();

        $this->actingAs($cashier)->post('/sales', $form)->assertSessionHasNoErrors();
        $this->actingAs($cashier)->post('/sales', $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::count());
    }

    public function test_the_ledger_is_still_whole_afterwards(): void
    {
        $pending = $this->sent();
        $this->actingAs($this->staff())->post('/sales', $this->payment($pending))->assertSessionHasNoErrors();

        $this->artisan('inventory:verify')->assertExitCode(0);
    }
}
