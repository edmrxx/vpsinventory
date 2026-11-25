<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a customer owes, and whether they may owe more.
 *
 * The one place a credit sale is weighed against a limit. The form request
 * asks so the error lands under the right box; `SalePoster` asks again with
 * the customer's row locked, and that is the answer that counts: two tills
 * charging one customer at the same moment must not both fit under a limit
 * only one of them fits under.
 *
 * What is owed is always the sum of each sale's `balance_due`, in whole
 * centavos. No figure of its own is kept on the customer.
 *
 * A limit of zero means no credit. There is no way to say "no limit".
 *
 * Every other reading of an account lives here too (phase 19): what is
 * overdue, the aging buckets, the statement, the flags, and the check that
 * each sale's balance still adds up. A screen asks; it never sums for
 * itself.
 *
 * Dates are compared with `>=` and `<` against the start of a day only. A
 * `date` cast writes a full datetime on SQLite, so a `<=` against a bare
 * date would drop that very day there and keep it on MySQL.
 */
final class CustomerCredit
{
    /**
     * What this customer owes, in centavos.
     *
     * `$locking` is for the poster, holding the customer's row. A plain read
     * in a transaction sees the snapshot from its first read, which can be
     * from before the lock was granted, so it would miss the very sale the
     * lock made it wait for. A locking read sees what is committed now.
     */
    public static function owed(int $customerId, bool $locking = false): int
    {
        return (int) Sale::query()
            ->where('customer_id', $customerId)
            ->where('balance_due', '>', 0)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->sum(DB::raw('ROUND(balance_due * 100)'));
    }

    /**
     * Why this much cannot go on this customer's account, or null if it can.
     *
     * @param  int  $onAccount  In centavos.
     * @param  int|null  $owed  In centavos. Read here when not given.
     */
    public static function refusal(Customer $customer, int $onAccount, ?int $owed = null): ?string
    {
        if ($customer->is_walk_in) {
            return 'A walk-in sale is paid at the counter. Pick the customer to charge this to.';
        }

        $limit = Quantity::centavos((float) $customer->credit_limit);

        if ($limit <= 0) {
            return "{$customer->name} has no credit limit, so this sale cannot go on account.";
        }

        $owed ??= self::owed((int) $customer->id);

        if ($owed + $onAccount <= $limit) {
            return null;
        }

        return self::money($onAccount)." on account would take {$customer->name} over the limit of "
            .self::money($limit).'. Owed now: '.self::money($owed).'; credit left: '
            .self::money(max($limit - $owed, 0)).'.';
    }

    /** The longest terms a sale, or a customer, may be given. */
    public const MAX_TERMS_DAYS = 365;

    /**
     * When a sale on this date falls due: after the days agreed on that
     * sale (phase 18.1), or after the customer's usual terms when none were
     * sent.
     */
    public static function dueDate(Customer $customer, Carbon $saleDate, ?int $termsDays = null): Carbon
    {
        return $saleDate->copy()->addDays($termsDays ?? (int) $customer->payment_terms_days);
    }

    /**
     * The days agreed on one sale on account, or why they cannot be given.
     *
     * The customer's usual terms are where every sale starts (phase 18.1).
     * Anybody selling may give fewer days, down to none (due the day of the
     * sale). More days than usual is giving more credit, so it needs
     * `customers.credit`, the permission that sets the terms themselves.
     * The form request asks here so the error sits under the box;
     * `SalePoster` asks again and decides.
     */
    public static function termsRefusal(Customer $customer, int $termsDays, bool $mayExtend): ?string
    {
        if ($termsDays < 0) {
            return 'Terms cannot be below zero.';
        }

        if ($termsDays > self::MAX_TERMS_DAYS) {
            return 'Terms are at most '.self::MAX_TERMS_DAYS.' days.';
        }

        $usual = (int) $customer->payment_terms_days;

        if ($termsDays > $usual && ! $mayExtend) {
            return "{$customer->name}'s usual terms are {$usual} ".($usual === 1 ? 'day' : 'days')
                .'. Only somebody who may set credit can give more.';
        }

        return null;
    }

    /**
     * The customer's account in pesos, for a screen.
     *
     * Credit left never reads below zero: a limit lowered under what is
     * already owed leaves nothing to spend, not a negative.
     *
     * @param  int|null  $owed  In centavos. Read here when not given.
     * @return array{limit:string, balance:string, available:string, terms_days:int}
     */
    public static function account(Customer $customer, ?int $owed = null): array
    {
        $owed ??= self::owed((int) $customer->id);
        $limit = Quantity::centavos((float) $customer->credit_limit);

        return [
            'limit' => self::figure($limit),
            'balance' => self::figure($owed),
            'available' => self::figure(max($limit - $owed, 0)),
            'terms_days' => (int) $customer->payment_terms_days,
        ];
    }

    /**
     * Is this customer behind? `balance` and `overdue` in centavos.
     *
     * Overdue: some invoice is past its due date and not paid. Over limit:
     * owing more than the limit allows, which happens when a limit is
     * lowered, or set to nothing, under what is already owed — a sale never
     * goes over it.
     *
     * @return array{overdue: bool, over_limit: bool}
     */
    public static function flags(Customer $customer, int $balance, int $overdue): array
    {
        return [
            'overdue' => $overdue > 0,
            'over_limit' => $balance > 0 && $balance > Quantity::centavos((float) $customer->credit_limit),
        ];
    }

    /**
     * The aging buckets as of a day, oldest last.
     *
     * Each is `[key, label, on or after, before]`, as dates; a null end is
     * open. "Current" is everything not yet past due, including what falls
     * due today. Worked out here as dates, so the SQL needs no date maths of
     * its own and reads the same on MySQL and SQLite.
     *
     * @return list<array{key:string, label:string, from:?string, before:?string}>
     */
    public static function agingBuckets(Carbon $asOf): array
    {
        $day = fn (int $back) => $asOf->copy()->startOfDay()->subDays($back)->toDateString();

        return [
            ['key' => 'current', 'label' => 'Not yet due', 'from' => $day(0), 'before' => null],
            ['key' => 'days_30', 'label' => '1–30 days', 'from' => $day(30), 'before' => $day(0)],
            ['key' => 'days_60', 'label' => '31–60 days', 'from' => $day(60), 'before' => $day(30)],
            ['key' => 'days_90', 'label' => '61–90 days', 'from' => $day(90), 'before' => $day(60)],
            ['key' => 'over_90', 'label' => 'Over 90 days', 'from' => null, 'before' => $day(90)],
        ];
    }

    /**
     * What every customer owes, split by how far past due, as of a day.
     *
     * One grouped query, however many customers. An invoice with no due
     * date counts as due on the day it was sold.
     *
     * @return array{buckets: list<array{key:string,label:string}>, rows: list<array<string, mixed>>, totals: array<string, string>}
     */
    public static function aging(Carbon $asOf, ?int $customerId = null): array
    {
        $buckets = self::agingBuckets($asOf);
        $due = 'COALESCE(sales.due_date, sales.sale_date)';

        $query = Sale::query()
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.status', DocumentStatus::Posted->value)
            ->where('sales.balance_due', '>', 0)
            ->when($customerId, fn (Builder $q, $id) => $q->where('sales.customer_id', $id))
            ->groupBy('customers.id', 'customers.code', 'customers.name', 'customers.credit_limit', 'customers.payment_terms_days')
            ->orderBy('customers.name')
            ->select([
                'customers.id', 'customers.code', 'customers.name',
                'customers.credit_limit', 'customers.payment_terms_days',
            ])
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(ROUND(sales.balance_due * 100)), 0) AS total_cents')
            ->selectRaw("MIN({$due}) AS oldest_due");

        foreach ($buckets as $bucket) {
            [$condition, $bindings] = self::between($due, $bucket['from'], $bucket['before']);

            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN {$condition} THEN ROUND(sales.balance_due * 100) ELSE 0 END), 0) AS {$bucket['key']}",
                $bindings,
            );
        }

        $totals = array_fill_keys([...array_column($buckets, 'key'), 'total'], 0);

        $rows = $query->toBase()->get()->map(function ($row) use ($buckets, &$totals) {
            $figures = [];

            foreach ($buckets as $bucket) {
                $cents = (int) $row->{$bucket['key']};
                $figures[$bucket['key']] = self::figure($cents);
                $totals[$bucket['key']] += $cents;
            }

            $totals['total'] += (int) $row->total_cents;
            $limit = Quantity::centavos((float) $row->credit_limit);

            return [
                'id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'invoices' => (int) $row->invoices,
                'oldest_due' => $row->oldest_due ? Carbon::parse($row->oldest_due)->format('d M Y') : null,
                'total' => self::figure((int) $row->total_cents),
                'limit' => self::figure($limit),
                'overdue' => (int) $row->total_cents > (int) $row->current,
                'over_limit' => (int) $row->total_cents > $limit,
                ...$figures,
            ];
        })->all();

        return [
            'buckets' => array_map(fn (array $b) => ['key' => $b['key'], 'label' => $b['label']], $buckets),
            'rows' => $rows,
            'totals' => array_map(fn (int $cents) => self::figure($cents), $totals),
        ];
    }

    /**
     * Every invoice still owed, narrowed: the Collectibles list (phase 19.1).
     *
     * `month` is `YYYY-MM`, read against the due date or, with `by` = sold,
     * the sale date. `status` is `overdue` (past due as of the day) or
     * `current` (not yet past due). A sale with no due date counts as due on
     * the day it was sold, as in aging.
     *
     * @param  array{customer_id?:?int, month?:?string, by?:?string, status?:?string, posted_by?:?int}  $filters
     */
    public static function openInvoices(Carbon $asOf, array $filters = []): Builder
    {
        $due = 'COALESCE(sales.due_date, sales.sale_date)';
        $today = $asOf->copy()->startOfDay()->toDateString();

        return Sale::query()
            ->where('sales.status', DocumentStatus::Posted->value)
            ->where('sales.balance_due', '>', 0)
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $id) => $q->where('sales.customer_id', $id))
            ->when($filters['posted_by'] ?? null, fn (Builder $q, $id) => $q->where('sales.posted_by', $id))
            ->when($filters['month'] ?? null, function (Builder $q, string $month) use ($due, $filters) {
                $start = Carbon::createFromFormat('!Y-m', $month);
                $column = ($filters['by'] ?? 'due') === 'sold' ? 'sales.sale_date' : $due;

                $q->whereRaw("{$column} >= ?", [$start->toDateString()])
                    ->whereRaw("{$column} < ?", [$start->copy()->addMonthNoOverflow()->toDateString()]);
            })
            ->when(($filters['status'] ?? null) === 'overdue', fn (Builder $q) => $q->whereRaw("{$due} < ?", [$today]))
            ->when(($filters['status'] ?? null) === 'current', fn (Builder $q) => $q->whereRaw("{$due} >= ?", [$today]));
    }

    /**
     * What the narrowed list adds up to, in one query: what is collectible,
     * how much of it is past due, and over how many invoices and customers.
     *
     * @param  array{customer_id?:?int, month?:?string, by?:?string, status?:?string, posted_by?:?int}  $filters
     * @return array{total:string, overdue:string, invoices:int, customers:int}
     */
    public static function openTotals(Carbon $asOf, array $filters = []): array
    {
        $today = $asOf->copy()->startOfDay()->toDateString();

        $row = self::openInvoices($asOf, $filters)
            ->selectRaw('COALESCE(SUM(ROUND(sales.balance_due * 100)), 0) AS total_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(sales.due_date, sales.sale_date) < ? THEN ROUND(sales.balance_due * 100) ELSE 0 END), 0) AS overdue_cents', [$today])
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COUNT(DISTINCT sales.customer_id) AS customers')
            ->toBase()
            ->first();

        return [
            'total' => self::figure((int) ($row->total_cents ?? 0)),
            'overdue' => self::figure((int) ($row->overdue_cents ?? 0)),
            'invoices' => (int) ($row->invoices ?? 0),
            'customers' => (int) ($row->customers ?? 0),
        ];
    }

    /**
     * What is past due as of a day, across every customer: the dashboard's
     * Overdue card. One query.
     *
     * @return array{cents:int, customers:int, invoices:int}
     */
    public static function overdue(Carbon $asOf, ?int $staffId = null): array
    {
        $row = self::pastDue($asOf, $staffId)
            ->selectRaw('COALESCE(SUM(ROUND(balance_due * 100)), 0) AS cents')
            ->selectRaw('COUNT(DISTINCT customer_id) AS customers')
            ->selectRaw('COUNT(*) AS invoices')
            ->toBase()
            ->first();

        return [
            'cents' => (int) ($row->cents ?? 0),
            'customers' => (int) ($row->customers ?? 0),
            'invoices' => (int) ($row->invoices ?? 0),
        ];
    }

    /**
     * The customers furthest behind, longest overdue first: the dashboard's
     * list under the cards. One query.
     *
     * @return list<array{id:int, name:string, code:?string, cents:int, invoices:int, oldest_due:string, days:int}>
     */
    public static function overdueCustomers(Carbon $asOf, ?int $staffId = null, int $limit = 5): array
    {
        return self::pastDue($asOf, $staffId)
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->groupBy('customers.id', 'customers.name', 'customers.code')
            ->select(['customers.id', 'customers.name', 'customers.code'])
            ->selectRaw('COALESCE(SUM(ROUND(sales.balance_due * 100)), 0) AS cents')
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('MIN(sales.due_date) AS oldest_due')
            ->orderBy('oldest_due')
            ->orderByDesc('cents')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(function ($row) use ($asOf) {
                $oldest = Carbon::parse($row->oldest_due)->startOfDay();

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'code' => $row->code,
                    'cents' => (int) $row->cents,
                    'invoices' => (int) $row->invoices,
                    'oldest_due' => $oldest->format('d M Y'),
                    'days' => (int) $oldest->diffInDays($asOf->copy()->startOfDay()),
                ];
            })
            ->all();
    }

    /**
     * A customer's statement of account for a period.
     *
     * The balance brought forward, then every sale that went on account,
     * every return that came off what was owed, and every payment, by date,
     * with the balance after each. Voided documents count for nothing, as
     * everywhere else. A period that runs to today closes on exactly what
     * the customer owes now: `StatementTest` holds it to that.
     *
     * @return array{opening:string, closing:string, charged:string, credited:string, entries: list<array<string, mixed>>}
     */
    public static function statement(Customer $customer, Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay()->toDateString();
        $end = $to->copy()->startOfDay()->addDay()->toDateString();

        $sales = fn () => Sale::query()
            ->where('customer_id', $customer->id)
            ->where('status', DocumentStatus::Posted->value)
            ->where('on_account', '>', 0);

        $returns = fn () => DB::table('sales_returns')
            ->join('sales', 'sales.id', '=', 'sales_returns.sale_id')
            ->where('sales.customer_id', $customer->id)
            ->where('sales_returns.status', DocumentStatus::Posted->value)
            ->where('sales_returns.credited_amount', '>', 0);

        $payments = fn () => CustomerPayment::query()
            ->where('customer_id', $customer->id)
            ->where('status', DocumentStatus::Posted->value);

        $opening = (int) $sales()->where('sale_date', '<', $start)->sum(DB::raw('ROUND(on_account * 100)'))
            - (int) $returns()->where('sales_returns.return_date', '<', $start)->sum(DB::raw('ROUND(sales_returns.credited_amount * 100)'))
            - (int) $payments()->where('payment_date', '<', $start)->sum(DB::raw('ROUND(amount * 100)'));

        $entries = collect();

        foreach ($sales()->where('sale_date', '>=', $start)->where('sale_date', '<', $end)
            ->get(['id', 'reference', 'sale_date', 'due_date', 'terms_days', 'on_account', 'total_amount']) as $sale) {
            $entries->push([
                'kind' => 'sale',
                'order' => 0,
                'id' => $sale->id,
                'date' => $sale->sale_date,
                'reference' => $sale->reference,
                'description' => 'Sale on account'
                    .((float) $sale->on_account < (float) $sale->total_amount ? ' (part of '.self::plain($sale->total_amount).')' : '')
                    .($sale->due_date ? ', due '.$sale->due_date->format('d M Y') : '')
                    // The days agreed on that sale (phase 18.1).
                    .($sale->due_date && $sale->terms_days !== null
                        ? ' ('.$sale->terms_days.' '.($sale->terms_days === 1 ? 'day' : 'days').')'
                        : ''),
                'debit' => Quantity::centavos((float) $sale->on_account),
                'credit' => 0,
            ]);
        }

        foreach ($returns()->where('sales_returns.return_date', '>=', $start)->where('sales_returns.return_date', '<', $end)
            ->get(['sales_returns.id', 'sales_returns.reference', 'sales_returns.return_date', 'sales_returns.credited_amount', 'sales.reference AS sale_reference']) as $return) {
            $entries->push([
                'kind' => 'return',
                'order' => 1,
                'id' => (int) $return->id,
                'date' => Carbon::parse($return->return_date),
                'reference' => $return->reference,
                'description' => 'Goods returned against '.$return->sale_reference,
                'debit' => 0,
                'credit' => Quantity::centavos((float) $return->credited_amount),
            ]);
        }

        foreach ($payments()->with('method:id,name')->where('payment_date', '>=', $start)->where('payment_date', '<', $end)
            ->get(['id', 'reference', 'payment_date', 'amount', 'payment_method_id', 'method_reference']) as $payment) {
            $entries->push([
                'kind' => 'payment',
                'order' => 2,
                'id' => $payment->id,
                'date' => $payment->payment_date,
                'reference' => $payment->reference,
                'description' => 'Payment, '.($payment->method?->name ?? 'unknown method')
                    .($payment->method_reference ? ' ref '.$payment->method_reference : ''),
                'debit' => 0,
                'credit' => Quantity::centavos((float) $payment->amount),
            ]);
        }

        $running = $opening;
        $charged = 0;
        $credited = 0;

        $lines = $entries
            ->sortBy([
                fn ($a, $b) => $a['date']->toDateString() <=> $b['date']->toDateString(),
                fn ($a, $b) => $a['order'] <=> $b['order'],
                fn ($a, $b) => $a['id'] <=> $b['id'],
            ])
            ->values()
            ->map(function (array $entry) use (&$running, &$charged, &$credited) {
                $running += $entry['debit'] - $entry['credit'];
                $charged += $entry['debit'];
                $credited += $entry['credit'];

                return [
                    'kind' => $entry['kind'],
                    'id' => $entry['id'],
                    'date' => $entry['date']->format('d M Y'),
                    'reference' => $entry['reference'],
                    'description' => $entry['description'],
                    'debit' => $entry['debit'] > 0 ? self::figure($entry['debit']) : null,
                    'credit' => $entry['credit'] > 0 ? self::figure($entry['credit']) : null,
                    'balance' => self::figure($running),
                ];
            })
            ->all();

        return [
            'opening' => self::figure($opening),
            'closing' => self::figure($running),
            'charged' => self::figure($charged),
            'credited' => self::figure($credited),
            'entries' => $lines,
        ];
    }

    /**
     * Every sale whose balance no longer adds up, for `inventory:verify`.
     *
     * A posted sale owes what went on account, less what returns took off
     * it, less what payments that still stand put towards it. A voided one
     * owes nothing. Anything else means something wrote `balance_due`
     * directly.
     *
     * @return list<array{reference:string, balance_due:string, expected:string}>
     */
    public static function reconcile(): array
    {
        $credited = DB::table('sales_returns')
            ->selectRaw('COALESCE(SUM(ROUND(credited_amount * 100)), 0)')
            ->whereColumn('sales_returns.sale_id', 'sales.id')
            ->where('sales_returns.status', DocumentStatus::Posted->value);

        $paid = DB::table('customer_payment_allocations')
            ->join('customer_payments', 'customer_payments.id', '=', 'customer_payment_allocations.customer_payment_id')
            ->selectRaw('COALESCE(SUM(ROUND(customer_payment_allocations.amount * 100)), 0)')
            ->whereColumn('customer_payment_allocations.sale_id', 'sales.id')
            ->where('customer_payments.status', DocumentStatus::Posted->value);

        return DB::table('sales')
            ->where(fn ($q) => $q->where('on_account', '>', 0)->orWhere('balance_due', '<>', 0))
            ->select(['reference', 'status'])
            ->selectRaw('ROUND(on_account * 100) AS on_account_cents')
            ->selectRaw('ROUND(balance_due * 100) AS balance_cents')
            ->selectSub($credited, 'credited_cents')
            ->selectSub($paid, 'paid_cents')
            ->orderBy('id')
            ->get()
            ->map(function ($row) {
                $expected = $row->status === DocumentStatus::Posted->value
                    ? (int) $row->on_account_cents - (int) $row->credited_cents - (int) $row->paid_cents
                    : 0;

                return (int) $row->balance_cents === $expected ? null : [
                    'reference' => (string) $row->reference,
                    'balance_due' => self::figure((int) $row->balance_cents),
                    'expected' => self::figure($expected),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Posted sales with something still owed past their due date. */
    protected static function pastDue(Carbon $asOf, ?int $staffId): Builder
    {
        return Sale::query()
            ->where('sales.balance_due', '>', 0)
            ->where('sales.due_date', '<', $asOf->copy()->startOfDay()->toDateString())
            ->where('sales.status', DocumentStatus::Posted->value)
            ->when($staffId, fn (Builder $q, $id) => $q->where('sales.posted_by', $id));
    }

    /**
     * A date range as SQL: on or after `from`, before `before`.
     *
     * @return array{0:string, 1:list<string>}
     */
    protected static function between(string $column, ?string $from, ?string $before): array
    {
        $parts = [];
        $bindings = [];

        if ($from !== null) {
            $parts[] = "{$column} >= ?";
            $bindings[] = $from;
        }

        if ($before !== null) {
            $parts[] = "{$column} < ?";
            $bindings[] = $before;
        }

        return [implode(' AND ', $parts) ?: '1 = 1', $bindings];
    }

    private static function plain(string|float $amount): string
    {
        return settings('system.currency_symbol', '₱').number_format((float) $amount, 2);
    }

    public static function figure(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function money(int $cents): string
    {
        return settings('system.currency_symbol', '₱').number_format($cents / 100, 2);
    }
}
