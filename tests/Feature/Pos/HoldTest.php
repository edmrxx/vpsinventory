<?php

namespace Tests\Feature\Pos;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hold and recall at the till.
 *
 * The rule that matters: a hold is not a sale. It takes no number, moves no
 * stock and reserves nothing. It is the basket, kept so any till can bring
 * it back — and the sale it becomes is posted like any other.
 */
class HoldTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create([
            'name' => 'Ball Valve 1/2"',
            'selling_price' => 500,
        ]));

        $this->walkIn = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);
    }

    protected function cashier(): User
    {
        return $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create(['name' => 'Ana Cashier'])
        );
    }

    /** @return array<string, mixed> */
    protected function basket(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->walkIn->id,
            'note' => 'Mang Ben, getting his truck',
            'discount' => 0,
            'lines' => [[
                'product_id' => $this->product->id,
                'unit_id' => null,
                'quantity' => 3,
                'unit_price' => '500.00',
            ]],
        ], $overrides);
    }

    protected function hold(?User $user = null, array $overrides = []): HeldSale
    {
        $this->actingAs($user ?? $this->cashier())
            ->from('/pos')
            ->post('/pos/holds', $this->basket($overrides))
            ->assertRedirect('/pos')
            ->assertSessionHasNoErrors();

        return HeldSale::latest('id')->firstOrFail();
    }

    /* --------------------------------------------------------------- Access */

    public function test_holding_needs_the_till(): void
    {
        $clerk = $this->unaudited(
            fn () => User::factory()->withPermissions('sales.create', 'sales.view')->create()
        );

        $this->actingAs($clerk)->post('/pos/holds', $this->basket())->assertForbidden();
    }

    public function test_holding_needs_the_right_to_sell(): void
    {
        $looker = $this->unaudited(fn () => User::factory()->withPermissions('kiosk.use')->create());

        $this->actingAs($looker)->post('/pos/holds', $this->basket())->assertForbidden();

        $hold = $this->unaudited(fn () => HeldSale::create([
            'lines' => [['product_id' => $this->product->id, 'unit_id' => null, 'quantity' => '1', 'unit_price' => '500.00']],
        ]));

        $this->actingAs($looker)->post("/pos/holds/{$hold->id}/recall")->assertForbidden();
        $this->actingAs($looker)->delete("/pos/holds/{$hold->id}")->assertForbidden();
        $this->assertNotNull($hold->fresh());
    }

    /* -------------------------------------------------------------- Holding */

    public function test_a_hold_keeps_the_basket_and_moves_nothing(): void
    {
        $movements = InventoryMovement::count();

        $hold = $this->hold();

        $this->assertSame('Mang Ben, getting his truck', $hold->note);
        $this->assertSame($this->walkIn->id, $hold->customer_id);
        $this->assertSame('1500.00', $hold->total_amount);
        $this->assertSame('3', $hold->lines[0]['quantity']);
        $this->assertSame('500.00', $hold->lines[0]['unit_price']);

        // Not a sale: no document, no number, no stock gone, nothing set aside.
        $this->assertSame(0, Sale::count());
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(20, $this->product->refresh()->on_hand);

        // Written to the trail.
        $this->assertTrue(AuditTrail::where('module', 'Held Sales')->where('action', 'create')->exists());
    }

    public function test_a_part_quantity_comes_back_exactly(): void
    {
        $hold = $this->hold(overrides: ['lines' => [[
            'product_id' => $this->product->id,
            'unit_id' => null,
            'quantity' => '2.5',
            'unit_price' => '18.35',
        ]]]);

        $this->assertSame('2.5', $hold->lines[0]['quantity']);
        // 2.5 at 18.35 is 45.875, half up to the centavo.
        $this->assertSame('45.88', $hold->total_amount);
    }

    public function test_the_discount_is_kept_and_never_more_than_the_goods(): void
    {
        $hold = $this->hold(overrides: ['discount' => 100]);

        $this->assertSame('100.00', $hold->discount);
        $this->assertSame('1400.00', $hold->total_amount);
    }

    public function test_an_empty_counter_cannot_be_held(): void
    {
        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/pos/holds', $this->basket(['lines' => []]))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, HeldSale::count());
    }

    public function test_a_customer_switched_off_cannot_be_named(): void
    {
        $gone = $this->unaudited(fn () => Customer::factory()->create(['is_active' => false]));

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/pos/holds', $this->basket(['customer_id' => $gone->id]))
            ->assertSessionHasErrors('customer_id');
    }

    public function test_the_number_of_holds_is_bounded(): void
    {
        $this->unaudited(function () {
            foreach (range(1, HeldSale::MAX_OPEN) as $n) {
                HeldSale::create([
                    'note' => "Hold {$n}",
                    'lines' => [['product_id' => $this->product->id, 'unit_id' => null, 'quantity' => '1', 'unit_price' => '500.00']],
                ]);
            }
        });

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/pos/holds', $this->basket())
            ->assertSessionHasErrors('lines');

        $this->assertSame(HeldSale::MAX_OPEN, HeldSale::count());
    }

    /* ---------------------------------------------------------- The list */

    public function test_the_till_lists_every_hold_with_who_and_how_much(): void
    {
        $other = $this->cashier();
        $this->hold($other);

        // Another cashier on the same tablet sees it: the customer comes
        // back to the counter, not to a person.
        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->has('holds', 1)
                ->where('holds.0.note', 'Mang Ben, getting his truck')
                ->where('holds.0.customer', 'Walk-in Customer')
                ->where('holds.0.items', 3)
                ->where('holds.0.lines', 1)
                ->where('holds.0.total', '1500.00')
                ->where('holds.0.held_by', 'Ana Cashier'));
    }

    /* ------------------------------------------------------------ Recalling */

    public function test_recalling_hands_the_basket_back_and_takes_the_hold_away(): void
    {
        $hold = $this->hold();

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post("/pos/holds/{$hold->id}/recall")
            ->assertRedirect('/pos')
            ->assertSessionHas('recalled', fn ($recalled) => $recalled['id'] === $hold->id
                && $recalled['customer_id'] === $this->walkIn->id
                && $recalled['lines'][0]['product_id'] === $this->product->id
                && $recalled['lines'][0]['quantity'] === '3');

        $this->assertNull($hold->fresh());

        $this->assertTrue(
            AuditTrail::where('module', 'Held Sales')
                ->where('description', 'like', 'Recalled held sale%')
                ->exists()
        );
    }

    public function test_two_tills_cannot_both_recall_the_same_hold(): void
    {
        $hold = $this->hold();
        $cashier = $this->cashier();

        $this->actingAs($cashier)->from('/pos')->post("/pos/holds/{$hold->id}/recall")->assertSessionHasNoErrors();

        $this->actingAs($cashier)
            ->from('/pos')
            ->post("/pos/holds/{$hold->id}/recall")
            ->assertSessionHasErrors('hold')
            ->assertSessionMissing('recalled');
    }

    public function test_a_recalled_sale_is_posted_like_any_other(): void
    {
        $hold = $this->hold();
        $cashier = $this->cashier();

        $this->actingAs($cashier)->from('/pos')->post("/pos/holds/{$hold->id}/recall");

        $this->actingAs($cashier)
            ->post('/sales', [
                'customer_id' => $this->walkIn->id,
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'unit_price' => '500.00',
                ]],
            ])
            ->assertRedirect(route('pos', ['location' => Location::default()->id]));

        // Only now does the stock move.
        $this->assertSame(17, $this->product->refresh()->on_hand);
        $this->assertSame(1, Sale::count());
    }

    /* ----------------------------------------------------------- Discarding */

    public function test_a_hold_can_be_discarded(): void
    {
        $hold = $this->hold();

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->delete("/pos/holds/{$hold->id}")
            ->assertRedirect('/pos')
            ->assertSessionHas('success');

        $this->assertNull($hold->fresh());
        $this->assertSame(20, $this->product->refresh()->on_hand);
        $this->assertTrue(
            AuditTrail::where('module', 'Held Sales')
                ->where('description', 'like', 'Discarded held sale%')
                ->exists()
        );
    }

    public function test_a_product_on_hold_can_still_be_deleted(): void
    {
        $spare = $this->unaudited(fn () => Product::factory()->create());

        $hold = $this->hold(overrides: ['lines' => [[
            'product_id' => $spare->id,
            'unit_id' => null,
            'quantity' => 1,
            'unit_price' => '10.00',
        ]]]);

        // A hold names a product without holding a key to it.
        $this->unaudited(fn () => $spare->delete());

        $this->assertNotNull($hold->fresh());
    }

    public function test_the_reset_empties_the_holds(): void
    {
        $this->hold();

        $this->artisan('data:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, HeldSale::count());
    }
}
