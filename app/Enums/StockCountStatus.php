<?php

namespace App\Enums;

/**
 * Where a count sheet stands (phase 32).
 *
 * Not `DocumentStatus`: a count is never posted itself and moves nothing.
 * Approving it posts adjustments, which are posted documents of their own.
 *
 * Counting: the counters are writing what they find. For approval: handed
 * in; an approver approves it or sends it back for a recount. Approved: the
 * differences were posted. Cancelled: nothing was posted, and never will be.
 */
enum StockCountStatus: string
{
    case Counting = 'counting';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Counting => 'Counting',
            self::Submitted => 'For approval',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Counting => 'info',
            self::Submitted => 'warning',
            self::Approved => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /** Still open: it holds the location, and may yet be posted. */
    public function isOpen(): bool
    {
        return $this === self::Counting || $this === self::Submitted;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Counting->value, self::Submitted->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
