<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who goods are bought from.
 */
class Supplier extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'code', 'name', 'contact_person', 'email',
        'phone', 'tin', 'is_vat_registered', 'payment_terms_days', 'address', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    /**
     * How this supplier is described on a list: "VAT" or "Non-VAT".
     *
     * Written here rather than on each screen, so the wording cannot drift
     * between the table, the dialog and the supplier's own page.
     */
    public function vatLabel(): string
    {
        return $this->is_vat_registered ? 'VAT' : 'Non-VAT';
    }

    /** Everything ever bought from this supplier. */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** Money paid to this supplier against its bills (phase 24). */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Orders placed with this supplier (phase 22). */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
