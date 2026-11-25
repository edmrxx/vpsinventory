<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one customer payment put towards one invoice.
 *
 * Not auditable on its own, like a sale line: the payment's Post row lists
 * the invoices it paid, and a payment against ten invoices would otherwise
 * write ten trail rows.
 */
class CustomerPaymentAllocation extends Model
{
    protected $fillable = ['customer_payment_id', 'sale_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
