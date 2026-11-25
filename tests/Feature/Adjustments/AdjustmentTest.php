<?php

namespace Tests\Feature\Adjustments;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'increase',
            'adjustment_date' => now()->toDateString(),
            'reason' => 'count',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '300.00'],
            ],
        ], $overrides);
    }

    protected function assertStockMatchesItsLayers(): void
    {
        $this->assertSame([], app(InventoryLedger::class)->reconcile());
    }

    /* ---------------------------------------------------------------- Access */

    public function test_staff_may_look_but_not_record_one(): void
    {
        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        // A decrease is the quietest way to make shrinkage disappear.
        $this->actingAs($staff)->get('/adjustments')->assertOk();
        $this->actingAs($staff)->post('/adjustments', $this->payload())->assertForbidden();
    }

    public function test_an_adjustment_has_no_edit_or_delete_route(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => $r->getName());

        $this->assertNotContains('adjustments.edit', $names);
        $this->assertNotContains('adjustments.update', $names);
        $this->assertNotContains('adjustments.destroy', $names);
    }

    /* --------------------------------------------------------------- Posting */

    public function test_an_increase_opens_a_batch(): void
    {
        $this->actingAs($this->admin())
            ->post('/adjustments', $this->payload())
            ->assertRedirect();

        $adjustment = InventoryAdjustment::first();

        $this->assertStringStartsWith('ADJ-', $adjustment->reference);
        $this->assertSame('increase', $adjustment->kind);
        $this->assertSame('3000.00', $adjustment->total_cost);
        $this->assertSame(10, $this->product->fresh()->on_hand);
        $this->assertSame(1, InventoryLayer::count());

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_decrease_is_costed_by_the_batches_it_eats(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_cost' => '100.00']],
        ]));
        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '200.00']],
        ]));

        // Six out: four at 100, two at 200.
        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'kind' => 'decrease',
            'reason' => 'damaged',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 6]],
        ]));

        $decrease = InventoryAdjustment::where('kind', 'decrease')->first();

        $this->assertSame('800.00', $decrease->total_cost);
        $this->assertSame(8, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_opening_stock_is_offered_as_its_own_kind(): void
    {
        // This is how a business enters what it already had.
        $this->actingAs($this->admin())
            ->post('/adjustments', $this->payload(['kind' => 'opening', 'reason' => 'opening']))
            ->assertRedirect();

        $this->assertSame('opening', InventoryAdjustment::first()->kind);
        $this->assertSame(10, $this->product->fresh()->on_hand);

        $this->assertStockMatchesItsLayers();
    }

    /* ------------------------------------------------------------ Validation */

    public function test_a_decrease_cannot_take_more_than_is_there(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload());

        $this->actingAs($admin)
            ->post('/adjustments', $this->payload([
                'kind' => 'decrease',
                'reason' => 'lost',
                'lines' => [['product_id' => $this->product->id, 'quantity' => 11]],
            ]))
            ->assertSessionHasErrors('lines.0.quantity');

        $this->assertSame(10, $this->product->fresh()->on_hand);

        $this->assertStockMatchesItsLayers();
    }

    public function test_two_decrease_lines_of_one_product_are_counted_together(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload());

        $this->actingAs($admin)
            ->post('/adjustments', $this->payload([
                'kind' => 'decrease',
                'reason' => 'lost',
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 6],
                    ['product_id' => $this->product->id, 'quantity' => 6],
                ],
            ]))
            ->assertSessionHasErrors(['lines.0.quantity', 'lines.1.quantity']);

        $this->assertStockMatchesItsLayers();
    }

    public function test_a_reason_must_be_one_of_the_listed_ones(): void
    {
        $this->actingAs($this->admin())
            ->post('/adjustments', $this->payload(['reason' => 'because']))
            ->assertSessionHasErrors('reason');
    }

    public function test_an_adjustment_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->admin())
            ->post('/adjustments', $this->payload([
                'adjustment_date' => now()->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('adjustment_date');
    }

    /* ----------------------------------------------------------------- Void */

    public function test_voiding_an_increase_takes_the_stock_back_off(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload());
        $adjustment = InventoryAdjustment::first();

        $this->actingAs($admin)
            ->post("/adjustments/{$adjustment->id}/void", ['reason' => 'Counted twice.'])
            ->assertRedirect();

        $this->assertSame('voided', $adjustment->fresh()->status->value);
        $this->assertSame(0, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_voiding_a_decrease_puts_the_stock_back(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload());
        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'kind' => 'decrease',
            'reason' => 'damaged',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4]],
        ]));

        $decrease = InventoryAdjustment::where('kind', 'decrease')->first();
        $this->assertSame(6, $this->product->fresh()->on_hand);

        $this->actingAs($admin)
            ->post("/adjustments/{$decrease->id}/void", ['reason' => 'Found them.'])
            ->assertRedirect();

        $this->assertSame(10, $this->product->fresh()->on_hand);
        $this->assertStockMatchesItsLayers();
    }

    public function test_an_increase_cannot_be_voided_once_its_stock_has_moved(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload());
        $adjustment = InventoryAdjustment::first();

        // One unit taken away: the batch is no longer whole.
        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'kind' => 'decrease',
            'reason' => 'damaged',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]));

        $this->actingAs($admin)
            ->post("/adjustments/{$adjustment->id}/void", ['reason' => 'Too late.'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('posted', $adjustment->fresh()->status->value);
        $this->assertStockMatchesItsLayers();
    }

    public function test_voiding_needs_its_own_permission(): void
    {
        $this->actingAs($this->admin())->post('/adjustments', $this->payload());
        $adjustment = InventoryAdjustment::first();

        $staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->actingAs($staff)
            ->post("/adjustments/{$adjustment->id}/void", ['reason' => 'Trying it on.'])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------ Duplicates */

    public function test_the_same_form_sent_twice_makes_one_adjustment(): void
    {
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/adjustments', $this->payload(['idempotency_key' => $key]));
        $this->actingAs($admin)
            ->post('/adjustments', $this->payload(['idempotency_key' => $key]))
            ->assertRedirect();

        $this->assertSame(1, InventoryAdjustment::count());
        $this->assertSame(10, $this->product->fresh()->on_hand);
    }

    public function test_a_repeat_of_a_decrease_that_emptied_the_shelf_lands_on_it(): void
    {
        $key = (string) Str::uuid();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/adjustments', $this->payload());

        $payload = $this->payload([
            'kind' => 'decrease',
            'reason' => 'lost',
            'idempotency_key' => $key,
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10]],
        ]);

        $this->actingAs($admin)->post('/adjustments', $payload);
        $this->actingAs($admin)
            ->post('/adjustments', $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, InventoryAdjustment::count());
        $this->assertSame(0, $this->product->fresh()->on_hand);
    }

    /* ---------------------------------------------------------------- Trail */

    public function test_a_posting_and_a_void_each_write_one_row(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/adjustments', $this->payload([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_cost' => '100.00'],
                ['product_id' => $this->product->id, 'quantity' => 6, 'unit_cost' => '120.00'],
            ],
        ]));

        $adjustment = InventoryAdjustment::first();
        $this->actingAs($admin)->post("/adjustments/{$adjustment->id}/void", ['reason' => 'Mistake.']);

        $rows = AuditTrail::where('module', 'Inventory Adjustments')->get();

        $this->assertSame(1, $rows->where('action', AuditAction::Post)->count());
        $this->assertSame(1, $rows->where('action', AuditAction::Void)->count());
    }
}
