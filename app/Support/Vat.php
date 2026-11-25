<?php

namespace App\Support;

/**
 * The VAT split, worked out in one place.
 *
 * Every amount a user types is VAT-inclusive: the price on the shelf and the
 * price on the supplier's invoice are what changes hands. So VAT is taken
 * *out* of a total rather than added on top of it.
 *
 * The net is worked out first and the VAT is what is left over, never the
 * other way round. Rounding each half on its own would let the two drift a
 * centavo apart from the total they came from, and a document whose columns
 * do not add up is a document nobody trusts.
 *
 * Nothing else may work these figures out again — the same rule the
 * inventory services live by. `vatSplit()` in `resources/js/lib/money.js`
 * draws the same sum while somebody types, in the same whole centavos.
 *
 * None of this is stored. The switch is a view for comparing VAT against
 * non-VAT, so every document follows it, and turning it off puts every
 * screen back the way it was.
 */
class Vat
{
    /**
     * The rate the business charges on what it sells.
     *
     * Zero when the business is not VAT-registered, and zero is what makes
     * every split fall away without a second flag to read. Sales, sales
     * returns and the till read this.
     *
     * **Purchases do not.** What a supplier charges is the supplier's
     * business, not ours, so the buying side reads `rate()` below and asks
     * the supplier whether it applies.
     */
    public static function currentRate(): float
    {
        if (! settings('tax.vat_registered')) {
            return 0.0;
        }

        return round((float) settings('tax.vat_rate'), 2);
    }

    /**
     * The rate as configured, whatever the Tax Settings switch says.
     *
     * A supplier who is VAT-registered charges VAT whether or not *we* are
     * registered — you simply cannot claim it back. So the buying side
     * needs the figure even with the switch off, which is exactly what
     * `currentRate()` refuses to give.
     */
    public static function rate(): float
    {
        return round((float) settings('tax.vat_rate'), 2);
    }

    /**
     * The rate written for a payload: "12.00".
     *
     * A bare float reaches the page as 12.0 and the dialog as 12 — the same
     * number written two ways, which `DocumentPreviewTest` fails on.
     */
    public static function rateLabel(float|string|null $rate): string
    {
        return number_format((float) $rate, 2, '.', '');
    }

    /**
     * What is left of a VAT-inclusive amount once the VAT is taken out.
     *
     * Written as a string with two decimals, the way a money column leaves
     * a model. A float would reach the dialog as 2000 and the page as
     * 2000.0 — the same number, written two ways, and `DocumentPreviewTest`
     * is right to call that a disagreement.
     */
    public static function net(float|string|null $gross, float|string|null $rate): string
    {
        return static::amount(static::netCentavos(static::centavos($gross), $rate));
    }

    /** The VAT inside a VAT-inclusive amount. */
    public static function tax(float|string|null $gross, float|string|null $rate): string
    {
        $centavos = static::centavos($gross);

        return static::amount($centavos - static::netCentavos($centavos, $rate));
    }

    /**
     * Both halves of an amount, ready for a payload.
     *
     * @return array{net: string, vat: string}
     */
    public static function split(float|string|null $gross, float|string|null $rate): array
    {
        return [
            'net' => static::net($gross, $rate),
            'vat' => static::tax($gross, $rate),
        ];
    }

    /* ------------------------------------------------------------ Internal */

    protected static function centavos(float|string|null $gross): int
    {
        return (int) round(((float) $gross) * 100);
    }

    /**
     * The net, in centavos, worked out with whole numbers only.
     *
     * Dividing floats puts PHP and the browser one centavo apart on an
     * amount whose net lands on exactly half a centavo — ₱42.14 came out
     * 37.63 here and 37.62 on the screen. Whole numbers cannot drift, so
     * the rate is carried as basis points and the halving is done inside
     * the fraction: this is `round(c ÷ d)` with nothing left to chance.
     *
     * The same sum lives in `vatSplit()` in `resources/js/lib/money.js`.
     */
    protected static function netCentavos(int $centavos, float|string|null $rate): int
    {
        $basisPoints = (int) round(((float) $rate) * 100);

        if ($basisPoints <= 0) {
            return $centavos;
        }

        $sign = $centavos < 0 ? -1 : 1;
        $divisor = 10000 + $basisPoints;

        return $sign * intdiv(2 * abs($centavos) * 10000 + $divisor, 2 * $divisor);
    }

    protected static function amount(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
