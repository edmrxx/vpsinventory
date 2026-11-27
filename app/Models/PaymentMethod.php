<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A way a customer may pay: Cash, GCash, Maya, a bank transfer.
 *
 * There is exactly one cash method, and it can be neither deleted nor
 * switched off: change is given from it, and a sale posted with no payment
 * named is recorded against it. The others are the owner's to add, rename
 * and switch off.
 */
class PaymentMethod extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['name', 'kind', 'requires_reference', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'requires_reference' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** Customer payments against invoices made this way. */
    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** Supplier payments made this way (phase 24). */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Deliveries paid on delivery this way (phase 24.5). */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function isCash(): bool
    {
        return $this->kind === PaymentKind::Cash;
    }

    /** The one cash method. */
    public static function cash(): ?self
    {
        return static::query()->where('kind', PaymentKind::Cash->value)->orderBy('id')->first();
    }

    /**
     * The methods a sale form may pick: cash first, because most sales are
     * cash, then the rest by name.
     *
     * @return list<array{value:int, label:string, kind:string, cash:bool, reference:bool}>
     */
    public static function options(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN kind = ? THEN 0 ELSE 1 END', [PaymentKind::Cash->value])
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'requires_reference'])
            ->map(fn (self $method) => [
                'value' => $method->id,
                'label' => $method->name,
                'kind' => $method->kind->value,
                'cash' => $method->isCash(),
                'reference' => $method->requires_reference,
            ])
            ->all();
    }

    public function auditLabel(): string
    {
        return (string) $this->name;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
