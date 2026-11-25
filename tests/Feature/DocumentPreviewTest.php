<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\LossReport;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dialog on a list screen.
 *
 * A purchase, a sale, an adjustment and a product can each be read without
 * leaving the list. The dialog fetches the same payload the page renders,
 * because both come from one `detail()` method on the controller — so these
 * tests check that they really do agree, rather than trusting that they do.
 */
class DocumentPreviewTest extends TestCase
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

    /** One of each document, so every preview has something real to read. */
    protected function trade(): void
    {
        $admin = $this->admin();
        $supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '120.00']],
        ]);

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => '100.00',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);

        // A return of each kind, so the two dialogs have something real to
        // read. The sale ate the adjustment's batch, so the delivery's own
        // batch is still whole and can be sent back.
        $sale = Sale::latest('id')->firstOrFail();
        $purchase = Purchase::latest('id')->firstOrFail();

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
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 3]],
        ]);

        // A transfer, from the default to the warehouse (phase 31.5).
        $this->actingAs($admin)->post('/transfers', [
            'from_location_id' => Location::default()->id,
            'to_location_id' => Location::where('name', 'Warehouse')->value('id'),
            'transfer_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5]],
        ])->assertSessionHasNoErrors();

        // A count sheet with one figure entered, handed in (phase 32).
        $this->actingAs($admin)->post('/stock-counts', [
            'location_id' => Location::default()->id,
        ])->assertSessionHasNoErrors();
        $count = StockCount::latest('id')->firstOrFail();
        $this->actingAs($admin)->put("/stock-counts/{$count->id}/counts", ['counts' => [
            ['id' => $count->lines()->value('id'), 'counted_quantity' => 3],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/stock-counts/{$count->id}/submit")->assertSessionHasNoErrors();

        // A loss report, held aside and waiting (phase 33). A theft needs no
        // photo, so nothing is written to the private disk.
        $this->actingAs($admin)->post('/loss-reports', [
            'location_id' => Location::default()->id,
            'reason' => 'theft',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        // Leave the acting user behind. One test method shares one
        // application, so a guard that has already resolved a user keeps
        // answering with it, and a guest check would quietly pass.
        $this->forgetSignedInUser();
    }

    /* ------------------------------------------------- The page and the dialog */

    public static function documents(): array
    {
        return [
            'purchase' => ['purchases', Purchase::class, 'purchase'],
            'sale' => ['sales', Sale::class, 'sale'],
            'adjustment' => ['adjustments', InventoryAdjustment::class, 'adjustment'],
            'sales return' => ['sales-returns', SalesReturn::class, 'salesReturn'],
            'purchase return' => ['purchase-returns', PurchaseReturn::class, 'purchaseReturn'],
            'transfer' => ['transfers', StockTransfer::class, 'transfer'],
            'stock count' => ['stock-counts', StockCount::class, 'count'],
            'loss report' => ['loss-reports', LossReport::class, 'report'],
        ];
    }

    #[DataProvider('documents')]
    public function test_the_dialog_answers_with_what_the_page_renders(
        string $path,
        string $model,
        string $key,
    ): void {
        $this->trade();

        $admin = $this->admin();
        $id = $model::value('id');

        // What the page puts on screen.
        $page = $this->actingAs($admin)->get("/{$path}/{$id}")->assertOk();
        $fromPage = $page->viewData('page')['props'][$key];

        // What the dialog fetches.
        $fromDialog = $this->actingAs($admin)
            ->getJson("/{$path}/{$id}/preview")
            ->assertOk()
            ->json($key);

        // Both empty would agree perfectly and say nothing. The two return
        // screens shipped with an empty `detail()` once, and the page drew
        // blank; this is what would have caught it.
        $this->assertNotEmpty($fromPage, "The [{$path}] page was sent an empty payload.");

        $this->assertSame(
            $fromPage,
            $fromDialog,
            "The [{$path}] dialog and page disagree. They must come from one method."
        );
    }

    /**
     * A customer payment's dialog (phase 19) reads the same `detail()` as its
     * page, like the documents above.
     */
    public function test_the_customer_payment_dialog_answers_with_what_its_page_renders(): void
    {
        $this->trade();

        $admin = $this->admin();
        $customer = $this->unaudited(fn () => Customer::factory()->create(['credit_limit' => 5000, 'payment_terms_days' => 30]));

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'on_account' => '500.00',
            'payments' => [],
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/customer-payments', [
            'customer_id' => $customer->id,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => \App\Models\PaymentMethod::cash()->id,
            'amount' => '200.00',
            'allocations' => [['sale_id' => Sale::latest('id')->value('id'), 'amount' => '200.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\CustomerPayment::value('id');

        $fromPage = $this->actingAs($admin)->get("/customer-payments/{$id}")->assertOk()->viewData('page')['props']['payment'];
        $fromDialog = $this->actingAs($admin)->getJson("/customer-payments/{$id}/preview")->assertOk()->json('payment');

        $this->assertNotEmpty($fromPage['allocations']);
        $this->assertSame($fromPage, $fromDialog, 'The customer payment dialog and page disagree.');
    }

    public function test_the_purchase_request_dialog_answers_with_what_its_page_renders(): void
    {
        $admin = $this->admin();
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)->post('/purchase-requests', [
            'reason' => 'Running low before the weekend',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 12, 'unit_cost' => '150.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\PurchaseRequest::value('id');

        $page = $this->actingAs($admin)->get("/purchase-requests/{$id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/purchase-requests/{$id}/preview")->assertOk()->json();

        $this->assertNotEmpty($page['request']['lines']);
        $this->assertSame($page['request'], $dialog['request'], 'The purchase request dialog and page disagree.');
        $this->assertSame($page['can'], $dialog['can'], 'The purchase request dialog and page offer different buttons.');
        $this->assertTrue($dialog['can']['approve']);
    }

    public function test_the_purchase_order_dialog_answers_with_what_its_page_renders(): void
    {
        $admin = $this->admin();
        $supplier = $this->unaudited(fn () => Supplier::factory()->create(['is_vat_registered' => true]));

        $this->actingAs($admin)->post('/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 12, 'unit_cost' => '150.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\PurchaseOrder::value('id');

        $page = $this->actingAs($admin)->get("/purchase-orders/{$id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/purchase-orders/{$id}/preview")->assertOk()->json();

        $this->assertNotEmpty($page['order']['lines']);
        $this->assertSame($page['order'], $dialog['order'], 'The purchase order dialog and page disagree.');
        $this->assertSame($page['can'], $dialog['can'], 'The purchase order dialog and page offer different buttons.');
    }

    public function test_the_supplier_payment_dialog_answers_with_what_its_page_renders(): void
    {
        $admin = $this->admin();
        $supplier = $this->unaudited(fn () => Supplier::factory()->create(['payment_terms_days' => 30]));

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'on_account' => true,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => '100.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/supplier-payments', [
            'supplier_id' => $supplier->id,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => \App\Models\PaymentMethod::cash()->id,
            'amount' => '200.00',
            'allocations' => [['purchase_id' => Purchase::latest('id')->value('id'), 'amount' => '200.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\SupplierPayment::value('id');

        $fromPage = $this->actingAs($admin)->get("/supplier-payments/{$id}")->assertOk()->viewData('page')['props']['payment'];
        $fromDialog = $this->actingAs($admin)->getJson("/supplier-payments/{$id}/preview")->assertOk()->json('payment');

        $this->assertNotEmpty($fromPage['allocations']);
        $this->assertSame($fromPage, $fromDialog, 'The supplier payment dialog and page disagree.');
    }

    public function test_the_quotation_dialog_answers_with_what_its_page_renders(): void
    {
        $admin = $this->admin();
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($admin)->post('/quotations', [
            'customer_id' => $customer->id,
            'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '250.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\Quotation::value('id');

        $page = $this->actingAs($admin)->get("/quotations/{$id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/quotations/{$id}/preview")->assertOk()->json();

        $this->assertNotEmpty($page['quotation']['lines']);
        $this->assertSame($page['quotation'], $dialog['quotation'], 'The quotation dialog and page disagree.');
        $this->assertSame($page['can'], $dialog['can'], 'The quotation dialog and page offer different buttons.');
    }

    public function test_the_sales_order_dialog_answers_with_what_its_page_renders(): void
    {
        $this->trade();
        $admin = $this->admin();
        $customer = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($admin)->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => now()->addDays(5)->toDateString(),
            'approve' => true,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '250.00']],
        ])->assertSessionHasNoErrors();

        $id = \App\Models\SalesOrder::value('id');

        $page = $this->actingAs($admin)->get("/sales-orders/{$id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/sales-orders/{$id}/preview")->assertOk()->json();

        $this->assertNotEmpty($page['order']['lines']);
        $this->assertSame($page['order'], $dialog['order'], 'The sales order dialog and page disagree.');
        $this->assertSame($page['can'], $dialog['can'], 'The sales order dialog and page offer different buttons.');
    }

    public function test_the_product_dialog_answers_with_what_its_page_renders(): void
    {
        $this->trade();

        $admin = $this->admin();

        $page = $this->actingAs($admin)->get("/products/{$this->product->id}")->assertOk();

        $fromDialog = $this->actingAs($admin)
            ->getJson("/products/{$this->product->id}/preview")
            ->assertOk();

        foreach (['product', 'batches', 'stockValue', 'movements'] as $key) {
            $this->assertSame(
                $page->viewData('page')['props'][$key],
                $fromDialog->json($key),
                "The product dialog and page disagree about [{$key}]."
            );
        }
    }

    /**
     * A customer and a supplier open in a dialog on their lists (24 November
     * 2026), so the list keeps its place. Each reads the same `detail()` as
     * its page.
     */
    public function test_the_customer_dialog_answers_with_what_its_page_renders(): void
    {
        $this->trade();

        $admin = $this->admin();
        $customer = $this->unaudited(fn () => Customer::factory()->create(['credit_limit' => 5000, 'payment_terms_days' => 30]));

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '300.00']],
            'payments' => [],
            'on_account' => '300.00',
        ])->assertSessionHasNoErrors();

        $page = $this->actingAs($admin)->get("/customers/{$customer->id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/customers/{$customer->id}/preview")->assertOk()->json();

        $this->assertNotEmpty($dialog['unpaid']);

        foreach (['customer', 'account', 'flags', 'unpaid', 'sales', 'can'] as $key) {
            $this->assertSame($page[$key], $dialog[$key], "The customer dialog and page disagree about [{$key}].");
        }
    }

    public function test_the_supplier_dialog_answers_with_what_its_page_renders(): void
    {
        $this->trade();

        $admin = $this->admin();
        $supplier = Supplier::firstOrFail();

        $page = $this->actingAs($admin)->get("/suppliers/{$supplier->id}")->assertOk()->viewData('page')['props'];
        $dialog = $this->actingAs($admin)->getJson("/suppliers/{$supplier->id}/preview")->assertOk()->json();

        $this->assertNotEmpty($dialog['purchases']);

        foreach (['supplier', 'purchases', 'account', 'can'] as $key) {
            $this->assertSame($page[$key], $dialog[$key], "The supplier dialog and page disagree about [{$key}].");
        }
    }

    public function test_a_customer_or_supplier_dialog_needs_its_view_permission(): void
    {
        $this->trade();

        $nobody = $this->unaudited(fn () => User::factory()->create());

        $this->actingAs($nobody)->getJson('/customers/'.Customer::value('id').'/preview')->assertForbidden();
        $this->actingAs($nobody)->getJson('/suppliers/'.Supplier::value('id').'/preview')->assertForbidden();
    }

    /* ----------------------------------------------------------------- Access */

    public function test_a_guest_cannot_read_a_dialog(): void
    {
        $this->trade();

        $this->getJson('/purchases/'.Purchase::value('id').'/preview')->assertUnauthorized();
    }

    public function test_a_user_without_the_permission_cannot_read_a_dialog(): void
    {
        $this->trade();

        $nobody = $this->unaudited(fn () => User::factory()->create());

        foreach (['purchases' => Purchase::class, 'sales' => Sale::class] as $path => $model) {
            $this->actingAs($nobody)
                ->getJson("/{$path}/".$model::value('id').'/preview')
                ->assertForbidden();
        }
    }

    /**
     * The dialog must not be a way round the cost wall.
     *
     * The page leaves the cost out of the payload for anyone without
     * `sales.view_cost`. A dialog that fetched it separately would be a
     * hole in the same wall.
     */
    public function test_the_sale_dialog_holds_the_cost_back_too(): void
    {
        $this->trade();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $reply = $this->actingAs($staff)
            ->getJson('/sales/'.Sale::value('id').'/preview')
            ->assertOk();

        $this->assertArrayNotHasKey('total_cost', $reply->json('sale'));
        $this->assertArrayNotHasKey('gross_profit', $reply->json('sale'));
        $this->assertArrayNotHasKey('unit_cost', $reply->json('sale.lines.0'));
        $this->assertFalse($reply->json('can.viewCost'));
    }

    public function test_an_admin_dialog_does_carry_the_cost(): void
    {
        $this->trade();

        $sale = Sale::firstOrFail();

        $this->actingAs($this->admin())
            ->getJson("/sales/{$sale->id}/preview")
            ->assertOk()
            ->assertJsonPath('sale.total_cost', $sale->total_cost)
            ->assertJsonPath('sale.gross_profit', $sale->grossProfit());
    }

    /* ------------------------------------------------------------------ Trail */

    public function test_reading_a_dialog_is_written_to_the_trail(): void
    {
        $this->trade();

        $admin = $this->admin();
        $purchase = Purchase::firstOrFail();

        $before = AuditTrail::where('module', 'Purchases')->count();

        $this->actingAs($admin)->getJson("/purchases/{$purchase->id}/preview")->assertOk();

        $this->assertSame(
            $before + 1,
            AuditTrail::where('module', 'Purchases')->count(),
            'Reading a document in a dialog is still reading it.'
        );
    }
}
