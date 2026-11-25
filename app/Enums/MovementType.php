<?php

namespace App\Enums;

/**
 * Why stock moved.
 *
 * Every row in the movement ledger carries one of these, and each one knows
 * which way the stock went. The ledger asks the case, never the caller, so a
 * sale can never be posted as an increase by mistake.
 */
enum MovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SalesReturn = 'sales_return';
    case PurchaseReturn = 'purchase_return';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case PurchaseVoid = 'purchase_void';
    case SaleVoid = 'sale_void';
    // Phase 31.5: a transfer writes one of each per line, out of the source
    // and into the destination; its void writes the mirror pair.
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferVoidOut = 'transfer_void_out';
    case TransferVoidIn = 'transfer_void_in';

    /**
     * Does this bring stock in, or take it out?
     *
     * An incoming movement opens a new FIFO layer at the cost it came in at.
     * An outgoing one eats the oldest layers still holding stock.
     */
    public function isIncoming(): bool
    {
        return match ($this) {
            self::Opening, self::Purchase, self::SalesReturn, self::AdjustmentIn, self::SaleVoid,
            self::TransferIn, self::TransferVoidIn => true,
            self::Sale, self::PurchaseReturn, self::AdjustmentOut, self::PurchaseVoid,
            self::TransferOut, self::TransferVoidOut => false,
        };
    }

    public function isOutgoing(): bool
    {
        return ! $this->isIncoming();
    }

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening',
            self::Purchase => 'Purchase',
            self::Sale => 'Sale',
            self::SalesReturn => 'Sales Return',
            self::PurchaseReturn => 'Purchase Return',
            self::AdjustmentIn => 'Adjustment (+)',
            self::AdjustmentOut => 'Adjustment (-)',
            self::PurchaseVoid => 'Purchase Voided',
            self::SaleVoid => 'Sale Voided',
            self::TransferOut => 'Transfer Out',
            self::TransferIn => 'Transfer In',
            self::TransferVoidOut => 'Transfer Voided (-)',
            self::TransferVoidIn => 'Transfer Voided (+)',
        };
    }

    /** The colour family for the badge on the movement screen. */
    public function tone(): string
    {
        return match ($this) {
            self::Opening => 'neutral',
            self::Purchase => 'success',
            self::Sale => 'danger',
            self::SalesReturn => 'info',
            self::PurchaseReturn => 'warning',
            self::AdjustmentIn => 'success',
            self::AdjustmentOut => 'warning',
            self::PurchaseVoid => 'danger',
            self::SaleVoid => 'info',
            self::TransferOut, self::TransferIn => 'neutral',
            self::TransferVoidOut, self::TransferVoidIn => 'neutral',
        };
    }

    /**
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
