<?php

namespace App\Http\Requests\Concerns;

use App\Models\Product;
use App\Support\Quantity;
use App\Support\UnitConversion;
use Illuminate\Validation\Validator;

/**
 * The rules every quantity on a document form shares.
 *
 * Up to three decimals, and decimals only where the product's unit allows
 * them: 2.5 m of wire is fine, 2.5 pieces is refused against the line that
 * asked for it. The ledger refuses the same thing again; this is where the
 * person at the screen is told which line to fix.
 */
trait ValidatesQuantities
{
    /**
     * @param  bool  $zeroAllowed  True on a return form, where a line left
     *                             at 0 is simply not being returned.
     * @return list<string>
     */
    protected function quantityRules(bool $zeroAllowed = false): array
    {
        return [
            'required', 'numeric', 'decimal:0,'.Quantity::PLACES,
            $zeroAllowed ? 'min:0' : 'gt:0',
            'max:'.Quantity::MAX,
        ];
    }

    /** @return array<string, string> */
    protected function quantityMessages(string $field = 'lines.*.quantity'): array
    {
        return [
            "{$field}.gt" => 'The quantity must be more than zero.',
            "{$field}.decimal" => 'A quantity may carry at most '.Quantity::PLACES.' decimals.',
            "{$field}.numeric" => 'The quantity must be a number.',
        ];
    }

    /**
     * Refuse a part of a product counted whole.
     *
     * Asks the database nothing when every quantity is whole, which is
     * nearly always.
     *
     * @param  array<int, int>  $productIds  Line index => the product it moves.
     */
    protected function refusePartsOfWholeUnits(Validator $validator, array $productIds): void
    {
        $lines = $this->input('lines', []);

        $withParts = array_filter(
            $productIds,
            fn ($id, $index) => ! Quantity::isWhole($lines[$index]['quantity'] ?? 0),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($withParts === []) {
            return;
        }

        $products = Product::query()
            ->with('unit:id,code,allows_decimals')
            ->whereIn('id', array_unique($withParts))
            ->get(['id', 'sku', 'unit_id'])
            ->keyBy('id');

        foreach ($withParts as $index => $productId) {
            $product = $products[$productId] ?? null;

            if (! $product || $product->unit?->allows_decimals) {
                continue;
            }

            $unit = $product->unit?->code ? " ({$product->unit->code})" : '';

            $validator->errors()->add(
                "lines.{$index}.quantity",
                "[{$product->sku}] is counted in whole numbers{$unit}, so the quantity cannot have decimals."
            );
        }
    }

    /**
     * Convert every line typed in another unit into the product's own unit,
     * and refuse the ones that do not convert.
     *
     * This replaces `refusePartsOfWholeUnits()` on a form that offers other
     * units: whether 2.5 may be typed now depends on the unit picked, and
     * 1.1 boxes of 12 is refused because it is 13.2 pieces.
     *
     * @return array<int, array{unit_id:?int, factor:int|float, unit_quantity:int|float, base:?int, error:?string, field:string}>
     */
    protected function convertLines(Validator $validator): array
    {
        $resolved = UnitConversion::resolve($this->input('lines', []));

        foreach ($resolved as $index => $line) {
            if ($line['error'] !== null) {
                $validator->errors()->add("lines.{$index}.{$line['field']}", $line['error']);
            }
        }

        return $resolved;
    }

    /**
     * Each line's product id, keyed by line index.
     *
     * @return array<int, int>
     */
    protected function productIdsByLine(): array
    {
        return collect($this->input('lines', []))
            ->map(fn ($line) => (int) ($line['product_id'] ?? 0))
            ->all();
    }
}
