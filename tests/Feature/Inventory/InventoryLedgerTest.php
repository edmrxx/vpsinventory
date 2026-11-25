<?php

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\AuditTrail;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function poster(): AdjustmentPoster
    {
        return app(AdjustmentPoster::class);
    }

    protected function ledger(): InventoryLedger
    {
        return app(InventoryLedger::class);
    }

    protected function product(array $attributes = []): Product
    {
        return $this->unaudited(fn () => Product::factory()->create($attributes));
    }

    /**
     * The rule the whole ledger rests on: what a product says it holds is
     * what its batches add up to. Every test ends here.
     */
    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame(
            [],
            $this->ledger()->reconcile(),
            'A product has come apart from the batches behind it.'
        );
    }

    /* ------------------------------------------------------------ Bringing in */

    public function test_opening_stock_arrives_through_a_posted_document(): void
    {
        $product = $this->product(['sku' => 'TSHIRT-BLK']);

        $adjustment = $this->poster()->openingBalance([
            ['product_id' => $product->id, 'quantity' => 90, 'unit_cost' => 300],
        ]);

        $this->assertSame(90, $product->fresh()->on_hand);
        $this->assertSame('posted', $adjustment->status->value);

        $layer = InventoryLayer::firstWhere('product_id', $product->id);
        $this->assertSame(90, $layer->quantity_received);
        $this->assertSame(90, $layer->quantity_remaining);
        $this->assertSame('300.00', $layer->unit_cost);

        $movement = InventoryMovement::firstWhere('product_id', $product->id);
        $this->assertSame(MovementType::Opening, $movement->type);
        $this->assertSame(90, $movement->quantity_in);
        $this->assertSame(90, $movement->balance_after);
        $this->assertSame('27000.00', $movement->total_cost);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_second_delivery_opens_a_second_batch(): void
    {
        $product = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);
        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 120],
        ]);

        $this->assertSame(2, InventoryLayer::where('product_id', $product->id)->count());
        $this->assertSame(15, $product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ----------------------------------------------------------------- FIFO */

    public function test_stock_leaves_the_oldest_batch_first(): void
    {
        $product = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);
        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 200],
        ]);

        // Four units: all four come out of the first, cheaper batch.
        $this->poster()->post('decrease', [
            ['product_id' => $product->id, 'quantity' => 4],
        ]);

        $layers = InventoryLayer::where('product_id', $product->id)->orderBy('id')->get();

        $this->assertSame(6, $layers[0]->quantity_remaining);
        $this->assertSame(10, $layers[1]->quantity_remaining);

        $out = InventoryMovement::where('type', MovementType::AdjustmentOut->value)->first();
        $this->assertSame('400.00', $out->total_cost);
        $this->assertSame('100.00', $out->unit_cost);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_withdrawal_spanning_two_batches_is_costed_across_both(): void
    {
        $product = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);
        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 200],
        ]);

        // Twelve: ten at 100, two at 200.
        $this->poster()->post('decrease', [
            ['product_id' => $product->id, 'quantity' => 12],
        ]);

        $out = InventoryMovement::where('type', MovementType::AdjustmentOut->value)->first();

        $this->assertSame('1400.00', $out->total_cost);
        // 1400 over 12 units.
        $this->assertSame('116.67', $out->unit_cost);

        $layers = InventoryLayer::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame(0, $layers[0]->quantity_remaining);
        $this->assertSame(8, $layers[1]->quantity_remaining);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_withdrawal_records_which_batches_it_took(): void
    {
        $product = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);
        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 200],
        ]);
        $this->poster()->post('decrease', [
            ['product_id' => $product->id, 'quantity' => 12],
        ]);

        $out = InventoryMovement::where('type', MovementType::AdjustmentOut->value)->first();
        $taken = $out->consumptions()->orderBy('id')->get();

        // A sales return has to put stock back at the cost it left at, so
        // the ledger keeps the receipt.
        $this->assertCount(2, $taken);
        $this->assertSame(10, $taken[0]->quantity);
        $this->assertSame('100.00', $taken[0]->unit_cost);
        $this->assertSame(2, $taken[1]->quantity);
        $this->assertSame('200.00', $taken[1]->unit_cost);
    }

    /* --------------------------------------------------------------- Refusals */

    public function test_stock_cannot_go_negative(): void
    {
        $product = $this->product(['sku' => 'SOCKS-WHT']);

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 80],
        ]);

        try {
            $this->poster()->post('decrease', [
                ['product_id' => $product->id, 'quantity' => 6],
            ]);

            $this->fail('The ledger allowed stock to go negative.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('SOCKS-WHT', $e->getMessage());
            $this->assertStringContainsString('6 needed, 5 on hand', $e->getMessage());
        }

        $this->assertSame(5, $product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_refused_document_leaves_nothing_behind(): void
    {
        $good = $this->product();
        $short = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $good->id, 'quantity' => 10, 'unit_cost' => 100],
            ['product_id' => $short->id, 'quantity' => 1, 'unit_cost' => 50],
        ]);

        $documentsBefore = InventoryAdjustment::count();
        $movementsBefore = InventoryMovement::count();

        try {
            // The first line is fine; the second asks for more than there is.
            $this->poster()->post('decrease', [
                ['product_id' => $good->id, 'quantity' => 5],
                ['product_id' => $short->id, 'quantity' => 99],
            ]);

            $this->fail('The ledger posted a document it should have refused.');
        } catch (InventoryException) {
            // Expected.
        }

        // The whole document rolls back, including the line that was fine.
        $this->assertSame($documentsBefore, InventoryAdjustment::count());
        $this->assertSame($movementsBefore, InventoryMovement::count());
        $this->assertSame(10, $good->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_document_dated_before_the_last_one_is_refused(): void
    {
        $product = $this->product();

        $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]],
            Carbon::parse('2026-10-15'),
        );

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('before the last posted document');

        $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]],
            Carbon::parse('2026-10-14'),
        );
    }

    public function test_the_same_day_is_still_allowed(): void
    {
        $product = $this->product();
        $day = Carbon::parse('2026-10-15');

        $this->poster()->post('increase', [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]], $day);
        $this->poster()->post('increase', [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]], $day);

        $this->assertSame(10, $product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_same_document_cannot_be_posted_twice(): void
    {
        $product = $this->product();

        $adjustment = $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100],
        ]);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('has already been posted');

        $this->ledger()->post(
            $adjustment,
            $adjustment->reference,
            Carbon::today(),
            MovementType::AdjustmentIn,
            [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100]],
        );
    }

    public function test_a_quantity_of_zero_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('must be more than zero');

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 0, 'unit_cost' => 100],
        ]);
    }

    public function test_an_empty_document_is_refused(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('nothing to post');

        $this->poster()->post('increase', []);
    }

    /* ------------------------------------------------------- Several lines */

    public function test_one_document_can_name_the_same_product_twice(): void
    {
        $product = $this->product();

        $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 100],
            ['product_id' => $product->id, 'quantity' => 6, 'unit_cost' => 150],
        ]);

        $this->assertSame(10, $product->fresh()->on_hand);

        // The stock card has to read correctly line by line, not just at
        // the end of the document.
        $balances = InventoryMovement::where('product_id', $product->id)
            ->orderBy('id')
            ->pluck('balance_after')
            ->all();

        $this->assertSame([4, 10], $balances);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_document_covering_several_products_posts_as_one(): void
    {
        $a = $this->product();
        $b = $this->product();

        $this->poster()->openingBalance([
            ['product_id' => $a->id, 'quantity' => 90, 'unit_cost' => 300],
            ['product_id' => $b->id, 'quantity' => 8, 'unit_cost' => 300],
        ]);

        $this->assertSame(90, $a->fresh()->on_hand);
        $this->assertSame(8, $b->fresh()->on_hand);
        $this->assertSame(1, InventoryAdjustment::count());
        $this->assertStockMatchesItsLayers();
    }

    /* ---------------------------------------------------------------- Trail */

    public function test_a_posting_writes_one_audit_row_for_the_document(): void
    {
        $product = $this->product();

        $this->poster()->openingBalance([
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 120],
        ]);

        // Two lines, two layers, two movements — and one line in the trail.
        $this->assertSame(1, AuditTrail::count());

        $row = AuditTrail::first();
        $this->assertSame(AuditAction::Post, $row->action);
        $this->assertSame('Inventory Adjustments', $row->module);
        $this->assertStringContainsString('ADJ-', $row->description);
    }

    public function test_moving_stock_writes_no_audit_row_against_the_product(): void
    {
        $product = $this->product();

        $this->poster()->openingBalance([
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);

        // The product's stock changed, but nobody edited the product.
        $this->assertSame(0, AuditTrail::where('module', 'Products')->count());
    }

    /* ------------------------------------------------------------ Numbering */

    public function test_references_run_in_order_and_follow_the_settings(): void
    {
        $product = $this->product();

        $first = $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
            Carbon::parse('2026-10-17'),
        );

        $second = $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
            Carbon::parse('2026-10-17'),
        );

        $this->assertSame('ADJ-2026-0001', $first->reference);
        $this->assertSame('ADJ-2026-0002', $second->reference);
    }

    public function test_the_number_starts_again_in_a_new_year(): void
    {
        $product = $this->product();

        $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
            Carbon::parse('2026-12-31'),
        );

        $next = $this->poster()->post(
            'increase',
            [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
            Carbon::parse('2027-01-02'),
        );

        $this->assertSame('ADJ-2027-0001', $next->reference);
    }

    public function test_a_refused_document_does_not_burn_a_reference(): void
    {
        $product = $this->product();

        try {
            $this->poster()->post('decrease', [
                ['product_id' => $product->id, 'quantity' => 1],
            ]);
        } catch (InventoryException) {
            // Expected: there is no stock to take.
        }

        $next = $this->poster()->post('increase', [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1],
        ]);

        $this->assertStringEndsWith('0001', $next->reference);
    }

    /* ------------------------------------------------------------ Reconcile */

    public function test_reconcile_notices_stock_that_has_no_batches_behind_it(): void
    {
        $product = $this->product(['sku' => 'GHOST-1']);

        // Exactly what the sample seeder used to do before this phase.
        Product::whereKey($product->id)->update(['on_hand' => 50]);

        $this->assertSame(
            [['sku' => 'GHOST-1', 'on_hand' => 50, 'layers' => 0]],
            $this->ledger()->reconcile()
        );
    }
}
