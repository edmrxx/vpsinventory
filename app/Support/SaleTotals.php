<?php

namespace App\Support;

/**
 * What a sale comes to, in whole centavos.
 *
 * The form request, `SalePoster` and `PaymentSettlement` all ask this, so
 * the total the cashier is told to collect is exactly the total the sale is
 * stored at — a payment that has to match it to the centavo cannot be
 * checked against a figure worked out twice.
 *
 * Each line is priced to the centavo first, half up, so the subtotal is the
 * sum of the lines it prints. The discount is taken off the whole sale and
 * can never take more than the goods came to.
 *
 * Mobilization (phase 28.1, CR-4) is a charge added to the whole document —
 * bringing the goods to the site — after the discount, so it is never
 * discounted: total = subtotal − discount + mobilization. Never below zero.
 * The goods part of a total is `goods` (subtotal − discount): what a return
 * refunds from and what a figure narrowed to a category reads.
 */
final class SaleTotals
{
    /**
     * @param  iterable<array{quantity?:mixed, unit_price?:mixed}>  $lines  As typed: per typed unit.
     * @return array{subtotal:int, discount:int, goods:int, mobilization:int, total:int}
     */
    public static function of(
        iterable $lines,
        int|float|string|null $discount,
        int|float|string|null $mobilization = 0,
    ): array {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += Quantity::cents(
                Quantity::toMilli($line['quantity'] ?? 0),
                round((float) ($line['unit_price'] ?? 0), 2),
            );
        }

        $off = min(max(Quantity::centavos(round((float) ($discount ?? 0), 2)), 0), max($subtotal, 0));
        $charge = max(Quantity::centavos(round((float) ($mobilization ?? 0), 2)), 0);

        return [
            'subtotal' => $subtotal,
            'discount' => $off,
            'goods' => $subtotal - $off,
            'mobilization' => $charge,
            'total' => $subtotal - $off + $charge,
        ];
    }
}
