<?php

namespace App\Enums;

/**
 * Where a quotation stands.
 *
 * Open: offered, waiting for the customer. Accepted: it became a sale.
 * Cancelled: withdrawn, with a reason. "Expired" is not stored: an open
 * quotation past its `valid_until` reads as expired, so the date alone
 * decides and nothing has to run at midnight.
 */
enum QuotationStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Accepted => 'Accepted',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Accepted => 'success',
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
