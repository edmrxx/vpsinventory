<?php

namespace Tests\Feature\Returns;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function stock(int $quantity, float $cost): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /** Sell, and hand back the sale that was made. */
    protected function sell(int $quantity, string $price = '500.00', string $discount = '0.00'): Sale
    {
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => $discount,
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ]);

        return Sale::latest('id')->firstOrFail();
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    /** @return array<string, mixed> */
    protected function payload(Sale $sale, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    /* ---------------------------------------------------------------- Access */

    public function test_a_user_without_the_view_permission_is_refused(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/sales-returns')
            ->assertForbidden();
    }

    public function test_a_return_has_no_void_edit_or_delete_route(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName());

        // Undoing an undo leaves a history nobody can follow.
        $this->assertNotContains('sales-returns.void', $names);
        $this->assertNotContains('sales-returns.edit', $names);
        $this->assertNotContains('sales-returns.update', $names);
        $this->assertNotContains('sales-returns.destroy', $names);
    }

    /* --------------------------------------------------------------- Posting */

    public function test_goods_go_back_into_the_batch_they_left(): void
    {
        $this->stock(3, 300);
        $this->stock(10, 350);

        // Five out: three from the cheap batch, two from the dearer one.
        $sale = $this->sell(5);

        $this->actingAs($this->admin())
            ->post('/sales-returns', $this->payload($sale, 2))
            ->assertRedirect();

        $layers = InventoryLayer::where('product_id', $this->product->id)->orderBy('id')->get();

        // The last two out were the 350s, so those are the first two back.
        $this->assertSame(0, $layers[0]->quantity_remaining);
        $this->assertSame(10, $layers[1]->quantity_remaining);
        $this->assertSame(10, $this->product->fresh()->on_hand);

        $return = SalesReturn::first();
        $this->assertSame('700.00', $return->total_cost);

        $movement = InventoryMovement::where('type', MovementType::SalesReturn->value)->first();
        $this->assertSame(2, $movement->quantity_in);

        $this->assertStockMatchesItsLayers();
    }

    public function test_the_sale_line_remembers_what_came_back(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 2));

        $line = SaleLine::first();

        $this->assertSame(2, $line->returned_quantity);
        $this->assertSame(3, $line->returnable());

        $this->assertStockMatchesItsLayers();
    }

    public function test_two_partial_returns_cannot_exceed_what_was_sold(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales-returns', $this->payload($sale, 3));

        // Three are already back; only two are left.
        $this->actingAs($admin)
            ->post('/sales-returns', $this->payload($sale, 3))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(1, SalesReturn::count());
        $this->assertSame(8, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_everything_can_come_back_but_no_more(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(4);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/sales-returns', $this->payload($sale, 4))
            ->assertSessionHasNoErrors();

        $this->assertSame(10, $this->product->fresh()->on_hand);

        $this->actingAs($admin)
            ->post('/sales-returns', $this->payload($sale, 1))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_discounted_sale_hands_back_only_what_was_charged(): void
    {
        $this->stock(10, 300);

        // Ten at 500 is 5,000, less 500 off, so 4,500 was collected.
        $sale = $this->sell(10, '500.00', '500.00');

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 10));

        // The whole sale back is 4,500, not 5,000.
        $this->assertSame('4500.00', SalesReturn::first()->total_amount);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_part_of_a_discounted_sale_is_prorated_too(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(10, '500.00', '500.00');

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 2));

        // 500 a unit less the tenth that was discounted is 450 each.
        $this->assertSame('900.00', SalesReturn::first()->total_amount);

        $this->assertStockMatchesItsLayers();
    }

    public function test_an_awkward_discount_never_refunds_more_than_was_charged(): void
    {
        $this->stock(10, 100);

        // Three at 100 with 10 off: 290 was collected. A third of 290 is
        // 96.666…, and 96.67 three times would be 290.01 — a centavo the
        // business never took.
        $sale = $this->sell(3, '100.00', '10.00');

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 3));

        $this->assertSame('290.00', SalesReturn::first()->total_amount);
        $this->assertStockMatchesItsLayers();
    }

    public function test_three_single_returns_add_back_up_to_what_was_charged(): void
    {
        $this->stock(10, 100);
        $sale = $this->sell(3, '100.00', '10.00');
        $admin = $this->admin();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->post('/sales-returns', $this->payload($sale, 1, [
                'idempotency_key' => (string) Str::uuid(),
            ]));
        }

        // One at a time must come to the same as all at once.
        $this->assertSame(3, SalesReturn::count());
        $this->assertSame('290.00', number_format((float) SalesReturn::sum('total_amount'), 2, '.', ''));
        $this->assertStockMatchesItsLayers();
    }

    public function test_two_lines_with_an_awkward_discount_add_back_up_exactly(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CAP-NVY']));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 100],
            ['product_id' => $other->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);

        $admin = $this->admin();

        // 3 at 100 and 3 at 50 is 450, less 17 off: 433 collected.
        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => '17.00',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => '100.00'],
                ['product_id' => $other->id, 'quantity' => 3, 'unit_price' => '50.00'],
            ],
        ]);

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => $sale->lines->map(fn ($line) => [
                'sale_line_id' => $line->id,
                'quantity' => $line->quantity,
            ])->all(),
        ]);

        $this->assertSame('433.00', SalesReturn::first()->total_amount);
        $this->assertStockMatchesItsLayers();
    }

    public function test_an_empty_return_is_refused(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(3);

        $this->actingAs($this->admin())
            ->post('/sales-returns', $this->payload($sale, 0))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_nothing_can_be_returned_against_a_voided_sale(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(3);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sales/{$sale->id}/void", ['reason' => 'Mistake.']);

        $this->actingAs($admin)
            ->post('/sales-returns', $this->payload($sale, 1))
            ->assertSessionHasErrors('sale_id');
    }

    /* ---------------------------------------------- The sale cannot be voided */

    public function test_a_sale_with_goods_back_can_no_longer_be_voided(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/sales-returns', $this->payload($sale, 2));

        // A void puts every unit back. Two are already back, so voiding
        // would count them twice and break the ledger.
        $this->actingAs($admin)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Too late.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('posted', $sale->fresh()->status->value);
        $this->assertSame(7, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------------------ Duplicates */

    public function test_the_same_form_sent_twice_makes_one_return(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $payload = $this->payload($sale, 5, ['idempotency_key' => $key]);

        $this->actingAs($admin)->post('/sales-returns', $payload);
        // The whole sale is back, so a second check would say "nothing left
        // to return" for a return that went through.
        $this->actingAs($admin)
            ->post('/sales-returns', $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, SalesReturn::count());
        $this->assertSame(10, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------- The line knows its row */

    public function test_a_sale_line_is_tied_to_the_movement_that_took_its_stock(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(4);

        $line = SaleLine::first();

        // Without this link a return could not find the batches to refill.
        $this->assertNotNull($line->inventory_movement_id);
        $this->assertSame(
            $line->inventory_movement_id,
            InventoryMovement::where('type', MovementType::Sale->value)->value('id'),
        );

        $this->assertStockMatchesItsLayers();
    }

    /* ---------------------------------------------------------------- Trail */

    public function test_a_return_writes_one_post_row(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 2));

        $rows = AuditTrail::where('module', 'Sales Returns')->get();

        $this->assertSame(1, $rows->where('action', AuditAction::Post)->count());
    }

    /* --------------------------------------------------------------- Screens */

    public function test_the_form_says_what_is_still_returnable(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);

        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 2));

        $this->actingAs($this->admin())
            ->get("/sales-returns/create?sale={$sale->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SalesReturns/Create')
                ->where('sale.lines.0.quantity', 5)
                ->where('sale.lines.0.returned_quantity', 2)
                ->where('sale.lines.0.returnable', 3));
    }

    public function test_the_form_refuses_a_sale_that_is_not_posted(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(3);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/sales/{$sale->id}/void", ['reason' => 'Mistake.']);

        $this->actingAs($admin)
            ->get("/sales-returns/create?sale={$sale->id}")
            ->assertNotFound();
    }

    public function test_staff_never_receive_the_cost(): void
    {
        $this->stock(10, 300);
        $sale = $this->sell(5);
        $this->actingAs($this->admin())->post('/sales-returns', $this->payload($sale, 2));

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->get('/sales-returns/'.SalesReturn::first()->id)
            ->assertInertia(fn ($page) => $page
                ->missing('salesReturn.total_cost')
                ->missing('salesReturn.lines.0.line_cost'));
    }
}
