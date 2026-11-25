<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Services\DashboardMetrics;
use App\Services\DashboardOverview;
use App\Services\InventoryMetrics;
use App\Services\InventoryValuation;
use App\Support\StaffOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardMetrics $metrics,
        protected InventoryMetrics $inventory,
        protected DashboardOverview $overview,
        protected InventoryValuation $valuation,
    ) {}

    /**
     * The dashboard.
     *
     * Only the greeting is sent straight away. Every figure is deferred, so
     * the shell paints at once and the skeletons stand in until the numbers
     * arrive. The figures themselves come from a cache, so a reload inside
     * five minutes costs no query at all.
     */
    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);
        $seesAllStaff = $request->user()->can('dashboard.view_all_staff');

        // Whose documents are added up. Somebody without the permission is
        // held to their own, whatever the address asks for: the filter is
        // decided here, not trusted from the browser.
        $filters['staff_id'] = $seesAllStaff ? $filters['staff_id'] : $request->user()->id;

        [$from, $to, $label] = $this->overview->resolve(
            $filters['period'],
            $filters['from'],
            $filters['to'],
        );

        $seesSales = $request->user()->can('sales.view');
        $seesPurchases = $request->user()->can('purchases.view');
        $seesStock = $request->user()->can('stock.view');
        $seesCost = $request->user()->can('viewCost', Sale::class);

        $narrow = [
            'category_id' => $filters['category_id'],
            'product_id' => $filters['product_id'],
            'staff_id' => $filters['staff_id'],
        ];

        // The cards also need to know the period by name, to say "Today's
        // sales" rather than "Sales".
        $cardFilters = $narrow + ['period' => $filters['period']];

        // The ledger has no column saying who moved stock, so the movement
        // chart cannot follow the Staff filter. It is left out while one is
        // set rather than showing everybody's movements under one name.
        $byStaff = $filters['staff_id'] !== null;

        $may = [
            'sales' => $seesSales,
            'purchases' => $seesPurchases,
            'stock' => $seesStock,
            'cost' => $seesCost,
            'adjustments' => $request->user()->can('adjustments.view'),
            // Only decide where the overdue card and its list link to: the
            // Aging report and a customer's page each have a permission.
            'receivables' => $request->user()->can('customer_payments.view'),
            'customers' => $request->user()->can('customers.view'),
            // The Purchase requests card and its list (phase 21).
            'purchase_requests' => $request->user()->can('purchase_requests.view'),
            // The Payables card (phase 24).
            'payables' => $request->user()->can('supplier_payments.view'),
            // The Orders to approve card (phase 24.4).
            'purchase_orders' => $request->user()->can('purchase_orders.view'),
        ];

        return Inertia::render('Dashboard', [
            'title' => 'Dashboard',
            'greeting' => [
                'name' => $request->user()->name,
                'username' => $request->user()->username,
                'today' => now()->format('l, d F Y'),
                'timezone' => config('app.timezone'),
            ],

            'filters' => array_merge($filters, [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $label,
            ]),

            'options' => [
                'periods' => collect(DashboardOverview::PERIODS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
                'categories' => Category::where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->name])
                    ->all(),
                'products' => Product::orderBy('sku')
                    ->get(['id', 'sku', 'name'])
                    ->map(fn (Product $p) => ['value' => $p->id, 'label' => $p->sku.' — '.$p->name])
                    ->all(),
                // Only the people this user may see. Without the
                // permission that is themselves, and nobody else's name
                // is sent at all.
                'staff' => $this->staffOptions($request, $seesAllStaff),
            ],
            'staffLocked' => ! $seesAllStaff,

            // The hero cards. Deferred as one block: they are worked out
            // together from the same figures, so splitting them would ask
            // the database the same questions twice.
            'overview' => Inertia::defer(
                fn () => $this->overview->cards($from, $to, $cardFilters, $may),
            ),

            // The short lists under the cards. With the cards, because the
            // owner reads them together.
            'exceptions' => Inertia::defer(
                fn () => $this->overview->exceptions($from, $to, $narrow, $may),
            ),

            // The charts. One deferred group, so they arrive together
            // rather than in six separate round trips.
            'trend' => $seesSales || $seesPurchases
                ? Inertia::defer(
                    fn () => $this->withoutProfitUnless($seesCost, $this->cached('trend', $from, $to, $narrow, $filters['grain'],
                        fn () => $this->inventory->periodSeries($from, $to, $narrow, $filters['grain']))),
                    'charts',
                )
                : null,

            'returnsTrend' => $seesSales || $seesPurchases
                ? Inertia::defer(
                    fn () => $this->cached('returns', $from, $to, $narrow, $filters['grain'],
                        fn () => $this->inventory->returnsSeries($from, $to, $narrow, $filters['grain'])),
                    'charts',
                )
                : null,

            'salesByCategory' => $seesSales
                ? Inertia::defer(
                    fn () => $this->cached('sales_by_category', $from, $to, $narrow, null,
                        fn () => $this->inventory->salesByCategory($from, $to, $narrow)),
                    'charts',
                )
                : null,

            // What the stock is worth, by category: a value at cost, so it
            // follows the cost permission as the Inventory value card does.
            'stockByCategory' => $seesStock && $seesCost
                ? Inertia::defer(fn () => $this->cached('stock_by_category', $from, $to, $narrow, null,
                    fn () => $this->valuation->valueByCategory($narrow)), 'charts')
                : null,

            'movements' => $request->user()->can('movements.view') && ! $byStaff
                ? Inertia::defer(
                    fn () => $this->cached('movements', $from, $to, $narrow, null,
                        fn () => $this->inventory->movementSummary($from, $to, $narrow)),
                    'charts',
                )
                : null,

            // Both the money and the units, so the screen can switch
            // between them without asking again.
            'ranking' => $seesSales
                ? Inertia::defer(
                    fn () => $this->cached('ranking', $from, $to, $narrow, $seesCost,
                        fn () => $this->inventory->productPerformance($from, $to, $narrow, $seesCost, 10)),
                    'charts',
                )
                : null,

            // What somebody has to do something about, and what the figures
            // are saying. Their own deferred group, because they are the
            // slowest block and should not hold the charts up.
            'attention' => Inertia::defer(
                fn () => $this->overview->attention($from, $to, $narrow, $may),
                'attention',
            ),

            'insights' => Inertia::defer(
                fn () => $this->overview->insights($from, $to, $narrow, $may),
                'attention',
            ),

            'slowest' => $seesSales && $seesStock
                ? Inertia::defer(
                    fn () => $this->cached('slowest', $from, $to, $narrow, null,
                        fn () => $this->inventory->slowestMovers($from, $to, $narrow, 5)),
                    'charts',
                )
                : null,

            // The audit feed stays, because it answers a different question
            // from the figures above it — but it is audit data, so it
            // follows the audit permission.
            'recent' => $request->user()->can('audit.view')
                ? Inertia::defer(fn () => $this->metrics->recentActivity(12), 'feed')
                : null,

            // Only what the screen actually reads. The quick-action and
            // navigation flags went when their buttons did: the sidebar
            // already opens those screens, and a prop nothing reads is a
            // question asked of the database for nobody.
            'can' => [
                'audit' => $request->user()->can('audit.view'),
                'sales' => $seesSales,
                'purchases' => $seesPurchases,
                'stock' => $seesStock,
                'cost' => $seesCost,
            ],
        ]);
    }

    /**
     * Remember a chart block for this period and filter.
     *
     * Each one is a handful of grouped aggregates over the whole period,
     * and the dashboard is opened far more often than a document is
     * posted. The key carries everything that changes the answer, and the
     * ledger moves the cache generation on whenever stock moves, so a
     * stale figure cannot outlive the document that changed it.
     */
    protected function cached(
        string $block,
        Carbon $from,
        Carbon $to,
        array $filters,
        mixed $extra,
        callable $callback,
    ): mixed {
        return $this->inventory->cached($block, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filters' => $filters,
            'extra' => $extra,
        ], $callback);
    }

    /**
     * What the filter bar is allowed to ask for.
     *
     * @return array{period:string, grain:string, from:?string, to:?string, category_id:?int, product_id:?int, staff_id:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(DashboardOverview::PERIODS))],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'grain' => ['nullable', Rule::in(['auto', 'day', 'week', 'month'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
        ]);

        return [
            // A daily control screen: it opens on today.
            'period' => $validated['period'] ?? 'today',
            'grain' => $validated['grain'] ?? 'auto',
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'staff_id' => isset($validated['staff_id']) ? (int) $validated['staff_id'] : null,
        ];
    }

    /**
     * The people the Staff filter offers.
     *
     * @return list<array{value:int,label:string}>
     */
    protected function staffOptions(Request $request, bool $seesAllStaff): array
    {
        $user = $request->user();

        if (! $seesAllStaff) {
            return [['value' => $user->id, 'label' => $user->name]];
        }

        return StaffOptions::all();
    }

    /**
     * The trend without its profit line, for somebody who may not read cost.
     *
     * Taken out of the payload, not hidden by the screen.
     */
    protected function withoutProfitUnless(bool $seesCost, array $trend): array
    {
        if (! $seesCost) {
            unset($trend['profit']);
        }

        return $trend;
    }

    /**
     * Throw the cached figures away and read them again.
     */
    public function refresh(): RedirectResponse
    {
        $this->metrics->flush();
        $this->inventory->flush();

        return back()->with('success', 'The figures were read again.');
    }
}
