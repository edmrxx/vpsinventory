<?php

namespace App\Enums;

/**
 * Where a sales order stands.
 *
 * Pending: placed, waiting for approval, reserving nothing. Approved:
 * holding its stock until `reserve_until`. Fulfilled: it became a sale.
 * Cancelled: released, with a reason. "Expired" is not stored: an approved
 * order past its date reads as expired and holds nothing, so the date alone
 * decides.
 */
enum SalesOrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Reserved',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'info',
            self::Fulfilled => 'success',
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
