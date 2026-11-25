<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\Concerns\ValidatesOtherUnits;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\MasterInput;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use ValidatesOtherUnits;

    /**
     * The category and unit are checked for existence only. A product whose
     * category was switched off after it was created must still be editable,
     * so the `after()` rule below refuses an inactive one only when it is
     * being changed.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'sku' => [
                'required', 'string', 'max:60',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('products', 'sku')->ignore($product),
            ],
            // What a scanner reads off the label (phase 30): any printable
            // characters but a space, one product per barcode.
            'barcode' => [
                'nullable', 'string', 'max:64',
                'regex:/^[\x21-\x7E]+$/',
                Rule::unique('products', 'barcode')->ignore($product),
            ],
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'preferred_supplier_id' => [
                'nullable', 'integer',
                // A supplier switched off later stays on the product; the
                // after() rule refuses only a move onto an inactive one.
                Rule::exists('suppliers', 'id'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            // A picture, not a document. SVG is deliberately absent: it is
            // XML and can carry script, and it would be served from this
            // system's own domain.
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // Ticked to take the existing picture off without putting a
            // new one in its place.
            'remove_photo' => ['sometimes', 'boolean'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'reorder_level' => ['required', 'numeric', 'decimal:0,'.Quantity::PLACES, 'min:0', 'max:'.Quantity::MAX],
            'is_active' => ['required', 'boolean'],
        ] + $this->otherUnitRules($product->otherUnits()->pluck('unit_id')->map(fn ($id) => (int) $id)->all());
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
            'photo.image' => 'The photo must be a picture file.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP. SVG is not allowed.',
            'photo.max' => 'The photo must be 2 MB or smaller.',
            'reorder_level.decimal' => 'The reorder level may carry at most '.Quantity::PLACES.' decimals.',
        ] + $this->otherUnitMessages();
    }

    /**
     * Moving a product into a category or unit that is switched off is
     * refused. Leaving it where it already sits is allowed.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $product = $this->route('product');

                $this->refuseInactiveMove(
                    $validator,
                    'category_id',
                    Category::class,
                    (int) $product->category_id,
                    'Pick a category that is still active.'
                );

                $this->refuseInactiveMove(
                    $validator,
                    'unit_id',
                    Unit::class,
                    (int) $product->unit_id,
                    'Pick a unit that is still active.'
                );

                $this->refuseInactiveMove(
                    $validator,
                    'preferred_supplier_id',
                    Supplier::class,
                    (int) $product->preferred_supplier_id,
                    'Pick a supplier that is still active.'
                );

                $this->refusePartsOnAWholeUnit($validator);
                $this->refuseOddOtherUnits($validator);
            },
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function refuseInactiveMove(
        Validator $validator,
        string $field,
        string $model,
        int $current,
        string $message,
    ): void {
        $chosen = (int) $this->input($field);

        if ($chosen === 0 || $chosen === $current || $validator->errors()->has($field)) {
            return;
        }

        if ($model::whereKey($chosen)->where('is_active', true)->doesntExist()) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * Decimals need a unit that allows them.
     *
     * That covers the reorder level typed in, and the stock already on the
     * shelf: moving a product holding 2.5 onto pcs would leave half a piece
     * that no form could ever sell or adjust away.
     */
    protected function refusePartsOnAWholeUnit(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['unit_id', 'reorder_level'])) {
            return;
        }

        $product = $this->route('product');
        $partLevel = ! Quantity::isWhole($this->input('reorder_level'));
        $partStock = ! Quantity::isWhole($product->on_hand);

        if (! $partLevel && ! $partStock) {
            return;
        }

        if (Unit::whereKey($this->integer('unit_id'))->where('allows_decimals', true)->exists()) {
            return;
        }

        if ($partStock) {
            $onHand = Quantity::format($product->on_hand);

            $validator->errors()->add(
                'unit_id',
                "This product holds {$onHand} in stock, and this unit is counted in whole numbers. "
                    .'Pick a unit that allows decimals, or bring the stock to a whole number first.'
            );

            return;
        }

        $validator->errors()->add(
            'reorder_level',
            'This unit is counted in whole numbers, so the reorder level cannot have decimals.'
        );
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
