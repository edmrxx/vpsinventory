<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VAT on the buying side.
 *
 * The two sides of the ledger answer two different questions, and these
 * tests are what keep them apart:
 *
 *   Selling — do *we* charge VAT?       the Tax Settings switch, a view
 *   Buying  — did the supplier charge?  the supplier's own flag, a record
 *
 * You cannot claim input VAT from a supplier who charges none, whatever
 * your own registration says. So a purchase ignores the switch entirely,
 * and the rate it was invoiced at is stamped on it when it is posted: the
 * day a supplier registers for VAT, last year's deliveries from them must
 * not quietly redraw.
 */
class PurchaseVatTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PEN-BLU']));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function settings(): Settings
    {
        return app(Settings::class);
    }

    /** The business itself registers for VAT — the selling side. */
    protected function weAreVatRegistered(): void
    {
        $this->settings()->set('tax.vat_registered', true);
        $this->settings()->set('tax.vat_rate', 12);
    }

    protected function supplier(bool $vat): Supplier
    {
        return $this->unaudited(
            fn () => Supplier::factory()->create(['is_vat_registered' => $vat])
        );
    }

    protected function buyFrom(Supplier $supplier, string $unitCost = '120.00', int $quantity = 12): Purchase
    {
        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $unitCost],
            ],
        ])->assertRedirect();

        return Purchase::latest('id')->firstOrFail();
    }

    /* ------------------------------------------- The supplier decides, not us */

    public function test_a_vat_supplier_splits_even_with_our_own_switch_off(): void
    {
        // The whole point. The business is NOT VAT-registered, and the
        // delivery still shows VAT, because the supplier charged it.
        $this->assertFalse((bool) settings('tax.vat_registered'));

        $purchase = $this->buyFrom($this->supplier(true));

        $this->assertSame(12.0, (float) $purchase->vat_rate);
        $this->assertSame(['net' => '1285.71', 'vat' => '154.29'], $purchase->vatSplit());
    }

    public function test_a_non_vat_supplier_does_not_split_even_with_our_switch_on(): void
    {
        // The other half, and the one that changes today's behaviour. We
        // are registered, but this supplier is not, so there is no input
        // VAT to claim and nothing to show.
        $this->weAreVatRegistered();

        $purchase = $this->buyFrom($this->supplier(false));

        $this->assertSame(0.0, (float) $purchase->vat_rate);
        $this->assertFalse($purchase->isVatable());

        $payload = $this->actingAs($this->admin())
            ->getJson("/purchases/{$purchase->id}/preview")
            ->assertOk()
            ->json('purchase');

        $this->assertSame('0.00', $payload['vat_rate']);
        $this->assertSame('1440.00', $payload['net_amount']);
        $this->assertSame('0.00', $payload['vat_amount']);
    }

    /* -------------------------------------------------------- It is a record */

    public function test_changing_the_supplier_afterwards_leaves_the_delivery_alone(): void
    {
        $supplier = $this->supplier(true);
        $purchase = $this->buyFrom($supplier);

        $this->assertSame(12.0, (float) $purchase->vat_rate);

        // The supplier deregisters. What they invoiced last month does not
        // change because of what they are today.
        $this->actingAs($this->admin())
            ->patch("/suppliers/{$supplier->id}/vat")
            ->assertRedirect();

        $this->assertFalse($supplier->refresh()->is_vat_registered);
        $this->assertSame(12.0, (float) $purchase->fresh()->vat_rate);

        $payload = $this->actingAs($this->admin())
            ->getJson("/purchases/{$purchase->id}/preview")
            ->assertOk()
            ->json('purchase');

        $this->assertSame('12.00', $payload['vat_rate']);
        $this->assertSame('154.29', $payload['vat_amount']);
    }

    public function test_turning_our_own_switch_on_and_off_never_moves_a_purchase(): void
    {
        $purchase = $this->buyFrom($this->supplier(true));

        $before = $purchase->getAttributes();

        $this->weAreVatRegistered();
        $this->settings()->set('tax.vat_registered', false);

        $this->assertSame($before, $purchase->fresh()->getAttributes());
    }

    /* ---------------------------------------------------- The two sides apart */

    public function test_a_sale_still_follows_our_own_switch(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $sell = function (): Sale {
            $this->actingAs($this->admin())->post('/sales', [
                'customer_id' => $this->customer->id,
                'sale_date' => Carbon::today()->toDateString(),
                'discount' => 0,
                'idempotency_key' => (string) Str::uuid(),
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00'],
                ],
            ])->assertRedirect();

            return Sale::latest('id')->firstOrFail();
        };

        $plain = $sell();

        $this->assertSame(['net' => '1000.00', 'vat' => '0.00'], $plain->vatSplit());

        $this->weAreVatRegistered();

        // The same sale, now that we are registered. Selling is a view, so
        // it follows the switch both ways.
        $this->assertSame(['net' => '892.86', 'vat' => '107.14'], $plain->fresh()->vatSplit());
    }

    /* ------------------------------------------------------------ The returns */

    public function test_a_purchase_return_follows_the_delivery_it_undoes(): void
    {
        $supplier = $this->supplier(true);
        $purchase = $this->buyFrom($supplier);

        $this->actingAs($this->admin())->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 3]],
        ])->assertRedirect();

        $return = PurchaseReturn::latest('id')->firstOrFail();

        // The switch is off, so if the return read that instead of its own
        // purchase it would show nothing beside a delivery that shows VAT.
        $payload = $this->actingAs($this->admin())
            ->getJson("/purchase-returns/{$return->id}/preview")
            ->assertOk()
            ->json('purchaseReturn');

        $this->assertSame('12.00', $payload['vat_rate']);
        $this->assertNotSame('0.00', $payload['vat_amount']);
    }

    public function test_a_return_against_a_non_vat_delivery_shows_nothing(): void
    {
        $this->weAreVatRegistered();

        $purchase = $this->buyFrom($this->supplier(false));

        $this->actingAs($this->admin())->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Short delivery',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 2]],
        ])->assertRedirect();

        $payload = $this->actingAs($this->admin())
            ->getJson('/purchase-returns/'.PurchaseReturn::latest('id')->value('id').'/preview')
            ->assertOk()
            ->json('purchaseReturn');

        $this->assertSame('0.00', $payload['vat_rate']);
    }

    /* ---------------------------------------------------------------- The API */

    public function test_the_api_follows_the_same_two_rules(): void
    {
        $admin = $this->admin();

        $vatPurchase = $this->buyFrom($this->supplier(true));
        $plainPurchase = $this->buyFrom($this->supplier(false));

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/purchases/{$vatPurchase->id}")
            ->assertOk()
            ->assertJsonPath('data.vat_rate', '12.00')
            ->assertJsonPath('data.vat_amount', '154.29');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/purchases/{$plainPurchase->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.vat_rate');
    }

    /* -------------------------------------------------------- The entry screen */

    public function test_the_new_purchase_form_is_told_which_suppliers_charge_vat(): void
    {
        $vat = $this->supplier(true);

        $this->actingAs($this->admin())
            ->get('/purchases/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suppliers.0.value', $vat->id)
                ->where('suppliers.0.is_vat_registered', true));
    }
}
