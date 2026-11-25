<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\Quantity;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the dashboard and the reports.
 *
 * One service, because a number shown twice must mean the same thing twice.
 * The card, the chart, the top-seller list and the report all read from
 * here, so they cannot drift apart.
 *
 * What the words mean, stated once:
 *
 *   Net sales      posted sales, less what came back. A return counts
 *                  against the month it happened in, not the month of the
 *                  sale it undoes: that is when the money went back.
 *   Net purchases  posted deliveries, less what went back. Opening stock
 *                  and adjustments are NOT purchases — nothing was bought.
 *   Inventory      what the batches on the shelf are worth, read from
 *                  InventoryValuation so the Stock screen cannot disagree.
 *
 * A voided document counts for nothing, anywhere.
 *
 * Every block is cached under its own key. `flush()` is called after a
 * document posts, from inside the ledger, so a figure is never stale.
 */
class InventoryMetrics
{
    /** How long a block stays cached, in seconds. */
    public const TTL = 300;

    protected const PREFIX = 'inventory_metrics.';

    /** The keys this service owns, so flushing needs no guesswork. */
    protected const KEYS = ['sold', 'purchased', 'monthly', 'top_sellers', 'low_stock'];

    public function __construct(protected InventoryValuation $valuation) {}

    /* --------------------------------------------------------------- Money */

    /**
     * What was sold in a period, less what came back.
     */
    public function netSales(?Carbon $from = null, ?Carbon $to = null): string
    {
        [$from, $to] = $this->range($from, $to);

        $sold = (float) Sale::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('sale_date', $this->bounds($from, $to))
            ->sum('total_amount');

        $returned = (float) SalesReturn::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('return_date', $this->bounds($from, $to))
            ->sum('total_amount');

        return $this->money($sold - $returned);
    }

    /**
     * What was bought in a period, less what went back.
     */
    public function netPurchases(?Carbon $from = null, ?Carbon $to = null): string
    {
        [$from, $to] = $this->range($from, $to);

        $bought = (float) Purchase::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_date', $this->bounds($from, $to))
            ->sum('total_amount');

        $returned = (float) PurchaseReturn::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('return_date', $this->bounds($from, $to))
            ->sum('total_cost');

        return $this->money($bought - $returned);
    }

    /* --------------------------------------------------------------- Cards */

    /**
     * The four figures across the top of the dashboard.
     *
     * Each one is left out entirely for somebody who may not open the
     * screen it summarises, rather than hidden once it has arrived.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(bool $seesSales, bool $seesPurchases, bool $seesStock, bool $seesCost = false): array
    {
        $summary = $seesStock ? $this->lowStockSummary() : null;
        $cards = [];

        if ($seesSales) {
            $cards[] = [
                'key' => 'sold',
                'label' => 'Total sold',
                'value' => $this->remember('sold', fn () => $this->netSales()),
                'hint' => 'This year, less returns',
                'icon' => 'Receipt',
                'tone' => 'success',
                'money' => true,
            ];
        }

        if ($seesPurchases) {
            $cards[] = [
                'key' => 'purchased',
                'label' => 'Total purchased',
                'value' => $this->remember('purchased', fn () => $this->netPurchases()),
                'hint' => 'This year, less returns',
                'icon' => 'ShoppingCart',
                'tone' => 'info',
                'money' => true,
            ];
        }

        // What the stock cost is a cost figure, so it needs `sales.view_cost`
        // as well as `stock.view`, as on the web dashboard.
        if ($seesStock && $seesCost) {
            $cards[] = [
                'key' => 'inventory',
                'label' => 'Inventory balance',
                'value' => $summary['value'],
                'hint' => 'At what the batches cost',
                'icon' => 'Warehouse',
                'tone' => 'brand',
                'money' => true,
            ];
        }

        if ($seesStock) {
            $cards[] = [
                'key' => 'low_stock',
                'label' => 'Low stock items',
                'value' => (string) $summary['low_stock'],
                'hint' => 'At or below reorder level',
                'icon' => 'TriangleAlert',
                'tone' => $summary['low_stock'] > 0 ? 'warning' : 'neutral',
                'money' => false,
            ];
        }

        return $cards;
    }

    /* --------------------------------------------------------------- Chart */

    /**
     * Sales against purchases, month by month, with no gaps.
     *
     * Grouped with SUBSTR rather than a date function, because the tests
     * run on SQLite and the app on MySQL, and the two spell their date
     * functions differently.
     *
     * @return array{categories:list<string>,series:list<array{name:string,data:list<float>}>}
     */
    /**
     * Sales against purchases over the year, for the dashboard.
     *
     * Cached, because the dashboard is opened constantly and the figure
     * only changes when a document posts — at which point the ledger
     * flushes it.
     */
    public function monthlySeries(bool $seesSales, bool $seesPurchases): array
    {
        return $this->remember(
            "monthly.{$seesSales}.{$seesPurchases}",
            function () use ($seesSales, $seesPurchases) {
                [$from, $to] = $this->range();

                return $this->seriesFor($from, $to, $seesSales, $seesPurchases);
            }
        );
    }

    /**
     * Sales against purchases over a chosen period, for the reports.
     *
     * Not cached, because the rest of a report is not: a report is a
     * question asked of the documents and answered fresh. A cached chart
     * beside uncached totals would sooner or later disagree with them.
     *
     * The grain follows the period rather than being fixed, so the chart
     * always has a shape to it. A month or more is plotted month by month,
     * which is what the dashboard shows. A shorter period is plotted day by
     * day, because one month drawn monthly is a single dot.
     *
     * @return array{categories: list<string>, series: list<array{name:string,data:list<float>}>}
     */
    public function seriesFor(
        Carbon $from,
        Carbon $to,
        bool $seesSales,
        bool $seesPurchases,
        array $filters = [],
    ): array {
        [$buckets, $labels, $length] = $this->buckets($from, $to);

        $series = [];

        if ($seesSales) {
            $series[] = [
                'name' => 'Sales',
                'data' => $this->byMonth(
                    $buckets,
                    Sale::query()->where('status', DocumentStatus::Posted->value)
                        ->tap(fn ($query) => $this->narrowSales($query, $filters, 'sales')),
                    'sale_date',
                    'total_amount',
                    SalesReturn::query()->where('status', DocumentStatus::Posted->value)
                        ->tap(fn ($query) => $this->narrowSales($query, $filters, 'sales_returns')),
                    'return_date',
                    'total_amount',
                    $length,
                    $from,
                    $to,
                ),
            ];
        }

        if ($seesPurchases) {
            $series[] = [
                'name' => 'Purchases',
                'data' => $this->byMonth(
                    $buckets,
                    Purchase::query()->where('status', DocumentStatus::Posted->value)
                        ->tap(fn ($query) => $this->narrowPurchases($query, $filters, 'purchases')),
                    'purchase_date',
                    'total_amount',
                    PurchaseReturn::query()->where('status', DocumentStatus::Posted->value)
                        ->tap(fn ($query) => $this->narrowPurchases($query, $filters, 'purchase_returns')),
                    'return_date',
                    'total_cost',
                    $length,
                    $from,
                    $to,
                ),
            ];
        }

        return ['categories' => $labels, 'series' => $series];
    }

    /**
     * The buckets a period is plotted in, and how to label them.
     *
     * @return array{0: list<string>, 1: list<string>, 2: int}
     */
    /**
     * The buckets a period is plotted in, and how to label them.
     *
     * `$grain` is 'day', 'week', 'month', or 'auto' to let the length of
     * the period decide. A week has no portable SQL function — MySQL and
     * SQLite disagree — so a weekly chart is queried a day at a time and
     * added up here. A year is 365 rows, which is nothing.
     *
     * @return array{0: list<string>, 1: list<string>, 2: int, 3: array<string, string>}
     *                                                                                   Keys, labels, the SQL substring
     *                                                                                   length, and the day-to-bucket map a
     *                                                                                   weekly chart rolls up through.
     */
    protected function buckets(Carbon $from, Carbon $to, string $grain = 'auto'): array
    {
        if ($grain === 'auto') {
            $grain = $from->copy()->startOfMonth()->eq($to->copy()->startOfMonth())
                ? 'day'
                : 'month';
        }

        $keys = [];
        $labels = [];

        if ($grain === 'day') {
            $cursor = $from->copy()->startOfDay();

            while ($cursor->lte($to)) {
                $keys[] = $cursor->format('Y-m-d');
                $labels[] = $cursor->format('j M');
                $cursor->addDay();
            }

            return [$keys, $labels, 10, []];
        }

        if ($grain === 'week') {
            // Queried daily, then added up. Each day knows which week it
            // belongs to, and the week is named by the Monday it starts on.
            $rollUp = [];
            $cursor = $from->copy()->startOfDay();

            while ($cursor->lte($to)) {
                $week = $cursor->copy()->startOfWeek();
                $key = $week->format('Y-m-d');

                if (! isset($keys[$key])) {
                    $keys[$key] = $key;
                    $labels[$key] = $week->format('j M');
                }

                $rollUp[$cursor->format('Y-m-d')] = $key;
                $cursor->addDay();
            }

            return [array_values($keys), array_values($labels), 10, $rollUp];
        }

        // Month by month. The year is named only when the period crosses
        // one, or two Octobers would read the same.
        $spansYears = $from->year !== $to->year;
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $keys[] = $cursor->format('Y-m');
            $labels[] = $spansYears ? $cursor->format('M Y') : $cursor->format('M');
            $cursor->addMonth();
        }

        return [$keys, $labels, 7, []];
    }

    /* --------------------------------------------------------- Top sellers */

    /**
     * The products that sold most, by net quantity.
     *
     * Net of returns, so a product sold ten times and brought back nine is
     * not a best seller. Anything left at nothing or below is dropped
     * rather than shown as a zero.
     *
     * @return list<array<string, mixed>>
     */
    public function topSellers(int $limit = 5): array
    {
        return $this->remember("top_sellers.{$limit}", function () use ($limit) {
            [$from, $to] = $this->range();

            $sold = DB::table('sale_lines')
                ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
                ->where('sales.status', DocumentStatus::Posted->value)
                ->whereBetween('sales.sale_date', $this->bounds($from, $to))
                ->groupBy('sale_lines.product_id')
                ->select('sale_lines.product_id')
                ->selectRaw('SUM(sale_lines.quantity) AS quantity')
                // The line's share of what the sale actually collected, so
                // a discounted sale is not counted at its list price.
                // Multiplied before it is divided, on purpose. SQLite gives
                // a DECIMAL column integer affinity, so total ÷ subtotal is
                // 1800 ÷ 2000 = 0 there and 0.9 on MySQL. Doing the division
                // last gives the same answer on both.
                ->selectRaw(
                    'SUM(CASE WHEN sales.subtotal > 0 '
                        .'THEN sale_lines.line_total * sales.total_amount / sales.subtotal '
                        .'ELSE sale_lines.line_total END) AS amount'
                )
                ->get()
                ->keyBy('product_id');

            $returned = DB::table('sales_return_lines')
                ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
                ->where('sales_returns.status', DocumentStatus::Posted->value)
                ->whereBetween('sales_returns.return_date', $this->bounds($from, $to))
                ->groupBy('sales_return_lines.product_id')
                ->select('sales_return_lines.product_id')
                ->selectRaw('SUM(sales_return_lines.quantity) AS quantity')
                ->selectRaw('SUM(sales_return_lines.line_refund) AS amount')
                ->get()
                ->keyBy('product_id');

            $rows = [];

            foreach ($sold as $productId => $line) {
                $quantity = Quantity::diff($line->quantity, $returned[$productId]->quantity ?? 0);
                $amount = (float) $line->amount - (float) ($returned[$productId]->amount ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $rows[] = [
                    'product_id' => (int) $productId,
                    'quantity' => $quantity,
                    'amount' => $this->money($amount),
                ];
            }

            // Most sold first, then by what it brought in, then by SKU, so
            // the order never wobbles between two equal rows.
            $products = Product::whereIn('id', array_column($rows, 'product_id'))
                ->get(['id', 'sku', 'name'])
                ->keyBy('id');

            usort($rows, function (array $a, array $b) use ($products) {
                return [$b['quantity'], (float) $b['amount'], $products[$a['product_id']]->sku ?? '']
                    <=> [$a['quantity'], (float) $a['amount'], $products[$b['product_id']]->sku ?? ''];
            });

            return array_map(
                fn (array $row) => $row + [
                    'sku' => $products[$row['product_id']]->sku ?? null,
                    'name' => $products[$row['product_id']]->name ?? null,
                ],
                array_slice($rows, 0, $limit),
            );
        });
    }

    /* ---------------------------------------------------------- Low stock */

    /**
     * The products running out, the shortest first.
     *
     * @return list<array<string, mixed>>
     */
    public function lowStock(int $limit = 5): array
    {
        return $this->remember("low_stock.{$limit}", fn () => Product::query()
            ->with('unit:id,code')
            ->where('is_active', true)
            ->lowStock()
            ->orderByRaw('(reorder_level - on_hand) DESC')
            ->orderBy('sku')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'on_hand', 'reorder_level', 'unit_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'on_hand' => $product->on_hand,
                'reorder_level' => $product->reorder_level,
                'unit' => $product->unit?->code,
            ])
            ->all());
    }

    /* ------------------------------------------------------------ Reports */

    /*
     * The Reports screen's filters (phase 34), stated once:
     *
     * - `staff_id` narrows to the documents one user posted (`posted_by`),
     *   sales, returns and purchases alike — the rule `narrow()` applies to
     *   the dashboard.
     * - `payment_method_id` narrows to the sales **paid at least in part**
     *   that way, and to the returns of those sales. A split sale counts
     *   whole: one payment cannot be cut out of the goods it paid for, so
     *   the sale is either in or out. A return goes with the sale it undoes,
     *   whatever it was refunded in. Purchases record no payment method, so
     *   the purchase blocks are left out while it is set — never shown
     *   unfiltered beside filtered sales (`ReportFilters::coversPurchases()`).
     *
     * Every block on the screen and in the spreadsheet takes the same array,
     * so the summary, the chart and the breakdowns cannot tell two stories.
     */

    /**
     * The sales summary for a period.
     *
     * The same definitions as the dashboard, because they come from the
     * same place. A figure shown on two screens has to mean one thing.
     *
     * `$rows` caps each breakdown; null sends every row (the spreadsheet).
     *
     * @param  array{staff_id?:?int, payment_method_id?:?int}  $filters
     * @return array<string, mixed>
     */
    public function salesSummary(
        Carbon $from,
        Carbon $to,
        bool $withCost = false,
        array $filters = [],
        ?int $rows = self::BREAKDOWN_ROWS,
    ): array {
        $totals = Sale::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('sale_date', $this->bounds($from, $to))
            ->tap(fn ($query) => $this->narrowSales($query, $filters, 'sales'))
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('COALESCE(SUM(subtotal), 0) AS subtotal')
            ->selectRaw('COALESCE(SUM(discount), 0) AS discount')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(total_cost), 0) AS cost')
            ->first();

        $returns = SalesReturn::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('return_date', $this->bounds($from, $to))
            ->tap(fn ($query) => $this->narrowSales($query, $filters, 'sales_returns'))
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(total_cost), 0) AS cost')
            ->first();

        $net = (float) $totals->total - (float) $returns->total;
        $netCost = (float) $totals->cost - (float) $returns->cost;

        return array_merge([
            'documents' => (int) $totals->documents,
            'subtotal' => $this->money((float) $totals->subtotal),
            'discount' => $this->money((float) $totals->discount),
            'returns' => $this->money((float) $returns->total),
            'return_documents' => (int) $returns->documents,
            'net' => $this->money($net),
            'by_product' => $this->salesByProduct($from, $to, $filters, $rows),
            'by_customer' => $this->salesByCustomer($from, $to, $filters, $rows),
        ], $withCost ? [
            'cost' => $this->money($netCost),
            'gross_profit' => $this->money($net - $netCost),
        ] : []);
    }

    /**
     * The purchase summary for a period. Only the Staff filter applies: a
     * purchase records no payment method.
     *
     * @param  array{staff_id?:?int}  $filters
     * @return array<string, mixed>
     */
    public function purchaseSummary(
        Carbon $from,
        Carbon $to,
        array $filters = [],
        ?int $rows = self::BREAKDOWN_ROWS,
    ): array {
        $totals = Purchase::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_date', $this->bounds($from, $to))
            ->tap(fn ($query) => $this->narrowPurchases($query, $filters, 'purchases'))
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->first();

        $returns = PurchaseReturn::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('return_date', $this->bounds($from, $to))
            ->tap(fn ($query) => $this->narrowPurchases($query, $filters, 'purchase_returns'))
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('COALESCE(SUM(total_cost), 0) AS total')
            ->first();

        return [
            'documents' => (int) $totals->documents,
            'total' => $this->money((float) $totals->total),
            'returns' => $this->money((float) $returns->total),
            'return_documents' => (int) $returns->documents,
            'net' => $this->money((float) $totals->total - (float) $returns->total),
            'by_supplier' => $this->purchasesBySupplier($from, $to, $filters, $rows),
            'by_product' => $this->purchasesByProduct($from, $to, $filters, $rows),
        ];
    }

    /**
     * The Reports filters on a query over `sales` or `sales_returns`.
     *
     * The payment method is asked of `sale_payments` with EXISTS, so a sale
     * paid two ways is still one row and its total is counted once.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|Builder  $query
     */
    public function narrowSales($query, array $filters, string $table): void
    {
        if ($filters['staff_id'] ?? null) {
            $query->where($table.'.posted_by', $filters['staff_id']);
        }

        if ($method = $filters['payment_method_id'] ?? null) {
            $sale = $table === 'sales' ? 'sales.id' : $table.'.sale_id';

            $query->whereExists(fn ($paid) => $paid
                ->selectRaw('1')
                ->from('sale_payments')
                ->whereColumn('sale_payments.sale_id', $sale)
                ->where('sale_payments.payment_method_id', $method));
        }
    }

    /**
     * The Reports filters on a query over `purchases` or `purchase_returns`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|Builder  $query
     */
    public function narrowPurchases($query, array $filters, string $table): void
    {
        if ($filters['staff_id'] ?? null) {
            $query->where($table.'.posted_by', $filters['staff_id']);
        }
    }

    /** How many rows of a breakdown are sent. The screen shows fewer. */
    protected const BREAKDOWN_ROWS = 50;

    /**
     * The sale-line amount, with the document's discount spread across it.
     *
     * Multiplied before it is divided, on purpose: SQLite gives a DECIMAL
     * column integer affinity, so `1800 / 2000` is 0 there and 0.9 on
     * MySQL. Dividing last gives the same answer on both.
     */
    protected const DISCOUNTED_LINE =
        'CASE WHEN sales.subtotal > 0 '
        .'THEN sale_lines.line_total * sales.total_amount / sales.subtotal '
        .'ELSE sale_lines.line_total END';

    /**
     * Turn what went out and what came back into one net breakdown.
     *
     * Every breakdown is net of returns, exactly as the summary above it
     * is. A gross breakdown beside a net total is a report that does not
     * add up, and somebody always notices.
     *
     * `total` describes every group, not only the rows returned, so the
     * screen can show the first few and gather the rest into one honest
     * "Other" line however many there are. `$limit` null returns them all.
     *
     * @param  Collection  $out  Keyed by group.
     * @param  Collection  $back  Keyed by group.
     * @param  callable  $label  Builds the row's naming fields.
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function netBreakdown(
        $out,
        $back,
        callable $label,
        string $measure,
        ?int $limit = self::BREAKDOWN_ROWS,
    ): array {
        $rows = [];
        $wholeAmount = 0.0;
        // In thousandths: the measure is a quantity, which may be 2.5.
        $wholeMeasure = 0;

        foreach ($out as $key => $line) {
            $measured = Quantity::diff($line->{$measure}, $back[$key]->{$measure} ?? 0);
            $amount = (float) $line->amount - (float) ($back[$key]->amount ?? 0);

            // Everything that went out came back: it belongs in neither the
            // list nor the total.
            if ($measured <= 0 && $amount <= 0) {
                continue;
            }

            $rows[] = array_merge($label($line), [
                $measure => $measured,
                'amount' => $this->money($amount),
                'sort' => $amount,
            ]);

            $wholeAmount += $amount;
            $wholeMeasure += Quantity::toMilli($measured);
        }

        usort($rows, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        $total = [
            'count' => count($rows),
            $measure => Quantity::fromMilli($wholeMeasure),
            'amount' => $this->money($wholeAmount),
        ];

        return [
            'rows' => array_map(
                fn (array $row) => collect($row)->except('sort')->all(),
                $limit === null ? $rows : array_slice($rows, 0, $limit),
            ),
            'total' => $total,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function salesByProduct(Carbon $from, Carbon $to, array $filters = [], ?int $rows = self::BREAKDOWN_ROWS): array
    {
        $sold = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->whereBetween('sales.sale_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowSales($query, $filters, 'sales'))
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->select('products.id', 'products.sku', 'products.name')
            ->selectRaw('SUM(sale_lines.quantity) AS quantity')
            ->selectRaw('SUM('.self::DISCOUNTED_LINE.') AS amount')
            ->get()
            ->keyBy('id');

        $returned = DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->where('sales_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowSales($query, $filters, 'sales_returns'))
            ->groupBy('sales_return_lines.product_id')
            ->select('sales_return_lines.product_id AS id')
            ->selectRaw('SUM(sales_return_lines.quantity) AS quantity')
            ->selectRaw('SUM(sales_return_lines.line_refund) AS amount')
            ->get()
            ->keyBy('id');

        return $this->netBreakdown(
            $sold,
            $returned,
            fn ($line) => ['sku' => $line->sku, 'name' => $line->name],
            'quantity',
            $rows,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function salesByCustomer(Carbon $from, Carbon $to, array $filters = [], ?int $rows = self::BREAKDOWN_ROWS): array
    {
        $sold = DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->whereBetween('sales.sale_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowSales($query, $filters, 'sales'))
            ->groupBy('customers.id', 'customers.name')
            ->select('customers.id', 'customers.name')
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('SUM(sales.total_amount) AS amount')
            ->get()
            ->keyBy('id');

        // The refund follows the customer of the sale it undoes. The count
        // of documents is left alone: the sale still happened.
        $refunded = DB::table('sales_returns')
            ->join('sales', 'sales.id', '=', 'sales_returns.sale_id')
            ->where('sales_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowSales($query, $filters, 'sales_returns'))
            ->groupBy('sales.customer_id')
            ->select('sales.customer_id AS id')
            ->selectRaw('0 AS documents')
            ->selectRaw('SUM(sales_returns.total_amount) AS amount')
            ->get()
            ->keyBy('id');

        return $this->netBreakdown(
            $sold,
            $refunded,
            fn ($line) => ['name' => $line->name],
            'documents',
            $rows,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function purchasesBySupplier(Carbon $from, Carbon $to, array $filters = [], ?int $rows = self::BREAKDOWN_ROWS): array
    {
        $bought = DB::table('purchases')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereBetween('purchases.purchase_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowPurchases($query, $filters, 'purchases'))
            ->groupBy('suppliers.id', 'suppliers.name')
            ->select('suppliers.id', 'suppliers.name')
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('SUM(purchases.total_amount) AS amount')
            ->get()
            ->keyBy('id');

        $sentBack = DB::table('purchase_returns')
            ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->where('purchase_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowPurchases($query, $filters, 'purchase_returns'))
            ->groupBy('purchases.supplier_id')
            ->select('purchases.supplier_id AS id')
            ->selectRaw('0 AS documents')
            ->selectRaw('SUM(purchase_returns.total_cost) AS amount')
            ->get()
            ->keyBy('id');

        return $this->netBreakdown(
            $bought,
            $sentBack,
            fn ($line) => ['name' => $line->name],
            'documents',
            $rows,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: array<string, mixed>}
     */
    protected function purchasesByProduct(Carbon $from, Carbon $to, array $filters = [], ?int $rows = self::BREAKDOWN_ROWS): array
    {
        $bought = DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereBetween('purchases.purchase_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowPurchases($query, $filters, 'purchases'))
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->select('products.id', 'products.sku', 'products.name')
            ->selectRaw('SUM(purchase_lines.quantity) AS quantity')
            ->selectRaw('SUM(purchase_lines.line_total) AS amount')
            ->get()
            ->keyBy('id');

        $sentBack = DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
            ->where('purchase_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrowPurchases($query, $filters, 'purchase_returns'))
            ->groupBy('purchase_return_lines.product_id')
            ->select('purchase_return_lines.product_id AS id')
            ->selectRaw('SUM(purchase_return_lines.quantity) AS quantity')
            ->selectRaw('SUM(purchase_return_lines.line_cost) AS amount')
            ->get()
            ->keyBy('id');

        return $this->netBreakdown(
            $bought,
            $sentBack,
            fn ($line) => ['sku' => $line->sku, 'name' => $line->name],
            'quantity',
            $rows,
        );
    }

    /* ------------------------------------------------- The dashboard figures */

    /*
     * Everything below is built from document **lines**, not document
     * totals, and that is deliberate.
     *
     * A sale's `total_amount` belongs to the whole document. One sale can
     * hold three categories, so a document total cannot be split by
     * category — and the dashboard has to filter by one. So every figure
     * here is summed from the lines, with the document's discount spread
     * across them.
     *
     * The consequence, stated once so nobody is surprised: a line-derived
     * total can differ from a document total by a centavo or two, from
     * rounding. The dashboard is consistent with itself. Where an exact
     * document figure is wanted, the Reports screen states it.
     */

    /**
     * Posted sale lines in a period, narrowed by the dashboard's filters.
     *
     * @param  array{category_id?:?int, product_id?:?int}  $filters
     */
    protected function saleLines(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->whereBetween('sales.sale_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters, 'sales'));
    }

    protected function saleReturnLines(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->join('products', 'products.id', '=', 'sales_return_lines.product_id')
            ->where('sales_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters, 'sales_returns'));
    }

    protected function purchaseLines(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereBetween('purchases.purchase_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters, 'purchases'));
    }

    protected function purchaseReturnLines(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_lines.purchase_return_id')
            ->join('products', 'products.id', '=', 'purchase_return_lines.product_id')
            ->where('purchase_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_returns.return_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters, 'purchase_returns'));
    }

    /**
     * The one place the dashboard's filters are applied.
     *
     * `staff_id` narrows to the documents one user posted, so it needs the
     * document table the query reads. The ledger has no such column: a
     * query over movements passes no document and ignores the filter, and
     * the dashboard leaves those blocks out while it is set.
     */
    protected function narrow(Builder $query, array $filters, ?string $document = null): void
    {
        if ($document !== null && ($filters['staff_id'] ?? null)) {
            $query->where($document.'.posted_by', $filters['staff_id']);
        }

        if ($filters['category_id'] ?? null) {
            $query->where('products.category_id', $filters['category_id']);
        }

        if ($filters['product_id'] ?? null) {
            $query->where('products.id', $filters['product_id']);
        }
    }

    /**
     * What a period came to: money in, money out, and what it cost.
     *
     * One query per document kind, four in all. Every figure the hero cards
     * show is worked out from this one array, so they cannot disagree with
     * each other.
     *
     * @return array<string, float|int>
     */
    public function periodFigures(Carbon $from, Carbon $to, array $filters = []): array
    {
        $sold = $this->saleLines($from, $to, $filters)
            ->selectRaw('COALESCE(SUM('.self::DISCOUNTED_LINE.'), 0) AS amount')
            ->selectRaw('COALESCE(SUM(sale_lines.line_cost), 0) AS cost')
            ->selectRaw('COALESCE(SUM(sale_lines.quantity), 0) AS quantity')
            ->selectRaw('COUNT(DISTINCT sales.id) AS documents')
            // Each line's share of what went on account, so the Category and
            // Product filters can narrow it. Added up it is the whole figure.
            // Multiplied before it is divided: see the note in CLAUDE.md.
            ->selectRaw('COALESCE(SUM(CASE WHEN sales.subtotal > 0 THEN sale_lines.line_total * sales.on_account / sales.subtotal ELSE 0 END), 0) AS on_account')
            ->first();

        $returned = $this->saleReturnLines($from, $to, $filters)
            ->selectRaw('COALESCE(SUM(sales_return_lines.line_refund), 0) AS amount')
            ->selectRaw('COALESCE(SUM(sales_return_lines.line_cost), 0) AS cost')
            ->selectRaw('COALESCE(SUM(sales_return_lines.quantity), 0) AS quantity')
            ->selectRaw('COUNT(DISTINCT sales_returns.id) AS documents')
            ->selectRaw('COALESCE(SUM(CASE WHEN sales_returns.total_amount > 0 THEN sales_return_lines.line_refund * sales_returns.credited_amount / sales_returns.total_amount ELSE 0 END), 0) AS credited')
            ->first();

        $bought = $this->purchaseLines($from, $to, $filters)
            ->selectRaw('COALESCE(SUM(purchase_lines.line_total), 0) AS amount')
            ->selectRaw('COUNT(DISTINCT purchases.id) AS documents')
            // Each line's share of what went on the supplier's account
            // (phase 24), so the Category and Product filters narrow it.
            // Multiplied before it is divided: see the note in CLAUDE.md.
            ->selectRaw('COALESCE(SUM(CASE WHEN purchases.total_amount > 0 THEN purchase_lines.line_total * purchases.on_account / purchases.total_amount ELSE 0 END), 0) AS on_account')
            ->first();

        $sentBack = $this->purchaseReturnLines($from, $to, $filters)
            ->selectRaw('COALESCE(SUM(purchase_return_lines.line_cost), 0) AS amount')
            // What came back that moved no cash: taken off an unpaid bill,
            // or kept as credit with the supplier (phase 24).
            ->selectRaw('COALESCE(SUM(CASE WHEN purchase_returns.total_cost > 0 THEN purchase_return_lines.line_cost * (purchase_returns.credited_amount + purchase_returns.supplier_credit_amount) / purchase_returns.total_cost ELSE 0 END), 0) AS kept')
            ->first();

        $netSales = (float) $sold->amount - (float) $returned->amount;
        $cogs = (float) $sold->cost - (float) $returned->cost;
        $soldQuantity = Quantity::diff($sold->quantity, $returned->quantity);

        return [
            'sales_gross' => (float) $sold->amount,
            'net_sales' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $netSales - $cogs,
            'margin' => $netSales > 0 ? ($netSales - $cogs) / $netSales * 100 : 0.0,
            'net_purchases' => (float) $bought->amount - (float) $sentBack->amount,
            // Sold but not paid for: what went on account, less what returns
            // took back off it. What separates net sales from money in.
            'on_account_net' => (float) $sold->on_account - (float) $returned->credited,
            // Bought but not paid for (phase 24): what went on suppliers'
            // accounts, less what returns took off it or left as credit.
            // What separates net purchases from money out.
            'purchases_unpaid_net' => (float) $bought->on_account - (float) $sentBack->kept,
            'returns_value' => (float) $returned->amount,
            // Of what was sold, how much came back. Measured against gross,
            // because a rate against the net would shrink its own
            // denominator and read lower than the truth.
            'returns_rate' => (float) $sold->amount > 0
                ? (float) $returned->amount / (float) $sold->amount * 100
                : 0.0,
            'return_documents' => (int) $returned->documents,
            'transactions' => (int) $sold->documents,
            'average_sale' => (int) $sold->documents > 0
                ? $netSales / (int) $sold->documents
                : 0.0,
            'units_sold' => $soldQuantity,
            'purchase_documents' => (int) $bought->documents,
        ];
    }

    /**
     * What came in, by payment method: Cash 18,000, GCash 6,500.
     *
     * Read from the payments each sale records, which add up to its total
     * exactly, over posted sales in the period — and, since phase 19, from
     * what customers paid against their invoices in the period, dated the
     * day the money came in. A voided sale or payment took nothing. The
     * Staff filter applies, because a payment belongs to a document and a
     * document to whoever posted it.
     *
     * One query either way: the two kinds of payment are read as one list
     * and added up by method, so the dashboard's query budget does not grow.
     * `account` is the part that came in against invoices.
     *
     * Null while the dashboard is narrowed to a category or a product: one
     * GCash payment for cement and nails together cannot be split between
     * them. The caller falls back to the line figure then.
     *
     * `$accountPayments` false leaves invoices paid out: the till's day adds
     * up to its own sales, and the drawer count is stage 4.
     *
     * @param  array{category_id?:?int, product_id?:?int, staff_id?:?int, payment_method_id?:?int}  $filters
     * @return array{total: float, account: float, methods: list<array{name:string, kind:string, amount:string}>}|null
     */
    public function collections(Carbon $from, Carbon $to, array $filters = [], bool $accountPayments = true): ?array
    {
        if (($filters['category_id'] ?? null) || ($filters['product_id'] ?? null)) {
            return null;
        }

        $staff = $filters['staff_id'] ?? null;
        // The Reports screen's payment method (phase 34): only that method's
        // money, from the till and from payments against invoices alike.
        $method = $filters['payment_method_id'] ?? null;

        $paid = DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->whereBetween('sales.sale_date', $this->bounds($from, $to))
            ->when($staff, fn (Builder $q, $id) => $q->where('sales.posted_by', $id))
            ->when($method, fn (Builder $q, $id) => $q->where('sale_payments.payment_method_id', $id))
            ->select('sale_payments.payment_method_id')
            // Whole centavos, so the methods add up to the total exactly.
            ->selectRaw('ROUND(sale_payments.amount * 100) AS cents')
            ->selectRaw('0 AS on_account');

        if ($accountPayments) {
            $paid->unionAll(
                DB::table('customer_payments')
                    ->where('customer_payments.status', DocumentStatus::Posted->value)
                    ->whereBetween('customer_payments.payment_date', $this->bounds($from, $to))
                    ->when($staff, fn (Builder $q, $id) => $q->where('customer_payments.posted_by', $id))
                    ->when($method, fn (Builder $q, $id) => $q->where('customer_payments.payment_method_id', $id))
                    ->select('customer_payments.payment_method_id')
                    ->selectRaw('ROUND(customer_payments.amount * 100) AS cents')
                    ->selectRaw('1 AS on_account')
            );
        }

        $rows = DB::query()
            ->fromSub($paid, 'paid')
            ->join('payment_methods', 'payment_methods.id', '=', 'paid.payment_method_id')
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.kind')
            ->selectRaw('payment_methods.name AS name, payment_methods.kind AS kind')
            ->selectRaw('COALESCE(SUM(paid.cents), 0) AS cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN paid.on_account = 1 THEN paid.cents ELSE 0 END), 0) AS account_cents')
            ->get();

        $methods = $rows
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'kind' => (string) $row->kind,
                'cents' => (int) $row->cents,
                'account_cents' => (int) $row->account_cents,
            ])
            // Cash first, as the till shows it; then the biggest.
            ->sortBy([
                fn ($a, $b) => ($a['kind'] === 'cash' ? 0 : 1) <=> ($b['kind'] === 'cash' ? 0 : 1),
                fn ($a, $b) => $b['cents'] <=> $a['cents'],
            ])
            ->values();

        return [
            'total' => $methods->sum('cents') / 100,
            'account' => $methods->sum('account_cents') / 100,
            'methods' => $methods->map(fn ($m) => [
                'name' => $m['name'],
                'kind' => $m['kind'],
                'amount' => $this->money($m['cents'] / 100),
            ])->all(),
        ];
    }

    /**
     * Money paid to suppliers against their bills in a period (phase 24),
     * dated the day it went out. Supplier credit used is not money and is
     * left out. One query.
     *
     * Null while narrowed to a category or a product: one payment for
     * several bills cannot be split between goods. The Staff filter applies,
     * by who recorded the payment.
     *
     * @param  array{category_id?:?int, product_id?:?int, staff_id?:?int}  $filters
     */
    public function supplierPaid(Carbon $from, Carbon $to, array $filters = []): ?float
    {
        if (($filters['category_id'] ?? null) || ($filters['product_id'] ?? null)) {
            return null;
        }

        $cents = (int) DB::table('supplier_payments')
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('payment_date', $this->bounds($from, $to))
            ->when($filters['staff_id'] ?? null, fn (Builder $q, $id) => $q->where('posted_by', $id))
            ->sum(DB::raw('ROUND(amount * 100)'));

        return $cents / 100;
    }

    /**
     * The same figures, bucket by bucket, for the sparklines and the chart.
     *
     * Two grouped queries for sales and two for purchases, whatever the
     * number of buckets. The returns are subtracted in PHP, as elsewhere.
     *
     * @return array{categories: list<string>, sales: list<float>, purchases: list<float>, profit: list<float>}
     */
    public function periodSeries(
        Carbon $from,
        Carbon $to,
        array $filters = [],
        string $grain = 'auto',
    ): array {
        [$keys, $labels, $length, $rollUp] = $this->buckets($from, $to, $grain);

        $sold = $this->fold($this->saleLines($from, $to, $filters)
            ->selectRaw("SUBSTR(sales.sale_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM('.self::DISCOUNTED_LINE.') AS amount')
            ->selectRaw('SUM(sale_lines.line_cost) AS cost'), $rollUp);

        $returned = $this->fold($this->saleReturnLines($from, $to, $filters)
            ->selectRaw("SUBSTR(sales_returns.return_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM(sales_return_lines.line_refund) AS amount')
            ->selectRaw('SUM(sales_return_lines.line_cost) AS cost'), $rollUp);

        $bought = $this->fold($this->purchaseLines($from, $to, $filters)
            ->selectRaw("SUBSTR(purchases.purchase_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM(purchase_lines.line_total) AS amount'), $rollUp);

        $sentBack = $this->fold($this->purchaseReturnLines($from, $to, $filters)
            ->selectRaw("SUBSTR(purchase_returns.return_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM(purchase_return_lines.line_cost) AS amount'), $rollUp);

        $sales = [];
        $purchases = [];
        $profit = [];

        foreach ($keys as $key) {
            $net = ($sold[$key]['amount'] ?? 0) - ($returned[$key]['amount'] ?? 0);
            $cost = ($sold[$key]['cost'] ?? 0) - ($returned[$key]['cost'] ?? 0);

            $sales[] = round($net, 2);
            $purchases[] = round(($bought[$key]['amount'] ?? 0) - ($sentBack[$key]['amount'] ?? 0), 2);
            $profit[] = round($net - $cost, 2);
        }

        return [
            'categories' => $labels,
            'sales' => $sales,
            'purchases' => $purchases,
            'profit' => $profit,
            'grain' => $grain,
        ];
    }

    /**
     * Run a bucketed query and key it, rolling days into weeks if asked.
     *
     * @param  array<string, string>  $rollUp  Day to week, empty otherwise.
     * @return array<string, array<string, float>>
     */
    protected function fold(Builder $query, array $rollUp = []): array
    {
        $folded = [];

        foreach ($query->groupBy('bucket')->get() as $row) {
            $values = (array) $row;
            $bucket = $values['bucket'];
            unset($values['bucket']);

            $key = $rollUp[$bucket] ?? $bucket;

            foreach ($values as $column => $value) {
                $folded[$key][$column] = ($folded[$key][$column] ?? 0) + (float) $value;
            }
        }

        return $folded;
    }

    /**
     * What came back, both ways, over the period.
     *
     * Plotted apart rather than netted off, because they are different
     * problems: goods a customer rejected and goods you rejected.
     *
     * @return array{categories: list<string>, sales: list<float>, purchases: list<float>}
     */
    public function returnsSeries(
        Carbon $from,
        Carbon $to,
        array $filters = [],
        string $grain = 'auto',
    ): array {
        [$keys, $labels, $length, $rollUp] = $this->buckets($from, $to, $grain);

        $fromCustomers = $this->fold($this->saleReturnLines($from, $to, $filters)
            ->selectRaw("SUBSTR(sales_returns.return_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM(sales_return_lines.line_refund) AS amount'), $rollUp);

        $toSuppliers = $this->fold($this->purchaseReturnLines($from, $to, $filters)
            ->selectRaw("SUBSTR(purchase_returns.return_date, 1, {$length}) AS bucket")
            ->selectRaw('SUM(purchase_return_lines.line_cost) AS amount'), $rollUp);

        return [
            'categories' => $labels,
            'sales' => array_map(fn ($k) => round($fromCustomers[$k]['amount'] ?? 0, 2), $keys),
            'purchases' => array_map(fn ($k) => round($toSuppliers[$k]['amount'] ?? 0, 2), $keys),
        ];
    }

    /**
     * What each category sold, net of returns.
     *
     * @return array{labels: list<string>, series: list<float>, total: string}
     */
    public function salesByCategory(Carbon $from, Carbon $to, array $filters = []): array
    {
        $sold = $this->saleLines($from, $to, $filters)
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->select('categories.id', 'categories.name')
            ->selectRaw('SUM('.self::DISCOUNTED_LINE.') AS amount')
            ->get()
            ->keyBy('id');

        $returned = $this->saleReturnLines($from, $to, $filters)
            ->groupBy('products.category_id')
            ->select('products.category_id AS id')
            ->selectRaw('SUM(sales_return_lines.line_refund) AS amount')
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($sold as $id => $row) {
            $amount = (float) $row->amount - (float) ($returned[$id]->amount ?? 0);

            if ($amount > 0) {
                $rows[] = ['label' => $row->name, 'amount' => round($amount, 2)];
            }
        }

        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'labels' => array_column($rows, 'label'),
            'series' => array_column($rows, 'amount'),
            'total' => $this->money(array_sum(array_column($rows, 'amount'))),
        ];
    }

    /**
     * How stock moved in the period, by reason.
     *
     * Read from the ledger rather than from the documents, because the
     * ledger is where stock actually moved — a void shows here as the
     * reversal it is.
     *
     * @return array{labels: list<string>, received: list<int|float>, issued: list<int|float>}
     */
    public function movementSummary(Carbon $from, Carbon $to, array $filters = []): array
    {
        $rows = DB::table('inventory_movements')
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->whereBetween('inventory_movements.movement_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters))
            ->groupBy('inventory_movements.type')
            ->select('inventory_movements.type')
            ->selectRaw('COALESCE(SUM(quantity_in), 0) AS received')
            ->selectRaw('COALESCE(SUM(quantity_out), 0) AS issued')
            ->get();

        $labels = [];
        $received = [];
        $issued = [];

        foreach ($rows as $row) {
            $type = MovementType::tryFrom($row->type);

            $labels[] = $type?->label() ?? $row->type;
            $received[] = Quantity::normalize($row->received);
            $issued[] = Quantity::normalize($row->issued);
        }

        return ['labels' => $labels, 'received' => $received, 'issued' => $issued];
    }

    /**
     * Products ranked by what they sold, net of returns.
     *
     * Both figures are sent, so the screen can switch between money and
     * units without asking again.
     *
     * @return list<array<string, mixed>>
     */
    public function productRanking(Carbon $from, Carbon $to, array $filters = [], int $limit = 10): array
    {
        $breakdown = $this->salesByProduct($from, $to, $filters);

        return array_slice($breakdown['rows'], 0, $limit);
    }

    /**
     * Stock that is sitting there.
     *
     * Products holding stock that sold least in the period, worst first.
     * A product that sold nothing at all is the worst of them, so the join
     * has to keep it — an inner join would drop exactly the rows this is
     * looking for.
     *
     * @return list<array<string, mixed>>
     */
    public function slowestMovers(Carbon $from, Carbon $to, array $filters = [], int $limit = 5): array
    {
        $sold = $this->saleLines($from, $to, $filters)
            ->groupBy('sale_lines.product_id')
            ->select('sale_lines.product_id AS id')
            ->selectRaw('SUM(sale_lines.quantity) AS quantity')
            ->selectRaw('SUM('.self::DISCOUNTED_LINE.') AS amount')
            ->get()
            ->keyBy('id');

        $rows = Product::query()
            ->where('is_active', true)
            ->where('on_hand', '>', 0)
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'sku', 'name', 'on_hand'])
            ->map(fn (Product $product) => [
                'sku' => $product->sku,
                'name' => $product->name,
                'on_hand' => $product->on_hand,
                'quantity' => Quantity::normalize($sold[$product->id]->quantity ?? 0),
                'amount' => $this->money((float) ($sold[$product->id]->amount ?? 0)),
            ])
            ->sortBy([['quantity', 'asc'], ['on_hand', 'desc']])
            ->take($limit)
            ->values()
            ->all();

        return $rows;
    }

    /* ----------------------------------------------- What needs attention */

    /**
     * Products at or below their reorder level, with what to do about it.
     *
     * Carries the shortfall and the last delivery, because the question
     * that follows "this is low" is always "how many, and what did it cost
     * last time?". Both come from one extra query, not one per row.
     *
     * @return list<array<string, mixed>>
     */
    public function lowStockDetail(array $filters = [], int $limit = 8): array
    {
        $products = Product::query()
            ->with('unit:id,code')
            ->where('is_active', true)
            ->lowStock()
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderByRaw('(reorder_level - on_hand) DESC')
            ->orderBy('sku')
            ->limit($limit)
            ->get(['id', 'sku', 'name', 'on_hand', 'reorder_level', 'unit_id']);

        $lastBought = $this->lastDeliveryFor($products->pluck('id')->all());

        return $products->map(function (Product $product) use ($lastBought) {
            $last = $lastBought[$product->id] ?? null;

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'on_hand' => $product->on_hand,
                'reorder_level' => $product->reorder_level,
                'unit' => $product->unit?->code,
                'shortfall' => max(0, Quantity::diff($product->reorder_level, $product->on_hand)),
                // Enough to clear the shortfall and stand at the reorder
                // level again, rounded up to a whole unit.
                'suggested' => max(1, Quantity::diff($product->reorder_level, $product->on_hand)),
                'last_bought_on' => $last ? Carbon::parse($last->bought_on)->format('d M Y') : null,
                'last_cost' => $last ? $this->money((float) $last->unit_cost) : null,
            ];
        })->all();
    }

    /**
     * The most recent delivery of each product, in one query.
     *
     * @param  list<int>  $productIds
     */
    protected function lastDeliveryFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereIn('purchase_lines.product_id', $productIds)
            ->groupBy('purchase_lines.product_id')
            ->select('purchase_lines.product_id')
            ->selectRaw('MAX(purchases.purchase_date) AS bought_on')
            // The cost of whichever line is newest. On a tie this takes the
            // dearer, which is the safer number to reorder against.
            ->selectRaw('MAX(purchase_lines.unit_cost) AS unit_cost')
            ->get()
            ->keyBy('product_id')
            ->all();
    }

    /**
     * Stock that has not sold for a long time.
     *
     * "A long time" is a setting, because what counts as slow differs by
     * trade. A product that has never sold at all is in here too, and is
     * the worst case: the join has to keep it.
     *
     * @return list<array<string, mixed>>
     */
    public function deadStock(int $days, array $filters = [], int $limit = 8): array
    {
        $cutoff = Carbon::today()->subDays($days);

        $lastSold = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->groupBy('sale_lines.product_id')
            ->select('sale_lines.product_id')
            ->selectRaw('MAX(sales.sale_date) AS sold_on')
            ->get()
            ->keyBy('product_id');

        return Product::query()
            ->where('is_active', true)
            ->where('on_hand', '>', 0)
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'sku', 'name', 'on_hand', 'cost'])
            ->map(function (Product $product) use ($lastSold, $cutoff) {
                $sold = $lastSold[$product->id]->sold_on ?? null;
                $soldAt = $sold ? Carbon::parse($sold) : null;

                if ($soldAt && $soldAt->gte($cutoff)) {
                    return null;
                }

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'on_hand' => $product->on_hand,
                    'tied_up' => $this->money(Quantity::lineTotal($product->on_hand, $product->cost)),
                    'last_sold_on' => $soldAt?->format('d M Y'),
                    'days_idle' => $soldAt ? $soldAt->diffInDays(Carbon::today()) : null,
                ];
            })
            ->filter()
            // The most money standing still, first.
            ->sortByDesc(fn (array $row) => (float) $row['tied_up'])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Products coming back more often than they should.
     *
     * A share, not a count: two of two returned is a worse sign than ten of
     * five hundred. A floor on the units sold keeps a single return on a
     * single sale from topping the list every time.
     *
     * @return list<array<string, mixed>>
     */
    public function highReturns(
        Carbon $from,
        Carbon $to,
        float $rate,
        array $filters = [],
        int $limit = 5,
        int $floor = 5,
    ): array {
        $sold = $this->saleLines($from, $to, $filters)
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->select('products.id', 'products.sku', 'products.name')
            ->selectRaw('SUM(sale_lines.quantity) AS quantity')
            ->get()
            ->keyBy('id');

        $returned = $this->saleReturnLines($from, $to, $filters)
            ->groupBy('sales_return_lines.product_id')
            ->select('sales_return_lines.product_id AS id')
            ->selectRaw('SUM(sales_return_lines.quantity) AS quantity')
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($sold as $id => $line) {
            $out = Quantity::normalize($line->quantity);
            $back = Quantity::normalize($returned[$id]->quantity ?? 0);

            if ($out < $floor || $back === 0) {
                continue;
            }

            $share = $back / $out * 100;

            if ($share < $rate) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'sku' => $line->sku,
                'name' => $line->name,
                'sold' => $out,
                'returned' => $back,
                'rate' => round($share, 1),
            ];
        }

        usort($rows, fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Posted decrease adjustment lines in a period: stock written off.
     *
     * Every reason counts — damage, theft, a write-off, and a recount that
     * found less than the books said. Each one is stock the business paid
     * for and can no longer sell. Dated by the adjustment, narrowed like
     * every other figure here, and costed by the batches the lines ate.
     */
    protected function lossLines(Carbon $from, Carbon $to, array $filters = []): Builder
    {
        return DB::table('inventory_adjustment_lines')
            ->join('inventory_adjustments', 'inventory_adjustments.id', '=', 'inventory_adjustment_lines.inventory_adjustment_id')
            ->join('products', 'products.id', '=', 'inventory_adjustment_lines.product_id')
            ->where('inventory_adjustments.status', DocumentStatus::Posted->value)
            ->where('inventory_adjustments.kind', InventoryAdjustment::KIND_DECREASE)
            ->whereBetween('inventory_adjustments.adjustment_date', $this->bounds($from, $to))
            ->tap(fn (Builder $query) => $this->narrow($query, $filters, 'inventory_adjustments'));
    }

    /**
     * What was lost in a period, in units and at cost, and by reason.
     *
     * The value is left out unless the caller may read cost: it is what
     * the lost goods cost, which is exactly the figure `sales.view_cost`
     * guards.
     *
     * @return array{documents:int, quantity:int|float, value:?float, by_reason:list<array<string, mixed>>}
     */
    public function lossTotals(Carbon $from, Carbon $to, array $filters = [], bool $withCost = false): array
    {
        $totals = $this->lossLines($from, $to, $filters)
            ->selectRaw('COUNT(DISTINCT inventory_adjustments.id) AS documents')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.total_cost), 0) AS value')
            ->first();

        $byReason = $this->lossLines($from, $to, $filters)
            ->groupBy('inventory_adjustments.reason')
            ->select('inventory_adjustments.reason')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.total_cost), 0) AS value')
            ->orderByDesc($withCost ? 'value' : 'quantity')
            ->get()
            ->map(fn ($row) => array_merge([
                'reason' => $row->reason,
                'label' => StoreAdjustmentRequest::REASONS[$row->reason] ?? ($row->reason ?? 'No reason'),
                'quantity' => Quantity::normalize($row->quantity),
            ], $withCost ? ['value' => $this->money((float) $row->value)] : []))
            ->all();

        return [
            'documents' => (int) $totals->documents,
            'quantity' => Quantity::normalize($totals->quantity),
            'value' => $withCost ? (float) $totals->value : null,
            'by_reason' => $byReason,
        ];
    }

    /**
     * The biggest losses of the period, one row per adjustment.
     *
     * Ranked by what they cost for somebody who may read cost, and by the
     * units for everybody else, so the order gives nothing away either.
     *
     * @return list<array<string, mixed>>
     */
    public function lossRows(Carbon $from, Carbon $to, array $filters = [], bool $withCost = false, int $limit = 5): array
    {
        return $this->lossLines($from, $to, $filters)
            ->groupBy(
                'inventory_adjustments.id',
                'inventory_adjustments.reference',
                'inventory_adjustments.reason',
                'inventory_adjustments.adjustment_date',
            )
            ->select(
                'inventory_adjustments.id',
                'inventory_adjustments.reference',
                'inventory_adjustments.reason',
                'inventory_adjustments.adjustment_date',
            )
            ->selectRaw('MIN(products.name) AS product')
            ->selectRaw('COUNT(DISTINCT inventory_adjustment_lines.product_id) AS products')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.quantity), 0) AS quantity')
            ->selectRaw('COALESCE(SUM(inventory_adjustment_lines.total_cost), 0) AS value')
            ->orderByDesc($withCost ? 'value' : 'quantity')
            ->orderByDesc('inventory_adjustments.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => array_merge([
                'id' => (int) $row->id,
                'reference' => $row->reference,
                'reason' => StoreAdjustmentRequest::REASONS[$row->reason] ?? ($row->reason ?? 'No reason'),
                'product' => (int) $row->products > 1
                    ? sprintf('%s and %d more', $row->product, (int) $row->products - 1)
                    : $row->product,
                'quantity' => Quantity::normalize($row->quantity),
                'on' => Carbon::parse($row->adjustment_date)->format('d M Y'),
            ], $withCost ? ['value' => $this->money((float) $row->value)] : []))
            ->all();
    }

    /**
     * The best and the worst month of the period.
     *
     * Only worth saying when there is more than one month to compare.
     *
     * @return array{best: ?array<string, string>, worst: ?array<string, string>}
     */
    public function bestAndWorstMonth(Carbon $from, Carbon $to, array $filters = []): array
    {
        $series = $this->periodSeries($from, $to, $filters, 'month');

        if (count($series['categories']) < 2) {
            return ['best' => null, 'worst' => null];
        }

        $sales = $series['sales'];
        $best = array_keys($sales, max($sales))[0];
        $worst = array_keys($sales, min($sales))[0];

        return [
            'best' => ['month' => $series['categories'][$best], 'amount' => $this->money($sales[$best])],
            'worst' => ['month' => $series['categories'][$worst], 'amount' => $this->money($sales[$worst])],
        ];
    }

    /**
     * Products ranked, with what each one earned.
     *
     * The cost columns are only worked out when the caller may read them,
     * so a user without `sales.view_cost` is not sent a margin to hide.
     *
     * @return list<array<string, mixed>>
     */
    public function productPerformance(
        Carbon $from,
        Carbon $to,
        array $filters = [],
        bool $withCost = false,
        int $limit = 10,
    ): array {
        $sold = $this->saleLines($from, $to, $filters)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.on_hand', 'categories.name')
            ->select('products.id', 'products.sku', 'products.name', 'products.on_hand')
            ->selectRaw('COALESCE(categories.name, \'Uncategorised\') AS category')
            ->selectRaw('SUM(sale_lines.quantity) AS quantity')
            ->selectRaw('SUM('.self::DISCOUNTED_LINE.') AS amount')
            ->selectRaw('SUM(sale_lines.line_cost) AS cost')
            ->get()
            ->keyBy('id');

        $returned = $this->saleReturnLines($from, $to, $filters)
            ->groupBy('sales_return_lines.product_id')
            ->select('sales_return_lines.product_id AS id')
            ->selectRaw('SUM(sales_return_lines.quantity) AS quantity')
            ->selectRaw('SUM(sales_return_lines.line_refund) AS amount')
            ->selectRaw('SUM(sales_return_lines.line_cost) AS cost')
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($sold as $id => $line) {
            $quantity = Quantity::diff($line->quantity, $returned[$id]->quantity ?? 0);
            $amount = (float) $line->amount - (float) ($returned[$id]->amount ?? 0);
            $cost = (float) $line->cost - (float) ($returned[$id]->cost ?? 0);

            if ($quantity <= 0 && $amount <= 0) {
                continue;
            }

            $rows[] = array_merge([
                'sku' => $line->sku,
                'name' => $line->name,
                'category' => $line->category,
                'on_hand' => Quantity::normalize($line->on_hand),
                'quantity' => $quantity,
                'amount' => $this->money($amount),
                'sort' => $amount,
            ], $withCost ? [
                'cost' => $this->money($cost),
                'profit' => $this->money($amount - $cost),
                'margin' => $amount > 0 ? round(($amount - $cost) / $amount * 100, 1) : 0.0,
            ] : []);
        }

        usort($rows, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return array_map(
            fn (array $row) => collect($row)->except('sort')->all(),
            array_slice($rows, 0, $limit),
        );
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * Throw every cached figure away.
     *
     * Called from the ledger after a document commits, so a posting is
     * visible at once rather than up to five minutes later.
     */
    /**
     * Throw every cached figure away.
     *
     * Done by moving the generation on, not by naming keys. The dashboard
     * caches a block per period, per filter and per permission set, which
     * is an unbounded number of keys — and the database cache driver has no
     * tags to sweep them with. Every key carries the generation, so raising
     * it by one makes all of them unreachable at once, and they expire on
     * their own afterwards.
     *
     * The old version of this listed its keys by hand and had to guess
     * which limits had been asked for. It would have been wrong the first
     * time somebody called `topSellers(7)`.
     */
    public function flush(): void
    {
        Cache::forever(self::PREFIX.'generation', $this->generation() + 1);
    }

    /**
     * Which generation of figures is current.
     *
     * `forever`, because losing it would silently serve stale figures. It
     * is one small row, read once per cached block.
     */
    protected function generation(): int
    {
        return (int) Cache::rememberForever(self::PREFIX.'generation', fn () => 1);
    }

    /**
     * Remember a figure that depends on a period, a filter and a permission.
     *
     * The dashboard asks the same question many ways, so the key has to
     * carry every input that changes the answer. Anything left out of it
     * would hand one user another user's figures.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function cached(string $block, array $inputs, callable $callback): mixed
    {
        return $this->remember($block.'.'.md5(json_encode($inputs)), $callback);
    }

    /** The stock figures, read from the one service that owns them. */
    protected function lowStockSummary(): array
    {
        return $this->valuation->summary();
    }

    /**
     * The period every figure covers unless told otherwise: this year so
     * far, which is what the mock-up's January-to-now chart shows.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public function range(?Carbon $from = null, ?Carbon $to = null): array
    {
        return [
            $from ?? Carbon::today()->startOfYear(),
            $to ?? Carbon::today(),
        ];
    }

    /**
     * One figure per month, zero-filled, less whatever came back.
     *
     * @param  list<string>  $months  As "2026-01".
     * @return list<float>
     */
    /**
     * One figure per bucket, net of what came back.
     *
     * `$length` is how much of the date makes the bucket: 7 for a month
     * (`2026-01`), 10 for a day (`2026-01-18`). A return counts against the
     * bucket it happened in, not the bucket of the document it undoes —
     * that is when the money moved.
     *
     * @param  list<string>  $buckets  The keys to report, in order.
     * @return list<float>
     */
    protected function byMonth(
        array $buckets,
        $documents,
        string $dateColumn,
        string $amountColumn,
        $returns,
        string $returnDateColumn,
        string $returnAmountColumn,
        int $length = 7,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $out = $documents
            ->when($from && $to, fn ($q) => $q->whereBetween($dateColumn, $this->bounds($from, $to)))
            ->selectRaw("SUBSTR({$dateColumn}, 1, {$length}) AS bucket")
            ->selectRaw("SUM({$amountColumn}) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $back = $returns
            ->when($from && $to, fn ($q) => $q->whereBetween($returnDateColumn, $this->bounds($from, $to)))
            ->selectRaw("SUBSTR({$returnDateColumn}, 1, {$length}) AS bucket")
            ->selectRaw("SUM({$returnAmountColumn}) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        return array_map(
            fn (string $bucket) => round((float) ($out[$bucket] ?? 0) - (float) ($back[$bucket] ?? 0), 2),
            $buckets,
        );
    }

    /**
     * The two ends of a period, as the database stores them.
     *
     * The date cast writes a full datetime. MySQL's DATE column truncates
     * it; SQLite keeps it. Comparing against the bare date would therefore
     * drop everything posted today on SQLite — the tests would pass on one
     * engine and fail on the other, which is worse than failing on both.
     *
     * @return array{0:string,1:string}
     */
    protected function bounds(Carbon $from, Carbon $to): array
    {
        return [$from->copy()->startOfDay()->toDateTimeString(), $to->copy()->endOfDay()->toDateTimeString()];
    }

    protected function remember(string $key, callable $callback): mixed
    {
        return Cache::remember(
            self::PREFIX.$this->generation().'.'.$key,
            self::TTL,
            $callback,
        );
    }

    protected function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
