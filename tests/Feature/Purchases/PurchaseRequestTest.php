<?php

namespace Tests\Feature\Purchases;

use App\Enums\AuditAction;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\PurchaseRequestException;
use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\DashboardOverview;
use App\Services\PurchaseRequestDesk;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21: purchase requests with approval.
 *
 * Staff raise a request; somebody holding `purchase_requests.approve` who
 * did not raise it approves or rejects it; a request nobody needs is closed
 * with a reason. Nothing moves stock or money.
 */
class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Product $wire;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20', 'cost' => 45]));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
    }

    /** @param  array<string, mixed>  $overrides */
    protected function form(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'reason' => 'Running low before the contractor order',
            'needed_by' => Carbon::today()->addDays(3)->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '45.00']],
        ], $overrides);
    }

    protected function raise(?User $by = null, array $overrides = []): PurchaseRequest
    {
        $this->actingAs($by ?? $this->staff)
            ->post('/purchase-requests', $this->form($overrides))
            ->assertSessionHasNoErrors();

        return PurchaseRequest::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------- Raising */

    public function test_staff_raise_a_request_that_waits_for_approval(): void
    {
        $request = $this->raise();

        $this->assertSame(PurchaseRequestStatus::Pending, $request->status);
        $this->assertSame('PRQ-'.now()->year.'-0001', $request->reference);
        $this->assertSame($this->staff->id, (int) $request->requested_by);
        $this->assertSame($this->supplier->id, (int) $request->supplier_id);
        $this->assertSame(Carbon::today()->toDateString(), $request->request_date->toDateString());
        $this->assertSame('900.00', $request->estimated_total);
        $this->assertCount(1, $request->lines);
        $this->assertSame(20, $request->lines->first()->quantity);

        // Nothing moved.
        $this->assertSame(0, $this->wire->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 0);

        $this->assertTrue(AuditTrail::query()
            ->where('action', AuditAction::Create->value)
            ->where('module', 'Purchase Requests')
            ->where('description', 'like', "%[{$request->reference}]%")
            ->exists());
    }

    public function test_a_line_typed_by_the_box_keeps_both_figures(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->wire->id, 'unit_id' => $box->id, 'factor' => 12]));

        $request = $this->raise(overrides: [
            'lines' => [['product_id' => $this->wire->id, 'unit_id' => $box->id, 'quantity' => 2, 'unit_cost' => '500.00']],
        ]);

        $line = $request->lines->first();
        $this->assertSame($box->id, (int) $line->unit_id);
        $this->assertSame(2, $line->unit_quantity);
        $this->assertSame(12, $line->unit_factor);
        $this->assertSame(24, $line->quantity);
        $this->assertSame('1000.00', $line->line_total);
        $this->assertSame('1000.00', $request->estimated_total);
    }

    public function test_the_form_is_checked(): void
    {
        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form(['reason' => '', 'lines' => []]))
            ->assertSessionHasErrors(['reason', 'lines']);

        // Half a piece of something counted whole.
        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form([
                'lines' => [['product_id' => $this->wire->id, 'quantity' => 2.5, 'unit_cost' => '45.00']],
            ]))
            ->assertSessionHasErrors('lines.0.quantity');

        // Needed yesterday.
        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form(['needed_by' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHasErrors('needed_by');

        $off = $this->unaudited(fn () => Product::factory()->inactive()->create());

        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form([
                'lines' => [['product_id' => $off->id, 'quantity' => 1, 'unit_cost' => '1.00']],
            ]))
            ->assertSessionHasErrors('lines.0.product_id');

        $this->assertDatabaseCount('purchase_requests', 0);
    }

    public function test_the_supplier_and_the_date_are_optional_and_the_cost_may_be_nought(): void
    {
        $request = $this->raise(overrides: [
            'supplier_id' => '',
            'needed_by' => '',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 5, 'unit_cost' => '0']],
        ]);

        $this->assertNull($request->supplier_id);
        $this->assertNull($request->needed_by);
        $this->assertSame('0.00', $request->estimated_total);
    }

    public function test_the_same_form_sent_twice_raises_one_request(): void
    {
        $key = (string) Str::uuid();

        $this->actingAs($this->staff)->post('/purchase-requests', $this->form(['idempotency_key' => $key]))->assertSessionHasNoErrors();
        $first = PurchaseRequest::firstOrFail();

        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form(['idempotency_key' => $key]))
            ->assertRedirect("/purchase-requests/{$first->id}");

        $this->assertDatabaseCount('purchase_requests', 1);

        // The same key with different contents is a stale screen, not a repeat.
        $this->actingAs($this->staff)
            ->post('/purchase-requests', $this->form(['idempotency_key' => $key, 'reason' => 'Something else entirely']))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertDatabaseCount('purchase_requests', 1);
    }

    public function test_somebody_without_the_permission_cannot_raise_one(): void
    {
        $clerk = $this->unaudited(fn () => User::factory()->withPermissions('purchase_requests.view')->create());

        $this->actingAs($clerk)->get('/purchase-requests/create')->assertForbidden();
        $this->actingAs($clerk)->post('/purchase-requests', $this->form())->assertForbidden();
    }

    /* ------------------------------------------------------------ Deciding */

    public function test_an_admin_approves_a_request_somebody_else_raised(): void
    {
        $request = $this->raise();

        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/approve", ['note' => 'Buy the cheaper brand'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Approved, $request->status);
        $this->assertSame($this->admin->id, (int) $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame('Buy the cheaper brand', $request->decision_note);

        $this->assertTrue(AuditTrail::where('action', AuditAction::Approve->value)->exists());
    }

    public function test_by_default_an_approver_may_approve_their_own_request_and_the_trail_says_so(): void
    {
        $request = $this->raise($this->admin);

        $this->actingAs($this->admin)
            ->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', true)
                ->where('can.ownRequest', true)
                ->where('can.selfApproval', true));

        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Approved, $request->fresh()->status);

        $trail = AuditTrail::where('action', AuditAction::Approve->value)->latest('id')->firstOrFail();
        $this->assertStringContainsString('Approved their own purchase request', $trail->description);
        $this->assertTrue($trail->properties['own_request']);

        // Somebody else's request reads as an ordinary approval.
        $other = $this->raise();
        $this->actingAs($this->admin)->post("/purchase-requests/{$other->id}/approve")->assertSessionHasNoErrors();

        $trail = AuditTrail::where('action', AuditAction::Approve->value)->latest('id')->firstOrFail();
        $this->assertStringStartsWith('Approved purchase request', $trail->description);
        $this->assertFalse($trail->properties['own_request']);
    }

    public function test_with_the_setting_off_nobody_answers_their_own_request_not_even_an_admin(): void
    {
        app(Settings::class)->set('purchasing.self_approval', false);

        $request = $this->raise($this->admin);

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/reject", ['reason' => 'Not needed'])->assertForbidden();

        $this->assertSame(PurchaseRequestStatus::Pending, $request->fresh()->status);

        // The page says why the buttons are not there.
        $this->actingAs($this->admin)
            ->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', false)
                ->where('can.ownRequest', true)
                ->where('can.selfApproval', false)
                ->where('can.close', true));

        // And the service refuses it too, whatever the screen did.
        $this->actingAs($this->admin);
        $this->expectException(PurchaseRequestException::class);
        app(PurchaseRequestDesk::class)->approve($request, $this->admin);
    }

    public function test_staff_may_not_approve_or_reject(): void
    {
        $other = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $request = $this->raise($other);

        $this->actingAs($this->staff)->post("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/purchase-requests/{$request->id}/reject", ['reason' => 'No'])->assertForbidden();

        $this->assertSame(PurchaseRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_another_role_given_the_permission_may_approve(): void
    {
        $manager = $this->unaudited(fn () => User::factory()->withPermissions(
            'purchase_requests.view', 'purchase_requests.approve',
        )->create());

        $request = $this->raise();

        $this->actingAs($manager)->post("/purchase-requests/{$request->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Approved, $request->fresh()->status);
    }

    public function test_a_rejection_says_why(): void
    {
        $request = $this->raise();

        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/reject", ['reason' => 'We still have stock in the warehouse'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Rejected, $request->status);
        $this->assertSame('We still have stock in the warehouse', $request->decision_note);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Reject->value)->exists());
    }

    public function test_a_request_is_decided_once(): void
    {
        $request = $this->raise();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/reject", ['reason' => 'Changed my mind'])->assertForbidden();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertForbidden();

        // A stale copy, read before the approval, is refused under the lock.
        $stale = PurchaseRequest::find($request->id);
        $stale->status = PurchaseRequestStatus::Pending;

        $this->actingAs($this->admin);
        $this->expectException(PurchaseRequestException::class);
        app(PurchaseRequestDesk::class)->reject($stale, $this->admin, 'Too late');
    }

    /* ------------------------------------------------------------- Closing */

    public function test_whoever_raised_it_may_withdraw_it(): void
    {
        $request = $this->raise();

        $this->actingAs($this->staff)
            ->post("/purchase-requests/{$request->id}/close", ['reason' => 'Raised it twice'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::Closed, $request->status);
        $this->assertSame($this->staff->id, (int) $request->closed_by);
        $this->assertSame('Raised it twice', $request->close_reason);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Close->value)->exists());

        // Closed is the end.
        $this->actingAs($this->staff)
            ->post("/purchase-requests/{$request->id}/close", ['reason' => 'Again'])
            ->assertForbidden();
    }

    public function test_staff_cannot_close_somebody_elses_request_but_an_approver_can(): void
    {
        $other = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $request = $this->raise($other);

        $this->actingAs($this->staff)
            ->post("/purchase-requests/{$request->id}/close", ['reason' => 'Not mine to keep'])
            ->assertForbidden();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/approve")->assertSessionHasNoErrors();

        // Approved, then not needed after all.
        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/close", ['reason' => 'The customer cancelled'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PurchaseRequestStatus::Closed, $request->fresh()->status);
    }

    public function test_a_rejected_request_cannot_be_closed(): void
    {
        $request = $this->raise();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/reject", ['reason' => 'Not now'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post("/purchase-requests/{$request->id}/close", ['reason' => 'Tidy up'])
            ->assertForbidden();
    }

    /* --------------------------------------------------------------- Shape */

    public function test_a_request_is_never_edited_or_deleted(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'purchase-requests')) {
                continue;
            }

            // A supplier's quote (phase 24.2) is working paper on the
            // request, not the request: it may be removed until the award,
            // and the request itself is untouched.
            if ($route->getName() === 'purchase-requests.quotes.destroy') {
                continue;
            }

            $this->assertEmpty(
                array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']),
                "[{$route->uri()}] would edit or delete a purchase request."
            );
            $this->assertStringNotContainsString('edit', $route->uri());
        }
    }

    public function test_the_list_filters_and_marks_what_this_person_may_answer(): void
    {
        $pending = $this->raise();
        $other = $this->raise();
        $this->actingAs($this->admin)->post("/purchase-requests/{$other->id}/reject", ['reason' => 'Not now']);

        $this->actingAs($this->admin)
            ->get('/purchase-requests?status=pending')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseRequests/Index')
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $pending->id)
                ->where('requests.data.0.can.decide', true)
                ->where('waiting', 1));

        $this->actingAs($this->staff)
            ->get('/purchase-requests')
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 2)
                ->where('requests.data.0.can.decide', false)
                ->where('can.approve', false));
    }

    public function test_a_product_or_unit_named_on_a_request_cannot_be_deleted(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->wire->id, 'unit_id' => $box->id, 'factor' => 12]));

        $this->raise(overrides: [
            'lines' => [['product_id' => $this->wire->id, 'unit_id' => $box->id, 'quantity' => 1, 'unit_cost' => '500.00']],
        ]);

        $this->assertFalse($this->admin->can('delete', $this->wire->fresh()));

        // Taken off the product, the box is still named on the request line.
        ProductUnit::query()->delete();
        $this->assertFalse($this->admin->can('delete', $box->fresh()));
    }

    public function test_deleting_the_preferred_supplier_only_clears_it(): void
    {
        $request = $this->raise();

        $this->unaudited(fn () => $this->supplier->delete());

        $this->assertNull($request->fresh()->supplier_id);
    }

    /* ----------------------------------------------------------- Dashboard */

    public function test_the_dashboard_card_counts_what_is_waiting(): void
    {
        $overview = app(DashboardOverview::class);
        [$from, $to] = $overview->resolve('today');
        $may = ['purchase_requests' => true];

        $card = fn (array $filters = []) => collect($overview->cards($from, $to, $filters, $may)['cards'])
            ->firstWhere('key', 'purchase_requests');

        $this->assertSame(0, $card()['value']);
        $this->assertSame('ok', $card()['state']);

        $first = $this->raise();
        $this->raise($this->admin, ['lines' => [['product_id' => $this->wire->id, 'quantity' => 10, 'unit_cost' => '50.00']]]);

        // Raising flushed the cached figures.
        $this->assertSame(2, $card()['value']);
        $this->assertStringContainsString('1,400.00', $card()['hint']);

        // Narrowed to one person, the card counts what they raised.
        $this->assertSame(1, $card(['staff_id' => $this->staff->id])['value']);

        $this->actingAs($this->admin)->post("/purchase-requests/{$first->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(1, $card()['value']);

        $list = collect($overview->exceptions($from, $to, [], $may))->firstWhere('key', 'purchase_requests');
        $this->assertSame('ok', $list['state']);
        $this->assertCount(1, $list['rows']);
    }

    public function test_the_card_needs_its_own_permission(): void
    {
        $overview = app(DashboardOverview::class);
        [$from, $to] = $overview->resolve('today');

        $cards = $overview->cards($from, $to, [], ['purchases' => true])['cards'];

        $this->assertNull(collect($cards)->firstWhere('key', 'purchase_requests'));
    }
}
