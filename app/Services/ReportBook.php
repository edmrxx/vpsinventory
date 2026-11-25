<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Support\ReportFilters;
use App\Support\Spreadsheet;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the Reports screen shows, and the same thing as a spreadsheet
 * (phase 34).
 *
 * One place decides which blocks a user receives — sales, purchases, the
 * money received, the stock value — and with or without what the goods
 * cost. The screen renders `blocks()`; the spreadsheet is written from the
 * same `blocks()` plus a register of every document behind them. Two copies
 * would drift, and the cost gating would drift with them.
 *
 * Every figure still comes from `InventoryMetrics` and `InventoryValuation`;
 * the registers narrow with `InventoryMetrics::narrowSales()` and
 * `narrowPurchases()`, the same rule the totals use, so a register adds up
 * to the summary above it.
 */
class ReportBook
{
    public function __construct(
        protected InventoryMetrics $metrics,
        protected InventoryValuation $valuation,
    ) {}

    /**
     * The blocks this user may read, narrowed by the filters. `$rows` caps
     * each breakdown; null sends every row.
     *
     * @return array{sales: ?array, purchases: ?array, collections: ?array, inventory: ?array, trend: ?array, can: array{viewCost: bool}}
     */
    public function blocks(User $user, ReportFilters $filters, ?int $rows = 50, bool $withTrend = true): array
    {
        $sees = $this->sees($user, $filters);
        $narrow = $filters->metrics();

        return [
            // Sales against purchases over the chosen period, plotted by
            // month, or by day when the period sits inside one month. It
            // reads the same service and the same filters as the totals
            // beside it, so the chart and the summary cannot tell two stories.
            'trend' => $withTrend && ($sees['sales'] || $sees['purchases'])
                ? $this->metrics->seriesFor($filters->from, $filters->to, $sees['sales'], $sees['purchases'], $narrow)
                : null,
            'sales' => $sees['sales']
                ? $this->metrics->salesSummary($filters->from, $filters->to, $sees['cost'], $narrow, $rows)
                : null,
            'purchases' => $sees['purchases']
                ? $this->metrics->purchaseSummary($filters->from, $filters->to, $narrow, $rows)
                : null,
            // The money that came in, by method: the till's payments and,
            // for somebody who may read them, payments against invoices.
            'collections' => $sees['sales']
                ? $this->metrics->collections($filters->from, $filters->to, $narrow, $sees['account_payments'])
                : null,
            // Valuation is "right now", not a period, and no filter narrows
            // it, so it is left out while one is set.
            'inventory' => $sees['stock'] && $filters->staffId === null && $filters->paymentMethodId === null
                ? $this->valuation->summary()
                : null,
            'can' => ['viewCost' => $sees['cost']],
        ];
    }

    /**
     * What this user may read. What a sale cost is its cost of goods, so the
     * profit sits behind the permission that guards it everywhere else.
     *
     * @return array{sales: bool, purchases: bool, cost: bool, stock: bool, account_payments: bool}
     */
    public function sees(User $user, ReportFilters $filters): array
    {
        $sales = $user->can('sales.view');

        return [
            'sales' => $sales,
            'purchases' => $user->can('purchases.view') && $filters->coversPurchases(),
            'cost' => $sales && $user->can('viewCost', Sale::class),
            'stock' => $user->can('stock.view'),
            'account_payments' => $user->can('customer_payments.view'),
        ];
    }

    /* ------------------------------------------------------- Spreadsheet */

    /**
     * The workbook: a summary sheet, then a register and the breakdowns for
     * each side the user may read. Returns the file's path.
     */
    public function workbook(User $user, ReportFilters $filters): string
    {
        $blocks = $this->blocks($user, $filters, rows: null, withTrend: false);
        $sees = $this->sees($user, $filters);
        $book = new Spreadsheet;

        try {
            return $this->fill($book, $user, $filters, $blocks, $sees);
        } catch (\Throwable $failed) {
            $book->discard();

            throw $failed;
        }
    }

    protected function fill(Spreadsheet $book, User $user, ReportFilters $filters, array $blocks, array $sees): string
    {
        $this->summarySheet($book, $user, $filters, $blocks);

        if ($blocks['sales'] !== null) {
            $this->salesSheet($book, $filters, $sees['cost']);
            $this->salesReturnsSheet($book, $filters, $sees['cost']);
            $this->breakdownSheet($book, 'Sales by product', $blocks['sales']['by_product'], 'quantity', true);
            $this->breakdownSheet($book, 'Sales by customer', $blocks['sales']['by_customer'], 'documents', false, 'Customer', 'Sales');
        }

        if ($blocks['purchases'] !== null) {
            $this->purchasesSheet($book, $filters);
            $this->purchaseReturnsSheet($book, $filters);
            $this->breakdownSheet($book, 'Purchases by supplier', $blocks['purchases']['by_supplier'], 'documents', false, 'Supplier', 'Deliveries');
            $this->breakdownSheet($book, 'Purchases by product', $blocks['purchases']['by_product'], 'quantity', true);
        }

        return $book->save();
    }

    /** The file's name: the period, so two downloads never look alike. */
    public function filename(ReportFilters $filters): string
    {
        return 'report-'.$filters->from->toDateString().'-to-'.$filters->to->toDateString().'.xlsx';
    }

    protected function summarySheet(Spreadsheet $book, User $user, ReportFilters $filters, array $blocks): void
    {
        $book->sheet('Summary', [34, 18, 40]);

        $book->row([(settings('company.name') ?: config('app.name')).' — Report'], Spreadsheet::TITLE)
            ->row(['Period', $filters->periodLabel()])
            ->row(['Cashier', $filters->staffLabel()])
            ->row(['Payment method', $filters->paymentLabel()])
            ->row(['Prepared by', $user->name.' (@'.$user->username.'), '.now()->format('d M Y g:i A')]);

        if ($filters->paymentMethodId !== null) {
            $book->row([Spreadsheet::text('Sales paid at least in part by this method, and their returns. A sale paid two ways counts whole. Purchases record no payment method and are left out.', Spreadsheet::NOTE)]);
        }

        $book->row([Spreadsheet::text('Every figure comes from the posted documents. Amounts include VAT.', Spreadsheet::NOTE)]);

        if ($sales = $blocks['sales']) {
            $book->blank()
                ->row(['Sales', 'Amount', 'Documents'], Spreadsheet::HEADER)
                ->row(['Sold', Spreadsheet::money($sales['subtotal']), $sales['documents']])
                ->row(['Discounts given', Spreadsheet::money(-1 * (float) $sales['discount'])])
                ->row(['Returned', Spreadsheet::money(-1 * (float) $sales['returns']), $sales['return_documents']])
                ->row([Spreadsheet::text('Net sales', Spreadsheet::BOLD), Spreadsheet::money($sales['net'], true)]);

            if ($blocks['can']['viewCost']) {
                $book->row(['Cost of goods sold', Spreadsheet::money($sales['cost'])])
                    ->row([Spreadsheet::text('Gross profit', Spreadsheet::BOLD), Spreadsheet::money($sales['gross_profit'], true)]);
            }
        }

        if ($collections = $blocks['collections']) {
            $book->blank()->row(['Money received', 'Amount'], Spreadsheet::HEADER);

            foreach ($collections['methods'] as $method) {
                $book->row([$method['name'], Spreadsheet::money($method['amount'])]);
            }

            $book->row([Spreadsheet::text('Total received', Spreadsheet::BOLD), Spreadsheet::money($collections['total'], true)]);

            if ((float) $collections['account'] > 0) {
                $book->row([Spreadsheet::text('of which paid against invoices', Spreadsheet::NOTE), Spreadsheet::money($collections['account'])]);
            }
        }

        if ($purchases = $blocks['purchases']) {
            $book->blank()
                ->row(['Purchases', 'Amount', 'Documents'], Spreadsheet::HEADER)
                ->row(['Bought', Spreadsheet::money($purchases['total']), $purchases['documents']])
                ->row(['Sent back', Spreadsheet::money(-1 * (float) $purchases['returns']), $purchases['return_documents']])
                ->row([Spreadsheet::text('Net purchases', Spreadsheet::BOLD), Spreadsheet::money($purchases['net'], true)]);
        }

        if ($inventory = $blocks['inventory']) {
            $book->blank()
                ->row(['Stock on hand today', 'Amount'], Spreadsheet::HEADER)
                ->row(['Value at cost', Spreadsheet::money($inventory['value'])])
                ->row(['Units', Spreadsheet::number($inventory['units'])])
                ->row(['Products running low', Spreadsheet::number($inventory['low_stock'])]);
        }
    }

    /** Every posted sale in the period, with how it was paid. */
    protected function salesSheet(Spreadsheet $book, ReportFilters $filters, bool $withCost): void
    {
        $methods = PaymentMethod::query()
            ->orderByRaw("CASE WHEN kind = 'cash' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name']);

        $header = ['Date', 'Reference', 'Customer', 'Cashier', 'Subtotal', 'Discount', 'Total'];
        foreach ($methods as $method) {
            $header[] = $method->name;
        }
        $header = array_merge($header, ['On account', 'Still owed'], $withCost ? ['Cost', 'Gross profit'] : []);

        $book->sheet('Sales', array_merge([12, 18, 28, 22, 14, 12, 14], array_fill(0, $methods->count(), 14), [14, 14], $withCost ? [14, 14] : []), true)
            ->row($header, Spreadsheet::HEADER);

        $sums = array_fill_keys(['subtotal', 'discount', 'total', 'on_account', 'balance_due', 'cost'], 0);
        $byMethod = array_fill_keys($methods->pluck('id')->all(), 0);

        DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales.posted_by')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->whereBetween('sales.sale_date', $this->bounds($filters))
            ->tap(fn (Builder $query) => $this->metrics->narrowSales($query, $filters->metrics(), 'sales'))
            ->select('sales.id', 'sales.reference', 'sales.sale_date', 'sales.subtotal', 'sales.discount',
                'sales.total_amount', 'sales.total_cost', 'sales.on_account', 'sales.balance_due')
            ->addSelect('customers.name AS customer', 'users.name AS cashier')
            ->chunkById(500, function ($sales) use ($book, $methods, $withCost, &$sums, &$byMethod) {
                $paid = DB::table('sale_payments')
                    ->whereIn('sale_id', $sales->pluck('id'))
                    ->get(['sale_id', 'payment_method_id', 'amount'])
                    ->groupBy('sale_id');

                foreach ($sales as $sale) {
                    $row = [
                        Spreadsheet::date($sale->sale_date),
                        $sale->reference,
                        $sale->customer,
                        $sale->cashier,
                        Spreadsheet::money($sale->subtotal),
                        Spreadsheet::money($sale->discount),
                        Spreadsheet::money($sale->total_amount),
                    ];

                    $payments = ($paid[$sale->id] ?? collect())->groupBy('payment_method_id');
                    foreach ($methods as $method) {
                        $amount = round((float) ($payments[$method->id] ?? collect())->sum('amount'), 2);
                        $byMethod[$method->id] += $amount;
                        $row[] = $amount == 0 ? null : Spreadsheet::money($amount);
                    }

                    $row[] = Spreadsheet::money($sale->on_account);
                    $row[] = Spreadsheet::money($sale->balance_due);

                    if ($withCost) {
                        $row[] = Spreadsheet::money($sale->total_cost);
                        $row[] = Spreadsheet::money(round((float) $sale->total_amount - (float) $sale->total_cost, 2));
                    }

                    $book->row($row);

                    $sums['subtotal'] += (float) $sale->subtotal;
                    $sums['discount'] += (float) $sale->discount;
                    $sums['total'] += (float) $sale->total_amount;
                    $sums['on_account'] += (float) $sale->on_account;
                    $sums['balance_due'] += (float) $sale->balance_due;
                    $sums['cost'] += (float) $sale->total_cost;
                }
            }, 'sales.id', 'id');

        $total = [Spreadsheet::text('Total', Spreadsheet::BOLD), null, null, null,
            Spreadsheet::money(round($sums['subtotal'], 2), true),
            Spreadsheet::money(round($sums['discount'], 2), true),
            Spreadsheet::money(round($sums['total'], 2), true)];
        foreach ($methods as $method) {
            $total[] = Spreadsheet::money(round($byMethod[$method->id], 2), true);
        }
        $total[] = Spreadsheet::money(round($sums['on_account'], 2), true);
        $total[] = Spreadsheet::money(round($sums['balance_due'], 2), true);
        if ($withCost) {
            $total[] = Spreadsheet::money(round($sums['cost'], 2), true);
            $total[] = Spreadsheet::money(round($sums['total'] - $sums['cost'], 2), true);
        }

        $book->row($total);
    }

    /** Every posted sales return in the period. */
    protected function salesReturnsSheet(Spreadsheet $book, ReportFilters $filters, bool $withCost): void
    {
        $book->sheet('Sales returns', array_merge([12, 18, 18, 28, 22, 14, 16, 14], $withCost ? [14] : []), true)
            ->row(array_merge(
                ['Date', 'Reference', 'Sale', 'Customer', 'Cashier', 'Refund', 'Off the balance', 'Paid back'],
                $withCost ? ['Cost'] : [],
            ), Spreadsheet::HEADER);

        $sums = ['total' => 0.0, 'credited' => 0.0, 'cost' => 0.0];

        DB::table('sales_returns')
            ->join('sales', 'sales.id', '=', 'sales_returns.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales_returns.posted_by')
            ->where('sales_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_returns.return_date', $this->bounds($filters))
            ->tap(fn (Builder $query) => $this->metrics->narrowSales($query, $filters->metrics(), 'sales_returns'))
            ->select('sales_returns.id', 'sales_returns.reference', 'sales_returns.return_date',
                'sales_returns.total_amount', 'sales_returns.total_cost', 'sales_returns.credited_amount')
            ->addSelect('sales.reference AS sale', 'customers.name AS customer', 'users.name AS cashier')
            ->chunkById(500, function ($returns) use ($book, $withCost, &$sums) {
                foreach ($returns as $return) {
                    $credited = (float) ($return->credited_amount ?? 0);

                    $book->row(array_merge([
                        Spreadsheet::date($return->return_date),
                        $return->reference,
                        $return->sale,
                        $return->customer,
                        $return->cashier,
                        Spreadsheet::money($return->total_amount),
                        Spreadsheet::money($credited),
                        Spreadsheet::money(round((float) $return->total_amount - $credited, 2)),
                    ], $withCost ? [Spreadsheet::money($return->total_cost)] : []));

                    $sums['total'] += (float) $return->total_amount;
                    $sums['credited'] += $credited;
                    $sums['cost'] += (float) $return->total_cost;
                }
            }, 'sales_returns.id', 'id');

        $book->row(array_merge([
            Spreadsheet::text('Total', Spreadsheet::BOLD), null, null, null, null,
            Spreadsheet::money(round($sums['total'], 2), true),
            Spreadsheet::money(round($sums['credited'], 2), true),
            Spreadsheet::money(round($sums['total'] - $sums['credited'], 2), true),
        ], $withCost ? [Spreadsheet::money(round($sums['cost'], 2), true)] : []));
    }

    /** Every posted delivery in the period. */
    protected function purchasesSheet(Spreadsheet $book, ReportFilters $filters): void
    {
        $book->sheet('Purchases', [12, 18, 28, 18, 18, 22, 14, 14, 14], true)
            ->row(['Date', 'Reference', 'Supplier', 'Supplier invoice', 'Order', 'Received by', 'Total', 'On account', 'Still owed'], Spreadsheet::HEADER);

        $sums = ['total' => 0.0, 'on_account' => 0.0, 'balance_due' => 0.0];

        DB::table('purchases')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->leftJoin('purchase_orders', 'purchase_orders.id', '=', 'purchases.purchase_order_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.posted_by')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereBetween('purchases.purchase_date', $this->bounds($filters))
            ->tap(fn (Builder $query) => $this->metrics->narrowPurchases($query, $filters->metrics(), 'purchases'))
            ->select('purchases.id', 'purchases.reference', 'purchases.purchase_date', 'purchases.supplier_reference',
                'purchases.total_amount', 'purchases.on_account', 'purchases.balance_due')
            ->addSelect('suppliers.name AS supplier', 'purchase_orders.reference AS order_reference', 'users.name AS received_by')
            ->chunkById(500, function ($purchases) use ($book, &$sums) {
                foreach ($purchases as $purchase) {
                    $book->row([
                        Spreadsheet::date($purchase->purchase_date),
                        $purchase->reference,
                        $purchase->supplier,
                        $purchase->supplier_reference,
                        $purchase->order_reference,
                        $purchase->received_by,
                        Spreadsheet::money($purchase->total_amount),
                        Spreadsheet::money($purchase->on_account),
                        Spreadsheet::money($purchase->balance_due),
                    ]);

                    $sums['total'] += (float) $purchase->total_amount;
                    $sums['on_account'] += (float) $purchase->on_account;
                    $sums['balance_due'] += (float) $purchase->balance_due;
                }
            }, 'purchases.id', 'id');

        $book->row([
            Spreadsheet::text('Total', Spreadsheet::BOLD), null, null, null, null, null,
            Spreadsheet::money(round($sums['total'], 2), true),
            Spreadsheet::money(round($sums['on_account'], 2), true),
            Spreadsheet::money(round($sums['balance_due'], 2), true),
        ]);
    }

    /** Every posted purchase return in the period. */
    protected function purchaseReturnsSheet(Spreadsheet $book, ReportFilters $filters): void
    {
        $book->sheet('Purchase returns', [12, 18, 18, 28, 22, 14, 14, 16], true)
            ->row(['Date', 'Reference', 'Delivery', 'Supplier', 'Recorded by', 'Value at cost', 'Off the bill', 'Supplier credit'], Spreadsheet::HEADER);

        $sums = ['total' => 0.0, 'credited' => 0.0, 'credit' => 0.0];

        DB::table('purchase_returns')
            ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->leftJoin('users', 'users.id', '=', 'purchase_returns.posted_by')
            ->where('purchase_returns.status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_returns.return_date', $this->bounds($filters))
            ->tap(fn (Builder $query) => $this->metrics->narrowPurchases($query, $filters->metrics(), 'purchase_returns'))
            ->select('purchase_returns.id', 'purchase_returns.reference', 'purchase_returns.return_date',
                'purchase_returns.total_cost', 'purchase_returns.credited_amount', 'purchase_returns.supplier_credit_amount')
            ->addSelect('purchases.reference AS delivery', 'suppliers.name AS supplier', 'users.name AS recorded_by')
            ->chunkById(500, function ($returns) use ($book, &$sums) {
                foreach ($returns as $return) {
                    $book->row([
                        Spreadsheet::date($return->return_date),
                        $return->reference,
                        $return->delivery,
                        $return->supplier,
                        $return->recorded_by,
                        Spreadsheet::money($return->total_cost),
                        Spreadsheet::money($return->credited_amount ?? 0),
                        Spreadsheet::money($return->supplier_credit_amount ?? 0),
                    ]);

                    $sums['total'] += (float) $return->total_cost;
                    $sums['credited'] += (float) ($return->credited_amount ?? 0);
                    $sums['credit'] += (float) ($return->supplier_credit_amount ?? 0);
                }
            }, 'purchase_returns.id', 'id');

        $book->row([
            Spreadsheet::text('Total', Spreadsheet::BOLD), null, null, null, null,
            Spreadsheet::money(round($sums['total'], 2), true),
            Spreadsheet::money(round($sums['credited'], 2), true),
            Spreadsheet::money(round($sums['credit'], 2), true),
        ]);
    }

    /**
     * A net breakdown, every row of it, with its total underneath — the
     * same `total` the screen's "Other" line is worked out from.
     */
    protected function breakdownSheet(
        Spreadsheet $book,
        string $name,
        array $breakdown,
        string $measure,
        bool $withSku,
        string $label = 'Product',
        string $measureLabel = 'Quantity, net',
    ): void {
        $book->sheet($name, $withSku ? [18, 40, 16, 16] : [40, 16, 16], true)
            ->row(array_merge($withSku ? ['SKU'] : [], [$label, $measureLabel, 'Amount, net']), Spreadsheet::HEADER);

        foreach ($breakdown['rows'] as $row) {
            $book->row(array_merge(
                $withSku ? [$row['sku']] : [],
                [$row['name'], Spreadsheet::number($row[$measure]), Spreadsheet::money($row['amount'])],
            ));
        }

        $book->row(array_merge(
            $withSku ? [Spreadsheet::text('Total', Spreadsheet::BOLD), null] : [Spreadsheet::text('Total', Spreadsheet::BOLD)],
            [Spreadsheet::number($breakdown['total'][$measure], true), Spreadsheet::money($breakdown['total']['amount'], true)],
        ));
    }

    /** @return array{0:string,1:string} */
    protected function bounds(ReportFilters $filters): array
    {
        return [
            $filters->from->copy()->startOfDay()->toDateTimeString(),
            $filters->to->copy()->endOfDay()->toDateTimeString(),
        ];
    }
}
