<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditTrail;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseRequestDesk;
use App\Support\QuoteAbstract;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 24.2: the canvass on an approved purchase request — suppliers'
 * prices, the Abstract of Quotation, the award and reopening it. Built on
 * the client's answers: the canvass is optional (Q2), a dearer supplier may
 * win with a reason (Q3), one supplier per item (Q4), delivery days, terms
 * and a note beside the price (Q5).
 */
class CanvassTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Product $wire;

    protected Product $breaker;

    protected Product $tape;

    protected Supplier $alpha;

    protected Supplier $bravo;

    protected Supplier $charlie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['name' => 'THHN Wire', 'sku' => 'WIRE']));
        $this->breaker = $this->unaudited(fn () => Product::factory()->create(['name' => 'Breaker 20A', 'sku' => 'BRK']));
        $this->tape = $this->unaudited(fn () => Product::factory()->create(['name' => 'Electrical Tape', 'sku' => 'TAPE']));
        $this->alpha = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Alpha Electrical']));
        $this->bravo = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Bravo Hardware']));
        $this->charlie = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'Charlie Supply']));
    }

    /** An approved request: 10 wire, 4 breakers, 20 tape. */
    protected function approvedRequest(): PurchaseRequest
    {
        $this->actingAs($this->staff)->post('/purchase-requests', [
            'reason' => 'Lahug job',
            'lines' => [
                ['product_id' => $this->wire->id, 'quantity' => 10, 'unit_cost' => '50.00'],
                ['product_id' => $this->breaker->id, 'quantity' => 4, 'unit_cost' => '300.00'],
                ['product_id' => $this->tape->id, 'quantity' => 20, 'unit_cost' => '20.00'],
            ],
        ])->assertSessionHasNoErrors();

        $request = PurchaseRequest::latest('id')->firstOrFail();
        app(PurchaseRequestDesk::class)->approve($request, $this->admin);

        return $request->fresh('lines');
    }

    /** @param  array<int, string|null>  $prices  in the order of the request's lines */
    protected function quote(PurchaseRequest $request, Supplier $supplier, array $prices, array $extra = [], ?User $by = null)
    {
        $keyed = [];
        foreach ($request->lines()->orderBy('id')->pluck('id') as $index => $lineId) {
            $keyed[$lineId] = $prices[$index] ?? null;
        }

        return $this->actingAs($by ?? $this->staff)
            ->post("/purchase-requests/{$request->id}/quotes", ['supplier_id' => $supplier->id, 'prices' => $keyed] + $extra);
    }

    /** Three suppliers. Wire: Alpha lowest. Breaker: Bravo lowest. Tape: Alpha and Charlie tie. */
    protected function canvassed(): PurchaseRequest
    {
        $request = $this->approvedRequest();

        $this->quote($request, $this->alpha, ['48.00', '320.00', '18.00'], ['delivery_days' => 3, 'payment_terms' => 'COD'])->assertSessionHasNoErrors();
        $this->quote($request, $this->bravo, ['52.00', '295.00', '19.50'], ['delivery_days' => 1])->assertSessionHasNoErrors();
        $this->quote($request, $this->charlie, [null, '310.00', '18.00'], ['notes' => 'No wire in stock'])->assertSessionHasNoErrors();

        return $request->fresh();
    }

    protected function cell(array $line, Supplier $supplier, array $abstract): ?array
    {
        $column = collect($abstract['suppliers'])->search(fn ($s) => $s['supplier_id'] === $supplier->id);

        return $line['cells'][$column];
    }

    /** request line id => quote line id, for the supplier named per line */
    protected function choices(PurchaseRequest $request, array $winners): array
    {
        $abstract = QuoteAbstract::of($request->fresh());
        $choices = [];

        foreach ($abstract['lines'] as $index => $line) {
            if (isset($winners[$index])) {
                $choices[$line['line_id']] = $this->cell($line, $winners[$index], $abstract)['quote_line_id'];
            }
        }

        return $choices;
    }

    /* ------------------------------------------------------------ Quotes */

    public function test_the_in_charge_records_each_suppliers_prices(): void
    {
        $request = $this->canvassed();

        $this->assertSame(3, SupplierQuote::where('purchase_request_id', $request->id)->count());

        $alpha = SupplierQuote::where('supplier_id', $this->alpha->id)->with('lines')->firstOrFail();
        $this->assertSame(3, $alpha->delivery_days);
        $this->assertSame('COD', $alpha->payment_terms);
        $this->assertSame('480.00', $alpha->lines->sortBy('id')->first()->line_total);

        // Charlie left the wire empty: no row for it.
        $this->assertSame(2, SupplierQuote::where('supplier_id', $this->charlie->id)->firstOrFail()->lines()->count());

        $this->assertTrue(AuditTrail::query()
            ->where('description', 'like', "Recorded a quote from Alpha Electrical on request [{$request->reference}]: 3 of 3 item(s)%")
            ->exists());
    }

    public function test_quotes_wait_for_an_approved_request(): void
    {
        $this->actingAs($this->staff)->post('/purchase-requests', [
            'reason' => 'Lahug job',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 10, 'unit_cost' => '50.00']],
        ]);
        $pending = PurchaseRequest::latest('id')->firstOrFail();

        $this->quote($pending, $this->alpha, ['48.00'])->assertForbidden();
        $this->actingAs($this->staff)->get("/purchase-requests/{$pending->id}/quotes/create")->assertRedirect("/purchase-requests/{$pending->id}");
    }

    public function test_one_quote_per_supplier_at_least_one_price_and_only_this_requests_items(): void
    {
        $request = $this->approvedRequest();
        $this->quote($request, $this->alpha, ['48.00'])->assertSessionHasNoErrors();

        $this->quote($request, $this->alpha, ['47.00'])
            ->assertSessionHasErrors(['supplier_id' => 'This supplier has already quoted on this request. Remove that quote first to enter it again.']);

        $this->quote($request, $this->bravo, [null, null, null])
            ->assertSessionHasErrors('prices');

        $this->actingAs($this->staff)->post("/purchase-requests/{$request->id}/quotes", [
            'supplier_id' => $this->bravo->id,
            'prices' => [999999 => '10.00'],
        ])->assertSessionHasErrors(['prices' => 'A price was given for an item that is not on this request.']);

        $this->quote($request, $this->bravo, ['0'])->assertSessionHasErrors('prices.*');
    }

    public function test_a_switched_off_supplier_cannot_quote(): void
    {
        $request = $this->approvedRequest();
        $this->bravo->update(['is_active' => false]);

        $this->quote($request, $this->bravo, ['48.00'])
            ->assertSessionHasErrors(['supplier_id' => 'Pick a supplier that is still active.']);
    }

    public function test_a_quote_can_be_removed_before_the_award(): void
    {
        $request = $this->canvassed();
        $quote = SupplierQuote::where('supplier_id', $this->bravo->id)->firstOrFail();

        $this->actingAs($this->staff)->delete("/purchase-requests/{$request->id}/quotes/{$quote->id}")->assertRedirect();

        $this->assertNull($quote->fresh());
        $this->assertTrue(AuditTrail::query()->where('description', "Removed the quote from Bravo Hardware on request [{$request->reference}].")->exists());
    }

    /* ---------------------------------------------------------- Abstract */

    public function test_the_abstract_marks_the_lowest_and_adds_each_supplier_up(): void
    {
        $abstract = QuoteAbstract::of($this->canvassed());
        [$wire, $breaker, $tape] = $abstract['lines'];

        $this->assertTrue($this->cell($wire, $this->alpha, $abstract)['lowest']);
        $this->assertFalse($this->cell($wire, $this->bravo, $abstract)['lowest']);
        $this->assertNull($this->cell($wire, $this->charlie, $abstract));
        $this->assertTrue($this->cell($breaker, $this->bravo, $abstract)['lowest']);

        // A tie marks both.
        $this->assertTrue($this->cell($tape, $this->alpha, $abstract)['lowest']);
        $this->assertTrue($this->cell($tape, $this->charlie, $abstract)['lowest']);

        // Alpha: 480 + 1,280 + 360.
        $this->assertSame('2120.00', collect($abstract['suppliers'])->firstWhere('supplier_id', $this->alpha->id)['total']);
        // The lowest on every item: 480 + 1,180 + 360.
        $this->assertSame('2020.00', $abstract['lowest_total']);
        $this->assertSame(2, collect($abstract['suppliers'])->firstWhere('supplier_id', $this->charlie->id)['items_quoted']);
    }

    public function test_the_page_carries_the_abstract_and_the_buttons(): void
    {
        $request = $this->canvassed();

        $this->actingAs($this->staff)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->has('request.canvass.suppliers', 3)
                ->where('request.canvass.lowest_total', '2020.00')
                ->where('can.quote', true)
                ->where('can.award', false));

        $this->actingAs($this->admin)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.award', true));
    }

    /* ------------------------------------------------------------- Award */

    public function test_the_owner_awards_the_lowest_on_each_item(): void
    {
        $request = $this->canvassed();
        $choices = $this->choices($request, [$this->alpha, $this->bravo, $this->charlie]);

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", ['choices' => $choices])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertTrue($request->isAwarded());
        $this->assertSame($this->admin->id, (int) $request->awarded_by);

        $abstract = QuoteAbstract::of($request);
        $this->assertSame('Alpha Electrical', $abstract['lines'][0]['award']['supplier']);
        $this->assertSame('Bravo Hardware', $abstract['lines'][1]['award']['supplier']);
        $this->assertSame('Charlie Supply', $abstract['lines'][2]['award']['supplier']);
        $this->assertSame('2020.00', $abstract['awarded_total']);

        $this->assertTrue(AuditTrail::query()->where('action', 'approve')
            ->where('description', 'like', "Awarded request [{$request->reference}]:%total 2,020.00.")->exists());
    }

    public function test_a_dearer_supplier_needs_a_reason(): void
    {
        $request = $this->canvassed();
        // Breaker to Alpha (320) instead of Bravo (295).
        $choices = $this->choices($request, [$this->alpha, $this->alpha, $this->alpha]);
        $breakerLine = array_keys($choices)[1];

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", ['choices' => $choices])
            ->assertSessionHasErrors(["reasons.{$breakerLine}"]);
        $this->assertFalse($request->fresh()->isAwarded());

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $choices,
            'reasons' => [$breakerLine => 'One supplier for the whole job'],
        ])->assertSessionHasNoErrors();

        $line = QuoteAbstract::of($request->fresh())['lines'][1];
        $this->assertSame('One supplier for the whole job', $line['award']['reason']);
        $this->assertTrue(AuditTrail::query()->where('description', 'like', '%Not the lowest: Breaker 20A to Alpha Electrical (One supplier for the whole job).')->exists());
    }

    public function test_every_quoted_item_needs_a_supplier_and_an_unquoted_one_is_left_out(): void
    {
        $request = $this->approvedRequest();
        // Only wire and breakers are quoted; nobody quoted the tape.
        $this->quote($request, $this->alpha, ['48.00', '300.00', null])->assertSessionHasNoErrors();

        $choices = $this->choices($request, [$this->alpha]);
        $breakerLine = $request->lines()->orderBy('id')->skip(1)->value('id');

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", ['choices' => $choices])
            ->assertSessionHasErrors(["choices.{$breakerLine}" => 'Choose a supplier for Breaker 20A.']);

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->alpha]),
        ])->assertSessionHasNoErrors();

        $abstract = QuoteAbstract::of($request->fresh());
        $this->assertFalse($abstract['lines'][2]['quoted']);
        $this->assertNull($abstract['lines'][2]['award']);
    }

    public function test_a_choice_must_be_one_of_that_items_prices(): void
    {
        $request = $this->canvassed();
        $choices = $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]);
        // Point the wire at the breaker's price.
        $lines = array_keys($choices);
        $choices[$lines[0]] = $choices[$lines[1]];

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", ['choices' => $choices])
            ->assertSessionHasErrors("choices.{$lines[0]}");
    }

    public function test_staff_cannot_award(): void
    {
        $request = $this->canvassed();

        $this->actingAs($this->staff)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ])->assertForbidden();
    }

    public function test_the_award_locks_the_quotes_until_it_is_reopened_with_a_reason(): void
    {
        $request = $this->canvassed();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ])->assertSessionHasNoErrors();

        $quote = SupplierQuote::where('supplier_id', $this->bravo->id)->firstOrFail();
        $late = $this->unaudited(fn () => Supplier::factory()->create());

        $this->quote($request->fresh(), $late, ['40.00'])->assertForbidden();
        $this->actingAs($this->staff)->delete("/purchase-requests/{$request->id}/quotes/{$quote->id}")->assertForbidden();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ])->assertForbidden();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/reopen", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/reopen", ['reason' => 'Bravo can no longer deliver'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertFalse($request->isAwarded());
        $this->assertNull(QuoteAbstract::of($request)['lines'][0]['award']);
        $this->assertTrue(AuditTrail::query()->where('description', "Reopened the award on request [{$request->reference}]: Bravo can no longer deliver")->exists());

        // Open again: the quote can now be removed.
        $this->actingAs($this->staff)->delete("/purchase-requests/{$request->id}/quotes/{$quote->id}")->assertRedirect();
        $this->assertNull($quote->fresh());
    }

    /* ----------------------------------------------- Orders, before 24.3 */

    public function test_the_canvass_is_optional_and_an_unawarded_request_still_orders_by_hand(): void
    {
        $request = $this->canvassed();

        $this->actingAs($this->admin)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.order', true));
    }

    public function test_an_awarded_request_is_not_ordered_by_hand(): void
    {
        $request = $this->canvassed();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ]);

        $this->actingAs($this->admin)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.order', false));

        $this->actingAs($this->admin)->get("/purchase-orders/create?request={$request->id}")
            ->assertRedirect('/purchase-orders/create')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'made from the award'));

        $this->actingAs($this->admin)->post('/purchase-orders', [
            'purchase_request_id' => $request->id,
            'supplier_id' => $this->alpha->id,
            'order_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 10, 'unit_cost' => '48.00']],
        ])->assertSessionHasErrors();

        $this->assertStringContainsString(
            'made from the award',
            collect(session('errors')->getBag('default')->all())->implode(' '),
        );

        $this->assertSame(0, PurchaseOrder::count());
    }

    /* ------------------------------------------ Orders from the award (24.3) */

    /** Wire and tape to Alpha (3 days, COD), breakers to Bravo (1 day). */
    protected function awarded(): PurchaseRequest
    {
        $request = $this->canvassed();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ])->assertSessionHasNoErrors();

        return $request->fresh();
    }

    public function test_an_award_becomes_one_order_per_winning_supplier_at_the_quoted_prices(): void
    {
        Carbon::setTestNow('2026-11-14 09:00:00');
        $request = $this->awarded();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")
            ->assertRedirect("/purchase-requests/{$request->id}")
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 orders were placed from the award'));

        $orders = PurchaseOrder::where('purchase_request_id', $request->id)->orderBy('id')->with('lines')->get();
        $this->assertCount(2, $orders);

        [$alpha, $bravo] = [$orders->firstWhere('supplier_id', $this->alpha->id), $orders->firstWhere('supplier_id', $this->bravo->id)];

        // Alpha: 10 wire at 48 and 20 tape at 18.
        $this->assertSame(['48.00', '18.00'], $alpha->lines->sortBy('id')->pluck('unit_cost')->values()->all());
        $this->assertSame('840.00', $alpha->total_amount);
        $this->assertSame('2026-11-17', $alpha->expected_date->toDateString());
        $this->assertStringContainsString("From the award on request [{$request->reference}]", $alpha->notes);
        $this->assertStringContainsString('Payment terms quoted: COD', $alpha->notes);

        // Phase 31.3: delivered to the default location, which receiving
        // fills in and may change.
        $this->assertTrue($orders->every(fn ($order) => (int) $order->deliver_to_location_id === Location::default()->id));

        // Bravo: 4 breakers at 295, expected the next day.
        $this->assertSame('1180.00', $bravo->total_amount);
        $this->assertSame('2026-11-15', $bravo->expected_date->toDateString());

        // Every order line knows the request line it fills.
        $this->assertSame(
            $request->lines()->orderBy('id')->pluck('id')->sort()->values()->all(),
            $orders->flatMap->lines->pluck('purchase_request_line_id')->sort()->values()->all(),
        );

        // The orders add up to the award.
        $this->assertSame(QuoteAbstract::of($request->fresh())['awarded_total'], number_format($orders->sum('total_amount'), 2, '.', ''));

        $request->refresh();
        $this->assertSame('closed', $request->status->value);
        $this->assertSame("Ordered on [{$orders[0]->reference}], [{$orders[1]->reference}].", $request->close_reason);
        $this->assertTrue(AuditTrail::query()->where('description', 'like', "Placed purchase order [{$orders[0]->reference}]:%from the award on request [{$request->reference}].")->exists());
        Carbon::setTestNow();
    }

    public function test_the_page_lists_every_order_and_the_button_goes(): void
    {
        $request = $this->awarded();

        $this->actingAs($this->admin)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.orderFromAward', true));

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order");

        $this->actingAs($this->admin)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->has('request.orders', 2)
                ->where('request.orders.0.supplier', 'Alpha Electrical')
                ->where('can.orderFromAward', false)
                ->where('can.reopenAward', false));
    }

    public function test_an_item_nobody_quoted_is_not_ordered_and_the_request_says_so(): void
    {
        $request = $this->approvedRequest();
        $this->quote($request, $this->alpha, ['48.00', '300.00', null])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->alpha]),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")->assertSessionHasNoErrors();

        $order = PurchaseOrder::where('purchase_request_id', $request->id)->sole();
        $this->assertCount(2, $order->lines);
        $this->assertStringEndsWith('1 item(s) nobody quoted were not ordered.', $request->fresh()->close_reason);
    }

    public function test_only_an_awarded_unordered_request_orders_from_its_award_and_only_once(): void
    {
        $request = $this->canvassed();

        // Quoted but not awarded.
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")->assertForbidden();

        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ]);
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")->assertRedirect();

        // Twice.
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")->assertForbidden();
        $this->assertSame(2, PurchaseOrder::where('purchase_request_id', $request->id)->count());

        // And the award can no longer be reopened.
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/reopen", ['reason' => 'Too late'])->assertForbidden();
    }

    public function test_placing_orders_needs_the_order_permission(): void
    {
        $request = $this->awarded();

        // Staff read orders but do not place them.
        $this->actingAs($this->staff)->post("/purchase-requests/{$request->id}/award/order")->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_an_item_asked_for_by_the_box_is_ordered_by_the_box(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->tape->id, 'unit_id' => $box->id, 'factor' => 12]));

        $this->actingAs($this->staff)->post('/purchase-requests', [
            'reason' => 'Tape for the job',
            'lines' => [['product_id' => $this->tape->id, 'unit_id' => $box->id, 'quantity' => 2, 'unit_cost' => '200.00']],
        ])->assertSessionHasNoErrors();
        $request = PurchaseRequest::latest('id')->firstOrFail();
        app(PurchaseRequestDesk::class)->approve($request, $this->admin);

        // 190 a box.
        $this->quote($request, $this->alpha, ['190.00'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", ['choices' => $this->choices($request, [$this->alpha])]);
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award/order")->assertSessionHasNoErrors();

        $line = PurchaseOrder::where('purchase_request_id', $request->id)->sole()->lines->sole();
        $this->assertSame($box->id, (int) $line->unit_id);
        $this->assertSame(2, $line->unit_quantity);
        $this->assertSame(24, $line->quantity);
        $this->assertSame('190.00', $line->unit_cost);
        $this->assertSame('380.00', $line->line_total);
    }

    /* ------------------------------------------------------------ Reset */

    public function test_the_go_live_reset_empties_the_canvass(): void
    {
        $request = $this->canvassed();
        $this->actingAs($this->admin)->post("/purchase-requests/{$request->id}/award", [
            'choices' => $this->choices($request, [$this->alpha, $this->bravo, $this->alpha]),
        ]);

        $this->artisan('data:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, SupplierQuote::count());
        $this->assertSame(0, PurchaseRequest::count());
    }
}
