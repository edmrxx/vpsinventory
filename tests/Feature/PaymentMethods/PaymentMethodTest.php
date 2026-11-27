<?php

namespace Tests\Feature\PaymentMethods;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Payment Methods screen: a short list shaped like Units.
 *
 * The rules that matter are about cash. There is one cash method, because
 * there is one drawer; it stays cash and stays on, because the till gives
 * change from it. A method a sale was paid with is never deleted.
 */
class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function cash(): PaymentMethod
    {
        return PaymentMethod::where('kind', 'cash')->firstOrFail();
    }

    protected function gcash(): PaymentMethod
    {
        return PaymentMethod::where('name', 'GCash')->firstOrFail();
    }

    /* ---------------------------------------------------------------- Access */

    public function test_the_list_needs_its_permission(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/payment-methods')
            ->assertForbidden();
    }

    public function test_staff_may_read_the_list_but_not_change_it(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->create());
        $staff->assignRole('Staff');

        $this->actingAs($staff)->get('/payment-methods')->assertOk();

        $this->actingAs($staff)
            ->post('/payment-methods', ['name' => 'Card', 'kind' => 'card', 'requires_reference' => true, 'is_active' => true])
            ->assertForbidden();
    }

    public function test_the_list_shows_every_method_with_what_it_may_do(): void
    {
        $this->actingAs($this->admin())
            ->get('/payment-methods')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PaymentMethods/Index')
                // Cash, GCash, Maya, Bank transfer (phase 16) and Cheque (phase 24.5).
                ->has('methods.data', 5)
                ->where('methods.data.1.name', 'Cash')
                ->where('methods.data.1.is_cash', true)
                // Cash can be neither switched off nor deleted.
                ->where('methods.data.1.can.status', false)
                ->where('methods.data.1.can.delete', false)
                ->has('kinds', 5));
    }

    /* ---------------------------------------------------------------- Create */

    public function test_a_new_method_can_be_added(): void
    {
        $this->actingAs($this->admin())
            ->post('/payment-methods', [
                'name' => '  Card  ',
                'kind' => 'card',
                'requires_reference' => true,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $card = PaymentMethod::where('name', 'Card')->firstOrFail();

        $this->assertSame('card', $card->kind->value);
        $this->assertTrue($card->requires_reference);

        // And it is offered on the next sale.
        $this->assertContains('Card', array_column(PaymentMethod::options(), 'label'));

        // Written to the trail like every master.
        $this->assertTrue(AuditTrail::where('module', 'like', '%Payment%')->exists());
    }

    public function test_a_name_cannot_be_used_twice(): void
    {
        $this->actingAs($this->admin())
            ->post('/payment-methods', ['name' => 'gcash', 'kind' => 'ewallet', 'requires_reference' => true, 'is_active' => true])
            ->assertSessionHasErrors('name');
    }

    public function test_there_is_only_one_cash_method(): void
    {
        $this->actingAs($this->admin())
            ->post('/payment-methods', ['name' => 'Petty cash', 'kind' => 'cash', 'requires_reference' => false, 'is_active' => true])
            ->assertSessionHasErrors('kind');

        $this->assertSame(1, PaymentMethod::where('kind', 'cash')->count());
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/payment-methods', ['name' => 'IOU', 'kind' => 'credit', 'requires_reference' => false, 'is_active' => true])
            ->assertSessionHasErrors('kind');
    }

    /* ---------------------------------------------------------------- Update */

    public function test_the_cash_method_may_be_renamed(): void
    {
        $cash = $this->cash();

        $this->actingAs($this->admin())
            ->put("/payment-methods/{$cash->id}", ['name' => 'Cash on hand', 'kind' => 'cash', 'requires_reference' => false, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame('Cash on hand', $cash->fresh()->name);
    }

    public function test_the_cash_method_stays_cash_and_stays_on(): void
    {
        $cash = $this->cash();

        $this->actingAs($this->admin())
            ->put("/payment-methods/{$cash->id}", ['name' => 'Cash', 'kind' => 'ewallet', 'requires_reference' => false, 'is_active' => false])
            ->assertSessionHasErrors(['kind', 'is_active']);

        $this->assertTrue($cash->fresh()->isCash());
        $this->assertTrue($cash->fresh()->is_active);
    }

    public function test_another_method_cannot_become_a_second_cash(): void
    {
        $gcash = $this->gcash();

        $this->actingAs($this->admin())
            ->put("/payment-methods/{$gcash->id}", ['name' => 'GCash', 'kind' => 'cash', 'requires_reference' => false, 'is_active' => true])
            ->assertSessionHasErrors('kind');
    }

    /* ---------------------------------------------------------------- Status */

    public function test_a_method_can_be_switched_off_and_on(): void
    {
        $gcash = $this->gcash();
        $admin = $this->admin();

        $this->actingAs($admin)->patch("/payment-methods/{$gcash->id}/status")->assertRedirect();
        $this->assertFalse($gcash->fresh()->is_active);
        $this->assertNotContains('GCash', array_column(PaymentMethod::options(), 'label'));

        $this->actingAs($admin)->patch("/payment-methods/{$gcash->id}/status")->assertRedirect();
        $this->assertTrue($gcash->fresh()->is_active);
    }

    public function test_the_cash_method_cannot_be_switched_off(): void
    {
        $cash = $this->cash();

        $this->actingAs($this->admin())
            ->patch("/payment-methods/{$cash->id}/status")
            ->assertForbidden();

        $this->assertTrue($cash->fresh()->is_active);
    }

    /* ---------------------------------------------------------------- Delete */

    public function test_an_unused_method_can_be_deleted(): void
    {
        $maya = PaymentMethod::where('name', 'Maya')->firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/payment-methods/{$maya->id}")
            ->assertRedirect('/payment-methods');

        $this->assertNull($maya->fresh());
    }

    public function test_the_cash_method_cannot_be_deleted(): void
    {
        $cash = $this->cash();

        $this->actingAs($this->admin())->delete("/payment-methods/{$cash->id}")->assertForbidden();

        $this->assertNotNull($cash->fresh());
    }

    public function test_a_method_a_sale_was_paid_with_stays(): void
    {
        $admin = $this->admin();
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $product = $this->unaudited(fn () => Product::factory()->create(['selling_price' => 100]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 50],
        ]);

        $gcash = $this->gcash();

        $this->actingAs($admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => Carbon::today()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '100.00']],
            'payments' => [['payment_method_id' => $gcash->id, 'amount' => '100.00', 'reference' => 'X1']],
        ])->assertRedirect();

        $this->actingAs($admin)->delete("/payment-methods/{$gcash->id}")->assertForbidden();
        $this->assertNotNull($gcash->fresh());

        // The list knows without asking once per row.
        $this->actingAs($admin)
            ->get('/payment-methods')
            ->assertInertia(fn ($page) => $page
                // By name: Bank transfer, Cash, Cheque, GCash, Maya.
                ->where('methods.data.3.name', 'GCash')
                ->where('methods.data.3.payments_count', 1)
                ->where('methods.data.3.can.delete', false));
    }
}
