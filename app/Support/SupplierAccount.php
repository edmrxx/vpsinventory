<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every reading of what the shop owes its suppliers (phase 24).
 *
 * The supplier side of `CustomerCredit`. A supplier's balance is always the
 * sum of its bills' `balance_due` — there is no figure on the supplier. Its
 * credit is what returns left with it (`supplier_credit_amount`) less what
 * payments that still stand have used (`credit_used`).
 *
 * Everything is worked out in whole centavos.
 */
final class SupplierAccount
{
    /**
     * What is still owed to this supplier, in centavos.
     *
     * `$locking` is for the poster, holding the supplier's row: a plain read
     * inside the transaction could see a snapshot from before the lock.
     */
    public static function owed(int $supplierId, bool $locking = false): int
    {
        return (int) Purchase::query()
            ->where('supplier_id', $supplierId)
            ->where('balance_due', '>', 0)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->sum(DB::raw('ROUND(balance_due * 100)'));
    }

    /**
     * The supplier credit still to use, in centavos: what returns left with
     * this supplier, less what payments that still stand have used.
     */
    public static function credit(int $supplierId, bool $locking = false): int
    {
        $left = (int) DB::table('purchase_returns')
            ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->where('purchases.supplier_id', $supplierId)
            ->where('purchase_returns.status', DocumentStatus::Posted->value)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->sum(DB::raw('ROUND(purchase_returns.supplier_credit_amount * 100)'));

        $used = (int) DB::table('supplier_payments')
            ->where('supplier_id', $supplierId)
            ->where('status', DocumentStatus::Posted->value)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->sum(DB::raw('ROUND(credit_used * 100)'));

        return max(0, $left - $used);
    }

    /** The day a bill falls due: the delivery date plus the supplier's terms. */
    public static function dueDate(Supplier $supplier, Carbon $purchaseDate): Carbon
    {
        return $purchaseDate->copy()->startOfDay()->addDays(max(0, (int) $supplier->payment_terms_days));
    }

    /**
     * A supplier's account at a glance, for its page and the payment form.
     *
     * @return array{owed:string, overdue:string, credit:string, terms_days:int}
     */
    public static function account(Supplier $supplier): array
    {
        $overdue = (int) self::pastDue(Carbon::today())
            ->where('supplier_id', $supplier->id)
            ->sum(DB::raw('ROUND(balance_due * 100)'));

        return [
            'owed' => CustomerCredit::figure(self::owed($supplier->id)),
            'overdue' => CustomerCredit::figure($overdue),
            'credit' => CustomerCredit::figure(self::credit($supplier->id)),
            'terms_days' => (int) $supplier->payment_terms_days,
        ];
    }

    /**
     * Every bill still owed, narrowed: the Payables list.
     *
     * `month` is `YYYY-MM`, read against the due date. `status` is
     * `overdue` (past due as of the day) or `current` (not yet).
     *
     * @param  array{supplier_id?:?int, month?:?string, status?:?string}  $filters
     */
    public static function openBills(Carbon $asOf, array $filters = []): Builder
    {
        $due = 'COALESCE(purchases.due_date, purchases.purchase_date)';
        $today = $asOf->copy()->startOfDay()->toDateString();

        return Purchase::query()
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->where('purchases.balance_due', '>', 0)
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('purchases.supplier_id', $id))
            ->when($filters['month'] ?? null, function (Builder $q, string $month) use ($due) {
                $start = Carbon::createFromFormat('!Y-m', $month);

                $q->whereRaw("{$due} >= ?", [$start->toDateString()])
                    ->whereRaw("{$due} < ?", [$start->copy()->addMonthNoOverflow()->toDateString()]);
            })
            ->when(($filters['status'] ?? null) === 'overdue', fn (Builder $q) => $q->whereRaw("{$due} < ?", [$today]))
            ->when(($filters['status'] ?? null) === 'current', fn (Builder $q) => $q->whereRaw("{$due} >= ?", [$today]));
    }

    /**
     * What the narrowed list adds up to, split by how far past due, in one
     * query. The buckets are the aging's, edges worked out in PHP.
     *
     * @param  array{supplier_id?:?int, month?:?string, status?:?string}  $filters
     * @return array{total:string, overdue:string, bills:int, suppliers:int, buckets: list<array{key:string,label:string,amount:string}>}
     */
    public static function openTotals(Carbon $asOf, array $filters = []): array
    {
        $due = 'COALESCE(purchases.due_date, purchases.purchase_date)';
        $today = $asOf->copy()->startOfDay()->toDateString();
        $buckets = CustomerCredit::agingBuckets($asOf);

        $query = self::openBills($asOf, $filters)
            ->selectRaw('COALESCE(SUM(ROUND(purchases.balance_due * 100)), 0) AS total_cents')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$due} < ? THEN ROUND(purchases.balance_due * 100) ELSE 0 END), 0) AS overdue_cents", [$today])
            ->selectRaw('COUNT(*) AS bills')
            ->selectRaw('COUNT(DISTINCT purchases.supplier_id) AS suppliers');

        foreach ($buckets as $bucket) {
            $parts = [];
            $bindings = [];

            if ($bucket['from'] !== null) {
                $parts[] = "{$due} >= ?";
                $bindings[] = $bucket['from'];
            }

            if ($bucket['before'] !== null) {
                $parts[] = "{$due} < ?";
                $bindings[] = $bucket['before'];
            }

            $condition = implode(' AND ', $parts) ?: '1 = 1';

            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN {$condition} THEN ROUND(purchases.balance_due * 100) ELSE 0 END), 0) AS {$bucket['key']}",
                $bindings,
            );
        }

        $row = $query->toBase()->first();

        return [
            'total' => CustomerCredit::figure((int) ($row->total_cents ?? 0)),
            'overdue' => CustomerCredit::figure((int) ($row->overdue_cents ?? 0)),
            'bills' => (int) ($row->bills ?? 0),
            'suppliers' => (int) ($row->suppliers ?? 0),
            'buckets' => array_map(fn (array $bucket) => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'amount' => CustomerCredit::figure((int) ($row->{$bucket['key']} ?? 0)),
            ], $buckets),
        ];
    }

    /**
     * What the shop owes its suppliers as of a day, and how much of it is
     * past due: the dashboard's Payables card. One query.
     *
     * @return array{cents:int, overdue_cents:int, bills:int, suppliers:int}
     */
    public static function payables(Carbon $asOf): array
    {
        $today = $asOf->copy()->startOfDay()->toDateString();

        $row = Purchase::query()
            ->where('status', DocumentStatus::Posted->value)
            ->where('balance_due', '>', 0)
            ->selectRaw('COALESCE(SUM(ROUND(balance_due * 100)), 0) AS cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date < ? THEN ROUND(balance_due * 100) ELSE 0 END), 0) AS overdue_cents', [$today])
            ->selectRaw('COUNT(*) AS bills')
            ->selectRaw('COUNT(DISTINCT supplier_id) AS suppliers')
            ->toBase()
            ->first();

        return [
            'cents' => (int) ($row->cents ?? 0),
            'overdue_cents' => (int) ($row->overdue_cents ?? 0),
            'bills' => (int) ($row->bills ?? 0),
            'suppliers' => (int) ($row->suppliers ?? 0),
        ];
    }

    /**
     * Every bill whose balance is not what its documents add up to, and
     * every supplier whose credit has gone below nothing. `inventory:verify`
     * runs this.
     *
     * A posted bill owes what went on account, less what returns took off
     * it, less what payments that still stand put towards it. A voided one
     * owes nothing.
     *
     * @return list<array{reference:string, balance_due:string, expected:string}>
     */
    /**
     * What was paid to suppliers in a period, and what is still owed (phase
     * 24.5, CR-5) — the Paid side of Payables, and the Reports block.
     *
     * Paid on delivery is each posted delivery's bill less what went on
     * account, dated the day it was delivered; paid later is the money of
     * posted supplier payments (never the supplier credit they used), dated
     * the day it was paid. Still owed and overdue are as of `$asOf`, whatever
     * the period, as on the Owed side. A voided document counts for nothing.
     * Five grouped queries, whatever the number of suppliers.
     *
     * @param  array{supplier_id?:?int, staff_id?:?int}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, methods: list<array{name: string, on_delivery: string, later: string, total: string}>}
     */
    public static function paidSummary(Carbon $from, Carbon $to, Carbon $asOf, array $filters = []): array
    {
        $supplier = $filters['supplier_id'] ?? null;
        $staff = $filters['staff_id'] ?? null;
        $between = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $deliveries = fn () => DB::table('purchases')
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('purchase_date', $between)
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier))
            ->when($staff, fn ($q) => $q->where('posted_by', $staff));

        $payments = fn () => DB::table('supplier_payments')
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('payment_date', $between)
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier))
            ->when($staff, fn ($q) => $q->where('posted_by', $staff));

        $bought = $deliveries()
            ->groupBy('supplier_id')
            ->select('supplier_id')
            ->selectRaw('COALESCE(SUM(ROUND(total_amount * 100)), 0) AS bought')
            ->selectRaw('COALESCE(SUM(ROUND((total_amount - on_account) * 100)), 0) AS on_delivery')
            ->get()->keyBy('supplier_id');

        $later = $payments()
            ->groupBy('supplier_id')
            ->select('supplier_id')
            ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS later')
            ->get()->keyBy('supplier_id');

        $today = $asOf->copy()->startOfDay()->toDateString();
        $owing = self::openBills($asOf, ['supplier_id' => $supplier])
            ->when($staff, fn ($q) => $q->where('purchases.posted_by', $staff))
            ->reorder()
            ->groupBy('purchases.supplier_id')
            ->select('purchases.supplier_id')
            ->selectRaw('COALESCE(SUM(ROUND(purchases.balance_due * 100)), 0) AS owed')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(purchases.due_date, purchases.purchase_date) < ? THEN ROUND(purchases.balance_due * 100) ELSE 0 END), 0) AS overdue', [$today])
            ->toBase()->get()->keyBy('supplier_id');

        $ids = collect([$bought->keys(), $later->keys(), $owing->keys()])->flatten()->unique()->values();
        $names = Supplier::whereIn('id', $ids)->pluck('name', 'id');

        $sum = array_fill_keys(['bought', 'on_delivery', 'later', 'owed', 'overdue'], 0);
        $rows = [];

        foreach ($ids as $id) {
            $row = [
                'bought' => (int) ($bought[$id]->bought ?? 0),
                'on_delivery' => (int) ($bought[$id]->on_delivery ?? 0),
                'later' => (int) ($later[$id]->later ?? 0),
                'owed' => (int) ($owing[$id]->owed ?? 0),
                'overdue' => (int) ($owing[$id]->overdue ?? 0),
            ];

            foreach ($row as $key => $cents) {
                $sum[$key] += $cents;
            }

            $rows[] = ['supplier_id' => (int) $id, 'supplier' => (string) ($names[$id] ?? '—')]
                + array_map(fn (int $cents) => self::money($cents), $row)
                + ['paid' => self::money($row['on_delivery'] + $row['later']), 'sort' => $row['on_delivery'] + $row['later'] + $row['owed']];
        }

        usort($rows, fn ($a, $b) => [$b['sort'], $a['supplier']] <=> [$a['sort'], $b['supplier']]);

        // How it was paid: on delivery and later, by method. A delivery from
        // before phase 24.5 names none: "Not recorded".
        $byMethod = [];

        foreach ([
            'on_delivery' => $deliveries()->whereColumn('total_amount', '>', 'on_account')
                ->groupBy('payment_method_id')->select('payment_method_id')
                ->selectRaw('COALESCE(SUM(ROUND((total_amount - on_account) * 100)), 0) AS cents')->get(),
            'later' => $payments()->where('amount', '>', 0)
                ->groupBy('payment_method_id')->select('payment_method_id')
                ->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS cents')->get(),
        ] as $when => $groups) {
            foreach ($groups as $group) {
                $key = $group->payment_method_id ?? 0;
                $byMethod[$key] ??= ['on_delivery' => 0, 'later' => 0];
                $byMethod[$key][$when] += (int) $group->cents;
            }
        }

        $methodNames = PaymentMethod::whereIn('id', array_keys($byMethod))->pluck('name', 'id');
        $methods = [];

        foreach ($byMethod as $id => $cents) {
            $methods[] = [
                'name' => $id === 0 ? 'Not recorded' : (string) ($methodNames[$id] ?? '—'),
                'on_delivery' => self::money($cents['on_delivery']),
                'later' => self::money($cents['later']),
                'total' => self::money($cents['on_delivery'] + $cents['later']),
                'sort' => $cents['on_delivery'] + $cents['later'],
            ];
        }

        usort($methods, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        return [
            'rows' => array_map(fn (array $row) => collect($row)->except('sort')->all(), $rows),
            'totals' => array_map(fn (int $cents) => self::money($cents), $sum)
                + ['paid' => self::money($sum['on_delivery'] + $sum['later'])],
            'methods' => array_map(fn (array $method) => collect($method)->except('sort')->all(), $methods),
        ];
    }

    public static function reconcile(): array
    {
        $credited = DB::table('purchase_returns')
            ->selectRaw('COALESCE(SUM(ROUND(credited_amount * 100)), 0)')
            ->whereColumn('purchase_returns.purchase_id', 'purchases.id')
            ->where('purchase_returns.status', DocumentStatus::Posted->value);

        $paid = DB::table('supplier_payment_allocations')
            ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
            ->selectRaw('COALESCE(SUM(ROUND(supplier_payment_allocations.amount * 100)), 0)')
            ->whereColumn('supplier_payment_allocations.purchase_id', 'purchases.id')
            ->where('supplier_payments.status', DocumentStatus::Posted->value);

        $wrong = DB::table('purchases')
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
                    'balance_due' => CustomerCredit::figure((int) $row->balance_cents),
                    'expected' => CustomerCredit::figure($expected),
                ];
            })
            ->filter()
            ->values()
            ->all();

        // A supplier's credit can never have been used beyond what it had.
        $suppliers = DB::table('supplier_payments')
            ->where('status', DocumentStatus::Posted->value)
            ->where('credit_used', '>', 0)
            ->distinct()
            ->pluck('supplier_id');

        foreach ($suppliers as $supplierId) {
            $left = (int) DB::table('purchase_returns')
                ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
                ->where('purchases.supplier_id', $supplierId)
                ->where('purchase_returns.status', DocumentStatus::Posted->value)
                ->sum(DB::raw('ROUND(purchase_returns.supplier_credit_amount * 100)'));

            $used = (int) DB::table('supplier_payments')
                ->where('supplier_id', $supplierId)
                ->where('status', DocumentStatus::Posted->value)
                ->sum(DB::raw('ROUND(credit_used * 100)'));

            if ($used > $left) {
                $wrong[] = [
                    'reference' => 'Supplier #'.$supplierId.' credit',
                    'balance_due' => CustomerCredit::figure($left - $used),
                    'expected' => CustomerCredit::figure(0),
                ];
            }
        }

        return $wrong;
    }

    /** Posted bills with something still owed past their due date. */
    /** Whole centavos as a money string: 123456 -> "1234.56". */
    protected static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    protected static function pastDue(Carbon $asOf): Builder
    {
        return Purchase::query()
            ->where('purchases.balance_due', '>', 0)
            ->where('purchases.due_date', '<', $asOf->copy()->startOfDay()->toDateString())
            ->where('purchases.status', DocumentStatus::Posted->value);
    }
}
