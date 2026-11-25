<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Collection;

/**
 * Turns a line typed in another unit into the product's own unit.
 *
 * 2 boxes of 100 is 200 pieces. Stock, batches, costs, returns and reports
 * all count in the product's own unit, so this is the one place a box
 * becomes pieces. The form request asks it so it can put an error against
 * the line; the poster asks it again inside the transaction, and is the
 * one that decides.
 *
 * The factor comes from the product's saved units, never from the form.
 */
final class UnitConversion
{
    /** What a form or poster eager-loads to call `options()` with no query per product. */
    public const WITH = ['unit:id,code,allows_decimals', 'otherUnits.unit:id,code,allows_decimals'];

    /**
     * Each line, in the product's own unit.
     *
     * A line with no `unit_id`, or naming the product's own unit, moves
     * exactly what was typed. `unit_id` comes back null for it, which is
     * how a document line records "the product's own unit".
     *
     * `base` is in thousandths, or null when the line cannot be converted.
     * `field` names the box `error` belongs to.
     *
     * @param  array<int, array{product_id?:mixed, unit_id?:mixed, quantity?:mixed}>  $lines
     * @return array<int, array{unit_id:?int, factor:int|float, unit_quantity:int|float, base:?int, error:?string, field:string}>
     */
    public static function resolve(array $lines): array
    {
        $productIds = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();

        /** @var Collection<int, Product> $products */
        $products = $productIds->isEmpty()
            ? collect()
            : Product::query()
                ->with(self::WITH)
                ->whereIn('id', $productIds)
                ->get(['id', 'sku', 'unit_id'])
                ->keyBy('id');

        $resolved = [];

        foreach ($lines as $index => $line) {
            $resolved[$index] = self::one($products[(int) ($line['product_id'] ?? 0)] ?? null, $line);
        }

        return $resolved;
    }

    /**
     * The units a form may pick for one product: its own first, then the
     * others, smallest first. Each carries its price, and its cost when
     * asked for.
     *
     * The product must come with `WITH` loaded.
     *
     * @return list<array{value:int, label:string, factor:int|float, price:string, decimals:bool, cost?:string}>
     */
    public static function options(Product $product, bool $withCost = false): array
    {
        $own = [
            'value' => (int) $product->unit_id,
            'label' => (string) $product->unit?->code,
            'factor' => 1,
            'price' => number_format((float) $product->selling_price, 2, '.', ''),
            'decimals' => (bool) $product->unit?->allows_decimals,
        ];

        if ($withCost) {
            $own['cost'] = number_format((float) $product->cost, 2, '.', '');
        }

        $others = $product->otherUnits->map(function (ProductUnit $other) use ($product, $withCost) {
            $option = [
                'value' => (int) $other->unit_id,
                'label' => (string) $other->unit?->code,
                'factor' => $other->factor,
                'price' => $other->priceFrom($product->selling_price),
                'decimals' => (bool) $other->unit?->allows_decimals,
            ];

            if ($withCost) {
                $option['cost'] = $other->costFrom($product->cost);
            }

            return $option;
        });

        return [$own, ...$others->all()];
    }

    /**
     * "2 box (200 pcs)" for a converted line, "200 pcs" for one typed in the
     * product's own unit.
     */
    public static function describe(
        int|float|string|null $unitQuantity,
        ?string $unitCode,
        int|float|string|null $quantity,
        ?string $ownCode,
        int|float|string|null $factor = 1,
    ): string {
        $own = trim(Quantity::format($quantity).' '.($ownCode ?? ''));

        if (Quantity::toMilli($factor) === Quantity::SCALE || $unitCode === null) {
            return $own;
        }

        return trim(Quantity::format($unitQuantity).' '.$unitCode)." ({$own})";
    }

    /**
     * @param  array{product_id?:mixed, unit_id?:mixed, quantity?:mixed}  $line
     * @return array{unit_id:?int, factor:int|float, unit_quantity:int|float, base:?int, error:?string, field:string}
     */
    protected static function one(?Product $product, array $line): array
    {
        $typed = $line['quantity'] ?? 0;
        $result = [
            'unit_id' => null,
            'factor' => 1,
            'unit_quantity' => Quantity::normalize($typed),
            'base' => null,
            'error' => null,
            'field' => 'quantity',
        ];

        // An unknown product is the product rule's to report.
        if (! $product) {
            return $result;
        }

        $ownCode = $product->unit?->code;
        $chosen = (int) ($line['unit_id'] ?? 0);

        if ($chosen === 0 || $chosen === (int) $product->unit_id) {
            if (! Quantity::isWhole($typed) && ! $product->unit?->allows_decimals) {
                return [...$result, 'error' => self::wholeOnly($product->sku, $ownCode)];
            }

            return [...$result, 'base' => Quantity::toMilli($typed)];
        }

        $other = $product->otherUnits->firstWhere('unit_id', $chosen);

        if (! $other) {
            $offered = collect([$ownCode])
                ->merge($product->otherUnits->map(fn (ProductUnit $u) => $u->unit?->code))
                ->filter()
                ->implode(', ');

            return [
                ...$result,
                'error' => "[{$product->sku}] is not sold in that unit. Pick one of: {$offered}.",
                'field' => 'unit_id',
            ];
        }

        $code = $other->unit?->code;
        $result = [...$result, 'unit_id' => (int) $other->unit_id, 'factor' => $other->factor];

        if (! Quantity::isWhole($typed) && ! $other->unit?->allows_decimals) {
            return [...$result, 'error' => self::wholeOnly($product->sku, $code)];
        }

        $base = Quantity::times($typed, $other->factor);
        $amount = Quantity::format($typed)." {$code} of [{$product->sku}]";

        if ($base === null) {
            return [
                ...$result,
                'error' => "{$amount} does not come to a quantity of {$ownCode} the system can hold "
                    .'(at most '.Quantity::PLACES.' decimals).',
            ];
        }

        if ($base % Quantity::SCALE !== 0 && ! $product->unit?->allows_decimals) {
            return [
                ...$result,
                'error' => "{$amount} is ".Quantity::format(Quantity::fromMilli($base))." {$ownCode}, "
                    ."and {$ownCode} is counted in whole numbers.",
            ];
        }

        return [...$result, 'base' => $base];
    }

    protected static function wholeOnly(string $sku, ?string $code): string
    {
        $unit = $code ? " ({$code})" : '';

        return "[{$sku}] is counted in whole numbers{$unit}, so the quantity cannot have decimals.";
    }
}
