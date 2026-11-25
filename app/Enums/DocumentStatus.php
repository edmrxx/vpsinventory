<?php

namespace App\Enums;

/**
 * Where a document stands.
 *
 * There is no draft: saving posts at once. The column exists so a draft
 * stage can be added later without a migration, and so the ledger can refuse
 * to post the same document twice.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Voided => 'Voided',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Posted => 'success',
            self::Voided => 'danger',
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
