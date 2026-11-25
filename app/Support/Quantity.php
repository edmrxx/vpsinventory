<?php

namespace App\Support;

/**
 * Quantities, worked out in one place.
 *
 * A quantity may carry up to three decimals: 2.5 m of wire, 0.125 kg of
 * nails. The database keeps it as a DECIMAL, which is exact. PHP would not
 * be: a float adds 0.1 and 0.2 to 0.30000000000000004, and a stock count
 * built from thousands of those drifts away from the batches behind it.
 *
 * So every comparison and every sum is done in **thousandths**, as whole
 * numbers — 2.5 is 2500 — the same way `App\Support\Vat` works money in
 * whole centavos. A value is turned into thousandths with `toMilli()`, the
 * sum is done on integers, and it goes back with `toDb()` or `normalize()`.
 * Never add, subtract or compare two quantities as they arrive.
 *
 * `quantity()` in `resources/js/lib/money.js` draws the same figures, and
 * `lineTotal()` there prices a line exactly as `lineTotal()` here does.
 */
class Quantity
{
    /** Decimals a quantity may carry. */
    public const PLACES = 3;

    /** Thousandths in one whole unit. */
    public const SCALE = 1000;

    /**
     * The largest quantity a column holds: DECIMAL(15,3).
     *
     * Validation keeps far below this, so the thousandths stay exact in a
     * 64-bit integer and in a float alike.
     */
    public const MAX = '999999999.999';

    /**
     * A quantity in whole thousandths.
     *
     * A string is read digit by digit rather than through a float, so
     * "123456789.125" lands on exactly 123456789125. A fourth decimal, if
     * one ever arrives, is rounded half up.
     */
    public static function toMilli(int|float|string|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value * self::SCALE;
        }

        if (is_float($value)) {
            return (int) round($value * self::SCALE);
        }

        $value = trim($value);

        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $parts)) {
            // "1e3" and the like: rare enough that a float is fine.
            return is_numeric($value) ? (int) round(((float) $value) * self::SCALE) : 0;
        }

        $sign = $parts[1] === '-' ? -1 : 1;
        $whole = (int) ($parts[2] === '' ? '0' : $parts[2]);
        $fraction = str_pad(substr($parts[3] ?? '', 0, self::PLACES + 1), self::PLACES + 1, '0');

        $milli = $whole * self::SCALE + intdiv((int) $fraction, 10);

        if ((int) $fraction % 10 >= 5) {
            $milli++;
        }

        return $sign * $milli;
    }

    /**
     * Thousandths written for a DECIMAL column: 2500 -> "2.500".
     *
     * A string, so the value reaches MySQL exactly as it was worked out.
     */
    public static function toDb(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, self::SCALE).'.'.str_pad((string) ($milli % self::SCALE), self::PLACES, '0', STR_PAD_LEFT);
    }

    /**
     * Thousandths as a plain number: 10000 -> 10, 2500 -> 2.5.
     *
     * A whole quantity comes back as an int, so a screen, a payload and a
     * test all still see 10, not "10.000" or 10.0.
     */
    public static function fromMilli(int $milli): int|float
    {
        return $milli % self::SCALE === 0
            ? intdiv($milli, self::SCALE)
            : $milli / self::SCALE;
    }

    /**
     * Any quantity, tidied the same way: "10.000" -> 10, "2.500" -> 2.5.
     *
     * Use it on everything read past a model's cast — a `SUM()`, a raw
     * query — since MySQL sends a DECIMAL as a string and SQLite as a float.
     */
    public static function normalize(int|float|string|null $value): int|float
    {
        return self::fromMilli(self::toMilli($value));
    }

    /**
     * One quantity less another, exactly: 0.3 - 0.1 is 0.2, not
     * 0.19999999999999998.
     */
    public static function diff(int|float|string|null $from, int|float|string|null $less): int|float
    {
        return self::fromMilli(self::toMilli($from) - self::toMilli($less));
    }

    /** True when the quantity has no fraction: 10, "10.000". */
    public static function isWhole(int|float|string|null $value): bool
    {
        return self::toMilli($value) % self::SCALE === 0;
    }

    /**
     * A quantity written for a person: 1234.5 -> "1,234.5", 10 -> "10".
     */
    public static function format(int|float|string|null $value): string
    {
        $milli = self::toMilli($value);
        $text = number_format(abs($milli) / self::SCALE, self::PLACES);
        $text = rtrim(rtrim($text, '0'), '.');

        return ($milli < 0 ? '-' : '').$text;
    }

    /**
     * Quantity times a price, to the centavo, rounded half up.
     *
     * 2.5 m at ₱18.35 is ₱45.875, which is ₱45.88. Worked in centavos and
     * thousandths, so it never lands on the wrong side of a half the way a
     * float product can. `lineTotal()` in `money.js` is the same sum.
     */
    /**
     * How many of the product's own unit a quantity of another unit is.
     *
     * 1.5 boxes of 12 is 18 pieces. Worked out digit by digit through
     * bcmath, because thousandths times thousandths can pass what a PHP
     * integer holds. Returns null when the answer needs more than three
     * decimals, which no quantity column can store.
     *
     * @return int|null In thousandths.
     */
    public static function times(int|float|string|null $quantity, int|float|string|null $factor): ?int
    {
        $product = bcmul(self::toDb(self::toMilli($quantity)), self::toDb(self::toMilli($factor)), 2 * self::PLACES);

        [$whole, $fraction] = array_pad(explode('.', $product), 2, '');

        if (rtrim(substr($fraction, self::PLACES), '0') !== '') {
            return null;
        }

        // Past the largest quantity a column holds, it is refused too.
        if (bccomp(ltrim($product, '-'), self::MAX, self::PLACES) > 0) {
            return null;
        }

        return self::toMilli($whole.'.'.substr($fraction, 0, self::PLACES));
    }

    public static function lineTotal(int|float|string|null $quantity, int|float|string|null $price): float
    {
        return self::cents(self::toMilli($quantity), $price) / 100;
    }

    /**
     * Thousandths times a price, in whole centavos, rounded half up.
     *
     * The ledger costs a batch with this: what was taken, at what that
     * batch cost.
     */
    public static function cents(int $milli, int|float|string|null $price): int
    {
        return self::settle(self::centavos($price) * $milli);
    }

    /** A price in whole centavos: "18.35" -> 1835. */
    public static function centavos(int|float|string|null $price): int
    {
        return (int) round(((float) ($price ?? 0)) * 100);
    }

    /**
     * Centavo-thousandths — centavos times thousandths — to whole centavos.
     *
     * Several batches added up first and rounded once, so a line costed
     * from three batches does not carry three roundings.
     */
    public static function settle(int|float $centavoMilli): int
    {
        if (! is_int($centavoMilli)) {
            // Past what 64 bits hold. Far beyond any real document.
            return (int) round($centavoMilli / self::SCALE);
        }

        $sign = $centavoMilli < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($centavoMilli) + self::SCALE, 2 * self::SCALE);
    }
}
