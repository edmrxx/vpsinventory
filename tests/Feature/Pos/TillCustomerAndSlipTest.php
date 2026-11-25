<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing a customer at the till, and the slip it prints.
 *
 * A named customer names the sale and nothing more: it is paid in full at
 * the counter like a walk-in sale, because credit is a later stage. The
 * slip is drawn on the screen from the sale's own detail payload, so what
 * is tested here is what reaches the page for it.
 */
class TillCustomerAndSlipTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['selling_price' => 500]));
        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);
    }

    protected function cashier(): User
    {
        return $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );
    }

    /* ------------------------------------------------------------- Customer */

    public function test_the_till_offers_every_active_customer_with_walk_in_first(): void
    {
        $this->unaudited(function () {
            Customer::factory()->create(['name' => 'Aling Nena Hardware', 'is_active' => true]);
            Customer::factory()->create(['name' => 'Zed Builders', 'is_active' => false]);
        });

        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->where('customer.id', $this->walkIn->id)
                ->has('customers', 2)
                ->where('customers.0.label', 'Walk-in Customer')
                ->where('customers.0.walk_in', true)
                ->where('customers.1.label', 'Aling Nena Hardware'));
    }

    public function test_a_till_sale_can_be_made_out_to_a_named_customer(): void
    {
        $regular = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Aling Nena Hardware']));

        $this->actingAs($this->cashier())
            ->post('/sales', [
                'customer_id' => $regular->id,
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00']],
                'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '1000.00']],
            ])
            ->assertRedirect(route('pos', ['location' => Location::default()->id]))
            ->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame($regular->id, $sale->customer_id);
        // Still paid in full at the counter: naming a customer is not credit.
        $this->assertEquals(1000.0, (float) $sale->payments()->sum('amount'));
    }

    public function test_a_named_customer_still_has_to_pay_in_full(): void
    {
        $regular = $this->unaudited(fn () => Customer::factory()->create());

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/sales', [
                'customer_id' => $regular->id,
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => '500.00']],
                'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => '400.00']],
            ])
            ->assertSessionHasErrors('payments.0.amount');

        $this->assertSame(0, Sale::count());
    }

    /* ----------------------------------------------------------------- Slip */

    public function test_the_till_is_told_which_sale_it_just_posted(): void
    {
        $this->actingAs($this->cashier())
            ->post('/sales', [
                'customer_id' => $this->walkIn->id,
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '500.00']],
            ])
            ->assertRedirect(route('pos', ['location' => Location::default()->id]));

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertSame(['id' => $sale->id, 'reference' => $sale->reference], session('sale'));
    }

    public function test_every_screen_knows_how_to_print_a_slip(): void
    {
        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->where('slip.width', '80')
                ->where('slip.footer', 'Thank you for your purchase!'));

        app(Settings::class)->setMany([
            'documents.slip_width' => '58',
            'documents.slip_footer' => 'Salamat po!',
        ]);

        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->where('slip.width', '58')
                ->where('slip.footer', 'Salamat po!'));
    }

    public function test_only_the_two_roll_widths_are_accepted(): void
    {
        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        $this->actingAs($admin)
            ->from('/settings/documents')
            ->put('/settings/documents', ['values' => array_merge(
                collect(app(Settings::class)->groupByKey('documents'))
                    ->mapWithKeys(fn ($value, $key) => [substr($key, strlen('documents.')) => $value])
                    ->all(),
                ['slip_width' => '70'],
            )])
            ->assertSessionHasErrors();

        $this->assertSame('80', (string) app(Settings::class)->get('documents.slip_width'));
    }
}
