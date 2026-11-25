<?php

namespace Tests\Feature\Purchases;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Models\AuditTrail;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create(['code' => 'ABC-TRADING']));
        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK', 'cost' => 300]));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'supplier_reference' => 'INV-001',
            'purchase_date' => Carbon::today()->toDateString(),
            'notes' => null,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => '300.00'],
            ],
        ], $overrides);
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/purchases')
            ->assertForbidden();
    }

    public function test_the_view_permission_alone_cannot_record_a_purchase(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->withPermissions('purchases.view')->create()))
            ->post('/purchases', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_posted_purchase_has_no_edit_or_delete_route(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName());

        // Absent, not merely gated: a posted document is never changed.
        $this->assertNotContains('purchases.edit', $names);
        $this->assertNotContains('purchases.update', $names);
        $this->assertNotContains('purchases.destroy', $names);
    }

    /* --------------------------------------------------------------- Posting */

    public function test_a_purchase_puts_stock_on_the_shelf(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload())
            ->assertRedirect();

        $purchase = Purchase::first();

        $this->assertSame('posted', $purchase->status->value);
        $this->assertStringStartsWith('PR-', $purchase->reference);
        $this->assertSame('30000.00', $purchase->total_amount);
        $this->assertSame(100, $this->product->fresh()->on_hand);

        $layer = InventoryLayer::firstWhere('product_id', $this->product->id);
        $this->assertSame(100, $layer->quantity_remaining);
        $this->assertSame('300.00', $layer->unit_cost);

        $movement = InventoryMovement::firstWhere('product_id', $this->product->id);
        $this->assertSame(MovementType::Purchase, $movement->type);
        $this->assertSame($purchase->reference, $movement->reference);

        $this->assertStockMatchesItsLayers();
    }

    public function test_the_total_is_worked_out_on_the_server(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'total_amount' => '1.00',
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => '10.00', 'line_total' => '1.00'],
                ],
            ]))
            ->assertRedirect();

        // Whatever the browser sent is ignored.
        $this->assertSame('30.00', Purchase::first()->total_amount);
        $this->assertSame('30.00', Purchase::first()->lines->first()->line_total);
    }

    public function test_the_purchase_cost_becomes_the_products_last_known_cost(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '325.50'],
                ],
            ]));

        $this->assertSame('325.50', $this->product->fresh()->cost);
        // The product itself was not edited by anybody.
        $this->assertSame(0, AuditTrail::where('module', 'Products')->count());
    }

    public function test_several_lines_post_as_one_document(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CAP-NVY']));

        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => '300.00'],
                    ['product_id' => $other->id, 'quantity' => 50, 'unit_cost' => '200.00'],
                ],
            ]))
            ->assertRedirect();

        $this->assertSame(1, Purchase::count());
        $this->assertSame('40000.00', Purchase::first()->total_amount);
        $this->assertSame(100, $this->product->fresh()->on_hand);
        $this->assertSame(50, $other->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------------------ Validation */

    public function test_an_inactive_supplier_is_refused(): void
    {
        $this->unaudited(fn () => $this->supplier->update(['is_active' => false]));

        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload())
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_an_inactive_product_is_refused(): void
    {
        $this->unaudited(fn () => $this->product->update(['is_active' => false]));

        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload())
            ->assertSessionHasErrors('lines.0.product_id');
    }

    public function test_a_purchase_with_no_lines_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload(['lines' => []]))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_quantity_of_zero_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'lines' => [['product_id' => $this->product->id, 'quantity' => 0, 'unit_cost' => '300.00']],
            ]))
            ->assertSessionHasErrors('lines.0.quantity');
    }

    public function test_a_negative_cost_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => '-5.00']],
            ]))
            ->assertSessionHasErrors('lines.0.unit_cost');
    }

    public function test_a_purchase_cannot_be_dated_in_the_future(): void
    {
        // A future date would raise the floor for everybody, because nothing
        // may be dated before the last posted document.
        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'purchase_date' => Carbon::today()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('purchase_date');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_purchase_cannot_be_dated_before_the_last_posted_document(): void
    {
        app(AdjustmentPoster::class)->openingBalance(
            [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 1]],
            Carbon::today(),
        );

        $this->actingAs($this->admin())
            ->post('/purchases', $this->payload([
                'purchase_date' => Carbon::today()->subDays(3)->toDateString(),
            ]))
            ->assertSessionHasErrors('purchase_date');
    }

    /* ------------------------------------------------------------ Duplicates */

    public function test_the_same_form_sent_twice_makes_one_purchase(): void
    {
        $key = (string) Str::uuid();
        $admin = $this->admin();

        // The very same payload, twice: a double click, or a refresh.
        $this->actingAs($admin)->post('/purchases', $this->payload(['idempotency_key' => $key]));
        $this->actingAs($admin)
            ->post('/purchases', $this->payload(['idempotency_key' => $key]))
            ->assertRedirect();

        $this->assertSame(1, Purchase::count());
        $this->assertSame(100, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_different_delivery_under_a_stale_key_is_refused_rather_than_swallowed(): void
    {
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', $this->payload(['idempotency_key' => $key]));

        // Pressing Back brings the create screen out of the browser's
        // history with its old key. Typing the next delivery into it must
        // not quietly land on the first purchase and lose this one.
        $this->actingAs($admin)
            ->post('/purchases', $this->payload([
                'idempotency_key' => $key,
                'supplier_reference' => 'INV-002',
                'lines' => [['product_id' => $this->product->id, 'quantity' => 7, 'unit_cost' => '305.00']],
            ]))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Purchase::count());
        // The first purchase is untouched, and the second was not posted.
        $this->assertSame(100, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_create_screen_hands_out_a_fresh_key_each_time(): void
    {
        $admin = $this->admin();

        $first = $this->actingAs($admin)->get('/purchases/create')
            ->viewData('page')['props']['idempotencyKey'];
        $second = $this->actingAs($admin)->get('/purchases/create')
            ->viewData('page')['props']['idempotencyKey'];

        $this->assertNotSame($first, $second);
    }

    public function test_the_same_supplier_invoice_cannot_be_recorded_twice(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', $this->payload());

        $this->actingAs($admin)
            ->post('/purchases', $this->payload(['idempotency_key' => (string) Str::uuid()]))
            ->assertSessionHasErrors('supplier_reference');

        $this->assertSame(1, Purchase::count());
    }

    /* ----------------------------------------------------------------- Void */

    public function test_an_untouched_purchase_can_be_voided(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/purchases', $this->payload());

        $purchase = Purchase::first();

        $this->actingAs($admin)
            ->post("/purchases/{$purchase->id}/void", ['reason' => 'Wrong supplier.'])
            ->assertRedirect();

        $purchase->refresh();

        $this->assertSame('voided', $purchase->status->value);
        $this->assertSame('Wrong supplier.', $purchase->void_reason);
        $this->assertSame(0, $this->product->fresh()->on_hand);
        $this->assertSame(0, InventoryLayer::firstWhere('product_id', $this->product->id)->quantity_remaining);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_purchase_whose_stock_has_moved_cannot_be_voided(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/purchases', $this->payload());

        $purchase = Purchase::first();

        // One unit sold. The cost of that sale is already settled.
        app(AdjustmentPoster::class)->post('decrease', [
            ['product_id' => $this->product->id, 'quantity' => 1],
        ]);

        $this->actingAs($admin)
            ->post("/purchases/{$purchase->id}/void", ['reason' => 'Changed my mind.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('posted', $purchase->fresh()->status->value);
        $this->assertSame(99, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_purchase_cannot_be_voided_twice(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/purchases', $this->payload());

        $purchase = Purchase::first();

        $this->actingAs($admin)->post("/purchases/{$purchase->id}/void", ['reason' => 'First time.']);

        $this->actingAs($admin)
            ->post("/purchases/{$purchase->id}/void", ['reason' => 'Second time.'])
            ->assertForbidden();

        $this->assertSame(0, $this->product->fresh()->on_hand);
    }

    public function test_voiding_needs_its_own_permission(): void
    {
        $this->actingAs($this->admin())->post('/purchases', $this->payload());
        $purchase = Purchase::first();

        // Staff may buy goods in but not undo a posted document.
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->post("/purchases/{$purchase->id}/void", ['reason' => 'Trying it on.'])
            ->assertForbidden();

        $this->assertSame('posted', $purchase->fresh()->status->value);
    }

    public function test_a_void_must_say_why(): void
    {
        $this->actingAs($this->admin())->post('/purchases', $this->payload());
        $purchase = Purchase::first();

        $this->actingAs($this->admin())
            ->post("/purchases/{$purchase->id}/void", ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_voiding_puts_the_cost_back_to_the_last_price_that_still_stands(): void
    {
        $admin = $this->admin();

        // Opening stock at 300, then two purchases at 310 and 320.
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 300],
        ]);

        $this->actingAs($admin)->post('/purchases', $this->payload([
            'supplier_reference' => 'INV-A',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => '310.00']],
        ]));
        $this->actingAs($admin)->post('/purchases', $this->payload([
            'supplier_reference' => 'INV-B',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => '320.00']],
        ]));

        $this->assertSame('320.00', $this->product->fresh()->cost);

        $second = Purchase::where('supplier_reference', 'INV-B')->firstOrFail();
        $this->actingAs($admin)->post("/purchases/{$second->id}/void", ['reason' => 'Wrong price.']);

        // Back to the purchase that still stands.
        $this->assertSame('310.00', $this->product->fresh()->cost);

        $first = Purchase::where('supplier_reference', 'INV-A')->firstOrFail();
        $this->actingAs($admin)->post("/purchases/{$first->id}/void", ['reason' => 'That one too.']);

        // Nothing bought stands now, so it falls back to the opening batch.
        $this->assertSame('300.00', $this->product->fresh()->cost);
        $this->assertStockMatchesItsLayers();
    }

    /* ---------------------------------------------------------------- Trail */

    public function test_a_purchase_writes_one_post_row_and_one_void_row(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', $this->payload([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '300.00'],
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '310.00'],
            ],
        ]));

        $purchase = Purchase::first();
        $this->actingAs($admin)->post("/purchases/{$purchase->id}/void", ['reason' => 'Duplicate delivery.']);

        $rows = AuditTrail::where('module', 'Purchases')->get();

        // Two lines, two layers, two movements — and one line in the trail
        // for the posting, one for the void.
        $this->assertSame(1, $rows->where('action', AuditAction::Post)->count());
        $this->assertSame(1, $rows->where('action', AuditAction::Void)->count());
    }

    /* ---------------------------------------------------------------- Screens */

    public function test_the_list_opens_and_carries_its_filters(): void
    {
        $this->actingAs($this->admin())->post('/purchases', $this->payload());

        $this->actingAs($this->admin())
            ->get('/purchases')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Index')
                ->has('purchases.data', 1)
                ->has('options.suppliers')
                ->where('purchases.data.0.lines_count', 1));
    }

    public function test_the_detail_screen_shows_the_lines(): void
    {
        $this->actingAs($this->admin())->post('/purchases', $this->payload());
        $purchase = Purchase::first();

        $this->actingAs($this->admin())
            ->get("/purchases/{$purchase->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Show')
                ->has('purchase.lines', 1)
                ->where('purchase.lines.0.sku', 'TSHIRT-BLK')
                ->where('can.void', true));
    }

    public function test_the_create_screen_carries_the_date_limits(): void
    {
        $this->actingAs($this->admin())
            ->get('/purchases/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Purchases/Create')
                ->has('products')
                ->has('idempotencyKey')
                ->where('dates.max', Carbon::today()->toDateString()));
    }
}
