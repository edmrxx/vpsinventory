<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\CustomerCredit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Who goods are sold to.
 *
 * One row carries `is_walk_in`, and that row is the counter sale. It cannot
 * be deleted or switched off, because a sale always names a customer.
 */
class Customer extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'code', 'name', 'contact_person', 'email',
        'phone', 'tin', 'address', 'notes', 'is_active',
        'credit_limit', 'payment_terms_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_walk_in' => 'boolean',
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
        ];
    }

    /** Everything ever sold to this customer. */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** Orders this customer placed (phase 29). */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** Orders the kiosk sent to the cashier for this customer (CR-2). */
    public function pendingPayments(): HasMany
    {
        return $this->hasMany(PendingPayment::class);
    }

    /** Quotations made for this customer (phase 28). */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /** Money this customer brought in against what they owe. */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Adds `balance_cents`: what each customer owes, in one query however
     * many rows the page holds.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query->addSelect([
            'balance_cents' => Sale::query()
                ->selectRaw('COALESCE(SUM(ROUND(balance_due * 100)), 0)')
                ->whereColumn('sales.customer_id', 'customers.id')
                ->where('balance_due', '>', 0),
        ]);
    }

    /**
     * Adds `overdue_cents`: what each customer owes past its due date as of
     * a day. One subquery beside `withBalance()`, so a page of customers
     * still costs one query.
     */
    public function scopeWithOverdue(Builder $query, Carbon $asOf): Builder
    {
        return $query->addSelect([
            'overdue_cents' => Sale::query()
                ->selectRaw('COALESCE(SUM(ROUND(balance_due * 100)), 0)')
                ->whereColumn('sales.customer_id', 'customers.id')
                ->where('balance_due', '>', 0)
                ->where('due_date', '<', $asOf->copy()->startOfDay()->toDateString()),
        ]);
    }

    /** Only the customers with something past due as of a day. */
    public function scopeOverdue(Builder $query, Carbon $asOf): Builder
    {
        return $query->whereHas('sales', fn (Builder $q) => $q
            ->where('balance_due', '>', 0)
            ->where('due_date', '<', $asOf->copy()->startOfDay()->toDateString()));
    }

    /**
     * Only the customers owing more than their limit allows. Compared in
     * whole centavos, the same way the flag is.
     */
    public function scopeOverLimit(Builder $query): Builder
    {
        return $query->whereRaw(
            '(SELECT COALESCE(SUM(ROUND(sales.balance_due * 100)), 0) FROM sales '
                .'WHERE sales.customer_id = customers.id AND sales.balance_due > 0) > ROUND(customers.credit_limit * 100)'
        );
    }

    /** Only the customers who owe something. */
    public function scopeOwing(Builder $query): Builder
    {
        return $query->whereHas('sales', fn (Builder $q) => $q->where('balance_due', '>', 0));
    }

    /** May a sale to this customer go on account at all? */
    public function takesCredit(): bool
    {
        return ! $this->is_walk_in && (float) $this->credit_limit > 0;
    }

    /**
     * The customers a sale may be made out to, for a pick list.
     *
     * Walk-in leads, because it is the one most often wanted; the rest by
     * name. The sales form, its filter and the till all read this, so they
     * cannot offer different lists.
     *
     * With `$withCredit`, each row also says what credit it has, or null: the
     * two screens that take a payment need it to offer "On account". The
     * figure is as of when the page opened; the poster weighs it again.
     *
     * @return list<array{value:int, label:string, walk_in:bool, credit?:array<string, mixed>|null}>
     */
    public static function options(bool $activeOnly = false, bool $withCredit = false): array
    {
        return static::query()
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->when($withCredit, fn (Builder $q) => $q->withBalance()->withOverdue(Carbon::today()))
            ->orderByDesc('is_walk_in')
            ->orderBy('name')
            ->get($withCredit ? ['*'] : ['id', 'name', 'is_walk_in'])
            ->map(fn (self $c) => [
                'value' => $c->id,
                'label' => $c->name,
                'walk_in' => (bool) $c->is_walk_in,
                ...($withCredit ? [
                    'credit' => $c->takesCredit()
                        ? [
                            ...CustomerCredit::account($c, (int) $c->balance_cents),
                            // Past due as of today: the till says so, and
                            // still lets the sale through within the limit.
                            'overdue' => CustomerCredit::figure((int) $c->overdue_cents),
                        ]
                        : null,
                ] : []),
            ])
            ->all();
    }

    /** The counter-sale row, or null when the seeder has not run. */
    public static function walkIn(): ?self
    {
        return static::where('is_walk_in', true)->first();
    }
}
