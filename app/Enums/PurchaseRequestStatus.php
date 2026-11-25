<?php

namespace App\Enums;

/**
 * Where a purchase request stands.
 *
 * Not `DocumentStatus`: a request is never posted and moves nothing, and the
 * sales, purchases and payments that read that enum have no use for these.
 *
 * Pending waits for somebody allowed to approve it. Approved and rejected
 * are that person's answer. Closed means finished with: nobody needs it any
 * more, or (from phase 22) it became a purchase order.
 */
enum PurchaseRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Closed => 'neutral',
        };
    }

    /** Still open: something may yet be done with it. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
