<?php

namespace App\Enums;

/**
 * Where a pending payment stands (CR-2).
 *
 * Pending: sent from the kiosk, holding its goods, waiting at the cashier —
 * shown *Awaiting payment*. Paid: the cashier took the money and it became a
 * sale. Cancelled: the customer did not pay; the goods are free again.
 * "Expired" is not stored: a pending one past its `hold_until` reads as
 * expired and holds nothing, so the date alone decides.
 */
enum PendingPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
