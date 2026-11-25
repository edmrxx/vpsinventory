<?php

namespace Tests\Feature\Locations;

use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\LossReport;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesOrder;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\BackupMaker;
use App\Services\InventoryLedger;
use App\Support\LossPhoto;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 33: a loss is reported with a reason and photos, the goods are held
 * aside, and only an approver writes it off.
 */
class LossReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Location $store;

    protected Location $warehouse;

    protected Product $paint;

    protected function setUp(): void
    {
        parent::setUp();

        // The evidence goes to the private disk: never the real one.
        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->store = Location::where('name', 'Main Store')->firstOrFail();
        $this->warehouse = Location::where('name', 'Warehouse')->firstOrFail();
        $can = $this->unaudited(fn () => Unit::factory()->create(['code' => 'can', 'allows_decimals' => false]));
        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'unit_id' => $can->id, 'cost' => 300, 'selling_price' => 500]));

        $this->actingAs($this->admin);

        // 6 at 300, then 4 at 320, at the store.
        $this->bringIn(6, 300);
        $this->bringIn(4, 320);
    }

    protected function bringIn(int $quantity, float $cost, ?Location $at = null): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->paint->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ], Carbon::today(), 'other', locationId: ($at ?? $this->store)->id);
    }

    protected function at(Location $location): int|float
    {
        return LocationStock::where('product_id', $this->paint->id)
            ->where('location_id', $location->id)->value('on_hand') ?? 0;
    }

    /** @return array<string, mixed> */
    protected function form(int|float $quantity, array $overrides = []): array
    {
        return array_merge([
            'location_id' => $this->store->id,
            'reason' => 'damaged',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => $quantity]],
            'photos' => [UploadedFile::fake()->image('dented.jpg', 800, 600)],
        ], $overrides);
    }

    protected function report(int|float $quantity, array $overrides = [], ?User $as = null): LossReport
    {
        $this->actingAs($as ?? $this->admin)->post('/loss-reports', $this->form($quantity, $overrides))
            ->assertSessionHasNoErrors();

        return LossReport::latest('id')->firstOrFail();
    }

    protected function assertBooksBalance(): void
    {
        $ledger = app(InventoryLedger::class);

        $this->assertSame([], $ledger->reconcile());
        $this->assertSame([], $ledger->reconcileLocations());
    }

    /* ------------------------------------------------------------ Reporting */

    public function test_a_report_holds_the_goods_aside_without_moving_them(): void
    {
        $report = $this->report(3, as: $this->staff);

        $this->assertStringStartsWith('LR-', $report->reference);
        $this->assertSame('pending', $report->status->value);
        $this->assertSame('damaged', $report->reason);
        $this->assertSame(1, $report->photos()->count());
        Storage::disk('local')->assertExists($report->photos()->value('path'));

        // Still on the books: nothing moved.
        $this->assertSame(10, $this->at($this->store));
        $this->assertSame(10, $this->paint->fresh()->on_hand);

        // But nobody may sell it.
        $walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->post('/sales', [
            'customer_id' => $walkIn->id,
            'sale_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 8, 'unit_price' => '500.00']],
        ])->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 7 can of [PNT-1G] is free to sell at Main Store (10 can on hand, 3 can held for a loss report), and this sale asks for 8 can.',
        ]);

        // Nor move it.
        $this->post('/transfers', [
            'from_location_id' => $this->store->id,
            'to_location_id' => $this->warehouse->id,
            'transfer_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 8]],
        ])->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 7 of [PNT-1G] at Main Store is free to move — 3 held for a loss report there — and this asks for 8.',
        ]);

        // Nor hold it for a customer.
        $customer = $this->unaudited(fn () => Customer::factory()->create());
        $this->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => Carbon::today()->addDays(7)->toDateString(),
            'location_id' => $this->store->id,
            'approve' => true,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 8, 'unit_price' => '480.00']],
        ])->assertSessionHasErrors();
        $this->assertSame(0, SalesOrder::where('status', 'approved')->count());

        // The Stock screen shows it held, and what is left available.
        $this->get('/stock?location_id='.$this->store->id)
            ->assertInertia(fn ($page) => $page
                ->where('stock.data.0.reserved', 3)
                ->where('stock.data.0.held_for_losses', 3)
                ->where('stock.data.0.available', 7));

        $this->assertTrue(AuditTrail::where('description', 'like', "Reported loss [{$report->reference}]%")->exists());
    }

    public function test_only_what_is_free_may_be_reported(): void
    {
        $this->report(8);

        $this->post('/loss-reports', $this->form(3))->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 2 can of [PNT-1G] at Main Store is free to report (8 can held for a loss report), and this reports 3 can.',
        ]);

        $this->post('/loss-reports', $this->form(1, ['location_id' => $this->warehouse->id]))->assertSessionHasErrors([
            'lines.0.quantity' => 'Only 0 can of [PNT-1G] is on hand at Warehouse, and this reports 1 can.',
        ]);

        // 2.5 tins is refused; the same form sent twice lands on the first.
        $this->post('/loss-reports', $this->form(1.5))->assertSessionHasErrors('lines.0.quantity');

        $key = (string) Str::uuid();
        $this->post('/loss-reports', $this->form(1, ['idempotency_key' => $key]))->assertSessionHasNoErrors();
        $this->post('/loss-reports', $this->form(1, ['idempotency_key' => $key]))->assertRedirect();
        $this->assertSame(2, LossReport::count());
    }

    public function test_a_photo_is_needed_when_the_goods_are_there_to_photograph(): void
    {
        $this->post('/loss-reports', $this->form(1, ['photos' => []]))->assertSessionHasErrors([
            'photos' => 'Add at least one photo of the goods: a loss reported as damaged or spoiled needs one as evidence.',
        ]);

        // A theft has nothing to show.
        $this->post('/loss-reports', $this->form(1, ['reason' => 'theft', 'photos' => []]))->assertSessionHasNoErrors();

        // Not a picture: refused, and an SVG too.
        $this->post('/loss-reports', $this->form(1, ['photos' => [UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]]))
            ->assertSessionHasErrors('photos.0');
        $this->post('/loss-reports', $this->form(1, ['photos' => [UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')]]))
            ->assertSessionHasErrors('photos.0');

        // With the setting off, a photo is always optional.
        app(Settings::class)->set('losses.photo_required', false);
        $this->post('/loss-reports', $this->form(1, ['reason' => 'expired', 'photos' => []]))->assertSessionHasNoErrors();

        $this->assertSame(2, LossReport::count());
    }

    /* ------------------------------------------------------------ Answering */

    public function test_approving_writes_it_off_as_a_decrease_with_its_reason_and_cost(): void
    {
        $report = $this->report(8, ['reason' => 'rust'], $this->staff);

        $this->actingAs($this->admin)->post("/loss-reports/{$report->id}/approve")->assertSessionHasNoErrors();

        $report->refresh();
        $this->assertSame('approved', $report->status->value);

        $adjustment = InventoryAdjustment::findOrFail($report->adjustment_id);
        $this->assertSame(['decrease', 'rust', $this->store->id], [$adjustment->kind, $adjustment->reason, $adjustment->location_id]);
        $this->assertStringContainsString($report->reference, $adjustment->notes);

        // 6 at 300 and 2 at 320: the oldest batches first.
        $this->assertSame('2440.00', $report->total_cost);
        $this->assertSame('2440.00', $adjustment->total_cost);
        $this->assertSame('2440.00', $report->lines->sole()->total_cost);

        // Gone from the books, and nothing held any more.
        $this->assertSame(2, $this->at($this->store));
        $this->get('/stock?location_id='.$this->store->id)
            ->assertInertia(fn ($page) => $page->where('stock.data.0.reserved', 0)->where('stock.data.0.available', 2));

        $this->assertTrue(AuditTrail::where('description', 'like', "Approved loss report [{$report->reference}]: written off as {$adjustment->reference}%")->exists());
        $this->assertBooksBalance();
    }

    public function test_an_approver_may_approve_their_own_and_the_trail_says_so(): void
    {
        $report = $this->report(1);
        $this->post("/loss-reports/{$report->id}/approve")->assertSessionHasNoErrors();

        $this->assertTrue(AuditTrail::where('description', 'like', "Approved their own loss report [{$report->reference}]%")->exists());
    }

    public function test_rejecting_or_withdrawing_frees_the_goods(): void
    {
        $rejected = $this->report(4, as: $this->staff);
        $withdrawn = $this->report(3, as: $this->staff);

        $this->actingAs($this->admin)->post("/loss-reports/{$rejected->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post("/loss-reports/{$rejected->id}/reject", ['reason' => 'Still sellable'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/loss-reports/{$withdrawn->id}/cancel", ['reason' => 'Reported twice'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $rejected->fresh()->status->value);
        $this->assertSame('Still sellable', $rejected->fresh()->decision_note);
        $this->assertSame('cancelled', $withdrawn->fresh()->status->value);

        // All 10 free again, nothing written off.
        $this->get('/stock?location_id='.$this->store->id)
            ->assertInertia(fn ($page) => $page->where('stock.data.0.reserved', 0)->where('stock.data.0.available', 10));
        $this->assertSame(0, InventoryAdjustment::where('kind', 'decrease')->count());

        // An answered report is final.
        $this->actingAs($this->admin)->post("/loss-reports/{$rejected->id}/approve")->assertForbidden();
    }

    public function test_a_write_off_the_goods_no_longer_cover_is_refused_and_the_report_keeps_waiting(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $this->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 5, 'unit_cost' => '310.00']],
        ])->assertSessionHasNoErrors();
        $purchase = Purchase::sole();

        // 15 at the store; 12 reported. A purchase return does not look at
        // holds — the shelf is what it is — and takes the delivery's 5.
        $report = $this->report(12);

        $this->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        $this->post("/loss-reports/{$report->id}/approve")->assertSessionHasErrors('report');

        $this->assertSame('pending', $report->fresh()->status->value);
        $this->assertSame(10, $this->at($this->store));
        $this->assertSame(0, InventoryAdjustment::where('kind', 'decrease')->count());
        $this->assertBooksBalance();
    }

    public function test_held_goods_are_still_counted_so_a_count_and_a_write_off_never_take_them_twice(): void
    {
        $report = $this->report(3);

        // The count's book figure includes what the report holds: the goods
        // are still on the shelf, set aside, until written off.
        $this->post('/stock-counts', ['location_id' => $this->store->id])->assertSessionHasNoErrors();
        $count = StockCount::sole();
        $this->assertSame(10, $count->lines()->where('product_id', $this->paint->id)->first()->system_quantity);

        // The approver is shown what is held, so a set-aside box the counters
        // skipped is not taken off twice.
        $this->get("/stock-counts/{$count->id}")
            ->assertInertia(fn ($page) => $page->where('count.lines.0.held_for_losses', 3));

        // Counted with the set-aside goods: 10. Written off, then the count
        // approved: the 3 come off once.
        $this->put("/stock-counts/{$count->id}/counts", ['counts' => [
            ['id' => $count->lines()->value('id'), 'counted_quantity' => 10],
        ]])->assertSessionHasNoErrors();
        $this->post("/stock-counts/{$count->id}/submit");

        $this->post("/loss-reports/{$report->id}/approve")->assertSessionHasNoErrors();
        $this->post("/stock-counts/{$count->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(7, $this->at($this->store));
        $this->assertBooksBalance();
    }

    /* ------------------------------------------------------------ Who, and the photos */

    public function test_staff_report_and_withdraw_their_own_but_do_not_write_off(): void
    {
        $mine = $this->report(1, as: $this->staff);
        $theirs = $this->report(1, as: $this->admin);

        $this->actingAs($this->staff)->post("/loss-reports/{$mine->id}/approve")->assertForbidden();
        $this->actingAs($this->staff)->post("/loss-reports/{$mine->id}/reject", ['reason' => 'No'])->assertForbidden();
        $this->actingAs($this->staff)->post("/loss-reports/{$theirs->id}/cancel", ['reason' => 'Not mine'])->assertForbidden();

        $this->actingAs($this->staff)->get("/loss-reports/{$mine->id}")
            ->assertInertia(fn ($page) => $page->where('can.approve', false)->where('can.cancel', true));

        $nobody = $this->unaudited(fn () => User::factory()->create());
        $this->actingAs($nobody)->get('/loss-reports')->assertForbidden();
    }

    public function test_a_photo_is_served_only_through_its_own_report_to_those_who_may_read_it(): void
    {
        $report = $this->report(1);
        $other = $this->report(1);
        $photo = $report->photos()->sole();

        $this->get("/loss-reports/{$report->id}/photos/{$photo->id}")->assertOk();

        // Through another report's address: not found.
        $this->get("/loss-reports/{$other->id}/photos/{$photo->id}")->assertNotFound();

        $nobody = $this->unaudited(fn () => User::factory()->create());
        $this->actingAs($nobody)->get("/loss-reports/{$report->id}/photos/{$photo->id}")->assertForbidden();

        // Never on the public disk.
        $this->assertStringStartsWith(LossPhoto::DIRECTORY.'/', $photo->path);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_the_list_page_and_dialog_read_the_report(): void
    {
        $report = $this->report(2, ['reason' => 'expired']);

        $this->get('/loss-reports')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LossReports/Index')
                ->where('reports.data.0.reference', $report->reference)
                ->where('reports.data.0.reason', 'Expired')
                ->where('reports.data.0.photos_count', 1));

        $this->get('/loss-reports/create')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reasons.0.value', 'damaged')
                ->where('products.0.on_hand', 8));

        $this->getJson("/loss-reports/{$report->id}/preview")->assertOk()
            ->assertJsonPath('report.reference', $report->reference)
            ->assertJsonPath('report.photos.0.url', "/loss-reports/{$report->id}/photos/".$report->photos()->value('id'));
    }

    public function test_a_reset_removes_the_evidence_and_a_backup_carries_it(): void
    {
        $report = $this->report(1);
        $path = $report->photos()->value('path');

        $this->post('/backups')->assertSessionHasNoErrors();
        $name = app(BackupMaker::class)->all()[0]['name'];
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path('backups/'.$name)) === true);
        $this->assertNotFalse($zip->locateName('private/'.$path));
        $zip->close();

        $this->artisan('data:reset --force')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, LossReport::count());
    }

    public function test_the_new_reasons_label_old_and_new_adjustments(): void
    {
        $this->assertSame('Lost or missing', StoreAdjustmentRequest::REASONS['lost']);

        $this->post('/adjustments', [
            'kind' => 'decrease',
            'adjustment_date' => Carbon::today()->toDateString(),
            'location_id' => $this->store->id,
            'reason' => 'theft',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('theft', InventoryAdjustment::latest('id')->value('reason'));
    }
}
