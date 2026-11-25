<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a sale was paid.
 *
 * `amount` is what this payment put towards the sale, so a sale's rows add
 * up to its total less what went on account: only money that came in is a
 * row here. On the cash row `tendered` is what was handed over, and the gap
 * is the change.
 *
 * Not auditable on its own, like a sale line: the sale's Post row already
 * says it was paid, and a split sale would otherwise write a trail row per
 * payment.
 */
class SalePayment extends Model
{
    protected $fillable = ['sale_id', 'payment_method_id', 'amount', 'tendered', 'reference'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /** What was handed back from this payment: only ever from cash. */
    public function change(): string
    {
        if ($this->tendered === null) {
            return '0.00';
        }

        return bcsub((string) $this->tendered, (string) $this->amount, 2);
    }
}
