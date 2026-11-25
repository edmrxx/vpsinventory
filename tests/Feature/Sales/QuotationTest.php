<?php

namespace Tests\Feature\Sales;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Exceptions\QuotationException;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\SalePoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 28: quotations.
 *
 * A price offered to a customer until a date. Nothing moves or is reserved.
 * It becomes a sale on the sale form without typing again, at the quoted
 * prices, with the stock checked when the sale posts; an expired one is
 * quoted again at today's prices; an open one may be cancelled with a
 * reason.
 */
class QuotationTest extends TestCase
{
    use RefreshDatabase;

    protected User $staff;

    protected Customer $customer;

    protected Customer $walkIn;

    protected Product $paint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->customer = $this->unaudited(fn () => Customer::factory()->create(['name' => 'Santos Builders']));
        $this->paint = $this->unaudited(fn () => Product::factory()->create(['sku' => 'PNT-1G', 'selling_price' => 500]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->paint->id, 'quantity' => 10, 'unit_cost' => 300],
        ], Carbon::today()->subDay());

        $this->actingAs($this->staff);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function quote(array $overrides = []): Quotation
    {
        $this->post('/quotations', array_merge([
            'customer_id' => $this->customer->id,
            'attention' => 'Engr. Santos',
            'valid_until' => Carbon::today()->addDays(15)->toDateString(),
            'discount' => '100.00',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 4, 'unit_price' => '480.00']],
        ], $overrides))->assertSessionHasNoErrors();

        return Quotation::latest('id')->firstOrFail();
    }

    /** The sale form, as the browser posts it from a quotation. */
    protected function sellFrom(Quotation $quotation, array $overrides = [])
    {
        return $this->post('/sales', array_merge([
            'quotation_id' => $quotation->id,
            'customer_id' => $quotation->customer_id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => $quotation->discount,
            'payments' => [['payment_method_id' => PaymentMethod::cash()->id, 'amount' => $quotation->total_amount]],
            'lines' => $quotation->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'quantity' => $line->unit_quantity,
                'unit_price' => $line->unit_price,
            ])->all(),
        ], $overrides));
    }

    /* ------------------------------------------------------------- Making */

    public function test_a_quotation_is_dated_priced_and_moves_nothing(): void
    {
        $quotation = $this->quote();

        $this->assertSame('QT-'.now()->year.'-0001', $quotation->reference);
        $this->assertSame(QuotationStatus::Open, $quotation->status);
        $this->assertSame(Carbon::today()->toDateString(), $quotation->quote_date->toDateString());
        $this->assertSame(Carbon::today()->addDays(15)->toDateString(), $quotation->valid_until->toDateString());
        $this->assertSame('1920.00', $quotation->subtotal);
        $this->assertSame('100.00', $quotation->discount);
        $this->assertSame('1820.00', $quotation->total_amount);
        $this->assertSame('Engr. Santos', $quotation->attention);

        // Nothing left the shelf, and nothing is set aside.
        $this->assertSame(10, $this->paint->fresh()->on_hand);

        $this->assertTrue(AuditTrail::where('action', AuditAction::Create->value)
            ->where('module', 'Quotations')->exists());
    }

    public function test_the_validity_defaults_from_the_setting(): void
    {
        $this->get('/quotations/create')
            ->assertInertia(fn ($page) => $page->where('validUntil', Carbon::today()->addDays(15)->toDateString()));
    }

    public function test_the_form_is_checked(): void
    {
        $this->post('/quotations', ['customer_id' => '', 'valid_until' => '', 'lines' => []])
            ->assertSessionHasErrors(['customer_id', 'valid_until', 'lines']);

        $this->post('/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::yesterday()->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasErrors('valid_until');

        $this->post('/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->toDateString(),
            'discount' => '600.00',
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasErrors('discount');

        // More than is on hand is fine: a quotation reserves nothing.
        $this->post('/quotations', [
            'customer_id' => $this->customer->id,
            'valid_until' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 50, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();
    }

    public function test_the_same_form_sent_twice_makes_one_quotation(): void
    {
        $key = (string) Str::uuid();
        $first = $this->quote(['idempotency_key' => $key]);

        $this->post('/quotations', [
            'customer_id' => $this->customer->id,
            'attention' => 'Engr. Santos',
            'valid_until' => Carbon::today()->addDays(15)->toDateString(),
            'discount' => '100.00',
            'idempotency_key' => $key,
            'lines' => [['product_id' => $this->paint->id, 'quantity' => 4, 'unit_price' => '480.00']],
        ])->assertRedirect("/quotations/{$first->id}");

        $this->assertDatabaseCount('quotations', 1);
    }

    /* ------------------------------------------------------- Becoming a sale */

    public function test_the_sale_form_opens_filled_from_the_quotation(): void
    {
        $quotation = $this->quote();

        $this->get("/sales/create?quotation={$quotation->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fromQuotation.id', $quotation->id)
                ->where('fromQuotation.customer_id', $this->customer->id)
                ->where('fromQuotation.discount', '100.00')
                ->where('fromQuotation.lines.0.quantity', 4)
                ->where('fromQuotation.lines.0.unit_price', '480.00')
                ->has('fromQuotation.short', 0));
    }

    public function test_a_quotation_becomes_a_sale_at_the_quoted_price_and_is_accepted(): void
    {
        // The price went up after the quotation was made.
        $quotation = $this->quote();
        $this->unaudited(fn () => $this->paint->update(['selling_price' => 550]));

        $this->sellFrom($quotation->load('lines'))->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame('1820.00', $sale->total_amount);
        $this->assertSame('480.00', $sale->lines->first()->unit_price);
        $this->assertSame(6, $this->paint->fresh()->on_hand);

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Accepted, $quotation->status);
        $this->assertSame($sale->id, (int) $quotation->sale_id);
        $this->assertNotNull($quotation->accepted_at);

        // The sale names it, and it cannot be sold twice.
        $this->get("/sales/{$sale->id}")
            ->assertInertia(fn ($page) => $page->where('sale.quotation.reference', $quotation->reference));

        $this->sellFrom($quotation)->assertSessionHasErrors('quotation_id');
        $this->get("/sales/create?quotation={$quotation->id}")->assertRedirect('/sales/create');
    }

    public function test_stock_is_checked_again_at_checkout(): void
    {
        $quotation = $this->quote(['lines' => [['product_id' => $this->paint->id, 'quantity' => 12, 'unit_price' => '480.00']], 'discount' => '0']);

        // The sale form says it is short before anybody presses Save.
        $this->get("/sales/create?quotation={$quotation->id}")
            ->assertInertia(fn ($page) => $page->has('fromQuotation.short', 1));

        $this->sellFrom($quotation->load('lines'))->assertSessionHasErrors();

        $this->assertSame(QuotationStatus::Open, $quotation->fresh()->status);
        $this->assertSame(10, $this->paint->fresh()->on_hand);
    }

    public function test_an_expired_quotation_cannot_become_a_sale(): void
    {
        $quotation = $this->quote();
        $quotation->forceFill(['valid_until' => Carbon::yesterday()])->save();

        $this->get("/quotations/{$quotation->id}")
            ->assertInertia(fn ($page) => $page
                ->where('quotation.status', 'expired')
                ->where('can.sell', false)
                ->where('can.requote', true));

        $this->sellFrom($quotation->load('lines'))->assertSessionHasErrors('quotation_id');

        // And the poster refuses it under the lock, whatever the form said.
        $this->expectException(QuotationException::class);
        app(SalePoster::class)->post([
            'quotation_id' => $quotation->id,
            'customer_id' => $this->customer->id,
            'sale_date' => Carbon::today(),
        ], [['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '480.00']]);
    }

    public function test_a_quotation_is_sold_only_to_its_own_customer(): void
    {
        $quotation = $this->quote();

        $this->sellFrom($quotation->load('lines'), ['customer_id' => $this->walkIn->id])
            ->assertSessionHasErrors('customer_id');
    }

    public function test_quoting_again_uses_todays_prices(): void
    {
        $box = $this->unaudited(fn () => Unit::factory()->create(['code' => 'box']));
        $this->unaudited(fn () => ProductUnit::create(['product_id' => $this->paint->id, 'unit_id' => $box->id, 'factor' => 4]));

        $quotation = $this->quote(['lines' => [
            ['product_id' => $this->paint->id, 'quantity' => 2, 'unit_price' => '480.00'],
            ['product_id' => $this->paint->id, 'unit_id' => $box->id, 'quantity' => 1, 'unit_price' => '1900.00'],
        ], 'discount' => '0']);

        $this->unaudited(fn () => $this->paint->update(['selling_price' => 520]));

        $this->get("/quotations/create?from={$quotation->id}")
            ->assertInertia(fn ($page) => $page
                ->where('customerId', $this->customer->id)
                ->where('fromQuotation.lines.0.unit_price', '520.00')
                // The box has no price of its own: 4 × the piece price.
                ->where('fromQuotation.lines.1.unit_price', '2080.00')
                ->has('fromQuotation.dropped', 0));
    }

    /* ---------------------------------------------------------- Cancelling */

    public function test_an_open_quotation_is_cancelled_with_a_reason_once(): void
    {
        $quotation = $this->quote();

        $this->post("/quotations/{$quotation->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/quotations/{$quotation->id}/cancel", ['reason' => 'Customer bought elsewhere'])->assertSessionHasNoErrors();

        $quotation->refresh();
        $this->assertSame(QuotationStatus::Cancelled, $quotation->status);
        $this->assertSame('Customer bought elsewhere', $quotation->cancel_reason);
        $this->assertTrue(AuditTrail::where('action', AuditAction::Cancel->value)->exists());

        $this->post("/quotations/{$quotation->id}/cancel", ['reason' => 'Again'])->assertForbidden();
        $this->sellFrom($quotation->load('lines'))->assertSessionHasErrors('quotation_id');
    }

    /* --------------------------------------------------------------- Shape */

    public function test_a_quotation_is_never_edited_or_deleted(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'quotations')) {
                continue;
            }

            $this->assertEmpty(array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']));
            $this->assertStringNotContainsString('edit', $route->uri());
        }
    }

    public function test_the_list_tells_live_from_expired(): void
    {
        $live = $this->quote();
        $old = $this->quote();
        $old->forceFill(['valid_until' => Carbon::yesterday()])->save();

        $this->get('/quotations?status=live')
            ->assertInertia(fn ($page) => $page
                ->component('Quotations/Index')
                ->has('quotations.data', 1)
                ->where('quotations.data.0.id', $live->id)
                ->where('quotations.data.0.can.sell', true)
                ->where('live', 1));

        $this->get('/quotations?status=expired')
            ->assertInertia(fn ($page) => $page
                ->has('quotations.data', 1)
                ->where('quotations.data.0.status', 'expired'));
    }

    public function test_a_customer_or_product_on_a_quotation_cannot_be_deleted(): void
    {
        $this->quote();
        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        $this->assertFalse($admin->can('delete', $this->customer->fresh()));
        $this->assertFalse($admin->can('delete', $this->paint->fresh()));
    }

    public function test_somebody_without_the_permission_cannot_quote(): void
    {
        $clerk = $this->unaudited(fn () => User::factory()->withPermissions('quotations.view')->create());

        $this->actingAs($clerk)->get('/quotations')->assertOk();
        $this->actingAs($clerk)->get('/quotations/create')->assertForbidden();
    }
}
