<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryValuation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MovementScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function stock(int $quantity, float $cost = 300): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/movements')
            ->assertForbidden();
    }

    public function test_the_ledger_has_no_write_routes(): void
    {
        $writes = collect(Route::getRoutes())
            // The Stock screen is `stock` and `stock/…`; `stock-counts`
            // (phase 32) is a module of its own, with writes of its own.
            ->filter(fn ($route) => str_starts_with($route->uri(), 'movements')
                || $route->uri() === 'stock' || str_starts_with($route->uri(), 'stock/'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

        // The ledger is append-only: a row is answered, never changed.
        $this->assertCount(0, $writes);
    }

    /* ----------------------------------------------------------------- Order */

    public function test_the_newest_movement_comes_first(): void
    {
        $this->stock(10);
        $this->stock(5);
        $this->stock(3);

        $this->actingAs($this->admin())
            ->get('/movements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Movements/Index')
                ->has('movements.data', 3)
                // Sorting by date alone would put same-day rows oldest
                // first, and the running balance would read backwards.
                ->where('movements.data.0.balance_after', 18)
                ->where('movements.data.1.balance_after', 15)
                ->where('movements.data.2.balance_after', 10));
    }

    public function test_sorting_by_date_cannot_reverse_the_running_balance(): void
    {
        $this->stock(10);
        $this->stock(5);
        $this->stock(3);

        // Every row is dated today, so a date sort would fall back to the
        // id ascending tiebreaker and read 10, 15, 18 — the balance column
        // running upward. The date is not sortable for that reason.
        $this->actingAs($this->admin())
            ->get('/movements?sort=movement_date&direction=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('movements.data.0.balance_after', 18)
                ->where('movements.data.2.balance_after', 10)
                // The query string asked for a column that is not sortable,
                // so it fell back to the default.
                ->where('filters.sort', 'id'));
    }

    /* --------------------------------------------------------------- Filters */

    public function test_the_product_filter_narrows_the_list(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CAP-NVY']));

        $this->stock(10);
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $other->id, 'quantity' => 4, 'unit_cost' => 100],
        ]);

        $this->actingAs($this->admin())
            ->get("/movements?product_id={$other->id}")
            ->assertInertia(fn ($page) => $page
                ->has('movements.data', 1)
                ->where('movements.data.0.sku', 'CAP-NVY'));
    }

    public function test_the_type_filter_narrows_the_list(): void
    {
        $this->stock(10);
        app(AdjustmentPoster::class)->post('decrease', [
            ['product_id' => $this->product->id, 'quantity' => 2],
        ]);

        $this->actingAs($this->admin())
            ->get('/movements?type=adjustment_out')
            ->assertInertia(fn ($page) => $page
                ->has('movements.data', 1)
                ->where('movements.data.0.quantity_out', 2));
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/movements?type=nonsense')
            ->assertSessionHasErrors('type');
    }

    public function test_the_date_range_narrows_the_list(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->get('/movements?from='.now()->addDay()->toDateString())
            ->assertInertia(fn ($page) => $page->has('movements.data', 0));
    }

    public function test_a_product_that_is_switched_off_can_still_be_filtered_on(): void
    {
        $this->stock(10);
        $this->unaudited(fn () => $this->product->update(['is_active' => false]));

        // The history names it, so it has to stay pickable.
        $this->actingAs($this->admin())
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                ->has('options.products', 1)
                ->where('options.products.0.label', fn ($label) => str_contains($label, 'inactive')));
    }

    /* ------------------------------------------------------- The document link */

    public function test_each_kind_of_document_links_to_its_own_screen(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $admin = $this->admin();

        // An adjustment, a purchase, and a sale.
        $this->stock(20);
        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '300.00']],
        ]);
        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00']],
        ]);

        $adjustmentId = InventoryAdjustment::value('id');
        $purchaseId = Purchase::value('id');

        $this->actingAs($admin)
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                // Newest first: the sale, the purchase, then the adjustment.
                ->where('movements.data.0.document_url', fn ($url) => str_starts_with($url, '/sales/'))
                ->where('movements.data.1.document_url', "/purchases/{$purchaseId}")
                ->where('movements.data.2.document_url', "/adjustments/{$adjustmentId}"));
    }

    public function test_a_sales_return_and_a_purchase_return_link_to_their_own_screens(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '300.00']],
        ]);

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);

        $sale = Sale::firstOrFail();
        $purchase = Purchase::firstOrFail();

        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 2]],
        ]);

        $this->actingAs($admin)->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 1]],
        ]);

        $salesReturnId = SalesReturn::value('id');
        $purchaseReturnId = PurchaseReturn::value('id');

        // All five kinds of document now have a row in the ledger.
        $this->actingAs($admin)
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                ->where('movements.data.0.document_url', "/purchase-returns/{$purchaseReturnId}")
                ->where('movements.data.1.document_url', "/sales-returns/{$salesReturnId}"));
    }

    public function test_a_voided_purchase_still_links_to_itself(): void
    {
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '300.00']],
        ]);

        $purchase = Purchase::firstOrFail();
        $this->actingAs($admin)->post("/purchases/{$purchase->id}/void", ['reason' => 'Wrong order.']);

        // The void writes its own movement, against the same document.
        $this->actingAs($admin)
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                ->where('movements.data.0.type', 'purchase_void')
                ->where('movements.data.0.document_url', "/purchases/{$purchase->id}"));
    }

    public function test_a_document_the_user_may_not_read_gets_no_link(): void
    {
        $this->stock(10);

        // Staff may read movements, but give this one no adjustment access.
        $user = $this->unaudited(fn () => User::factory()->withPermissions('movements.view')->create());

        $this->actingAs($user)
            ->get('/movements')
            ->assertInertia(fn ($page) => $page->where('movements.data.0.document_url', null));
    }

    /* --------------------------------------------------------------- The cost */

    public function test_staff_never_receive_the_cost(): void
    {
        $this->stock(10);

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        // On a sale row this is the cost of goods, so it is held back by the
        // same permission that guards it on the sale itself.
        $this->actingAs($staff)
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', false)
                ->missing('movements.data.0.unit_cost')
                ->missing('movements.data.0.total_cost'));
    }

    public function test_an_admin_sees_the_cost(): void
    {
        $this->stock(10, 300);

        $this->actingAs($this->admin())
            ->get('/movements')
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', true)
                ->where('movements.data.0.unit_cost', '300.00'));
    }

    /* ---------------------------------------------------- The product's card */

    public function test_the_product_screen_carries_its_stock_card_and_batches(): void
    {
        $this->stock(10, 300);
        $this->stock(5, 350);

        $this->actingAs($this->admin())
            ->get("/products/{$this->product->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('movements', 2)
                ->has('batches', 2)
                // Newest batch first, so the panel reads the same way as the
                // stock card beside it.
                ->where('batches.0.unit_cost', '350.00')
                ->where('batches.1.unit_cost', '300.00')
                // The oldest is still the one the next sale eats, so it is
                // marked. Reversing the panel must not lose that.
                ->where('batches.0.is_next', false)
                ->where('batches.1.is_next', true)
                ->where('stockValue', '4750.00'));
    }

    /**
     * The order on screen must never be mistaken for the order of costing.
     *
     * The panel was turned round to read like the stock card above it. The
     * FIFO pick is a different query, so a sale must still take the oldest
     * batch — the one at the bottom of the panel, marked "next out".
     */
    public function test_the_batch_panel_reads_newest_first_but_a_sale_still_eats_the_oldest(): void
    {
        $this->stock(10, 300);
        $this->stock(5, 350);

        $batches = app(InventoryValuation::class)->batchesFor($this->product);

        $this->assertSame('350.00', $batches[0]['unit_cost'], 'The newest batch must be on top.');
        $this->assertTrue($batches[1]['is_next'], 'The oldest batch is the one marked next out.');

        // Now sell three, and see which batch actually paid for them.
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '500.00']],
        ])->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        // Three at the oldest cost, not the newest.
        $this->assertSame('900.00', $sale->total_cost);

        $after = app(InventoryValuation::class)->batchesFor($this->product);

        $this->assertSame(7, $after[1]['quantity_remaining'], 'The oldest batch is the one that shrank.');
        $this->assertSame(5, $after[0]['quantity_remaining'], 'The newest batch is untouched.');
    }

    public function test_the_product_stock_card_holds_the_cost_back_too(): void
    {
        $this->stock(10, 300);

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->get("/products/{$this->product->id}")
            ->assertInertia(fn ($page) => $page->missing('movements.0.unit_cost'));
    }

    public function test_a_user_who_cannot_read_movements_gets_an_empty_card(): void
    {
        $this->stock(10);

        $user = $this->unaudited(fn () => User::factory()->withPermissions('products.view')->create());

        $this->actingAs($user)
            ->get("/products/{$this->product->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.viewMovements', false)
                ->has('movements', 0));
    }

    public function test_the_running_balance_is_stored_not_added_up(): void
    {
        $this->stock(10);
        app(AdjustmentPoster::class)->post('decrease', [
            ['product_id' => $this->product->id, 'quantity' => 4],
        ]);

        // The screen reads the balance straight off the row, so it never
        // has to add up every movement before it.
        $balances = InventoryMovement::orderBy('id')->pluck('balance_after')->all();

        $this->assertSame([10, 6], $balances);
    }
}
