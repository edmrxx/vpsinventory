<?php

namespace Tests\Feature\Returns;

use App\Enums\MovementType;
use App\Models\Customer;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function buy(int $quantity, string $cost = '300.00'): Purchase
    {
        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost]],
        ]);

        return Purchase::latest('id')->firstOrFail();
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    /** @return array<string, mixed> */
    protected function payload(Purchase $purchase, int $quantity, array $overrides = []): array
    {
        return array_merge([
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => $quantity]],
        ], $overrides);
    }

    /* --------------------------------------------------------------- Posting */

    public function test_goods_come_out_of_that_delivery_not_the_oldest_batch(): void
    {
        // An older, cheaper batch is sitting there first.
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);

        $purchase = $this->buy(10, '300.00');

        $this->actingAs($this->admin())
            ->post('/purchase-returns', $this->payload($purchase, 4))
            ->assertRedirect();

        $layers = InventoryLayer::where('product_id', $this->product->id)->orderBy('id')->get();

        // FIFO would have eaten the 100s. The goods belong to the supplier
        // who sent the 300s, so those are what go back.
        $this->assertSame(20, $layers[0]->quantity_remaining);
        $this->assertSame(6, $layers[1]->quantity_remaining);
        $this->assertSame(26, $this->product->fresh()->on_hand);

        $movement = InventoryMovement::where('type', MovementType::PurchaseReturn->value)->first();
        $this->assertSame(4, $movement->quantity_out);
        $this->assertSame('1200.00', $movement->total_cost);

        $this->assertStockMatchesItsLayers();
    }

    public function test_what_has_been_sold_cannot_go_back_to_the_supplier(): void
    {
        $purchase = $this->buy(10, '300.00');

        // Eight sold: only two of this delivery are still on the shelf.
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 8, 'unit_price' => '500.00']],
        ]);

        $this->actingAs($this->admin())
            ->post('/purchase-returns', $this->payload($purchase, 5))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(2, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_purchase_line_remembers_what_went_back(): void
    {
        $purchase = $this->buy(10);

        $this->actingAs($this->admin())->post('/purchase-returns', $this->payload($purchase, 3));

        $line = PurchaseLine::first();

        $this->assertSame(3, $line->returned_quantity);
        $this->assertSame(7, $line->returnable());

        $this->assertStockMatchesItsLayers();
    }

    public function test_two_partial_returns_cannot_exceed_the_delivery(): void
    {
        $purchase = $this->buy(10);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchase-returns', $this->payload($purchase, 6));

        $this->actingAs($admin)
            ->post('/purchase-returns', $this->payload($purchase, 6))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(1, PurchaseReturn::count());
        $this->assertSame(4, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_purchase_with_goods_back_can_no_longer_be_voided(): void
    {
        $purchase = $this->buy(10);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/purchase-returns', $this->payload($purchase, 3));

        $this->actingAs($admin)
            ->post("/purchases/{$purchase->id}/void", ['reason' => 'Too late.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('posted', $purchase->fresh()->status->value);
        $this->assertStockMatchesItsLayers();
    }

    public function test_the_same_form_sent_twice_makes_one_return(): void
    {
        $purchase = $this->buy(10);
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $payload = $this->payload($purchase, 10, ['idempotency_key' => $key]);

        $this->actingAs($admin)->post('/purchase-returns', $payload);
        $this->actingAs($admin)
            ->post('/purchase-returns', $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, PurchaseReturn::count());
        $this->assertSame(0, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_a_return_needs_its_reason(): void
    {
        $purchase = $this->buy(10);

        $this->actingAs($this->admin())
            ->post('/purchase-returns', $this->payload($purchase, 1, ['reason' => '']))
            ->assertSessionHasErrors('reason');
    }

    public function test_the_form_caps_each_line_at_what_is_still_here(): void
    {
        $purchase = $this->buy(10);

        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => '500.00']],
        ]);

        $this->actingAs($this->admin())
            ->get("/purchase-returns/create?purchase={$purchase->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/Create')
                ->where('purchase.lines.0.quantity', 10)
                // Ten were bought, but only four are still here.
                ->where('purchase.lines.0.returnable', 4));
    }

    public function test_a_purchase_line_is_tied_to_the_batch_it_opened(): void
    {
        $purchase = $this->buy(10, '300.00');

        $line = PurchaseLine::first();

        // Without this link a return would have to guess which batch the
        // goods belong to, and FIFO would give the wrong one.
        $this->assertNotNull($line->inventory_layer_id);
        $this->assertSame(10, $line->layer->quantity_received);
        $this->assertSame('300.00', $line->layer->unit_cost);

        $this->assertStockMatchesItsLayers();
    }

    public function test_staff_may_record_one(): void
    {
        $purchase = $this->buy(10);
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->post('/purchase-returns', $this->payload($purchase, 1))
            ->assertRedirect();

        $this->assertSame(1, PurchaseReturn::count());
    }
}
