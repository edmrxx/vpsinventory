<?php

namespace Tests\Feature\Inventory;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\Settings;
use App\Support\Vat;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The VAT switch.
 *
 * Every price in this system is VAT-inclusive, so the tax is taken out of a
 * total rather than added to it.
 *
 * The switch is a **view for comparing** VAT against non-VAT, not a record
 * of how a document was traded. So every sale and sales return follows it,
 * old documents included, and nothing is stored: turning it on and off
 * again must leave the database exactly as it was.
 *
 * **Purchases are not here.** The switch says whether *we* charge VAT;
 * whether a *supplier* charges it is the supplier's own flag, stamped on
 * the delivery when it is posted. `PurchaseVatTest` holds that side, and
 * these tests use sales so the two rules cannot be confused.
 *
 * What is deliberately *not* done, and so not tested: the stock valuation,
 * the cost of goods sold and the reports all still carry VAT.
 */
class VatTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Supplier $supplier;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PEN-BLU']));
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
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

    protected function registerForVat(float $rate = 12): void
    {
        $this->settings()->set('tax.vat_registered', true);
        $this->settings()->set('tax.vat_rate', $rate);
    }

    protected function stock(int $quantity, float $cost = 100): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    protected function buy(array $lines, ?User $as = null): Purchase
    {
        $this->actingAs($as ?? $this->admin())->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ])->assertRedirect();

        return Purchase::latest('id')->firstOrFail();
    }

    protected function sell(array $lines, float $discount = 0, ?User $as = null): Sale
    {
        $this->actingAs($as ?? $this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => $discount,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ])->assertRedirect();

        return Sale::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------------ The sums */

    public function test_the_net_and_the_vat_always_add_back_to_the_total(): void
    {
        // 1,440 is the figure from the screen the client pointed at.
        $this->assertSame('1285.71', Vat::net(1440, 12));
        $this->assertSame('154.29', Vat::tax(1440, 12));
        $this->assertSame(1440.0, round((float) Vat::net(1440, 12) + (float) Vat::tax(1440, 12), 2));

        // Awkward amounts too: the VAT is what is left, never rounded apart.
        foreach ([0.01, 7.77, 42.14, 99.99, 1000.02, 1234.56, 1000000.05] as $gross) {
            $this->assertSame(
                round($gross, 2),
                round((float) Vat::net($gross, 12) + (float) Vat::tax($gross, 12), 2),
                "The halves of {$gross} did not add back up."
            );
        }
    }

    public function test_a_net_landing_on_half_a_centavo_rounds_up(): void
    {
        // The two amounts that caught float division out: the browser said
        // 37.62 and 892.87 while the server said 37.63 and 892.88. Both
        // sides now work in whole centavos, and `vatSplit.test.js` holds
        // the same two cases.
        $this->assertSame('37.63', Vat::net('42.14', 12));
        $this->assertSame('4.51', Vat::tax('42.14', 12));

        $this->assertSame('892.88', Vat::net('1000.02', 12));
        $this->assertSame('107.14', Vat::tax('1000.02', 12));
    }

    public function test_no_vat_is_taken_out_when_the_rate_is_zero(): void
    {
        $this->assertSame('1440.00', Vat::net(1440, 0));
        $this->assertSame('0.00', Vat::tax(1440, 0));
    }

    public function test_the_rate_is_zero_until_the_business_registers(): void
    {
        $this->assertSame(0.0, Vat::currentRate());

        $this->registerForVat();

        $this->assertSame(12.0, Vat::currentRate());

        // A rate set while the switch is off still counts for nothing.
        $this->settings()->set('tax.vat_registered', false);

        $this->assertSame(0.0, Vat::currentRate());
    }

    /* --------------------------------------------------------- The stamp */

    public function test_a_document_shows_no_split_while_the_switch_is_off(): void
    {
        $this->stock(50);

        $sale = $this->sell([
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_price' => '120.00'],
        ]);

        $payload = $this->actingAs($this->admin())
            ->getJson("/sales/{$sale->id}/preview")
            ->assertOk()
            ->json('sale');

        $this->assertSame('0.00', $payload['vat_rate']);
        $this->assertSame('1440.00', $payload['net_amount']);
        $this->assertSame('0.00', $payload['vat_amount']);
    }

    public function test_a_document_posted_before_the_switch_splits_once_it_is_on(): void
    {
        // The point of the whole feature: the client sells non-VAT, then
        // turns the switch on to see the same trading the other way.
        $this->stock(50);

        $old = $this->sell([
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_price' => '120.00'],
        ]);

        $this->registerForVat();

        $payload = $this->actingAs($this->admin())
            ->getJson("/sales/{$old->id}/preview")
            ->assertOk()
            ->json('sale');

        $this->assertSame('12.00', $payload['vat_rate']);
        $this->assertSame('1285.71', $payload['net_amount']);
        $this->assertSame('154.29', $payload['vat_amount']);
    }

    public function test_turning_the_switch_on_and_off_changes_nothing_in_the_database(): void
    {
        $this->stock(50);

        $purchase = $this->buy([
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_cost' => '120.00'],
        ]);
        $sale = $this->sell([
            ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00'],
        ]);

        $before = [$purchase->getAttributes(), $sale->getAttributes()];

        $this->registerForVat();
        $this->settings()->set('tax.vat_registered', false);

        $this->assertSame($before, [
            $purchase->fresh()->getAttributes(),
            $sale->fresh()->getAttributes(),
        ]);
    }

    /* ------------------------------------------------------- The payload */

    public function test_a_sale_takes_its_vat_from_the_total_after_the_discount(): void
    {
        $this->registerForVat();
        $this->stock(50);

        $sale = $this->sell(
            [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00']],
            discount: 100,
        );

        $this->assertSame(1000.0, (float) $sale->subtotal);
        $this->assertSame(900.0, (float) $sale->total_amount);

        // 900 / 1.12, not 1000 / 1.12. VAT follows the money.
        $this->assertSame(['net' => '803.57', 'vat' => '96.43'], $sale->vatSplit());

        $payload = $this->actingAs($this->admin())
            ->getJson("/sales/{$sale->id}/preview")
            ->assertOk()
            ->json('sale');

        $this->assertSame('803.57', $payload['net_amount']);
        $this->assertSame('96.43', $payload['vat_amount']);

        // The lines are split on their own gross totals, so with a discount
        // they will not add up to the document. That is correct.
        $this->assertSame('892.86', $payload['lines'][0]['net_total']);
        $this->assertSame('107.14', $payload['lines'][0]['vat_total']);
    }

    public function test_a_line_is_split_on_its_total_and_not_per_unit(): void
    {
        $this->registerForVat();
        $this->stock(50);

        // 12 × 120 = 1,440. Splitting each unit and multiplying would give
        // 107.14 × 12 = 1,285.68, which is three centavos short.
        $sale = $this->sell([
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_price' => '120.00'],
        ]);

        $line = $this->actingAs($this->admin())
            ->getJson("/sales/{$sale->id}/preview")
            ->assertOk()
            ->json('sale.lines.0');

        $this->assertSame('1285.71', $line['net_total']);
        $this->assertSame('154.29', $line['vat_total']);
        $this->assertSame(1440.0, round((float) $line['net_total'] + (float) $line['vat_total'], 2));
    }

    /* ---------------------------------------------------------- The till */

    public function test_a_sale_rung_up_at_the_till_splits_as_well(): void
    {
        $this->registerForVat();
        $this->stock(50);

        // The till has no route of its own: it posts to `/sales` like the
        // form does. A counter receipt showing no VAT while the office copy
        // shows it would be the worst of both.
        $cashier = $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );

        $this->actingAs($cashier)->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '500.00'],
            ],
        ])->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(['net' => '446.43', 'vat' => '53.57'], $sale->vatSplit());
    }

    public function test_the_till_screen_is_told_the_rate(): void
    {
        $this->registerForVat();

        $cashier = $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tax.vat_registered', true)
                ->where('tax.vat_rate', 12)
            );
    }

    /* ------------------------------------------------------------ The API */

    public function test_the_api_follows_the_switch_too(): void
    {
        $this->registerForVat();
        $this->stock(50);

        $sale = $this->sell([
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_price' => '120.00'],
        ]);

        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonPath('data.vat_rate', '12.00')
            ->assertJsonPath('data.net_amount', '1285.71')
            ->assertJsonPath('data.vat_amount', '154.29')
            ->assertJsonPath('data.lines.0.net_total', '1285.71');

        // The same document, with the switch off: the fields are gone, so
        // a phone can tell "no VAT" from "nobody told me".
        $this->settings()->set('tax.vat_registered', false);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.vat_rate')
            ->assertJsonMissingPath('data.net_amount')
            ->assertJsonMissingPath('data.lines.0.net_total');
    }

    public function test_an_adjustment_never_splits(): void
    {
        $this->registerForVat();
        $this->stock(50);

        $adjustment = \App\Models\InventoryAdjustment::latest('id')->firstOrFail();

        // Nothing was bought or sold, so there is no VAT to show.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/adjustments/{$adjustment->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.vat_rate')
            ->assertJsonMissingPath('data.net_amount');
    }

    /* ------------------------------------------------------- The settings */

    public function test_the_switch_is_a_setting_an_administrator_can_change(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/tax')
            ->assertOk();

        $this->actingAs($this->admin())
            ->put('/settings/tax', ['values' => ['vat_registered' => true, 'vat_rate' => 12]])
            ->assertRedirect();

        $this->assertTrue((bool) settings('tax.vat_registered'));
        $this->assertSame(12.0, Vat::currentRate());
    }
}
