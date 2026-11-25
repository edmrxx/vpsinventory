<?php

namespace App\Support;

use App\Models\InventoryAdjustment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockTransfer;
use App\Models\User;

/**
 * Turns the document behind a movement into a link.
 *
 * The movement row already carries `source_type` and `source_id`, so the
 * address is built from those two columns alone. Loading the document would
 * cost one query for every kind of document on the page, which is exactly
 * what the N+1 tests are there to catch.
 */
class DocumentLink
{
    /** Which screen each kind of document lives on, and what opens it. */
    protected const MAP = [
        Purchase::class => ['path' => 'purchases', 'permission' => 'purchases.view'],
        Sale::class => ['path' => 'sales', 'permission' => 'sales.view'],
        SalesReturn::class => ['path' => 'sales-returns', 'permission' => 'sales_returns.view'],
        PurchaseReturn::class => ['path' => 'purchase-returns', 'permission' => 'purchase_returns.view'],
        InventoryAdjustment::class => ['path' => 'adjustments', 'permission' => 'adjustments.view'],
        StockTransfer::class => ['path' => 'transfers', 'permission' => 'transfers.view'],
    ];

    /**
     * The permissions this user holds, worked out once for the whole page
     * rather than once per row.
     *
     * @return array<class-string, bool>
     */
    public static function openableBy(?User $user): array
    {
        $allowed = [];

        foreach (self::MAP as $class => $document) {
            $allowed[$class] = (bool) $user?->can($document['permission']);
        }

        return $allowed;
    }

    /**
     * The address of one document, or null when there is none to open or
     * the user may not read that kind.
     *
     * @param  array<class-string, bool>  $allowed  From `openableBy()`.
     */
    public static function for(?string $sourceType, ?int $sourceId, array $allowed): ?string
    {
        if (! $sourceType || ! $sourceId || ! isset(self::MAP[$sourceType])) {
            return null;
        }

        if (! ($allowed[$sourceType] ?? false)) {
            return null;
        }

        return '/'.self::MAP[$sourceType]['path'].'/'.$sourceId;
    }

    /** A short word for the kind of document, for a title attribute. */
    public static function label(?string $sourceType): ?string
    {
        return match ($sourceType) {
            Purchase::class => 'purchase',
            Sale::class => 'sale',
            SalesReturn::class => 'sales return',
            PurchaseReturn::class => 'purchase return',
            InventoryAdjustment::class => 'adjustment',
            StockTransfer::class => 'transfer',
            default => null,
        };
    }
}
