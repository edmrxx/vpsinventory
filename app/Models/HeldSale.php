<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sale parked at the till, to be finished later.
 *
 * Not a document: no reference, no stock moved, nothing reserved. It is
 * the basket kept on the server, so another tablet — or the same one after
 * a restart — can bring it back. Recalling it puts the lines back on the
 * counter and deletes the row; the sale is then posted through
 * `POST /sales` like any other, which checks the shelf again.
 *
 * Auditable: putting a sale on hold is recorded by itself, and the
 * controller records the recall and the discard, which are deletes
 * Eloquent is never told about (see `HeldSaleController`).
 */
class HeldSale extends Model
{
    use Auditable;

    /**
     * How many sales may wait at once.
     *
     * A counter with fifty customers on hold has stopped holding and
     * started storing, and the list on the tablet is meant to be read at a
     * glance. It also keeps what the till loads bounded.
     */
    public const MAX_OPEN = 50;

    protected $fillable = ['customer_id', 'note', 'discount', 'total_amount', 'lines', 'held_by'];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'lines' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** How many things are in it, in the units they were rung up in. */
    public function items(): int|float
    {
        $milli = array_sum(array_map(
            fn (array $line) => Quantity::toMilli($line['quantity'] ?? 0),
            $this->lines ?? [],
        ));

        return Quantity::fromMilli($milli);
    }

    public function auditLabel(): string
    {
        return $this->note ?: "#{$this->getKey()}";
    }
}
