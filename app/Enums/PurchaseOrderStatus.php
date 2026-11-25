<?php

namespace App\Enums;

/**
 * Where a purchase order stands.
 *
 * For approval: placed, and waiting for somebody who may approve orders
 * (phase 24.4) — nothing can be received against it yet, and it prints only
 * as a draft. Rejected: the approver said no, with a reason; final. Open:
 * approved, nothing delivered yet. Partly received: some of it has come
 * in. Received: every line is in full. Closed: partly received and not
 * expected to be filled — closed short, with a reason. Cancelled: called
 * off before anything came, with a reason.
 *
 * Received and partly received are never set by hand: the purchase poster
 * works them out from what posted deliveries brought in (phase 23).
 */
enum PurchaseOrderStatus: string
{
    case PendingApproval = 'pending_approval';
    case Open = 'open';
    case PartlyReceived = 'partly_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'For approval',
            self::Rejected => 'Rejected',
            self::Open => 'Open',
            self::PartlyReceived => 'Partly received',
            self::Received => 'Received',
            self::Closed => 'Closed short',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::PendingApproval => 'warning',
            self::Rejected => 'danger',
            self::Open => 'info',
            self::PartlyReceived => 'warning',
            self::Received => 'success',
            self::Closed => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Not yet approved, or never will be: it prints only as a draft, and
     * nothing is received against it.
     */
    public function isUnapproved(): bool
    {
        return $this === self::PendingApproval || $this === self::Rejected;
    }

    /**
     * Still waiting for goods: a delivery may be posted against it. Only an
     * approved order — one waiting for approval is not outstanding.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::PartlyReceived;
    }

    /**
     * @return list<string>
     */
    public static function outstandingValues(): array
    {
        return [self::Open->value, self::PartlyReceived->value];
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
