<?php

namespace Tests\Feature\Purchases;

use App\Enums\AuditAction;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderException;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DashboardOverview;
use App\Services\PurchaseOrderDesk;
use App\Services\PurchaseRequestDesk;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 24.4: a purchase order is approved before it is sent.
 *
 * An order placed by somebody who may not approve orders waits *For
 * approval*: nothing is received against it and it prints only as a draft.
 * The approver (Admin) approves it or rejects it with a reason. An approver's
 * own order is approved as it is placed while `purchasing.self_approval` is
 * on; with it off, somebody else must answer it.
 */
class PurchaseOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** Staff, given `purchase_orders.create` on the Roles screen: the in-charge. */
    protected User $buyer;

    protected Product $wire;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create(['name' => 'Fernan Go']));
        $this->buyer = $this->unaudited(fn () => User::factory()->withRole('Staff')->create(['name' => 'Ana Buyer']));
        $this->buyer->givePermissionTo('purchase_orders.create');
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20', 'cost' => 45]));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Alpha Electrical']));
    }

    protected function place(User $by): PurchaseOrder
    {
        $this->actingAs($by)->post('/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '44.00']],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->firstOrFail();
    }

    protected function deliver(PurchaseOrder $order)
    {
        $order->load('lines');

        return $this->post('/purchases', [
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'product_id' => $this->wire->id,
                'quantity' => 20,
                'unit_cost' => '44.00',
            ]],
        ]);
    }

    /** @return array<string, mixed> */
    protected function detail(PurchaseOrder $order, User $as): array
    {
        return $this->actingAs($as)->getJson("/purchase-orders/{$order->id}/preview")->assertOk()->json();
    }

    /* ------------------------------------------------------------ Waiting */

    public function test_an_order_placed_by_the_in_charge_waits_for_approval(): void
    {
        $order = $this->place($this->buyer);

        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);
        $this->assertNull($order->approved_at);
        $this->assertFalse($order->isOutstanding());
        $this->assertSame(0, PurchaseOrder::query()->outstanding()->count());

        $this->assertTrue(AuditTrail::where('action', AuditAction::Create->value)
            ->where('description', 'like', "Placed purchase order [{$order->reference}]:%Waiting for approval.")
            ->exists());

        $mine = $this->detail($order, $this->buyer);
        $this->assertTrue($mine['order']['draft']);
        $this->assertNull($mine['order']['approved_by']);
        $this->assertSame('For approval', $mine['order']['status_label']);
        $this->assertFalse($mine['can']['approve']);
        $this->assertFalse($mine['can']['receive']);

        $owner = $this->detail($order, $this->admin);
        $this->assertTrue($owner['can']['approve']);
        $this->assertTrue($owner['can']['reject']);
        // The approver answers with Reject, not Cancel.
        $this->assertFalse($owner['can']['cancel']);
    }

    public function test_nothing_is_received_against_an_order_waiting_for_approval(): void
    {
        $order = $this->place($this->buyer);

        $this->actingAs($this->buyer)->get("/purchases/create?order={$order->id}")
            ->assertRedirect('/purchases/create')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'waiting for approval'));

        $this->actingAs($this->buyer);
        $this->deliver($order)->assertSessionHasErrors([
            'purchase_order_id' => "Order [{$order->reference}] is waiting for approval, so nothing can be received against it yet.",
        ]);

        $this->assertDatabaseCount('purchases', 0);
        $this->assertSame(0, $this->wire->fresh()->on_hand);
    }

    /* ------------------------------------------------------------ Answers */

    public function test_the_owner_approves_it_and_it_can_then_be_received(): void
    {
        $order = $this->place($this->buyer);

        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/approve")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'was approved'));

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Open, $order->status);
        $this->assertSame($this->admin->id, (int) $order->approved_by);
        $this->assertNotNull($order->approved_at);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Approve->value)
            ->where('description', "Approved purchase order [{$order->reference}].")
            ->exists());

        $paper = $this->detail($order, $this->buyer)['order'];
        $this->assertFalse($paper['draft']);
        $this->assertSame('Fernan Go', $paper['approved_by']);

        $this->actingAs($this->buyer);
        $this->deliver($order)->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
        $this->assertSame(20, $this->wire->fresh()->on_hand);
    }

    public function test_a_rejection_needs_a_reason_and_is_final(): void
    {
        $order = $this->place($this->buyer);

        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->fresh()->status);

        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/reject", ['reason' => 'Too dear, ask Bravo'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::Rejected, $order->status);
        $this->assertSame('Too dear, ask Bravo', $order->reject_reason);
        $this->assertSame($this->admin->id, (int) $order->rejected_by);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Reject->value)
            ->where('description', "Rejected purchase order [{$order->reference}]: Too dear, ask Bravo")
            ->exists());

        $paper = $this->detail($order, $this->admin);
        $this->assertTrue($paper['order']['draft']);
        $this->assertNull($paper['order']['approved_by']);
        $this->assertFalse($paper['can']['approve']);
        $this->assertFalse($paper['can']['cancel']);

        // Nothing moves it again.
        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/approve")->assertForbidden();
        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertForbidden();
        $this->actingAs($this->buyer);
        $this->deliver($order)->assertSessionHasErrors('purchase_order_id');
    }

    public function test_only_an_approver_answers_and_only_once(): void
    {
        $order = $this->place($this->buyer);

        // Route gate and policy both refuse somebody without the permission.
        $this->actingAs($this->buyer)->post("/purchase-orders/{$order->id}/approve")->assertForbidden();
        $this->actingAs($this->buyer)->post("/purchase-orders/{$order->id}/reject", ['reason' => 'No'])->assertForbidden();

        $desk = app(PurchaseOrderDesk::class);
        $desk->approve($order, $this->admin);

        // A second answer, from a tab opened before the first, is refused under the lock.
        try {
            $desk->reject($order, $this->admin, 'Too late');
            $this->fail('A second answer went through.');
        } catch (PurchaseOrderException $e) {
            $this->assertStringContainsString('no longer waiting for approval', $e->getMessage());
        }

        $this->assertSame(PurchaseOrderStatus::Open, $order->fresh()->status);
    }

    public function test_an_order_waiting_for_approval_may_still_be_called_off(): void
    {
        $order = $this->place($this->buyer);

        app(PurchaseOrderDesk::class)->cancel($order, $this->admin, 'Not needed after all');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $order->fresh()->status);
    }

    /* ---------------------------------------------------- One's own order */

    public function test_the_owners_own_order_is_approved_as_it_is_placed(): void
    {
        $order = $this->place($this->admin);

        $this->assertSame(PurchaseOrderStatus::Open, $order->status);
        $this->assertSame($this->admin->id, (int) $order->approved_by);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Approve->value)
            ->where('description', "Approved their own purchase order [{$order->reference}] as it was placed.")
            ->exists());
        $this->assertFalse($this->detail($order, $this->admin)['order']['draft']);
    }

    public function test_with_the_setting_off_nobody_approves_their_own_order(): void
    {
        app(Settings::class)->set('purchasing.self_approval', false);

        $order = $this->place($this->admin);
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);

        $this->actingAs($this->admin)->post("/purchase-orders/{$order->id}/approve")->assertForbidden();
        $this->assertTrue($this->detail($order, $this->admin)['can']['ownOrder']);

        try {
            app(PurchaseOrderDesk::class)->approve($order, $this->admin);
            $this->fail('An approver approved their own order with the setting off.');
        } catch (PurchaseOrderException $e) {
            $this->assertStringContainsString('somebody else must approve', $e->getMessage());
        }

        // A second approver may.
        $other = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->actingAs($other)->post("/purchase-orders/{$order->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Open, $order->fresh()->status);
    }

    /* --------------------------------------------------------- The award */

    public function test_orders_from_an_award_wait_for_approval_too(): void
    {
        $this->actingAs($this->buyer)->post('/purchase-requests', [
            'reason' => 'Lahug job',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 10, 'unit_cost' => '50.00']],
        ])->assertSessionHasNoErrors();

        $request = PurchaseRequest::latest('id')->firstOrFail();
        app(PurchaseRequestDesk::class)->approve($request, $this->admin);
        $request->load('lines');

        $this->actingAs($this->buyer)->post("/purchase-requests/{$request->id}/quotes", [
            'supplier_id' => $this->supplier->id,
            'prices' => [$request->lines->first()->id => '48.00'],
        ])->assertSessionHasNoErrors();

        $quoteLine = $request->fresh()->supplierQuotes()->first()->lines()->first();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => [$request->lines->first()->id => $quoteLine->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->buyer)->post("/purchase-requests/{$request->id}/award/order")->assertSessionHasNoErrors();

        $order = PurchaseOrder::where('purchase_request_id', $request->id)->sole();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $order->status);
        $this->assertSame('closed', $request->fresh()->status->value);

        // The request's approver is not the order's: the paper stays blank until the order is approved.
        $this->assertNull($this->detail($order, $this->admin)['order']['approved_by']);
    }

    /* ---------------------------------------------- The list and the card */

    public function test_the_list_and_the_dashboard_count_what_is_waiting(): void
    {
        $this->place($this->buyer);
        $this->place($this->buyer);
        $this->place($this->admin);

        $this->actingAs($this->admin)->get('/purchase-orders?status=pending_approval')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('awaitingApproval', 2)
                ->where('outstanding', 1)
                ->has('orders.data', 2));

        Cache::flush();
        $overview = app(DashboardOverview::class);
        [$from, $to] = $overview->resolve('today');

        $card = collect($overview->cards($from, $to, [], ['purchase_orders' => true])['secondary'] ?? [])
            ->firstWhere('key', 'purchase_orders');

        $this->assertNotNull($card);
        $this->assertSame(2, $card['value']);
        $this->assertStringContainsString('1,760.00 in all', $card['hint']);
        $this->assertSame('/purchase-orders?status=pending_approval', $card['href']);

        // Approving one is counted at once: the desk flushes after commit.
        app(PurchaseOrderDesk::class)->approve(PurchaseOrder::where('status', 'pending_approval')->first(), $this->admin);

        $card = collect($overview->cards($from, $to, [], ['purchase_orders' => true])['secondary'] ?? [])
            ->firstWhere('key', 'purchase_orders');
        $this->assertSame(1, $card['value']);

        // Without the permission there is no card.
        $none = collect($overview->cards($from, $to, [], [])['secondary'] ?? [])->firstWhere('key', 'purchase_orders');
        $this->assertNull($none);
    }
}
