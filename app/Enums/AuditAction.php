<?php

namespace App\Enums;

/**
 * The actions the audit trail records.
 *
 * Add a case here and it appears in the filter on the audit page by itself.
 *
 * Post and Void belong to the inventory documents: a purchase, a sale, a
 * return or an adjustment is posted once and then only ever voided, never
 * edited, so neither reads as an Update.
 *
 * Approve, Reject and Close belong to a purchase request, which is decided
 * rather than posted. Cancel belongs to a purchase order.
 */
enum AuditAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Create = 'create';
    case View = 'view';
    case Update = 'update';
    case Delete = 'delete';
    case Activate = 'activate';
    case Deactivate = 'deactivate';
    case Post = 'post';
    case Void = 'void';
    // A purchase request's answer, and the end of one nobody needs (phase 21).
    case Approve = 'approve';
    case Reject = 'reject';
    case Close = 'close';
    // A purchase order called off (phase 22).
    case Cancel = 'cancel';

    /**
     * The wording shown on screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::Login => 'Login',
            self::Logout => 'Logout',
            self::Create => 'Create',
            self::View => 'View',
            self::Update => 'Update',
            self::Delete => 'Delete',
            self::Activate => 'Activate',
            self::Deactivate => 'Deactivate',
            self::Post => 'Post',
            self::Void => 'Void',
            self::Approve => 'Approve',
            self::Reject => 'Reject',
            self::Close => 'Close',
            self::Cancel => 'Cancel',
        };
    }

    /**
     * The colour family used for the badge, matching the design tokens.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Update => 'info',
            self::Delete => 'danger',
            self::Deactivate => 'warning',
            self::Activate => 'success',
            self::Login => 'brand',
            self::Logout => 'neutral',
            self::View => 'neutral',
            self::Post => 'success',
            self::Void => 'danger',
            self::Approve => 'success',
            self::Reject => 'danger',
            self::Close => 'neutral',
            self::Cancel => 'danger',
        };
    }

    /**
     * Every case, shaped for a filter list.
     *
     * @return list<array{value:string,label:string,tone:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'tone' => $case->tone(),
            ],
            self::cases()
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
