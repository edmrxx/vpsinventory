<?php

namespace App\Http\Requests\StockCount;

use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What the counters found (phase 32): a line id and a figure for each line
 * changed since the last save. An empty figure clears one entered by
 * mistake — the line is then not counted, which is not the same as zero.
 *
 * Decimals only where the product's unit allows them, the same rule every
 * quantity follows.
 */
class RecordCountsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'counts' => ['required', 'array', 'min:1', 'max:5000'],
            'counts.*.id' => ['required', 'integer', 'distinct'],
            'counts.*.counted_quantity' => [
                'nullable', 'numeric', 'decimal:0,'.Quantity::PLACES, 'min:0', 'max:'.Quantity::MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'counts.required' => 'Nothing was entered.',
            'counts.*.counted_quantity.min' => 'A counted figure cannot be below zero.',
            'counts.*.counted_quantity.numeric' => 'The counted figure must be a number.',
            'counts.*.counted_quantity.decimal' => 'A quantity may carry at most '.Quantity::PLACES.' decimals.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var StockCount $count */
                $count = $this->route('stock_count');
                $sent = $this->input('counts', []);

                $lines = StockCountLine::query()
                    ->where('stock_count_id', $count->id)
                    ->whereIn('id', array_map(fn ($c) => (int) $c['id'], $sent))
                    ->with('product:id,sku,unit_id', 'product.unit:id,code,allows_decimals')
                    ->get(['id', 'product_id'])
                    ->keyBy('id');

                foreach ($sent as $index => $figure) {
                    $line = $lines[(int) $figure['id']] ?? null;

                    if (! $line) {
                        $validator->errors()->add("counts.{$index}.id", 'This line is not on the count sheet. Reload the page.');

                        continue;
                    }

                    $counted = $figure['counted_quantity'] ?? null;
                    $unit = $line->product?->unit;

                    if ($counted !== null && $counted !== '' && ! Quantity::isWhole($counted) && ! $unit?->allows_decimals) {
                        $code = $unit?->code ? " ({$unit->code})" : '';
                        $validator->errors()->add(
                            "counts.{$index}.counted_quantity",
                            "[{$line->product?->sku}] is counted in whole numbers{$code}, so the figure cannot have decimals."
                        );
                    }
                }
            },
        ];
    }

    /**
     * Line id => the figure, or null for one cleared.
     *
     * @return array<int, string|null>
     */
    public function figures(): array
    {
        $out = [];

        foreach ($this->validated()['counts'] as $figure) {
            $value = $figure['counted_quantity'] ?? null;
            $out[(int) $figure['id']] = $value === null || $value === '' ? null : (string) $value;
        }

        return $out;
    }
}
