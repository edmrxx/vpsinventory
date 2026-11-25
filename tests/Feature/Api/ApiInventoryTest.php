<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryMetrics;
use App\Services\InventoryValuation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API for the inventory modules.
 *
 * Every endpoint is hit with real rows, because the shape of a reply is
 * the whole contract a phone depends on. Several of these were built by
 * copying a neighbour, and a copy that keeps the wrong noun only shows up
 * when something actually calls it.
 */
class ApiInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Supplier $supplier;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create(['name' => 'ABC Trading']));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test', $user->getAllPermissions()->pluck('name')->all())->plainTextToken;
    }

    /** One of everything, so each endpoint has something real to return. */
    protected function trade(): void
    {
        $admin = $this->admin();

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '120.00']],
        ]);

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => '100.00',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
        ]);

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ]);

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($admin)->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 2]],
        ]);

        // Leave the acting user behind: a token call must stand on its own.
        $this->forgetSignedInUser();
    }

    /* ---------------------------------------------------------------- Shape */

    public static function listEndpoints(): array
    {
        return [
            'products' => ['products'],
            'categories' => ['categories'],
            'units' => ['units'],
            'suppliers' => ['suppliers'],
            'customers' => ['customers'],
            'purchases' => ['purchases'],
            'sales' => ['sales'],
            'sales returns' => ['sales-returns'],
            'purchase returns' => ['purchase-returns'],
            'adjustments' => ['adjustments'],
            'movements' => ['movements'],
            'stock' => ['stock'],
        ];
    }

    #[DataProvider('listEndpoints')]
    public function test_every_list_answers_in_the_one_shape(string $path): void
    {
        $this->trade();

        $this->withToken($this->tokenFor($this->admin()))
            ->getJson("/api/v1/{$path}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ])
            // Every one of these has at least one row after `trade()`.
            ->assertJsonPath('meta.total', fn ($total) => $total >= 1);
    }

    public function test_each_single_record_endpoint_returns_its_own_document(): void
    {
        $this->trade();

        $token = $this->tokenFor($this->admin());

        $cases = [
            "/api/v1/products/{$this->product->id}" => $this->product->sku,
            "/api/v1/suppliers/{$this->supplier->id}" => $this->supplier->code,
            "/api/v1/customers/{$this->customer->id}" => $this->customer->code,
            '/api/v1/purchases/'.Purchase::value('id') => Purchase::value('reference'),
            '/api/v1/sales/'.Sale::value('id') => Sale::value('reference'),
            '/api/v1/sales-returns/'.SalesReturn::value('id') => SalesReturn::value('reference'),
            '/api/v1/purchase-returns/'.PurchaseReturn::value('id') => PurchaseReturn::value('reference'),
            '/api/v1/adjustments/'.InventoryAdjustment::value('id') => InventoryAdjustment::value('reference'),
        ];

        foreach ($cases as $url => $expected) {
            $reply = $this->withToken($token)->getJson($url)->assertOk();

            $data = $reply->json('data');
            $found = $data['sku'] ?? $data['code'] ?? $data['reference'] ?? null;

            $this->assertSame($expected, $found, "[{$url}] returned the wrong record.");
        }
    }

    public function test_a_document_carries_its_lines_but_a_list_does_not(): void
    {
        $this->trade();

        $token = $this->tokenFor($this->admin());
        $sale = Sale::firstOrFail();

        // Forty documents of ten lines each would be four hundred rows
        // nobody asked for, so a list carries a count instead.
        $this->withToken($token)->getJson('/api/v1/sales')
            ->assertJsonPath('data.0.lines_count', 1)
            ->assertJsonMissingPath('data.0.lines');

        $this->withToken($token)->getJson("/api/v1/sales/{$sale->id}")
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.sku', 'TSHIRT-BLK');
    }

    /* ----------------------------------------------------------- The figures */

    public function test_the_dashboard_matches_the_web_screen(): void
    {
        $this->trade();

        $metrics = app(InventoryMetrics::class);

        $this->withToken($this->tokenFor($this->admin()))
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonCount(4, 'data.cards')
            ->assertJsonPath('data.cards.0.value', $metrics->netSales())
            ->assertJsonPath('data.cards.1.value', $metrics->netPurchases());
    }

    /**
     * Every stock row carries what it is worth.
     *
     * The endpoint lets a caller sort on `stock_value`, so it has to send
     * the column it sorts on. The Stock screen sends it to anyone holding
     * `stock.view`, and so does this.
     */
    public function test_each_stock_row_carries_its_value(): void
    {
        $this->trade();

        $product = Product::where('id', $this->product->id)->firstOrFail();

        $expected = app(InventoryValuation::class)->forProduct($product);

        $reply = $this->withToken($this->tokenFor($this->admin()))
            ->getJson('/api/v1/stock?per_page=50')
            ->assertOk();

        $row = collect($reply->json('data'))->firstWhere('sku', 'TSHIRT-BLK');

        $this->assertNotNull($row, 'The product should be on the stock list.');
        $this->assertArrayHasKey('stock_value', $row, 'A stock row must carry its value.');
        $this->assertSame($expected, $row['stock_value']);

        // And the sort the endpoint advertises actually works.
        $this->withToken($this->tokenFor($this->admin()))
            ->getJson('/api/v1/stock?sort=stock_value&direction=desc')
            ->assertOk();
    }

    /**
     * A list of products is not the stock list, so it carries no value.
     *
     * The value comes from a sub-select that only the stock endpoint adds.
     * Sending it as null everywhere else would have a phone show nothing
     * where it should show no field at all.
     */
    public function test_the_product_list_does_not_pretend_to_know_the_value(): void
    {
        $this->trade();

        $this->withToken($this->tokenFor($this->admin()))
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonMissingPath('data.0.stock_value');
    }

    public function test_the_stock_reply_carries_the_whole_valuation(): void
    {
        $this->trade();

        $this->withToken($this->tokenFor($this->admin()))
            ->getJson('/api/v1/stock')
            ->assertOk()
            ->assertJsonPath(
                'meta.summary.value',
                app(InventoryValuation::class)->total(),
            );
    }

    public function test_the_movements_come_newest_first(): void
    {
        $this->trade();

        $ids = array_column(
            $this->withToken($this->tokenFor($this->admin()))
                ->getJson('/api/v1/movements')
                ->assertOk()
                ->json('data'),
            'id',
        );

        $this->assertGreaterThan(1, count($ids), 'There should be several movements by now.');

        // Newest first, and by id rather than by date: posting order is the
        // only order in which the running balance reads correctly.
        $descending = $ids;
        rsort($descending);

        $this->assertSame($descending, $ids);
    }

    /* ------------------------------------------------------------- The cost */

    public function test_a_staff_token_never_receives_the_cost(): void
    {
        $this->trade();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $token = $this->tokenFor($staff);

        $sale = Sale::firstOrFail();

        $this->withToken($token)->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk()
            // The lines have to be present, or the three checks below would
            // pass on a reply that simply carried nothing.
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.sku', 'TSHIRT-BLK')
            ->assertJsonMissingPath('data.total_cost')
            ->assertJsonMissingPath('data.gross_profit')
            ->assertJsonMissingPath('data.lines.0.unit_cost');

        $this->withToken($token)->getJson('/api/v1/movements')
            ->assertOk()
            ->assertJsonMissingPath('data.0.unit_cost');
    }

    /**
     * The buying price is not the cost of goods sold.
     *
     * A purchase return's only value is what the goods cost from the
     * supplier, and the web screen shows it to anybody who may open the
     * document. Hiding it here would send a phone a return worth nothing.
     */
    public function test_a_staff_token_still_receives_the_purchase_side_cost(): void
    {
        $this->trade();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $token = $this->tokenFor($staff);

        $this->assertFalse($staff->can('viewCost', Sale::class), 'Staff must not hold sales.view_cost.');

        $return = PurchaseReturn::firstOrFail();

        $this->withToken($token)->getJson("/api/v1/purchase-returns/{$return->id}")
            ->assertOk()
            ->assertJsonPath('data.total_cost', $return->total_cost)
            ->assertJsonPath('data.lines.0.unit_cost', $return->lines->first()->unit_cost);

        $purchase = Purchase::firstOrFail();

        $this->withToken($token)->getJson("/api/v1/purchases/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.unit_cost', $purchase->lines->first()->unit_cost);

        $adjustment = InventoryAdjustment::firstOrFail();

        $this->withToken($token)->getJson("/api/v1/adjustments/{$adjustment->id}")
            ->assertOk()
            ->assertJsonPath('data.total_cost', $adjustment->total_cost);
    }

    /**
     * A sales return's cost is the cost of the goods that came back, so it
     * follows the same permission a sale's does.
     */
    public function test_a_staff_token_never_receives_a_sales_return_cost(): void
    {
        $this->trade();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $return = SalesReturn::firstOrFail();

        $this->withToken($this->tokenFor($staff))
            ->getJson("/api/v1/sales-returns/{$return->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonMissingPath('data.total_cost')
            ->assertJsonMissingPath('data.lines.0.line_cost');
    }

    public function test_an_admin_token_does_receive_it(): void
    {
        $this->trade();

        $sale = Sale::firstOrFail();

        $this->withToken($this->tokenFor($this->admin()))
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.total_cost', $sale->total_cost)
            ->assertJsonPath('data.gross_profit', $sale->grossProfit());
    }

    /* --------------------------------------------------------------- Access */

    public function test_every_endpoint_needs_a_token(): void
    {
        foreach (array_column(self::listEndpoints(), 0) as $path) {
            $this->getJson("/api/v1/{$path}")
                ->assertUnauthorized()
                ->assertJsonPath('success', false);
        }
    }

    public function test_a_permission_the_user_lacks_closes_its_endpoint(): void
    {
        $this->trade();

        $limited = $this->unaudited(
            fn () => User::factory()->withPermissions('products.view')->create()
        );

        $token = $this->tokenFor($limited);

        $this->withToken($token)->getJson('/api/v1/products')->assertOk();
        $this->withToken($token)->getJson('/api/v1/sales')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/stock')->assertForbidden();
    }

    /**
     * The inventory API reads and does not write.
     *
     * Saving a document moves stock the moment it is posted, so posting one
     * over the API deserves the idempotency key and the confirmation the web
     * form already has. Until that is built, the only writing endpoints are
     * the ones that hand out and take back a token — and POST is checked
     * here as well, because a `POST /api/v1/sales` added later would
     * otherwise slip in without anybody deciding to allow it.
     */
    public function test_the_api_writes_nothing(): void
    {
        $allowed = ['api/v1/login', 'api/v1/logout', 'api/v1/logout-all'];

        $writes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn ($route) => array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE'],
            ) !== [])
            ->reject(fn ($route) => in_array($route->uri(), $allowed, true))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            [],
            $writes,
            "These API routes write. Decide whether they should:\n  ".implode("\n  ", $writes)
        );
    }

    public function test_every_guarded_route_names_both_a_guard_and_a_permission(): void
    {
        $open = ['api/v1/login'];
        $authOnly = ['api/v1/me', 'api/v1/logout', 'api/v1/logout-all'];
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (in_array($route->uri(), $open, true)) {
                continue;
            }

            if (! in_array('auth:sanctum', $middleware, true)) {
                $missing[] = $route->uri().' (no guard)';

                continue;
            }

            if (in_array($route->uri(), $authOnly, true)) {
                continue;
            }

            $named = collect($middleware)->contains(fn ($m) => str_starts_with($m, 'permission:'));

            if (! $named) {
                $missing[] = $route->uri().' (no permission)';
            }
        }

        $this->assertSame([], $missing, "These API routes are not fully gated:\n  ".implode("\n  ", $missing));
    }
}
