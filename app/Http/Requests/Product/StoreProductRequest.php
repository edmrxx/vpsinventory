<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ValidatesOtherUnits;
use App\Models\Unit;
use App\Support\MasterInput;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    use ValidatesOtherUnits;

    /**
     * Stock is missing from these rules on purpose. It is never typed in:
     * it moves only through a posted document.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => [
                'required', 'string', 'max:60',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('products', 'sku'),
            ],
            // What a scanner reads off the label (phase 30): any printable
            // characters but a space, one product per barcode.
            'barcode' => [
                'nullable', 'string', 'max:64',
                'regex:/^[\x21-\x7E]+$/',
                Rule::unique('products', 'barcode'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'preferred_supplier_id' => [
                'nullable', 'integer',
                Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            // A picture, not a document. SVG is deliberately absent: it is
            // XML and can carry script, and it would be served from this
            // system's own domain.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'category_id' => [
                'required', 'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
            'unit_id' => [
                'required', 'integer',
                Rule::exists('units', 'id')->where('is_active', true),
            ],
            'cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'reorder_level' => ['required', 'numeric', 'decimal:0,'.Quantity::PLACES, 'min:0', 'max:'.Quantity::MAX],
            'is_active' => ['required', 'boolean'],
        ] + $this->otherUnitRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.regex' => 'The SKU may only hold capital letters, numbers, dots, dashes and underscores.',
            'barcode.regex' => 'The barcode may not hold spaces or special characters.',
            'barcode.unique' => 'Another product already has this barcode.',
            'preferred_supplier_id.exists' => 'Pick a supplier that is still active.',
            'category_id.exists' => 'Pick a category that is still active.',
            'unit_id.exists' => 'Pick a unit that is still active.',
            'reorder_level.decimal' => 'The reorder level may carry at most '.Quantity::PLACES.' decimals.',
            'photo.image' => 'The photo must be a picture file.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP. SVG is not allowed.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
        ] + $this->otherUnitMessages();
    }

    /**
     * A reorder level of 2.5 needs a unit that allows decimals.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->refuseOddOtherUnits($validator);

                if ($validator->errors()->hasAny(['unit_id', 'reorder_level'])) {
                    return;
                }

                if (! Quantity::isWhole($this->input('reorder_level'))
                    && ! Unit::whereKey($this->integer('unit_id'))->where('allows_decimals', true)->exists()) {
                    $validator->errors()->add(
                        'reorder_level',
                        'This unit is counted in whole numbers, so the reorder level cannot have decimals.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'sku' => 'upper',
            // Capitals, as the SKU: MySQL compares text without case and
            // SQLite with it, so "abc1" and "ABC1" must be one barcode on both.
            'barcode' => 'upper',
            'name' => 'trim',
            'brand' => 'trim',
            'model' => 'trim',
            'description' => 'trim',
        ]));

        $this->merge(['is_active' => $this->boolean('is_active')]);

        $this->prepareOtherUnits();
    }
}
