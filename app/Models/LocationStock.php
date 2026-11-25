<?php

namespace App\Models;

use App\Casts\QuantityCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one product sits at one location (phase 31.2).
 *
 * Written only by `App\Services\InventoryLedger`, in the same transaction as
 * the batches behind it, and never through a form. The product's total stays
 * on `products.on_hand`; the rows of one product add up to it, and
 * `inventory:verify` checks that they do.
 *
 * No `Auditable`: like a batch or a movement, it is explained by the
 * document that moved it.
 */
class LocationStock extends Model
{
    protected $fillable = ['product_id', 'location_id', 'on_hand'];

    protected function casts(): array
    {
        return ['on_hand' => QuantityCast::class];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
