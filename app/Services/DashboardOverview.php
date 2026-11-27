<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\CustomerCredit;
use App\Support\Quantity;
use App\Support\SupplierAccount;
use Illuminate\Support\Carbon;

/**
 * The hero cards on the dashboard.
 *
 * This class works **no figure out for itself**. Every number comes from
 * `InventoryMetrics` or `InventoryValuation`, which own them. What it does
 * is arrange: pick the period, ask for it, ask again for the period before,
 * and say how the two compare in a sentence an owner can read.
 *
 * That split matters. A dashboard that counted its own sales would sooner
 * or later disagree with the Sales screen, and the figure nobody can
 * explain is the one nobody trusts.
 */
class DashboardOverview
{
    /** The periods the filter bar offers, in the order it offers them. */
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'week' => 'This week',
        'month' => 'This month',
        'last_month' => 'Last month',
        'quarter' => 'This quarter',
        'year' => 'This year',
        'custom' => 'Custom range',
    ];

    public function __construct(
        protected InventoryMetrics $metrics,
        protected InventoryValuation $valuation,
    ) {}

    /**
     * Work out the dates a chosen period covers.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string} From, to, and a label.
     */
    public function resolve(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = Carbon::today();

        [$start, $end] = match ($period) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(), $today->copy()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'quarter' => [$today->copy()->startOfQuarter(), $today->copy()],
            'year' => [$today->copy()->startOfYear(), $today->copy()],
            'custom' => [
                $from ? Carbon::parse($from) : $today->copy()->startOfMonth(),
                $to ? Carbon::parse($to) : $today->copy(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };

        return [$start, $end, $start->format('d M Y').' to '.$end->format('d M Y')];
    }

    /**
     * The period of the same length that ended just before this one.
     *
     * Same length, not "last month": comparing nine days against a whole
     * month would make every figure look like a collapse.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    public function previous(Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;

        $end = $from->copy()->subDay();
        $start = $end->copy()->subDays($days - 1);

        return [$start, $end, $start->format('d M Y').' to '.$end->format('d M Y')];
    }

    /**
     * The hero cards.
     *
     * A card is left out of the payload entirely for somebody who may not
     * open the screen it summarises — not hidden once it has arrived. A
     * user who may read purchases but not sales gets the purchase card and
     * is never sent the sales figure at all.
     *
     * @param  array{category_id?:?int, product_id?:?int}  $filters
     * @param  array{sales?:bool, purchases?:bool, stock?:bool, cost?:bool}  $may
     * @return array{cards: list<array<string, mixed>>, compared_to: string}
     */
    public function cards(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        return $this->metrics->cached(
            'dashboard.cards',
            $this->keyFor($from, $to, $filters, $may),
            fn () => $this->buildCards($from, $to, $filters, $may),
        );
    }

    /**
     * What a cached block depends on.
     *
     * Every input that changes the answer belongs here. Leaving the
     * permissions out would hand one user another user's figures, which is
     * the worst bug a cache can have.
     */
    protected function keyFor(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'category' => $filters['category_id'] ?? null,
            'product' => $filters['product_id'] ?? null,
            // Whose documents. Without it, one staff member would be
            // handed another's cached figures.
            'staff' => $filters['staff_id'] ?? null,
            'period' => $filters['period'] ?? null,
            'may' => array_filter($may),
        ];
    }

    protected function buildCards(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        $seesSales = $may['sales'] ?? false;
        $seesPurchases = $may['purchases'] ?? false;
        $seesStock = $may['stock'] ?? false;
        $seesLosses = $may['adjustments'] ?? false;
        $seesCost = $may['cost'] ?? false;

        [$wasFrom, $wasTo, $comparedTo] = $this->previous($from, $to);

        // Only ask for what will be shown. A user who may read stock and
        // nothing else costs two queries, not ten.
        $wantsMoney = $seesSales || $seesPurchases;

        $now = $wantsMoney ? $this->metrics->periodFigures($from, $to, $filters) : [];
        $was = $wantsMoney ? $this->metrics->periodFigures($wasFrom, $wasTo, $filters) : [];
        $series = $wantsMoney ? $this->metrics->periodSeries($from, $to, $filters) : [];
        $stock = $seesStock ? $this->valuation->snapshot($filters) : null;

        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $staff = ['posted_by' => $filters['staff_id'] ?? null];
        $shelf = ['category_id' => $filters['category_id'] ?? null];
        $today = ($filters['period'] ?? null) === 'today';

        $shelfNote = ' Stock is counted for the whole shop, so the Staff filter does not change it.';

        $primary = [];
        $secondary = [];

        /* ------------------------------------------------ 1. Sales */

        if ($seesSales) {
            $primary[] = $this->card(
                'net_sales',
                $today ? 'Today\'s sales' : 'Sales',
                $now['net_sales'],
                $was['net_sales'],
                'What you sold, less what came back.',
                'money',
                $series['sales'],
                'Receipt',
                'success',
                formula: 'Sales = posted sale lines in the period, after each sale\'s '
                    .'discount, plus any mobilization charged, less the refunds on '
                    .'sales returns posted in the same period. Narrowed to a category '
                    .'or a product, mobilization is left out: it is not a product\'s. '
                    .'Voided sales count for nothing.',
                href: $this->link('/sales', $period + $staff + ['status' => 'posted']),
            );

            /* -------------------------------------------- 2. Collections */

            // From the payments each sale records, split by method. Narrowed
            // to a category or a product, a payment cannot be split between
            // the goods it paid for, so the figure falls back to the lines.
            $paid = $this->metrics->collections($from, $to, $filters);
            $paidWas = $paid === null ? null : $this->metrics->collections($wasFrom, $wasTo, $filters);

            $primary[] = $this->card(
                'collections',
                'Collections',
                $paid['total'] ?? $now['sales_gross'],
                $paidWas['total'] ?? $was['sales_gross'],
                $this->collectionsHint($paid, $now['transactions']),
                'money',
                null,
                'Wallet',
                'success',
                formula: 'Collections = what was paid on every posted sale in the period, '
                    .'after its discount: cash, GCash, bank and the rest, as each sale '
                    .'recorded it, plus what customers paid against their invoices in the '
                    .'period, on the day it came in. Cash counts what the sale was owed, not '
                    .'what was handed over, so change is already out of it. A sale put on a '
                    .'customer\'s account counts only for what was paid at the counter, until '
                    .'the customer pays it. Voided sales and payments count for nothing. '
                    .'Narrowed to a category or a product, it is the goods sold instead, on '
                    .'account or not, and payments against invoices are left out, because one '
                    .'payment cannot be split between the goods it paid for. Refunds on '
                    .'returns are money going out, so they are taken off in Net cash from '
                    .'trading, not here.',
                href: $this->link('/sales', $period + $staff + ['status' => 'posted']),
            );
        }

        /* ------------------------------------------- 3. Cash position */

        if ($seesSales && $seesPurchases) {
            // Sold is not the same as paid for: what went on account has
            // not come in yet.
            // What customers paid against invoices did come in, in the
            // period it came in. Left out while narrowed to a category or a
            // product: a payment cannot be split between goods.
            //
            // The same on the buying side since phase 24: a delivery on a
            // supplier's account is not money out until it is paid, and is
            // counted on the day the supplier payment goes out. A return that
            // came off a bill or stayed as supplier credit moved no cash.
            $paidOut = $this->metrics->supplierPaid($from, $to, $filters);
            $paidOutWas = $paidOut === null ? null : $this->metrics->supplierPaid($wasFrom, $wasTo, $filters);

            $cash = $now['net_sales'] - $now['on_account_net'] + ($paid['account'] ?? 0)
                - ($now['net_purchases'] - $now['purchases_unpaid_net']) - ($paidOut ?? 0);
            $cashWas = $was['net_sales'] - $was['on_account_net'] + ($paidWas['account'] ?? 0)
                - ($was['net_purchases'] - $was['purchases_unpaid_net']) - ($paidOutWas ?? 0);

            $primary[] = $this->card(
                'cash_position',
                'Net cash from trading',
                $cash,
                $cashWas,
                'Money in from sales, less money out to suppliers.',
                'money',
                null,
                'Banknote',
                $cash < 0 ? 'warning' : 'info',
                formula: 'Net cash from trading = Collections − refunds on sales returns '
                    .'− deliveries paid on delivery − supplier payments + cash refunds on purchase '
                    .'returns. Sales are counted as they were paid, cash or not, and what went on '
                    .'a customer\'s account is left out until it is paid, then counted on the day '
                    .'the customer pays it; a return on an unpaid sale takes it off the balance '
                    .'and moves no cash. Deliveries work the same way: one put on a supplier\'s '
                    .'account is counted on the day the supplier is paid, and a purchase return '
                    .'that came off a bill or stayed as supplier credit moves no cash. Narrowed to '
                    .'a category or a product, each line carries its share of what went on '
                    .'account, and payments against invoices and bills are left out, because one '
                    .'payment cannot be split between goods. No opening cash, expenses or bank '
                    .'deposits are recorded, so this is the cash that trading moved in the '
                    .'period, not the money in the drawer.',
                href: $this->link('/reports', $period),
            );
        }

        /* ------------------------------------------ 4. Gross profit */

        if ($seesSales) {
            $profitFormula = 'Estimated gross profit = Sales − cost of goods sold. The cost is '
                .'what those exact pieces cost, from the FIFO batches each sale used, less '
                .'the cost of what came back. Estimated, because opening stock costs are '
                .'typed in by hand and VAT is not taken out of either figure.';

            $primary[] = $seesCost
                ? $this->card(
                    'gross_profit',
                    'Est. gross profit',
                    $now['gross_profit'],
                    $was['gross_profit'],
                    sprintf(
                        'That is %s of the selling price.',
                        number_format($now['margin'], 1).'%',
                    ),
                    'money',
                    $series['profit'],
                    'TrendingUp',
                    'brand',
                    formula: $profitFormula,
                    href: $this->link('/reports', $period),
                )
                : $this->restricted('gross_profit', 'Est. gross profit', 'TrendingUp', $profitFormula);
        }

        /* --------------------------------------------- 5. Low stock */

        if ($seesStock) {
            $primary[] = $this->card(
                'low_stock',
                'Low stock items',
                $stock['low_stock'],
                null,
                'At or below the level you said to reorder at.',
                'count',
                null,
                'TriangleAlert',
                $stock['low_stock'] > 0 ? 'warning' : 'neutral',
                formula: 'Low stock = active products where on hand ≤ reorder level. A '
                    .'reorder level of 0 means the product is not watched. Counted now, '
                    .'not over the period.'.$shelfNote,
                href: $this->link('/stock', ['stock' => 'low'] + $shelf),
            );
        }

        /* ------------------------------------- 6. Overdue balances */

        if ($seesSales) {
            // As of today, whatever the period: a balance is overdue now or
            // it is not. One query. The Staff filter narrows it to the sales
            // that person posted; Category and Product do not apply, because
            // a balance belongs to a whole invoice.
            $overdue = CustomerCredit::overdue(Carbon::today(), $filters['staff_id'] ?? null);

            $primary[] = $this->card(
                'overdue_balances',
                'Overdue balances',
                $overdue['cents'] / 100,
                null,
                $overdue['cents'] > 0
                    ? sprintf(
                        '%d invoice%s, %d customer%s. As of today.',
                        $overdue['invoices'], $overdue['invoices'] === 1 ? '' : 's',
                        $overdue['customers'], $overdue['customers'] === 1 ? '' : 's',
                    )
                    : 'Nobody is past due. As of today.',
                'money',
                null,
                'Clock',
                $overdue['cents'] > 0 ? 'warning' : 'neutral',
                formula: 'Overdue balances = what is still owed on posted sales whose due date '
                    .'is before today, after returns and customer payments. Counted as of '
                    .'today, not over the period, like Low stock. The Staff filter narrows it '
                    .'to the sales that person posted; the Category and Product filters do '
                    .'not change it, because a balance belongs to a whole invoice. The Aging '
                    .'report splits it by how far past due.',
                href: ($may['receivables'] ?? false) ? $this->link('/aging') : null,
            );
        }

        /* ------------------------------ 7. Pending purchase requests */

        if ($may['purchase_requests'] ?? false) {
            // As of now, whatever the period: a request is waiting or it is
            // not. One query. The Staff filter narrows it to the requests
            // that person raised; Category and Product do not apply, because
            // a request belongs to whoever raised it, not to one product.
            $waiting = PurchaseRequest::waiting($filters['staff_id'] ?? null);
            $days = $waiting['oldest'] ? (int) $waiting['oldest']->diffInDays(Carbon::today()) : 0;

            $primary[] = $this->card(
                'purchase_requests',
                'Purchase requests',
                $waiting['count'],
                null,
                $waiting['count'] > 0
                    ? $this->peso($waiting['estimated']).' estimated. '.($days === 0
                        ? 'Raised today.'
                        : sprintf('Oldest waiting %d day%s.', $days, $days === 1 ? '' : 's'))
                    : 'Nothing is waiting for approval.',
                'count',
                null,
                'ClipboardList',
                $waiting['count'] > 0 ? 'warning' : 'neutral',
                formula: 'Pending purchase requests = requests raised and not yet approved, '
                    .'rejected or closed. Counted now, not over the period, like Low stock. '
                    .'The estimate is what the people asking expected the goods to cost. The '
                    .'Staff filter narrows it to the requests that person raised.',
                href: $this->link('/purchase-requests', [
                    'status' => 'pending',
                    'requested_by' => $filters['staff_id'] ?? null,
                ]),
            );
        }

        /* ------------------------------------------------ 8. Losses */

        if ($seesLosses) {
            $lost = $this->metrics->lossTotals($from, $to, $filters, $seesCost);
            $lostWas = $this->metrics->lossTotals($wasFrom, $wasTo, $filters, $seesCost);

            $reasons = implode(', ', array_map(
                fn (array $row) => $row['label'].' '.Quantity::format($row['quantity']),
                array_slice($lost['by_reason'], 0, 2),
            ));

            $primary[] = $this->card(
                'losses',
                'Recorded losses',
                $seesCost ? $lost['value'] : $lost['quantity'],
                $seesCost ? $lostWas['value'] : $lostWas['quantity'],
                $lost['documents'] === 0
                    ? 'Nothing written off in this period.'
                    : sprintf(
                        '%s unit%s on %d adjustment%s. %s',
                        Quantity::format($lost['quantity']),
                        $lost['quantity'] === 1 ? '' : 's',
                        $lost['documents'],
                        $lost['documents'] === 1 ? '' : 's',
                        $reasons,
                    ),
                $seesCost ? 'money' : 'count',
                null,
                'PackageMinus',
                $lost['documents'] > 0 ? 'danger' : 'neutral',
                // More loss is worse, so a rise is not good news.
                invert: true,
                formula: 'Recorded losses = every posted Decrease adjustment in the period: '
                    .'damaged or spoiled, lost or stolen, written off, and recounts that found '
                    .'less than the books. '
                    .($seesCost
                        ? 'Valued at what the batches it took cost.'
                        : 'Shown in units: the value at cost is held back for your role.'),
                href: $this->link('/adjustments', $period + $staff + ['kind' => 'decrease', 'status' => 'posted']),
            );
        }

        /* ------------------------------------- The secondary row */

        if ($may['payables'] ?? false) {
            // As of today, whatever the period, like Overdue balances: what
            // the shop owes its suppliers now. One query. Not narrowed by
            // Staff, Category or Product: a bill is the shop's, whole.
            $owe = SupplierAccount::payables(Carbon::today());

            $secondary[] = $this->card(
                'payables',
                'Payables',
                $owe['cents'] / 100,
                null,
                $owe['cents'] > 0
                    ? sprintf(
                        '%s past due. %d bill%s, %d supplier%s.',
                        $this->peso($owe['overdue_cents'] / 100),
                        $owe['bills'], $owe['bills'] === 1 ? '' : 's',
                        $owe['suppliers'], $owe['suppliers'] === 1 ? '' : 's',
                    )
                    : 'Nothing is owed to suppliers.',
                'money',
                null,
                'Landmark',
                $owe['overdue_cents'] > 0 ? 'warning' : 'neutral',
                // Owing more is not good news.
                invert: true,
                formula: 'Payables = what is still owed on posted deliveries put on a supplier\'s '
                    .'account, after purchase returns and supplier payments. Counted as of today, '
                    .'not over the period. Past due = bills whose due date, from the supplier\'s '
                    .'payment terms, is before today. The Staff, Category and Product filters do '
                    .'not change it, because a bill belongs to the whole shop.',
                href: $this->link('/payables'),
            );
        }

        if ($may['purchase_orders'] ?? false) {
            // As of now, whatever the period, like the Purchase requests
            // card: an order is waiting for approval or it is not (phase
            // 24.4). One query. The Staff filter narrows it to the orders
            // that person placed.
            $orders = PurchaseOrder::waitingForApproval($filters['staff_id'] ?? null);
            $days = $orders['oldest'] ? (int) $orders['oldest']->diffInDays(Carbon::today()) : 0;

            $secondary[] = $this->card(
                'purchase_orders',
                'Orders to approve',
                $orders['count'],
                null,
                $orders['count'] > 0
                    ? $this->peso($orders['total']).' in all. '.($days === 0
                        ? 'Placed today.'
                        : sprintf('Oldest waiting %d day%s.', $days, $days === 1 ? '' : 's'))
                    : 'No purchase order is waiting for approval.',
                'count',
                null,
                'ShieldCheck',
                $orders['count'] > 0 ? 'warning' : 'neutral',
                formula: 'Orders to approve = purchase orders placed and not yet approved or '
                    .'rejected. Counted now, not over the period. Nothing can be received against '
                    .'one until it is approved, and it prints only as a draft. The Staff filter '
                    .'narrows it to the orders that person placed.',
                href: $this->link('/purchase-orders', [
                    'status' => 'pending_approval',
                    'ordered_by' => $filters['staff_id'] ?? null,
                ]),
            );
        }

        if ($seesPurchases) {
            $secondary[] = $this->card(
                'net_purchases',
                'Net purchases',
                $now['net_purchases'],
                $was['net_purchases'],
                'Bought in, less what went back.',
                'money',
                null,
                'ShoppingCart',
                'info',
                formula: 'Net purchases = posted delivery lines in the period − the cost of '
                    .'purchase returns. Opening stock and adjustments are not purchases.',
                href: $this->link('/purchases', $period + $staff + ['status' => 'posted']),
            );
        }

        if ($seesStock) {
            $valueFormula = 'Inventory value = every batch still holding stock × what that '
                .'batch cost. Counted now, not over the period.'.$shelfNote;

            $secondary[] = $seesCost
                ? $this->card(
                    'inventory_value',
                    'Inventory value',
                    (float) $stock['value'],
                    null,
                    'What the stock on the shelf cost you.',
                    'money',
                    null,
                    'Warehouse',
                    'brand',
                    formula: $valueFormula,
                    href: $this->link('/stock', $shelf),
                )
                : $this->restricted('inventory_value', 'Inventory value', 'Warehouse', $valueFormula);

            $secondary[] = $this->card(
                'out_of_stock',
                'Out of stock',
                $stock['out_of_stock'],
                null,
                'Cannot be sold today.',
                'count',
                null,
                'PackageX',
                $stock['out_of_stock'] > 0 ? 'danger' : 'neutral',
                formula: 'Out of stock = active products with 0 on hand.'.$shelfNote,
                href: $this->link('/stock', ['stock' => 'out'] + $shelf),
            );
        }

        if ($seesSales) {
            $secondary[] = $this->card(
                'returns_rate',
                'Returns rate',
                $now['returns_rate'],
                $was['returns_rate'],
                sprintf(
                    '%s back on %d return%s.',
                    $this->peso($now['returns_value']),
                    $now['return_documents'],
                    $now['return_documents'] === 1 ? '' : 's',
                ),
                'percent',
                null,
                'RotateCcw',
                $now['returns_rate'] > ($was['returns_rate'] ?? 0) ? 'warning' : 'neutral',
                invert: true,
                formula: 'Returns rate = refunds on sales returns ÷ sales before returns × 100. '
                    .'Measured against the gross, because a rate against the net would '
                    .'shrink its own denominator.',
                href: $this->link('/sales-returns', $period),
            );

            $secondary[] = $this->card(
                'transactions',
                'Sales made',
                $now['transactions'],
                $was['transactions'],
                sprintf('%s on an average sale.', $this->peso($now['average_sale'])),
                'count',
                null,
                'ReceiptText',
                'info',
                formula: 'Sales made = posted sales in the period, counted as documents. '
                    .'The average is Sales ÷ that count.',
                href: $this->link('/sales', $period + $staff + ['status' => 'posted']),
            );
        }

        return [
            'cards' => $primary,
            'secondary' => $secondary,
            'compared_to' => $comparedTo,
            // When these figures were worked out, so the screen can say how
            // fresh they are rather than looking permanent.
            'generated_at' => Carbon::now()->format('g:i A'),
        ];
    }

    /**
     * The short lists under the cards: what to look at first.
     *
     * Each row opens the record behind it: low stock, losses, overdue
     * balances, and the purchase requests waiting longest for an answer.
     *
     * @return list<array<string, mixed>>
     */
    public function exceptions(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        return $this->metrics->cached(
            'dashboard.exceptions',
            $this->keyFor($from, $to, $filters, $may),
            fn () => $this->buildExceptions($from, $to, $filters, $may),
        );
    }

    protected function buildExceptions(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        $seesCost = $may['cost'] ?? false;
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $lists = [];

        if ($may['stock'] ?? false) {
            $rows = $this->metrics->lowStockDetail($filters, 5);

            $lists[] = [
                'key' => 'low_stock',
                'title' => 'Low stock',
                'subtitle' => 'Furthest below the reorder level',
                'icon' => 'TriangleAlert',
                'tone' => 'warning',
                'state' => 'ok',
                'empty' => 'Nothing is running low.',
                'href' => $this->link('/stock', ['stock' => 'low', 'category_id' => $filters['category_id'] ?? null]),
                'rows' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['name'],
                    'meta' => $row['sku'],
                    'figure' => Quantity::format($row['on_hand']).' / '.Quantity::format($row['reorder_level']),
                    'figure_note' => 'on hand / reorder',
                    'when' => $row['last_bought_on'] ? 'Last bought '.$row['last_bought_on'] : 'Never bought in',
                    'href' => $this->link('/stock', ['search' => $row['sku']]),
                ], $rows),
            ];
        }

        if ($may['adjustments'] ?? false) {
            $rows = $this->metrics->lossRows($from, $to, $filters, $seesCost, 5);

            $lists[] = [
                'key' => 'losses',
                'title' => 'Recorded losses',
                'subtitle' => $seesCost ? 'Largest by cost' : 'Largest by units',
                'icon' => 'PackageMinus',
                'tone' => 'danger',
                'state' => 'ok',
                'empty' => 'Nothing was written off in this period.',
                'href' => $this->link('/adjustments', $period + [
                    'kind' => 'decrease',
                    'status' => 'posted',
                    'posted_by' => $filters['staff_id'] ?? null,
                ]),
                'rows' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['reference'].' · '.$row['reason'],
                    'meta' => $row['product'],
                    'figure' => isset($row['value'])
                        ? $this->peso((float) $row['value'])
                        : Quantity::format($row['quantity']).' unit'.($row['quantity'] === 1 ? '' : 's'),
                    'figure_note' => isset($row['value']) ? Quantity::format($row['quantity']).' units' : null,
                    'when' => $row['on'],
                    'href' => '/adjustments/'.$row['id'],
                ], $rows),
            ];
        }

        if ($may['sales'] ?? false) {
            $rows = CustomerCredit::overdueCustomers(Carbon::today(), $filters['staff_id'] ?? null, 5);

            $lists[] = [
                'key' => 'overdue_balances',
                'title' => 'Overdue customer balances',
                'subtitle' => 'Longest overdue first, as of today',
                'icon' => 'Clock',
                'tone' => 'warning',
                'state' => 'ok',
                'empty' => 'Nobody is past due.',
                'href' => ($may['receivables'] ?? false) ? $this->link('/aging') : null,
                'rows' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['name'],
                    'meta' => $row['invoices'].' invoice'.($row['invoices'] === 1 ? '' : 's').' past due',
                    'figure' => $this->peso($row['cents'] / 100),
                    'figure_note' => $row['days'].' day'.($row['days'] === 1 ? '' : 's').' overdue',
                    'when' => 'Due '.$row['oldest_due'],
                    'href' => ($may['customers'] ?? false) ? '/customers/'.$row['id'] : null,
                ], $rows),
            ];
        }

        if ($may['purchase_requests'] ?? false) {
            $rows = PurchaseRequest::longestWaiting($filters['staff_id'] ?? null, 5);

            $lists[] = [
                'key' => 'purchase_requests',
                'title' => 'Pending purchase requests',
                'subtitle' => 'Waiting longest for approval',
                'icon' => 'ClipboardList',
                'tone' => 'warning',
                'state' => 'ok',
                'empty' => 'Nothing is waiting for approval.',
                'href' => $this->link('/purchase-requests', [
                    'status' => 'pending',
                    'requested_by' => $filters['staff_id'] ?? null,
                ]),
                'rows' => array_map(fn (array $row) => [
                    'id' => $row['id'],
                    'title' => $row['reference'].($row['requested_by'] ? ' · '.$row['requested_by'] : ''),
                    'meta' => $row['lines'].' item'.($row['lines'] === 1 ? '' : 's')
                        .($row['supplier'] ? ' · '.$row['supplier'] : ''),
                    'figure' => $this->peso($row['estimated']),
                    'figure_note' => $row['days'] === 0
                        ? 'raised today'
                        : 'waiting '.$row['days'].' day'.($row['days'] === 1 ? '' : 's'),
                    'when' => 'Raised '.$row['raised_on'],
                    'href' => '/purchase-requests/'.$row['id'],
                ], $rows),
            ];
        }

        return $lists;
    }

    /**
     * A card whose figure this user may not read.
     *
     * It carries no number at all — not a nought, which would be a lie,
     * and not the real figure with a flag the screen is trusted to obey.
     */
    protected function restricted(string $key, string $label, string $icon, string $formula): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => 'restricted',
            'formula' => $formula,
            'value' => null,
            'format' => 'money',
            'hint' => 'Only roles allowed to see cost and profit can read this figure.',
            'icon' => $icon,
            'tone' => 'neutral',
            'change' => null,
            'previous' => null,
            'invert' => false,
            'sparkline' => null,
            'href' => null,
        ];
    }

    /** An address with its filters, leaving out the empty ones. */
    protected function link(string $path, array $query = []): string
    {
        $query = array_filter($query, fn ($value) => $value !== null && $value !== '');

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }

    /**
     * What needs somebody's attention, worst first.
     *
     * Every item carries a severity, a plain sentence and somewhere to go.
     * A list of problems with nowhere to click is a list of worries.
     *
     * Nothing here is invented: each line is a count or a figure from the
     * ledger. If the list is empty, that is a real answer and the screen
     * says so.
     *
     * @return list<array<string, mixed>>
     */
    public function attention(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        return $this->metrics->cached(
            'dashboard.attention',
            $this->keyFor($from, $to, $filters, $may),
            fn () => $this->buildAttention($from, $to, $filters, $may),
        );
    }

    protected function buildAttention(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        $items = [];

        if ($may['stock'] ?? false) {
            // Stock that disagrees with the batches behind it. This is the
            // one real data fault the system can have: `on_hand` is
            // unsigned, so it can never go negative, but it can drift from
            // the sum of its layers if something went very wrong.
            $broken = app(InventoryLedger::class)->reconcile();

            if ($broken !== []) {
                $items[] = [
                    'key' => 'inconsistent',
                    'severity' => 'danger',
                    'title' => count($broken).' product'.(count($broken) === 1 ? '' : 's')
                        .' disagree with their batches',
                    'detail' => 'The stock figure and the batches behind it have come apart. '
                        .'Run `php artisan inventory:verify` to see which.',
                    'action' => null,
                    'count' => count($broken),
                ];
            }

            $out = $this->valuation->snapshot($filters)['out_of_stock'];

            if ($out > 0) {
                $items[] = [
                    'key' => 'out_of_stock',
                    'severity' => 'danger',
                    'title' => $out.' product'.($out === 1 ? '' : 's').' out of stock',
                    'detail' => 'Nothing left on the shelf, so nothing can be sold today.',
                    'action' => ['label' => 'See them', 'href' => '/stock?stock=out'],
                    'count' => $out,
                ];
            }

            // Low stock has its own list under the cards now, with the
            // rows, so it is not said twice here.

            $idleDays = (int) settings('system.dead_stock_days');
            $dead = $this->metrics->deadStock($idleDays, $filters, 8);

            if ($dead !== []) {
                $seesCost = $may['cost'] ?? false;
                $tied = array_sum(array_map(fn ($row) => (float) $row['tied_up'], $dead));

                // What it is tied up in is stock at cost, so it follows
                // the cost permission like the inventory value does.
                if (! $seesCost) {
                    $dead = array_map(fn (array $row) => collect($row)->except('tied_up')->all(), $dead);
                }

                $items[] = [
                    'key' => 'dead_stock',
                    'severity' => 'info',
                    'title' => count($dead).' product'.(count($dead) === 1 ? '' : 's')
                        .' have not sold in '.$idleDays.' days',
                    'detail' => $seesCost
                        ? $this->peso($tied).' is sitting on the shelf not moving.'
                        : 'Stock that is sitting on the shelf not moving.',
                    'action' => ['label' => 'See stock', 'href' => '/stock?stock=held'],
                    'count' => count($dead),
                    'rows' => $dead,
                ];
            }
        }

        if ($may['sales'] ?? false) {
            $rate = (float) settings('system.high_return_rate');
            $returning = $this->metrics->highReturns($from, $to, $rate, $filters, 5);

            if ($returning !== []) {
                $items[] = [
                    'key' => 'high_returns',
                    'severity' => 'warning',
                    'title' => count($returning).' product'.(count($returning) === 1 ? '' : 's')
                        .' coming back more than '.$rate.'% of the time',
                    'detail' => 'Worth checking the quality, the description, or who is selling them.',
                    'action' => ['label' => 'Sales returns', 'href' => '/sales-returns'],
                    'count' => count($returning),
                    'rows' => $returning,
                ];
            }
        }

        // Losses have their own list under the cards now.

        return $items;
    }

    /**
     * What is happening, in sentences.
     *
     * Every line is built from a figure that was already worked out, and
     * says the figure. Nothing here is written in advance and nothing is
     * guessed — a dashboard that tells an owner something untrue once is a
     * dashboard they stop reading.
     *
     * @return list<array<string, string>>
     */
    public function insights(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        return $this->metrics->cached(
            'dashboard.insights',
            $this->keyFor($from, $to, $filters, $may),
            fn () => $this->buildInsights($from, $to, $filters, $may),
        );
    }

    protected function buildInsights(Carbon $from, Carbon $to, array $filters, array $may): array
    {
        $seesSales = $may['sales'] ?? false;
        $seesPurchases = $may['purchases'] ?? false;

        if (! $seesSales && ! $seesPurchases) {
            return [];
        }

        [$wasFrom, $wasTo, $comparedTo] = $this->previous($from, $to);

        $now = $this->metrics->periodFigures($from, $to, $filters);
        $was = $this->metrics->periodFigures($wasFrom, $wasTo, $filters);

        $lines = [];

        if ($seesSales) {
            $lines[] = $this->movement(
                'Sales',
                $now['net_sales'],
                $was['net_sales'],
                $comparedTo,
                $this->metrics->productRanking($from, $to, $filters, 1),
            );

            if ($now['transactions'] > 0) {
                $lines[] = [
                    'tone' => 'neutral',
                    'text' => sprintf(
                        '%d sale%s went through, averaging %s each.',
                        $now['transactions'],
                        $now['transactions'] === 1 ? '' : 's',
                        $this->peso($now['average_sale']),
                    ),
                ];
            }
        }

        if ($seesPurchases) {
            $lines[] = $this->movement(
                'Purchases',
                $now['net_purchases'],
                $was['net_purchases'],
                $comparedTo,
                [],
            );
        }

        if ($seesSales && ($may['cost'] ?? false) && $now['net_sales'] > 0) {
            $lines[] = [
                'tone' => $now['margin'] >= 20 ? 'good' : 'warning',
                'text' => sprintf(
                    'You kept %s of every peso sold, which is %s on %s.',
                    number_format($now['margin'], 1).'%',
                    $this->peso($now['gross_profit']),
                    $this->peso($now['net_sales']),
                ),
            ];
        }

        $best = $this->metrics->bestAndWorstMonth($from, $to, $filters);

        if ($seesSales && $best['best']) {
            $lines[] = [
                'tone' => 'neutral',
                'text' => sprintf(
                    '%s was the best month at %s. %s was the quietest at %s.',
                    $best['best']['month'],
                    $this->peso((float) $best['best']['amount']),
                    $best['worst']['month'],
                    $this->peso((float) $best['worst']['amount']),
                ),
            ];
        }

        return array_values(array_filter($lines));
    }

    /**
     * One sentence about a figure rising or falling.
     *
     * @param  list<array<string, mixed>>  $topSeller
     */
    protected function movement(
        string $what,
        float $value,
        float $was,
        string $comparedTo,
        array $topSeller,
    ): ?array {
        $change = $this->change($value, $was);

        if ($change['direction'] === 'new') {
            return [
                'tone' => 'good',
                'text' => sprintf('%s came to %s. There was nothing in %s to compare it with.',
                    $what, $this->peso($value), $comparedTo),
            ];
        }

        if ($change['percent'] === null || $change['direction'] === 'flat') {
            return [
                'tone' => 'neutral',
                'text' => sprintf('%s held steady at %s, much as in %s.',
                    $what, $this->peso($value), $comparedTo),
            ];
        }

        $rising = $change['percent'] > 0;

        $driver = $topSeller !== [] && $rising
            ? sprintf(' %s sold most, at %s.', $topSeller[0]['name'], $this->peso((float) $topSeller[0]['amount']))
            : '';

        return [
            'tone' => $rising ? 'good' : 'warning',
            'text' => sprintf(
                '%s are %s %s against %s, at %s.%s',
                $what,
                $rising ? 'up' : 'down',
                abs($change['percent']).'%',
                $comparedTo,
                $this->peso($value),
                $driver,
            ),
        ];
    }

    /**
     * One card, with how it compares to the period before.
     *
     * @param  list<float>|null  $sparkline
     */
    /**
     * Under Collections: how it came in, Cash ₱18,000.00 · GCash ₱6,500.00.
     *
     * @param  array{total: float, methods: list<array{name:string, amount:string}>}|null  $paid
     */
    protected function collectionsHint(?array $paid, int $transactions): string
    {
        $sales = sprintf('%d sale%s', $transactions, $transactions === 1 ? '' : 's');

        if ($paid === null || $paid['methods'] === []) {
            return "Paid at the counter across {$sales}.";
        }

        $symbol = (string) settings('system.currency_symbol', '₱');

        return collect($paid['methods'])
            ->map(fn (array $m) => $m['name'].' '.$symbol.number_format((float) $m['amount'], 2))
            ->implode(' · ');
    }

    protected function card(
        string $key,
        string $label,
        float|int $value,
        float|int|null $was,
        string $hint,
        string $format,
        ?array $sparkline,
        string $icon,
        string $tone,
        bool $invert = false,
        string $formula = '',
        ?string $href = null,
    ): array {
        return [
            'key' => $key,
            'state' => 'ok',
            'href' => $href,
            // Said exactly, for the (i) on the card. An owner who cannot
            // check a figure has to take it on trust, and a figure taken
            // on trust is one nobody argues with when it is wrong.
            'formula' => $formula,
            'label' => $label,
            'value' => $format === 'money'
                ? number_format((float) $value, 2, '.', '')
                : ($format === 'percent' ? number_format((float) $value, 1) : Quantity::normalize($value)),
            'format' => $format,
            'hint' => $hint,
            'icon' => $icon,
            'tone' => $tone,
            'change' => $was === null ? null : $this->change((float) $value, (float) $was),
            'previous' => $was === null
                ? null
                : ($format === 'money'
                    ? number_format((float) $was, 2, '.', '')
                    : ($format === 'percent' ? number_format((float) $was, 1) : Quantity::normalize($was))),
            'invert' => $invert,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * The change between two periods, as a percentage.
     *
     * Null when there is nothing to compare against. Growing from zero is
     * not "infinity per cent"; it is simply new, and the screen says so.
     *
     * @return array{percent: ?float, direction: string}
     */
    protected function change(float $value, float $was): array
    {
        if (abs($was) < 0.005) {
            return [
                'percent' => null,
                'direction' => abs($value) < 0.005 ? 'flat' : 'new',
            ];
        }

        $percent = ($value - $was) / abs($was) * 100;

        return [
            'percent' => round($percent, 1),
            'direction' => abs($percent) < 0.05 ? 'flat' : ($percent > 0 ? 'up' : 'down'),
        ];
    }

    /**
     * How long the stock on the shelf would last at this period's rate.
     *
     * This is days of stock on hand, not inventory turnover. True turnover
     * needs the **average** inventory value over the period, and no such
     * figure is kept: a movement row stores the quantity left after it, not
     * what the shelf was worth that day. Rather than print a turnover
     * number that is really something else, this says plainly what it is.
     */
    protected function stockDaysHint(string $value, float $cogs, Carbon $from, Carbon $to): string
    {
        $days = max(1, $from->diffInDays($to) + 1);

        if ($cogs <= 0) {
            return 'What the batches on the shelf cost you.';
        }

        $perDay = $cogs / $days;
        $lasts = (int) round((float) $value / $perDay);

        return sprintf(
            'What the batches cost. At this period\'s rate it lasts about %d day%s.',
            $lasts,
            $lasts === 1 ? '' : 's',
        );
    }

    protected function peso(float $value): string
    {
        return '₱'.number_format($value, 2);
    }
}
