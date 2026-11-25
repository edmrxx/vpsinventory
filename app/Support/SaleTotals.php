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
 */
final class SaleTotals
{
    /**
     * @param  iterable<array{quantity?:mixed, unit_price?:mixed}>  $lines  As typed: per typed unit.
     * @return array{subtotal:int, discount:int, total:int}
     */
    public static function of(iterable $lines, int|float|string|null $discount): array
    {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += Quantity::cents(
                Quantity::toMilli($line['quantity'] ?? 0),
                round((float) ($line['unit_price'] ?? 0), 2),
            );
        }

        $off = min(max(Quantity::centavos(round((float) ($discount ?? 0), 2)), 0), max($subtotal, 0));

        return [
            'subtotal' => $subtotal,
            'discount' => $off,
            'total' => $subtotal - $off,
        ];
    }
}
