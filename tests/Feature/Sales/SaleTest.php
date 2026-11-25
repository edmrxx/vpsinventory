<?php

namespace Tests\Feature\Sales;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->product = $this->unaudited(
            fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK', 'selling_price' => 500])
        );
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** Put stock on the shelf the only way there is. */
    protected function stock(int $quantity, float $cost = 300, ?Product $product = null): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => ($product ?? $this->product)->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00'],
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
            ->get('/sales')
            ->assertForbidden();
    }

    public function test_a_posted_sale_has_no_edit_or_delete_route(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName());

        $this->assertNotContains('sales.edit', $names);
        $this->assertNotContains('sales.update', $names);
        $this->assertNotContains('sales.destroy', $names);
    }

    /* --------------------------------------------------------------- Posting */

    public function test_a_sale_takes_stock_off_the_shelf(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload())
            ->assertRedirect();

        $sale = Sale::first();

        $this->assertSame('posted', $sale->status->value);
        $this->assertStringStartsWith('SI-', $sale->reference);
        $this->assertSame('1000.00', $sale->total_amount);
        $this->assertSame(8, $this->product->fresh()->on_hand);

        $movement = InventoryMovement::where('type', MovementType::Sale->value)->first();
        $this->assertSame(2, $movement->quantity_out);

        $this->assertStockMatchesItsLayers();
    }

    public function test_the_sale_records_what_the_goods_actually_cost(): void
    {
        // Two batches: the oldest is eaten first.
        $this->stock(3, 300);
        $this->stock(10, 350);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload([
                'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => '500.00']],
            ]));

        $sale = Sale::first();

        // Three at 300 and two at 350.
        $this->assertSame('1600.00', $sale->total_cost);
        $this->assertSame('2500.00', $sale->total_amount);
        $this->assertSame('900.00', $sale->grossProfit());

        $this->assertSame('1600.00', $sale->lines->first()->line_cost);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_discount_comes_off_the_whole_document(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['discount' => '150.00']));

        $sale = Sale::first();

        $this->assertSame('1000.00', $sale->subtotal);
        $this->assertSame('150.00', $sale->discount);
        $this->assertSame('850.00', $sale->total_amount);
    }

    public function test_the_totals_are_worked_out_on_the_server(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload([
                'subtotal' => '1.00',
                'total_amount' => '1.00',
                'total_cost' => '1.00',
            ]));

        $this->assertSame('1000.00', Sale::first()->total_amount);
    }

    /* ------------------------------------------------------------ Validation */

    public function test_a_sale_cannot_take_more_than_is_on_hand(): void
    {
        $this->stock(3);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload([
                'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => '500.00']],
            ]))
            // Said on the line that caused it, not on the form as a whole.
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(0, Sale::count());
        $this->assertSame(3, $this->product->fresh()->on_hand);
    }

    public function test_two_lines_of_the_same_product_are_counted_together(): void
    {
        $this->stock(5);

        // Three and three is six, which is more than the five on hand, even
        // though neither line asks for too much on its own.
        $this->actingAs($this->admin())
            ->post('/sales', $this->payload([
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '500.00'],
                    ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '500.00'],
                ],
            ]))
            ->assertSessionHasErrors(['lines.0.quantity', 'lines.1.quantity']);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_discount_larger_than_the_sale_is_refused(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['discount' => '5000.00']))
            ->assertSessionHasErrors('discount');
    }

    public function test_a_sale_cannot_be_dated_in_the_future(): void
    {
        $this->stock(10);

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload([
                'sale_date' => Carbon::today()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('sale_date');
    }

    public function test_an_inactive_customer_is_refused(): void
    {
        $this->stock(10);
        $other = $this->unaudited(fn () => Customer::factory()->inactive()->create());

        $this->actingAs($this->admin())
            ->post('/sales', $this->payload(['customer_id' => $other->id]))
            ->assertSessionHasErrors('customer_id');
    }

    /* ------------------------------------------------------------ Duplicates */

    public function test_the_same_form_sent_twice_makes_one_sale(): void
    {
        $this->stock(10);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload(['idempotency_key' => $key]));
        $this->actingAs($admin)
            ->post('/sales', $this->payload(['idempotency_key' => $key]))
            ->assertRedirect();

        $this->assertSame(1, Sale::count());
        $this->assertSame(8, $this->product->fresh()->on_hand);
    }

    public function test_a_repeat_of_a_sale_that_took_the_last_stock_still_lands_on_it(): void
    {
        // The hard case: the sale emptied the shelf, so checking the stock a
        // second time would say there is none — for a sale that went through.
        $this->stock(3);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $payload = $this->payload([
            'idempotency_key' => $key,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '500.00']],
        ]);

        $this->actingAs($admin)->post('/sales', $payload);
        $this->assertSame(0, $this->product->fresh()->on_hand);

        $this->actingAs($admin)
            ->post('/sales', $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('sales.show', Sale::first()));

        $this->assertSame(1, Sale::count());
        $this->assertSame(0, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_stale_key_on_an_emptied_shelf_complains_about_the_key(): void
    {
        $this->stock(3);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload([
            'idempotency_key' => $key,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '500.00']],
        ]));

        // A different sale typed into a page the browser brought back. The
        // complaint must be about the stale key, not about the stock the
        // first sale legitimately took.
        $this->actingAs($admin)
            ->post('/sales', $this->payload([
                'idempotency_key' => $key,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '500.00']],
            ]))
            ->assertSessionHasErrors('idempotency_key')
            ->assertSessionDoesntHaveErrors('lines.0.quantity');

        $this->assertSame(1, Sale::count());
    }

    public function test_a_different_sale_under_a_stale_key_is_refused(): void
    {
        $this->stock(10);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload(['idempotency_key' => $key]));

        $this->actingAs($admin)
            ->post('/sales', $this->payload([
                'idempotency_key' => $key,
                'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => '500.00']],
            ]))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Sale::count());
    }

    /* ----------------------------------------------------------------- Void */

    public function test_voiding_puts_the_stock_back_in_the_batch_it_left(): void
    {
        $this->stock(10, 300);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload());
        $sale = Sale::first();

        $this->assertSame(8, $this->product->fresh()->on_hand);

        $this->actingAs($admin)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Customer changed their mind.'])
            ->assertRedirect();

        $this->assertSame('voided', $sale->fresh()->status->value);
        $this->assertSame(10, $this->product->fresh()->on_hand);

        // Back into the same batch, not into a new one.
        $this->assertSame(1, InventoryLayer::where('product_id', $this->product->id)->count());
        $this->assertSame(10, InventoryLayer::firstWhere('product_id', $this->product->id)->quantity_remaining);

        $this->assertStockMatchesItsLayers();
    }

    public function test_voiding_puts_each_batch_back_as_it_was(): void
    {
        $this->stock(3, 300);
        $this->stock(10, 350);
        $admin = $this->admin();

        // Five units: three from the first batch, two from the second.
        $this->actingAs($admin)->post('/sales', $this->payload([
            'lines' => [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => '500.00']],
        ]));

        $sale = Sale::first();
        $this->actingAs($admin)->post("/sales/{$sale->id}/void", ['reason' => 'Wrong order.']);

        $layers = InventoryLayer::where('product_id', $this->product->id)->orderBy('id')->get();

        // Each batch is whole again, and the FIFO order is undisturbed.
        $this->assertSame(3, $layers[0]->quantity_remaining);
        $this->assertSame(10, $layers[1]->quantity_remaining);
        $this->assertSame(13, $this->product->fresh()->on_hand);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        $this->stock(10);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload());
        $sale = Sale::first();

        $this->actingAs($admin)->post("/sales/{$sale->id}/void", ['reason' => 'First time.']);
        $this->actingAs($admin)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Second time.'])
            ->assertForbidden();

        $this->assertSame(10, $this->product->fresh()->on_hand);
    }

    public function test_voiding_needs_its_own_permission(): void
    {
        $this->stock(10);
        $this->actingAs($this->admin())->post('/sales', $this->payload());
        $sale = Sale::first();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Trying it on.'])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------ The margin */

    public function test_staff_never_receive_the_cost_or_the_profit(): void
    {
        $this->stock(10);
        $this->actingAs($this->admin())->post('/sales', $this->payload());
        $sale = Sale::first();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        // Held back on the server, not merely hidden once it has arrived.
        $this->actingAs($staff)
            ->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', false)
                ->missing('sale.total_cost')
                ->missing('sale.gross_profit')
                ->missing('sale.lines.0.unit_cost'));

        $this->actingAs($staff)
            ->get('/sales')
            ->assertInertia(fn ($page) => $page->missing('sales.data.0.gross_profit'));

        // The form must not be handed the cost either, or the below-cost
        // warning would quietly tell them what it is.
        $this->actingAs($staff)
            ->get('/sales/create')
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', false)
                ->has('products.0.price')
                ->missing('products.0.cost'));
    }

    public function test_an_admin_is_given_the_cost_on_the_form(): void
    {
        $this->stock(10, 300);

        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertInertia(fn ($page) => $page->has('products.0.cost'));
    }

    public function test_an_admin_sees_the_margin(): void
    {
        $this->stock(10, 300);
        $admin = $this->admin();
        $this->actingAs($admin)->post('/sales', $this->payload());
        $sale = Sale::first();

        $this->actingAs($admin)
            ->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.viewCost', true)
                ->where('sale.total_cost', '600.00')
                ->where('sale.gross_profit', '400.00'));
    }

    /* ---------------------------------------------------------------- Trail */

    public function test_a_sale_writes_one_post_row_and_one_void_row(): void
    {
        $this->stock(10);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales', $this->payload([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00'],
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '500.00'],
            ],
        ]));

        $sale = Sale::first();
        $this->actingAs($admin)->post("/sales/{$sale->id}/void", ['reason' => 'Duplicate.']);

        $rows = AuditTrail::where('module', 'Sales')->get();

        $this->assertSame(1, $rows->where('action', AuditAction::Post)->count());
        $this->assertSame(1, $rows->where('action', AuditAction::Void)->count());
    }

    /* --------------------------------------------------------------- Screens */

    public function test_the_create_screen_preselects_the_walk_in_customer(): void
    {
        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Create')
                ->where('walkInId', $this->customer->id)
                ->has('products')
                ->has('idempotencyKey'));
    }

    public function test_the_create_screen_says_what_is_on_hand(): void
    {
        $this->stock(7);

        $this->actingAs($this->admin())
            ->get('/sales/create')
            ->assertInertia(fn ($page) => $page->where('products.0.on_hand', 7));
    }

    public function test_the_detail_screen_shows_the_lines(): void
    {
        $this->stock(10);
        $this->actingAs($this->admin())->post('/sales', $this->payload());
        $sale = Sale::first();

        $this->actingAs($this->admin())
            ->get("/sales/{$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Show')
                ->has('sale.lines', 1)
                ->where('sale.lines.0.sku', 'TSHIRT-BLK'));
    }
}
