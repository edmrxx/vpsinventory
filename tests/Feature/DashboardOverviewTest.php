<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\DashboardOverview;
use App\Services\InventoryMetrics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The hero cards.
 *
 * These check the arithmetic an owner reads first: what was sold, what it
 * cost, what was made on it, and whether that is more or less than last
 * time. A wrong figure here is worse than a missing one, because the whole
 * point of the screen is to be believed without checking.
 */
class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $customer;

    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'TSHIRT-BLK']));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function overview(): DashboardOverview
    {
        return app(DashboardOverview::class);
    }

    /** Everything an admin may see. */
    protected function all(): array
    {
        return ['sales' => true, 'purchases' => true, 'stock' => true, 'cost' => true];
    }

    protected function sell(int $quantity, string $price, ?Product $product = null, string $discount = '0'): void
    {
        $this->actingAs($this->admin())->post('/sales', [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => $discount,
            'lines' => [[
                'product_id' => ($product ?? $this->product)->id,
                'quantity' => $quantity,
                'unit_price' => $price,
            ]],
        ])->assertRedirect();
    }

    /**
     * One card, from either row.
     *
     * Takes the whole answer from `cards()` or one row of it.
     */
    protected function cardFor(string $key, array $cards): ?array
    {
        if (isset($cards['cards'])) {
            $cards = array_merge($cards['cards'], $cards['secondary'] ?? []);
        }

        return collect($cards)->firstWhere('key', $key);
    }

    /* ------------------------------------------------------------- Arithmetic */

    public function test_the_cards_state_net_sales_cost_and_profit(): void
    {
        // 20 on the shelf at 100 each.
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);

        // Four sold at 500 = 2,000. They cost 4 x 100 = 400.
        $this->sell(4, '500.00');

        $cards = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        $this->assertSame('2000.00', $this->cardFor('net_sales', $cards)['value']);
        $this->assertSame('1600.00', $this->cardFor('gross_profit', $cards)['value']);
        // One sale, averaging the whole 2,000.
        $this->assertSame(1, $this->cardFor('transactions', $cards)['value']);
        $this->assertStringContainsString('2,000.00', $this->cardFor('transactions', $cards)['hint']);

        // 1,600 of 2,000 is 80% — said in the card's own words.
        $this->assertStringContainsString('80.0%', $this->cardFor('gross_profit', $cards)['hint']);
    }

    public function test_a_return_is_taken_off_the_sales_and_the_cost(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertRedirect();

        $cards = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        // Three sold at 500, costing 300.
        $this->assertSame('1500.00', $this->cardFor('net_sales', $cards)['value']);
        $this->assertSame('1200.00', $this->cardFor('gross_profit', $cards)['value']);

        // 500 of the 2,000 came back: a quarter.
        $this->assertSame('25.0', $this->cardFor('returns_rate', $cards)['value']);
    }

    /* ------------------------------------------------------------ Comparison */

    public function test_the_previous_period_is_the_same_length(): void
    {
        [$start, $end] = $this->overview()->previous(
            Carbon::parse('2026-10-01'),
            Carbon::parse('2026-10-10'),
        );

        // Ten days, ending the day before the period began.
        $this->assertSame('2026-09-21', $start->toDateString());
        $this->assertSame('2026-09-30', $end->toDateString());
    }

    public function test_the_change_is_measured_against_that_period(): void
    {
        // Stocked yesterday, because the ledger refuses a document dated
        // before the last one posted.
        $this->travelTo(Carbon::yesterday());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 100],
        ]);

        // Yesterday: 1,000. Today: 1,500. That is a rise of half.
        $this->sell(2, '500.00');

        $this->travelBack();
        $this->sell(3, '500.00');

        $cards = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        $change = $this->cardFor('net_sales', $cards)['change'];

        $this->assertSame(50.0, $change['percent']);
        $this->assertSame('up', $change['direction']);
        $this->assertSame('1000.00', $this->cardFor('net_sales', $cards)['previous']);
    }

    /**
     * Growing from nothing is not a percentage.
     *
     * Dividing by zero would give infinity, and a card reading "∞%" tells
     * an owner nothing. It says "new" instead.
     */
    public function test_growth_from_nothing_is_called_new(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);

        $this->sell(1, '500.00');

        $cards = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        $change = $this->cardFor('net_sales', $cards)['change'];

        $this->assertNull($change['percent']);
        $this->assertSame('new', $change['direction']);
    }

    /* --------------------------------------------------------------- Filters */

    public function test_a_category_filter_narrows_every_figure(): void
    {
        $other = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'CAP-NVY',
            'category_id' => Category::factory()->create(['name' => 'Headwear'])->id,
        ]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
            ['product_id' => $other->id, 'quantity' => 20, 'unit_cost' => 50],
        ]);

        $this->sell(4, '500.00');              // 2,000 on the first product
        $this->sell(2, '300.00', $other);      // 600 on the second

        $unfiltered = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        $this->assertSame('2600.00', $this->cardFor('net_sales', $unfiltered)['value']);

        $narrowed = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            ['category_id' => $other->category_id],
            $this->all(),
        );

        $this->assertSame('600.00', $this->cardFor('net_sales', $narrowed)['value']);
        // Two of the six units, and only that product's batch.
        $this->assertSame('900.00', $this->cardFor('inventory_value', $narrowed)['value']);
    }

    /* ----------------------------------------------------------- Permissions */

    public function test_a_user_is_never_sent_a_figure_they_may_not_read(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $cards = fn (array $may) => $this->overview()->cards(Carbon::today(), Carbon::today(), [], $may);
        $keys = fn (array $may) => collect($cards($may)['cards'])
            ->merge($cards($may)['secondary'])
            ->pluck('key')
            ->all();

        // Sales, but not the cost of them: the profit card is there, but
        // restricted, and carries no figure at all.
        $this->assertSame(
            ['net_sales', 'collections', 'gross_profit', 'overdue_balances', 'returns_rate', 'transactions'],
            $keys(['sales' => true, 'cost' => false]),
        );

        $profit = $this->cardFor('gross_profit', $cards(['sales' => true, 'cost' => false]));
        $this->assertSame('restricted', $profit['state']);
        $this->assertNull($profit['value']);
        $this->assertNull($profit['previous']);
        $this->assertNull($profit['sparkline']);
        $this->assertStringNotContainsString('1600', json_encode($cards(['sales' => true, 'cost' => false])));
        $this->assertStringNotContainsString('1,600', json_encode($cards(['sales' => true, 'cost' => false])));

        // Stock only: nothing about money earned or spent, and the value
        // on the shelf is a cost, so it is restricted too.
        $this->assertSame(
            ['low_stock', 'inventory_value', 'out_of_stock'],
            $keys(['stock' => true]),
        );
        $this->assertSame('restricted', $this->cardFor('inventory_value', $cards(['stock' => true]))['state']);

        // Cost without sales is not a way in through the back door.
        $this->assertSame([], $keys(['cost' => true]));
    }

    /* --------------------------------------------------------- The charts */

    /**
     * The chart must add up to the card above it.
     *
     * Both come from `InventoryMetrics`. If the line and the figure ever
     * disagree, somebody has worked a number out twice.
     */
    public function test_the_trend_adds_up_to_the_cards(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $metrics = app(InventoryMetrics::class);
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();

        $series = $metrics->periodSeries($from, $to, []);
        $figures = $metrics->periodFigures($from, $to, []);

        $this->assertSame(
            round($figures['net_sales'], 2),
            round(array_sum($series['sales']), 2),
            'The sales line does not add up to net sales.'
        );

        $this->assertSame(
            round($figures['gross_profit'], 2),
            round(array_sum($series['profit']), 2),
            'The profit line does not add up to the gross profit card.'
        );
    }

    /**
     * Weekly buckets are rolled up from daily ones.
     *
     * A week has no portable SQL function, so the days are queried and
     * added together here. The total must survive that.
     */
    public function test_a_weekly_chart_adds_up_to_the_same_total(): void
    {
        // Spread across several days, so a weekly bucket really has to
        // gather more than one. With everything on a single day the three
        // grains agree by accident — and when that day is a Monday they
        // agree even with the roll-up broken, which is how this test first
        // passed for no reason at all.
        //
        // The base date is taken before travelling, or every date below
        // would be relative to the one just travelled to.
        $base = Carbon::today()->subDays(4);

        $this->travelTo($base);

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        foreach ([[0, 4], [1, 3], [2, 2]] as [$offset, $quantity]) {
            $this->travelTo($base->copy()->addDays($offset));
            $this->sell($quantity, '500.00');
        }

        $this->travelBack();

        $metrics = app(InventoryMetrics::class);
        $from = Carbon::today()->startOfYear();
        $to = Carbon::today();

        $daily = $metrics->periodSeries($from, $to, [], 'day');
        $weekly = $metrics->periodSeries($from, $to, [], 'week');
        $monthly = $metrics->periodSeries($from, $to, [], 'month');

        // Prove there is money to lose before checking it was not lost.
        $this->assertGreaterThan(
            0,
            array_sum($daily['sales']),
            'The test data did not post, so this check proved nothing.'
        );

        // And that a week really did gather more than one day.
        $this->assertGreaterThan(
            1,
            count(array_filter($daily['sales'], fn ($v) => $v > 0)),
            'The sales landed on one day, so the roll-up was never exercised.'
        );

        $this->assertSame(
            round(array_sum($daily['sales']), 2),
            round(array_sum($weekly['sales']), 2),
            'Rolling days into weeks lost money.'
        );

        $this->assertSame(
            round(array_sum($daily['sales']), 2),
            round(array_sum($monthly['sales']), 2),
        );

        // Fewer buckets as the grain coarsens, and never none.
        $this->assertGreaterThan(count($weekly['categories']), count($daily['categories']));
        $this->assertGreaterThan(count($monthly['categories']), count($weekly['categories']));
    }

    public function test_the_category_donut_adds_up_to_net_sales(): void
    {
        $headwear = Category::factory()->create(['name' => 'Headwear']);
        $cap = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'CAP-NVY',
            'category_id' => $headwear->id,
        ]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
            ['product_id' => $cap->id, 'quantity' => 50, 'unit_cost' => 50],
        ]);

        $this->sell(4, '500.00');
        $this->sell(2, '300.00', $cap);

        $metrics = app(InventoryMetrics::class);
        $from = Carbon::today()->startOfMonth();
        $to = Carbon::today();

        $donut = $metrics->salesByCategory($from, $to, []);
        $figures = $metrics->periodFigures($from, $to, []);

        $this->assertCount(2, $donut['labels']);
        // Biggest slice first, so the legend reads in order.
        $this->assertSame(2000.0, $donut['series'][0]);

        $this->assertSame(
            round($figures['net_sales'], 2),
            round(array_sum($donut['series']), 2),
            'The slices do not add up to net sales.'
        );
    }

    /**
     * The slowest movers must keep a product that sold nothing.
     *
     * That is the row the panel exists to show. An inner join would drop
     * exactly those products and the panel would list the second-worst
     * sellers while claiming to list the worst.
     */
    public function test_the_slowest_movers_include_what_never_sold(): void
    {
        $dead = $this->unaudited(fn () => Product::factory()->create(['sku' => 'DEAD-01']));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
            ['product_id' => $dead->id, 'quantity' => 30, 'unit_cost' => 80],
        ]);

        $this->sell(4, '500.00');

        $slowest = app(InventoryMetrics::class)->slowestMovers(
            Carbon::today()->startOfMonth(),
            Carbon::today(),
            [],
        );

        $this->assertSame('DEAD-01', $slowest[0]['sku'], 'The product that never sold should be first.');
        $this->assertSame(0, $slowest[0]['quantity']);
        $this->assertSame(30, $slowest[0]['on_hand']);
    }

    /** The ranking carries both figures, so the toggle costs no request. */
    public function test_the_ranking_carries_money_and_units(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $rows = app(InventoryMetrics::class)->productRanking(
            Carbon::today()->startOfMonth(),
            Carbon::today(),
            [],
        );

        $this->assertSame('2000.00', $rows[0]['amount']);
        $this->assertSame(4, $rows[0]['quantity']);
    }

    /* ------------------------------------------------- Needs your attention */

    protected function attention(?array $may = null): array
    {
        return $this->overview()->attention(
            Carbon::today()->startOfYear(),
            Carbon::today(),
            [],
            $may ?? array_merge($this->all(), ['adjustments' => true]),
        );
    }

    protected function exceptions(?array $may = null): array
    {
        return $this->overview()->exceptions(
            Carbon::today()->startOfYear(),
            Carbon::today(),
            [],
            $may ?? array_merge($this->all(), ['adjustments' => true]),
        );
    }

    protected function itemFor(string $key, array $items): ?array
    {
        return collect($items)->firstWhere('key', $key);
    }

    /**
     * Nothing wrong is a real answer.
     *
     * An empty list has to stay empty, or the screen cries wolf on a
     * business that is running perfectly well.
     */
    public function test_a_healthy_shop_needs_no_attention(): void
    {
        // Opening stock, not an increase. Stocking up with an increase is
        // itself an adjustment, and the panel would rightly say so — the
        // first version of this test failed for exactly that reason.
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 500, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $this->assertSame([], $this->attention(), 'A shop with plenty of stock needs nothing doing.');
    }

    public function test_stock_at_or_below_its_reorder_level_is_raised(): void
    {
        $this->unaudited(fn () => $this->product->update(['reorder_level' => 20]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 12, 'unit_cost' => 100],
        ]);

        $list = $this->itemFor('low_stock', $this->exceptions());

        $this->assertNotNull($list, 'Twelve on hand against a reorder level of twenty is low.');
        $this->assertSame('/stock?stock=low', $list['href']);

        // On hand against the reorder level, and the row opens the product.
        $this->assertSame('12 / 20', $list['rows'][0]['figure']);
        $this->assertSame('/stock?search=TSHIRT-BLK', $list['rows'][0]['href']);

        // It is said once, under the cards, not again in the attention panel.
        $this->assertNull($this->itemFor('low_stock', $this->attention()));
    }

    public function test_a_low_product_carries_what_it_last_cost(): void
    {
        $this->unaudited(fn () => $this->product->update(['reorder_level' => 50]));

        $this->actingAs($this->admin())->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '123.45']],
        ])->assertRedirect();

        $row = $this->itemFor('low_stock', $this->exceptions())['rows'][0];

        $this->assertSame('Last bought '.now()->format('d M Y'), $row['when']);
    }

    /**
     * A product that never sold is dead stock, and the worst kind.
     *
     * It is also the row a careless join would drop, which is why it is
     * tested rather than assumed.
     */
    public function test_stock_that_has_never_sold_is_called_dead(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 40, 'unit_cost' => 100],
        ]);

        $item = $this->itemFor('dead_stock', $this->attention());

        $this->assertNotNull($item);
        $this->assertSame('TSHIRT-BLK', $item['rows'][0]['sku']);
        $this->assertNull($item['rows'][0]['last_sold_on'], 'It has never sold.');
        $this->assertStringContainsString(
            (string) settings('system.dead_stock_days'),
            $item['title'],
            'The window is a setting, so the wording must say which one was used.'
        );
    }

    public function test_stock_that_sold_recently_is_not_dead(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 40, 'unit_cost' => 100],
        ]);

        $this->sell(1, '500.00');

        $this->assertNull(
            $this->itemFor('dead_stock', $this->attention()),
            'It sold today, so it is not sitting there.'
        );
    }

    /**
     * Returns are judged as a share, with a floor under them.
     *
     * One return on one sale is 100%, and topping the list with it every
     * time would make the panel useless.
     */
    public function test_a_single_return_on_a_tiny_sale_is_not_raised(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(2, '500.00');

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertNull(
            $this->itemFor('high_returns', $this->attention()),
            'Two units sold is below the floor, however bad the share looks.'
        );
    }

    public function test_a_product_coming_back_often_is_raised(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(20, '500.00');

        $sale = Sale::latest('id')->firstOrFail();

        // Five of twenty is 25%, well over the ten per cent setting.
        $this->actingAs($this->admin())->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 5]],
        ])->assertRedirect();

        $item = $this->itemFor('high_returns', $this->attention());

        $this->assertNotNull($item);
        $this->assertSame(25.0, $item['rows'][0]['rate']);
        $this->assertSame(20, $item['rows'][0]['sold']);
        $this->assertSame(5, $item['rows'][0]['returned']);
    }

    /** A user is never told about a screen they may not open. */
    public function test_attention_follows_the_same_permissions(): void
    {
        $this->unaudited(fn () => $this->product->update(['reorder_level' => 50]));

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 100],
        ]);

        // No stock permission: no stock warnings at all.
        $this->assertSame([], $this->attention(['sales' => true]));
        $this->assertNull($this->itemFor('low_stock', $this->exceptions(['sales' => true])));

        $this->assertNotNull($this->itemFor('low_stock', $this->exceptions(['stock' => true])));
    }

    /* ----------------------------------------------------- What's happening */

    /**
     * Every sentence must carry the figure it is talking about.
     *
     * A line that says "sales are up" without saying up from what, and to
     * what, is a line nobody can check.
     */
    public function test_the_insights_say_the_numbers_out_loud(): void
    {
        // The period below is today alone, so the period it is compared
        // with is yesterday alone. The earlier sale has to land there.
        $this->travelTo(Carbon::yesterday());

        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 100],
        ]);

        $this->sell(2, '500.00');

        $this->travelBack();
        $this->sell(3, '500.00');

        $lines = $this->overview()->insights(
            Carbon::today(),
            Carbon::today(),
            [],
            array_merge($this->all(), ['adjustments' => true]),
        );

        $text = collect($lines)->pluck('text')->implode(' ');

        // Today 1,500 against 1,000 the day before: a rise of half.
        $this->assertStringContainsString('1,500.00', $text);
        $this->assertStringContainsString('50%', $text);
        $this->assertStringContainsString('up', $text);
    }

    public function test_the_insights_hold_back_the_margin_sentence(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $withCost = collect($this->overview()->insights(
            Carbon::today(),
            Carbon::today(),
            [],
            ['sales' => true, 'cost' => true],
        ))->pluck('text')->implode(' ');

        $withoutCost = collect($this->overview()->insights(
            Carbon::today(),
            Carbon::today(),
            [],
            ['sales' => true, 'cost' => false],
        ))->pluck('text')->implode(' ');

        $this->assertStringContainsString('of every peso sold', $withCost);
        $this->assertStringNotContainsString('of every peso sold', $withoutCost);
    }

    /* ------------------------------------------------- The ranking columns */

    public function test_the_ranking_carries_the_margin_only_for_those_who_may_see_it(): void
    {
        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $metrics = app(InventoryMetrics::class);
        $from = Carbon::today();
        $to = Carbon::today();

        $withCost = $metrics->productPerformance($from, $to, [], true)[0];
        $withoutCost = $metrics->productPerformance($from, $to, [], false)[0];

        // 2,000 sold at a cost of 400.
        $this->assertSame('400.00', $withCost['cost']);
        $this->assertSame('1600.00', $withCost['profit']);
        $this->assertSame(80.0, $withCost['margin']);

        $this->assertArrayNotHasKey('cost', $withoutCost);
        $this->assertArrayNotHasKey('profit', $withoutCost);
        $this->assertArrayNotHasKey('margin', $withoutCost);

        // Both carry what the row is and what is left of it.
        $this->assertSame(46, $withoutCost['on_hand']);
        $this->assertNotEmpty($withoutCost['category']);
    }

    /* -------------------------------------------------------------- The page */

    public function test_the_filter_bar_drives_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard?period=year&category_id=')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.period', 'year')
                ->where('filters.from', Carbon::today()->startOfYear()->toDateString())
                ->where('filters.to', Carbon::today()->toDateString())
                ->has('options.periods', count(DashboardOverview::PERIODS)));
    }

    /* ------------------------------------------------------- Phase 4 trimmings */

    /**
     * Every card says how it is worked out.
     *
     * A figure an owner cannot check is a figure they take on trust, and a
     * figure taken on trust is the one nobody argues with when it is wrong.
     */
    public function test_every_card_carries_the_formula_behind_it(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $cards = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            array_merge($this->all(), ['adjustments' => true]),
        );

        foreach (array_merge($cards['cards'], $cards['secondary']) as $card) {
            $this->assertNotSame(
                '',
                $card['formula'] ?? '',
                "The [{$card['key']}] card does not say how it is worked out."
            );
        }

        // And it says the real rule, not a vague one.
        $profit = $this->cardFor('gross_profit', $cards);
        $this->assertStringContainsString('FIFO', $profit['formula']);
    }

    /** The screen can say how fresh the figures are. */
    public function test_the_cards_say_when_they_were_read(): void
    {
        $overview = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            $this->all(),
        );

        $this->assertSame(now()->format('g:i A'), $overview['generated_at']);
    }

    /**
     * The dashboard does not repeat the sidebar.
     *
     * New sale, new purchase, adjust stock, reports and settings all used
     * to sit in the header. Every one of them is a screen the sidebar
     * already opens, so a second copy only gave the eye somewhere else to
     * look. Refresh stayed, because it belongs to this screen and is
     * nowhere else.
     */
    public function test_the_header_does_not_repeat_the_sidebar(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('can.createSale')
                ->missing('can.createPurchase')
                ->missing('can.createAdjustment')
                ->missing('can.reports')
                ->missing('can.settings')
                // What the screen does read stays.
                ->has('can.sales')
                ->has('can.cost'));
    }

    /**
     * Refresh throws the cached figures away.
     *
     * The screen then asks for the blocks again rather than reloading the
     * page, but the cache has to actually be cleared or it would be handed
     * back the very numbers it was trying to replace.
     */
    public function test_refresh_clears_the_cached_figures(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $metrics = app(InventoryMetrics::class);

        // Checked by behaviour, not by key. The figures are cached under a
        // generation now, and a test that spelled the key out would only
        // be repeating the implementation back to itself.
        $queries = function () use ($metrics) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $metrics->topSellers();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $cold = $queries();
        $this->assertGreaterThan(0, $cold, 'The first read has to do some work.');
        $this->assertLessThan($cold, $queries(), 'The second read should come from the cache.');

        $this->actingAs($this->admin())
            ->post('/dashboard/refresh')
            ->assertRedirect();

        $this->assertSame(
            $cold,
            $queries(),
            'Refresh must throw the cached figures away.'
        );
    }

    /* ----------------------------------------------------------- The cache */

    /**
     * A second look at the same period costs nothing.
     *
     * The dashboard is opened far more often than a document is posted, so
     * the blocks are remembered for five minutes.
     */
    public function test_the_cards_are_remembered_between_reads(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $read = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->overview()->cards(Carbon::today(), Carbon::today(), [], $this->all());
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $cold = $read();

        $this->assertGreaterThan(5, $cold, 'The first read works the figures out.');
        $this->assertSame(0, $read(), 'The second read should ask the database nothing.');
    }

    /**
     * A different filter is a different question.
     *
     * The key has to carry the period, the filters and the permissions. If
     * it did not, one user would be handed another user's figures — the
     * worst thing a cache can do.
     */
    public function test_a_different_filter_is_cached_apart(): void
    {
        $headwear = Category::factory()->create(['name' => 'Headwear']);
        $cap = $this->unaudited(fn () => Product::factory()->create([
            'sku' => 'CAP-NVY',
            'category_id' => $headwear->id,
        ]));

        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
            ['product_id' => $cap->id, 'quantity' => 50, 'unit_cost' => 50],
        ]);

        $this->sell(4, '500.00');
        $this->sell(2, '300.00', $cap);

        $all = $this->overview()->cards(Carbon::today(), Carbon::today(), [], $this->all());
        $hats = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            ['category_id' => $headwear->id],
            $this->all(),
        );

        $this->assertSame('2600.00', $this->cardFor('net_sales', $all)['value']);
        $this->assertSame('600.00', $this->cardFor('net_sales', $hats)['value']);
    }

    /** And a user who may see less is cached apart from one who may see more. */
    public function test_permissions_are_part_of_the_key(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $admin = $this->overview()->cards(Carbon::today(), Carbon::today(), [], $this->all());
        $staff = $this->overview()->cards(
            Carbon::today(),
            Carbon::today(),
            [],
            ['sales' => true, 'cost' => false],
        );

        $this->assertSame('1600.00', $this->cardFor('gross_profit', $admin)['value']);
        $this->assertNull(
            $this->cardFor('gross_profit', $staff)['value'],
            'A staff reader must not be served the admin cards from the cache.'
        );
    }

    /**
     * Posting a document throws the figures away.
     *
     * A cached dashboard that outlives the sale it is describing is worse
     * than no cache at all. The ledger moves the generation on from inside
     * the same transaction, so this holds for every kind of document.
     */
    public function test_posting_a_sale_clears_the_cached_dashboard(): void
    {
        app(AdjustmentPoster::class)->openingBalance([
            ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 100],
        ]);

        $this->sell(4, '500.00');

        $before = $this->overview()->cards(Carbon::today(), Carbon::today(), [], $this->all());
        $this->assertSame('2000.00', $this->cardFor('net_sales', $before)['value']);

        // Another sale, which must be visible at once.
        $this->sell(2, '500.00');

        $after = $this->overview()->cards(Carbon::today(), Carbon::today(), [], $this->all());

        $this->assertSame(
            '3000.00',
            $this->cardFor('net_sales', $after)['value'],
            'The dashboard served a figure from before the sale was posted.'
        );
    }

    public function test_an_unknown_period_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard?period=since-forever')
            ->assertSessionHasErrors('period');
    }
}
