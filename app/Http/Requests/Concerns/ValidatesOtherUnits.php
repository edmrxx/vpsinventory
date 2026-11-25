<?php

namespace App\Http\Requests\Concerns;

use App\Models\Unit;
use App\Support\Quantity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The other units a product is bought and sold in, on the product form.
 *
 * Each names a unit, how many of the product's own unit it holds, and
 * optionally its own price. A box of 100 pieces holds a whole number of
 * pieces; a roll of 75.5 m may hold a part, because metres allow decimals.
 */
trait ValidatesOtherUnits
{
    /** How many other units one product may carry. */
    public const MAX_OTHER_UNITS = 10;

    /** The largest factor: a pallet of 100,000 pieces is already generous. */
    public const MAX_FACTOR = 1000000;

    /**
     * @param  list<int>  $keep  Units the product already uses, allowed even
     *                           if switched off since.
     * @return array<string, mixed>
     */
    protected function otherUnitRules(array $keep = []): array
    {
        return [
            'units' => ['array', 'max:'.self::MAX_OTHER_UNITS],
            'units.*.unit_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('units', 'id')->where(
                    fn ($q) => $keep === []
                        ? $q->where('is_active', true)
                        : $q->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep))
                ),
            ],
            'units.*.factor' => [
                'required', 'numeric', 'decimal:0,'.Quantity::PLACES, 'gt:1', 'max:'.self::MAX_FACTOR,
            ],
            'units.*.selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    protected function otherUnitMessages(): array
    {
        return [
            'units.max' => 'A product may carry at most '.self::MAX_OTHER_UNITS.' other units.',
            'units.*.unit_id.required' => 'Pick a unit.',
            'units.*.unit_id.distinct' => 'This unit is already on the list.',
            'units.*.unit_id.exists' => 'Pick a unit that is still active.',
            'units.*.factor.required' => 'Say how many it holds.',
            'units.*.factor.gt' => 'It must hold more than one of the product\'s own unit.',
            'units.*.factor.decimal' => 'At most '.Quantity::PLACES.' decimals.',
            'units.*.factor.max' => 'At most '.number_format(self::MAX_FACTOR).'.',
            'units.*.selling_price.min' => 'The price cannot be below zero.',
        ];
    }

    /**
     * An other unit cannot be the product's own, and a box of pieces holds a
     * whole number of them.
     */
    protected function refuseOddOtherUnits(Validator $validator): void
    {
        $rows = $this->input('units', []);

        if ($rows === [] || $validator->errors()->has('unit_id')) {
            return;
        }

        $own = (int) $this->input('unit_id');
        $wholeOnly = ! Unit::whereKey($own)->value('allows_decimals');
        $ownCode = Unit::whereKey($own)->value('code');

        foreach ($rows as $index => $row) {
            if ((int) ($row['unit_id'] ?? 0) === $own) {
                $validator->errors()->add(
                    "units.{$index}.unit_id",
                    "This is already the product's own unit ({$ownCode})."
                );
            }

            if ($wholeOnly
                && ! $validator->errors()->has("units.{$index}.factor")
                && ! Quantity::isWhole($row['factor'] ?? 0)) {
                $validator->errors()->add(
                    "units.{$index}.factor",
                    "{$ownCode} is counted in whole numbers, so this must hold a whole number of them."
                );
            }
        }
    }

    /**
     * A form posted with a photo goes as multipart, where an empty list is
     * simply left out. A missing list therefore means "no other units".
     */
    protected function prepareOtherUnits(): void
    {
        $rows = $this->input('units');

        $this->merge([
            'units' => is_array($rows) ? array_values($rows) : [],
        ]);
    }
}
