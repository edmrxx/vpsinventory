<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The till on the shared tablet.
 *
 * What matters here is not the screen but what it is allowed to do, and
 * that it sells the same way everything else does. A second way to sell
 * would be a second place for the stock arithmetic to go wrong.
 */
class TillTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'TSHIRT-BLK',
            'selling_price' => 500,
        ]));

        $this->unaudited(fn () => Customer::factory()->walkIn()->create());
    }

    protected function cashier(): User
    {
        return $this->unaudited(
            fn () => User::factory()->withPermissions('kiosk.use', 'sales.create', 'sales.view')->create()
        );
    }

    protected function stock(int $quantity, float $cost = 100): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => $cost],
        ]);
    }

    /* --------------------------------------------------------------- Access */

    public function test_the_till_needs_the_kiosk_permission(): void
    {
        $clerk = $this->unaudited(
            fn () => User::factory()->withPermissions('sales.create', 'sales.view')->create()
        );

        $this->actingAs($clerk)->get('/pos')->assertForbidden();
    }

    public function test_a_cashier_can_open_the_till(): void
    {
        $this->stock(20);

        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pos/Index')
                ->has('products', 1)
                ->where('products.0.price', '500.00')
                ->where('products.0.on_hand', 20)
                ->where('customer.name', 'Walk-in Customer'));
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/pos')->assertRedirect(route('login'));
    }

    /* --------------------------------------------------------------- Selling */

    /**
     * The till has no selling route of its own.
     *
     * It posts to `POST /sales`, which is what keeps one Form Request, one
     * poster and one set of FIFO batches. If somebody ever adds a
     * `POST /pos`, this fails.
     *
     * The hold routes are the one exception, and they sell nothing: a hold
     * moves no stock (`HoldTest` proves it), and a recalled sale is posted
     * through `POST /sales` like any other.
     */
    public function test_the_till_sells_through_the_ordinary_route(): void
    {
        $writes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'pos'))
            ->reject(fn ($route) => in_array($route->getName(), [
                'pos.holds.store', 'pos.holds.recall', 'pos.holds.destroy',
            ], true))
            ->filter(fn ($route) => array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE'],
            ) !== [])
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $writes, 'The till must sell through POST /sales.');
    }

    public function test_a_sale_from_the_till_is_an_ordinary_sale(): void
    {
        $this->stock(20, 100);

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/sales', [
                'customer_id' => Customer::value('id'),
                'sale_date' => now()->toDateString(),
                'discount' => 0,
                'from' => 'pos',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 3,
                    'unit_price' => '500.00',
                ]],
            ])
            ->assertRedirect(route('pos', ['location' => Location::default()->id]))
            ->assertSessionHas('success');

        $sale = Sale::latest('id')->firstOrFail();

        // Posted through the same service: real reference, real FIFO cost.
        $this->assertSame('1500.00', $sale->total_amount);
        $this->assertSame('300.00', $sale->total_cost);
        $this->assertSame(17, $this->product->refresh()->on_hand);
    }

    /** Without the marker it behaves exactly as the ordinary form does. */
    public function test_the_ordinary_form_still_opens_the_document(): void
    {
        $this->stock(20);

        $this->actingAs($this->cashier())
            ->post('/sales', [
                'customer_id' => Customer::value('id'),
                'sale_date' => now()->toDateString(),
                'discount' => 0,
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '500.00',
                ]],
            ])
            ->assertRedirect(route('sales.show', Sale::latest('id')->first()));
    }

    public function test_the_till_cannot_sell_what_is_not_on_the_shelf(): void
    {
        $this->stock(2);

        $this->actingAs($this->cashier())
            ->from('/pos')
            ->post('/sales', [
                'customer_id' => Customer::value('id'),
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 5,
                    'unit_price' => '500.00',
                ]],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $this->product->refresh()->on_hand);
    }

    /* ------------------------------------------------------------ The screen */

    public function test_the_till_offers_only_categories_with_something_in_them(): void
    {
        $this->stock(5);

        $empty = Category::factory()->create(['name' => 'Nothing Here']);

        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(function ($page) use ($empty) {
                $offered = collect($page->toArray()['props']['categories'])->pluck('value');

                $this->assertFalse(
                    $offered->contains($empty->id),
                    'A category with no products is an empty shelf.'
                );
            });
    }

    public function test_an_inactive_product_is_not_for_sale(): void
    {
        $this->unaudited(fn () => Product::factory()->inactive()->create(['sku' => 'OLD-01']));

        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page->has('products', 1));
    }

    /* --------------------------------------------------- What sold today */

    public function test_the_till_shows_what_went_through_today(): void
    {
        $this->stock(20, 100);

        $cashier = $this->cashier();

        $this->actingAs($cashier)->post('/sales', [
            'customer_id' => Customer::value('id'),
            'sale_date' => now()->toDateString(),
            'from' => 'pos',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => '500.00',
            ]],
        ])->assertRedirect(route('pos', ['location' => Location::default()->id]));

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->has('sales.rows', 1)
                ->where('sales.rows.0.total', '1000.00')
                ->where('sales.rows.0.items', 1)
                ->where('sales.count', 1)
                ->where('sales.total', '1000.00'));
    }

    /**
     * The takings are what was taken.
     *
     * A voided sale is still listed — somebody has to see that it was
     * voided — but it is not money in the till.
     */
    public function test_a_voided_sale_is_listed_but_not_counted(): void
    {
        $this->stock(20, 100);

        $cashier = $this->unaudited(fn () => User::factory()->withPermissions(
            'kiosk.use', 'sales.create', 'sales.view', 'sales.void'
        )->create());

        $this->actingAs($cashier)->post('/sales', [
            'customer_id' => Customer::value('id'),
            'sale_date' => now()->toDateString(),
            'from' => 'pos',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => '500.00',
            ]],
        ]);

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($cashier)
            ->post("/sales/{$sale->id}/void", ['reason' => 'Rung up twice'])
            ->assertRedirect();

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->has('sales.rows', 1)
                ->where('sales.rows.0.status', 'voided')
                // Listed, but not money taken.
                ->where('sales.count', 0)
                ->where('sales.total', '0.00'));
    }

    /** Another day is one tap away, and today is what opens. */
    public function test_the_till_opens_on_today_and_can_look_back(): void
    {
        $this->travelTo(now()->subDay());

        $this->stock(20, 100);

        $cashier = $this->cashier();

        $this->actingAs($cashier)->post('/sales', [
            'customer_id' => Customer::value('id'),
            'sale_date' => now()->toDateString(),
            'from' => 'pos',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => '500.00',
            ]],
        ]);

        $this->travelBack();

        // Today is empty: yesterday's sale is not today's takings.
        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                ->has('sales.rows', 0)
                ->where('sales.count', 0)
                ->where('day.is_today', true)
                ->where('day.label', 'Today'));

        // And yesterday has it.
        $this->actingAs($cashier)
            ->get('/pos?date='.now()->subDay()->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('sales.rows', 1)
                ->where('sales.count', 1)
                ->where('sales.total', '500.00')
                ->where('day.is_today', false)
                ->where('day.label', 'Yesterday'));
    }

    public function test_a_day_that_has_not_happened_is_refused(): void
    {
        $this->actingAs($this->cashier())
            ->get('/pos?date='.now()->addDay()->toDateString())
            ->assertSessionHasErrors('date');
    }

    /**
     * The takings cover the whole day, not the rows on screen.
     *
     * Only fifty rows are sent. Summing those would quietly understate a
     * busy day the moment it passed fifty sales — the kind of wrong
     * figure nobody notices until they are counting cash.
     */
    public function test_the_takings_cover_the_whole_day_not_the_rows_shown(): void
    {
        $this->stock(400, 100);

        $cashier = $this->cashier();

        // Fifty-two sales of 500 each: 26,000 taken.
        for ($i = 0; $i < 52; $i++) {
            $this->actingAs($cashier)->post('/sales', [
                'customer_id' => Customer::value('id'),
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '500.00',
                ]],
            ]);
        }

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page
                // Fifty on screen, fifty-two counted.
                ->has('sales.rows', 50)
                ->where('sales.shown', 50)
                ->where('sales.of', 52)
                ->where('sales.count', 52)
                ->where('sales.total', '26000.00'));
    }

    /**
     * The calendar is told which days traded.
     *
     * It is the question somebody asks the moment a calendar opens: when
     * were we actually open? A dot answers it without tapping through
     * empty days one at a time.
     */
    public function test_the_calendar_is_told_which_days_had_a_sale(): void
    {
        $cashier = $this->cashier();

        $sell = function () use ($cashier) {
            $this->actingAs($cashier)->post('/sales', [
                'customer_id' => Customer::value('id'),
                'sale_date' => now()->toDateString(),
                'from' => 'pos',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => '500.00',
                ]],
            ])->assertRedirect(route('pos', ['location' => Location::default()->id]));
        };

        // Two days of trading, inside the same month. The stock has to go
        // on the shelf first and no later: the ledger refuses a document
        // dated before the last one posted.
        $base = now()->startOfMonth()->addDays(4);

        $this->travelTo($base);

        $this->stock(50, 100);

        $sell();

        $this->travelTo($base->copy()->addDays(2));
        $sell();

        // Stay on the second trading day rather than returning to the real
        // one. The till refuses a date in the future, and the fifth of the
        // month is still to come when this runs on the first of it — which
        // is how this test failed every month and passed every test of it.
        $this->actingAs($cashier)
            ->get('/pos?date='.$base->toDateString())
            ->assertInertia(fn ($page) => $page
                ->has('tradedOn', 2)
                ->where('tradedOn.0', $base->toDateString())
                ->where('tradedOn.1', $base->copy()->addDays(2)->toDateString()));

        $this->travelBack();
    }

    /** A voided sale is not a day's trading. */
    public function test_a_day_whose_only_sale_was_voided_is_not_marked(): void
    {
        $this->stock(20, 100);

        $cashier = $this->unaudited(fn () => User::factory()->withPermissions(
            'kiosk.use', 'sales.create', 'sales.view', 'sales.void'
        )->create());

        $this->actingAs($cashier)->post('/sales', [
            'customer_id' => Customer::value('id'),
            'sale_date' => now()->toDateString(),
            'from' => 'pos',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => '500.00',
            ]],
        ]);

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page->has('tradedOn', 1));

        $this->actingAs($cashier)->post(
            '/sales/'.Sale::latest('id')->value('id').'/void',
            ['reason' => 'Rung up twice'],
        );

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page->has('tradedOn', 0));
    }

    /** Voiding from the counter follows the permission, like everywhere. */
    public function test_the_till_says_whether_this_cashier_may_void(): void
    {
        $this->actingAs($this->cashier())
            ->get('/pos')
            ->assertInertia(fn ($page) => $page->where('can.void', false));

        $voider = $this->unaudited(fn () => User::factory()->withPermissions(
            'kiosk.use', 'sales.create', 'sales.view', 'sales.void'
        )->create());

        $this->actingAs($voider)
            ->get('/pos')
            ->assertInertia(fn ($page) => $page->where('can.void', true));
    }

    /**
     * A posted sale cannot be edited, from the till or from anywhere.
     *
     * The stock has already moved and the batches it consumed are already
     * spent. Changing it afterwards would mean the ledger no longer
     * describes what happened. A mistake is corrected with a void, a
     * return or an adjustment — each of which leaves a trail.
     */
    public function test_there_is_no_way_to_edit_a_posted_sale(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName());

        $this->assertNotContains('sales.edit', $names);
        $this->assertNotContains('sales.update', $names);
        $this->assertNotContains('sales.destroy', $names);

        $this->assertNotContains(
            'sales.update',
            PermissionRegistry::names(),
            'There must be no permission to edit a posted sale.'
        );
    }

    /* ----------------------------------------------------------- The landing */

    /**
     * Somebody who signed in at the tablet lands at the till.
     *
     * They came to serve a customer, not to read a dashboard.
     */
    public function test_a_kiosk_sign_in_lands_at_the_till(): void
    {
        $this->unaudited(fn () => User::factory()->withPermissions('kiosk.use')->create([
            'username' => 'cashier',
            'password' => 'till-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'cashier',
                'password' => 'till-pass-123',
                'from' => 'kiosk',
            ])
            ->assertRedirect(route('pos'));
    }

    /** An ordinary sign-in still lands on the dashboard. */
    public function test_an_ordinary_sign_in_still_lands_on_the_dashboard(): void
    {
        $this->unaudited(fn () => User::factory()->admin()->create([
            'username' => 'theboss',
            'password' => 'desk-pass-123',
        ]));

        $this->post('/login', [
            'username' => 'theboss',
            'password' => 'desk-pass-123',
        ])->assertRedirect(route('dashboard'));
    }
}
