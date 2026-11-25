<?php

namespace Tests\Feature\Locations;

use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 32: the count sheet — the figures at a cutoff, what was counted
 * beside them, and the differences posted as adjustments once approved.
 */
class StockCountTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Location $store;

    protected Location $warehouse;

    protected Product $paint;

    protected Product $brush;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();

        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'cost' => 300, 'selling_price' => 500]));
        $this->brush = $this->unaudited(fn () => Product::factory()->create(['sku' => 'BRS-2IN', 'cost' => 50, 'selling_price' => 90]));

        $this->actingAs($this->admin);

        $this->bringIn(10, $this->store, 300, $this->paint);
        $this->bringIn(20, $this->store, 50, $this->brush);
        $this->bringIn(5, $this->warehouse, 300, $this->paint);
    }

    protected function bringIn(int|float $quantity, Location $at, float $cost, Product $product): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ], Carbon::today(), 'other', locationId: $at->id);
    }

    protected function at(Location $location, Product $product): int|float
    {
        return LocationStock::where('product_id', $product->id)
            ->where('location_id', $location->id)->value('on_hand') ?? 0;
    }

    protected function start(?Location $at = null, array $overrides = [], ?User $as = null): StockCount
    {
        $this->actingAs($as ?? $this->admin)->post('/stock-counts', array_merge([
            'location_id' => ($at ?? $this->store)->id,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides))->assertSessionHasNoErrors();

        return StockCount::latest('id')->firstOrFail();
    }

    protected function line(StockCount $count, Product $product): StockCountLine
    {
        return $count->lines()->where('product_id', $product->id)->firstOrFail();
    }

    /** @param array<int, int|float|string|null> $figures Product id => counted. */
    protected function enter(StockCount $count, array $figures, ?User $as = null)
    {
        $counts = [];
        foreach ($figures as $productId => $counted) {
            $counts[] = ['id' => $count->lines()->where('product_id', $productId)->value('id'), 'counted_quantity' => $counted];
        }

        return $this->actingAs($as ?? $this->admin)->put("/stock-counts/{$count->id}/counts", ['counts' => $counts]);
    }

    protected function assertBooksBalance(): void
    {
        $ledger = app(InventoryLedger::class);

        $this->assertSame([], $ledger->reconcile());
        $this->assertSame([], $ledger->reconcileLocations());
    }

    /* ------------------------------------------------------------ Starting */

    public function test_starting_a_count_takes_the_figures_at_the_location_as_of_the_cutoff(): void
    {
        $count = $this->start();

        $this->assertStringStartsWith('CNT-', $count->reference);
        $this->assertSame('counting', $count->status->value);
        $this->assertNotNull($count->cutoff_at);

        // The store's own figures, not the product totals.
        $this->assertSame(10, $this->line($count, $this->paint)->system_quantity);
        $this->assertSame(20, $this->line($count, $this->brush)->system_quantity);
        $this->assertNull($this->line($count, $this->paint)->counted_quantity);

        // The warehouse holds paint only, but a count there lists every
        // active product: one found where the books say none is, is found.
        $there = $this->start($this->warehouse);
        $this->assertSame(5, $this->line($there, $this->paint)->system_quantity);
        $this->assertSame(0, $this->line($there, $this->brush)->system_quantity);

        $this->assertTrue(AuditTrail::where('description', 'like', "Started stock count [{$count->reference}]%")->exists());
    }

    public function test_one_category_may_be_counted_alone_and_a_product_switched_off_with_stock_is_still_listed(): void
    {
        $tools = $this->unaudited(fn () => Category::factory()->create(['name' => 'Tools']));
        $this->brush->forceFill(['category_id' => $tools->id])->saveQuietly();

        $count = $this->start(overrides: ['category_id' => $tools->id]);
        $this->assertSame([$this->brush->id], $count->lines()->pluck('product_id')->all());

        $this->post("/stock-counts/{$count->id}/cancel", ['reason' => 'Wrong scope'])->assertSessionHasNoErrors();

        // Switched off, but still on the shelf: counted all the same.
        $this->paint->forceFill(['is_active' => false])->saveQuietly();
        $all = $this->start();
        $this->assertEqualsCanonicalizing([$this->paint->id, $this->brush->id], $all->lines()->pluck('product_id')->all());
    }

    public function test_only_one_count_may_be_open_at_a_location(): void
    {
        $first = $this->start();

        $this->post('/stock-counts', [
            'location_id' => $this->store->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors(['location_id' => "Count [{$first->reference}] is still open at Main Store. Finish or cancel it before starting another there."]);

        // Another place is free.
        $this->start($this->warehouse);
        $this->assertSame(2, StockCount::count());

        // The same form sent twice lands on the first.
        $key = (string) Str::uuid();
        $this->post("/stock-counts/{$first->id}/cancel", ['reason' => 'Starting over']);
        $this->post('/stock-counts', ['location_id' => $this->store->id, 'idempotency_key' => $key])->assertRedirect();
        $this->post('/stock-counts', ['location_id' => $this->store->id, 'idempotency_key' => $key])->assertRedirect();
        $this->assertSame(3, StockCount::count());
    }

    /* ------------------------------------------------------------ Counting */

    public function test_counters_see_no_book_figure_while_counting_but_the_approver_does(): void
    {
        $count = $this->start(as: $this->staff);

        $this->actingAs($this->staff)->get("/stock-counts/{$count->id}")
            ->assertInertia(fn ($page) => $page
                ->where('count.blind', true)
                ->missing('count.lines.0.system_quantity')
                ->missing('count.lines.0.difference')
                ->missing('summary.over')
                ->where('can.count', true)
                ->where('can.approve', false));

        $this->actingAs($this->admin)->get("/stock-counts/{$count->id}")
            ->assertInertia(fn ($page) => $page
                ->where('count.blind', false)
                ->has('count.lines.0.system_quantity')
                ->has('count.lines.0.changed_since'));
    }

    public function test_figures_follow_the_quantity_rules_and_an_empty_one_is_not_zero(): void
    {
        $metre = $this->unaudited(fn () => Unit::factory()->create(['code' => 'mtr', 'allows_decimals' => true]));
        $wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE', 'unit_id' => $metre->id, 'cost' => 20]));
        $this->bringIn(75.5, $this->store, 20, $wire);

        $count = $this->start();

        $this->enter($count, [$this->paint->id => 2.5])->assertSessionHasErrors('counts.0.counted_quantity');
        $this->enter($count, [$this->paint->id => -1])->assertSessionHasErrors('counts.0.counted_quantity');
        $this->enter($count, [$wire->id => 74.25, $this->paint->id => 9])->assertSessionHasNoErrors();

        $this->assertSame(74.25, $this->line($count, $wire)->counted_quantity);
        $this->assertSame(9, $this->line($count, $this->paint)->counted_quantity);
        $this->assertSame($this->admin->id, $this->line($count, $this->paint)->counted_by);

        // Cleared again: not counted, which is not zero.
        $this->enter($count, [$this->paint->id => null])->assertSessionHasNoErrors();
        $this->assertNull($this->line($count, $this->paint)->counted_quantity);

        // A line of another count is refused.
        $other = $this->start($this->warehouse);
        $this->put("/stock-counts/{$count->id}/counts", ['counts' => [
            ['id' => $this->line($other, $this->paint)->id, 'counted_quantity' => 1],
        ]])->assertSessionHasErrors('counts.0.id');
    }

    public function test_a_count_is_handed_in_only_with_something_counted_and_then_its_figures_are_fixed(): void
    {
        $count = $this->start(as: $this->staff);

        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/submit")
            ->assertSessionHasErrors(['count' => "Nothing on count [{$count->reference}] has been counted yet. Enter at least one counted figure first."]);

        $this->enter($count, [$this->paint->id => 10], $this->staff)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/submit")->assertSessionHasNoErrors();

        $this->assertSame('submitted', $count->fresh()->status->value);
        $this->enter($count, [$this->paint->id => 11], $this->staff)->assertForbidden();
        $this->assertSame(10, $this->line($count, $this->paint)->counted_quantity);
    }

    /* ------------------------------------------------------------ Approving */

    public function test_approving_posts_what_was_over_and_short_as_adjustments_at_the_location(): void
    {
        $count = $this->start(as: $this->staff);

        // Paint: 8 found of 10. Brushes: 23 of 20. Nothing else counted.
        $this->enter($count, [$this->paint->id => 8, $this->brush->id => 23], $this->staff);
        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/submit");

        $this->actingAs($this->admin)->get("/stock-counts/{$count->id}")
            ->assertInertia(fn ($page) => $page
                ->where('summary.over', 1)
                ->where('summary.short', 1)
                ->where('summary.gain_value', 150)
                ->where('summary.loss_value', 600)
                ->where('can.approve', true));

        $this->actingAs($this->admin)->post("/stock-counts/{$count->id}/approve")->assertSessionHasNoErrors();

        $count->refresh();
        $this->assertSame('approved', $count->status->value);

        $gain = InventoryAdjustment::findOrFail($count->gain_adjustment_id);
        $loss = InventoryAdjustment::findOrFail($count->loss_adjustment_id);

        $this->assertSame(['increase', 'count', $this->store->id], [$gain->kind, $gain->reason, $gain->location_id]);
        $this->assertSame(['decrease', 'count', $this->store->id], [$loss->kind, $loss->reason, $loss->location_id]);
        $this->assertStringContainsString($count->reference, $loss->notes);
        $this->assertSame(3, $gain->lines->sole()->quantity);
        $this->assertSame(2, $loss->lines->sole()->quantity);

        $this->assertSame(8, $this->at($this->store, $this->paint));
        $this->assertSame(23, $this->at($this->store, $this->brush));
        // The warehouse was not counted and does not move.
        $this->assertSame(5, $this->at($this->warehouse, $this->paint));

        $this->assertSame(-2, $this->line($count, $this->paint)->variance);
        $this->assertSame('-600.00', $this->line($count, $this->paint)->variance_value);
        $this->assertSame(3, $this->line($count, $this->brush)->variance);
        $this->assertSame('150.00', $count->gain_value);
        $this->assertSame('600.00', $count->loss_value);

        $this->assertTrue(AuditTrail::where('description', 'like', "Approved stock count [{$count->reference}]%")->exists());
        $this->assertBooksBalance();
    }

    public function test_what_was_sold_after_the_cutoff_is_not_counted_twice(): void
    {
        $count = $this->start();

        // Counted 10 paint and 19 brushes; then, before approval, the till
        // sells 3 paint. The paint count matched; one brush is missing.
        $this->enter($count, [$this->paint->id => 10, $this->brush->id => 19]);

        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->post('/sales', [
            'customer_id' => $walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 3, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $this->post("/stock-counts/{$count->id}/submit");

        // The approver is shown the move since the cutoff.
        $this->get("/stock-counts/{$count->id}")
            ->assertInertia(fn ($page) => $page->where('count.lines', fn ($lines) => collect($lines)
                ->firstWhere('sku', 'PNT-1G')['changed_since'] === -3));

        $this->post("/stock-counts/{$count->id}/approve")->assertSessionHasNoErrors();

        // The sale stands; the count adds nothing to it.
        $this->assertSame(7, $this->at($this->store, $this->paint));
        $this->assertSame(19, $this->at($this->store, $this->brush));
        $this->assertNull($count->fresh()->gain_adjustment_id);
        $this->assertSame(0, $this->line($count, $this->paint)->variance);
        $this->assertBooksBalance();
    }

    public function test_a_switched_off_product_and_a_part_of_a_metre_post_when_approved(): void
    {
        $metre = $this->unaudited(fn () => Unit::factory()->create(['code' => 'mtr', 'allows_decimals' => true]));
        $wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE', 'unit_id' => $metre->id, 'cost' => 20]));
        $this->bringIn(75.5, $this->store, 20, $wire);

        // Switched off, but still on the shelf: listed, counted and posted.
        $this->paint->forceFill(['is_active' => false])->saveQuietly();

        $count = $this->start();
        $this->enter($count, [$wire->id => 74.25, $this->paint->id => 9])->assertSessionHasNoErrors();
        $this->post("/stock-counts/{$count->id}/submit");
        $this->post("/stock-counts/{$count->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(74.25, $this->at($this->store, $wire));
        $this->assertSame(9, $this->at($this->store, $this->paint));
        $this->assertSame(-1.25, $this->line($count, $wire)->variance);
        $this->assertSame('-25.00', $this->line($count, $wire)->variance_value);
        $this->assertSame(-1, $this->line($count, $this->paint)->variance);
        $this->assertBooksBalance();
    }

    public function test_a_count_where_everything_matched_is_approved_with_nothing_posted(): void
    {
        $count = $this->start();
        $this->enter($count, [$this->paint->id => 10]);
        $this->post("/stock-counts/{$count->id}/submit");
        $this->post("/stock-counts/{$count->id}/approve")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Count [{$count->reference}] was approved. Everything counted matched the books.");

        $count->refresh();
        $this->assertSame('approved', $count->status->value);
        $this->assertNull($count->gain_adjustment_id);
        $this->assertNull($count->loss_adjustment_id);
        // Only the three the set-up posted.
        $this->assertSame(3, InventoryAdjustment::count());
    }

    public function test_a_shortfall_bigger_than_what_is_there_now_is_refused_and_the_count_keeps_waiting(): void
    {
        $count = $this->start();
        $this->enter($count, [$this->paint->id => 0]);
        $this->post("/stock-counts/{$count->id}/submit");

        // 8 of the 10 leave for the warehouse after the cutoff: only 2 is
        // left at the store, and the count wants to take off 10.
        $this->post('/transfers', [
            'from_location_id' => $this->store->id,
            'to_location_id' => $this->warehouse->id,
            'transfer_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 8]],
        ])->assertSessionHasNoErrors();

        $this->post("/stock-counts/{$count->id}/approve")->assertSessionHasErrors('count');

        $this->assertSame('submitted', $count->fresh()->status->value);
        $this->assertSame(2, $this->at($this->store, $this->paint));
        $this->assertSame(0, InventoryAdjustment::where('reason', 'count')->count());
        $this->assertBooksBalance();
    }

    public function test_an_approver_may_approve_their_own_count_and_the_trail_says_so(): void
    {
        $count = $this->start();
        $this->enter($count, [$this->paint->id => 9]);
        $this->post("/stock-counts/{$count->id}/submit");
        $this->post("/stock-counts/{$count->id}/approve")->assertSessionHasNoErrors();

        $this->assertTrue(AuditTrail::where('description', 'like', "Approved their own stock count [{$count->reference}]%")->exists());
    }

    /* ---------------------------------------------------- Sending back, cancelling */

    public function test_a_count_sent_back_keeps_its_figures_and_can_be_counted_again(): void
    {
        $count = $this->start(as: $this->staff);
        $this->enter($count, [$this->paint->id => 4], $this->staff);
        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/submit");

        $this->actingAs($this->admin)->post("/stock-counts/{$count->id}/send-back", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/stock-counts/{$count->id}/send-back", ['reason' => 'Recount the paint'])->assertSessionHasNoErrors();

        $count->refresh();
        $this->assertSame('counting', $count->status->value);
        $this->assertSame('Recount the paint', $count->send_back_reason);
        $this->assertSame(4, $this->line($count, $this->paint)->counted_quantity);

        $this->enter($count, [$this->paint->id => 10], $this->staff)->assertSessionHasNoErrors();
        $this->assertSame(10, $this->line($count, $this->paint)->counted_quantity);
    }

    public function test_a_counter_cancels_only_their_own_count_and_the_approver_any(): void
    {
        $mine = $this->start(as: $this->staff);
        $theirs = $this->start($this->warehouse, as: $this->admin);

        $this->actingAs($this->staff)->post("/stock-counts/{$theirs->id}/cancel", ['reason' => 'Not mine'])->assertForbidden();
        $this->actingAs($this->staff)->post("/stock-counts/{$mine->id}/cancel", ['reason' => 'Started by mistake'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/stock-counts/{$theirs->id}/cancel", ['reason' => 'Not needed'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $mine->fresh()->status->value);
        $this->assertSame('cancelled', $theirs->fresh()->status->value);
        $this->assertSame(0, InventoryAdjustment::where('reason', 'count')->count());

        // A finished count cannot be cancelled, and the place is free again.
        $this->actingAs($this->admin)->post("/stock-counts/{$mine->id}/cancel", ['reason' => 'Again'])->assertForbidden();
        $this->start();
    }

    /* ------------------------------------------------------------ Who */

    public function test_staff_count_and_hand_in_but_do_not_approve(): void
    {
        $count = $this->start(as: $this->staff);
        $this->enter($count, [$this->paint->id => 9], $this->staff);
        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/submit")->assertSessionHasNoErrors();

        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/stock-counts/{$count->id}/send-back", ['reason' => 'No'])->assertForbidden();
        $this->assertSame('submitted', $count->fresh()->status->value);

        $nobody = $this->unaudited(fn () => User::factory()->create());
        $this->actingAs($nobody)->get('/stock-counts')->assertForbidden();
    }

    public function test_the_list_page_and_dialog_read_the_count(): void
    {
        $count = $this->start();
        $this->enter($count, [$this->paint->id => 9]);

        $this->get('/stock-counts')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockCounts/Index')
                ->where('counts.data.0.reference', $count->reference)
                ->where('counts.data.0.counted_count', 1)
                ->where('counts.data.0.lines_count', 2));

        $this->get('/stock-counts/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where("open.{$this->store->id}.reference", $count->reference));

        $this->getJson("/stock-counts/{$count->id}/preview")->assertOk()
            ->assertJsonPath('count.reference', $count->reference)
            ->assertJsonPath('summary.counted', 1);
    }

    public function test_a_product_or_location_named_on_a_count_is_not_deleted(): void
    {
        $empty = $this->unaudited(fn () => Location::factory()->create(['name' => 'Annex']));
        $spare = $this->unaudited(fn () => Product::factory()->create(['sku' => 'SPARE']));

        $this->start($empty);

        $this->assertFalse($this->admin->can('delete', $empty->fresh()));
        $this->assertStringContainsString('stock count', (string) Gate::forUser($this->admin)->inspect('delete', $spare->fresh())->message());
    }
}
