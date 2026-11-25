<?php

namespace App\Enums;

/**
 * Where a loss report stands (phase 33).
 *
 * Not `DocumentStatus`: a report is never posted itself. Approving it posts
 * a decrease adjustment, which is.
 *
 * Pending: the goods are held aside — on the books, but nobody may sell,
 * move or reserve them. Approved: written off. Rejected: the approver said
 * no, and the goods are free again. Withdrawn: taken back before an answer,
 * the goods free again too.
 */
enum LossReportStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Approved => 'Written off',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Withdrawn',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'danger',
            self::Rejected, self::Cancelled => 'neutral',
        };
    }

    /** Still holding its goods aside. */
    public function holds(): bool
    {
        return $this === self::Pending;
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
