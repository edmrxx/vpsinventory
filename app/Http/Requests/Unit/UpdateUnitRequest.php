<?php

namespace App\Http\Requests\Unit;

use App\Support\MasterInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUnitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9.\/-]+$/',
                Rule::unique('units', 'code')->ignore($this->route('unit')),
            ],
            'name' => ['required', 'string', 'max:80'],
            'allows_decimals' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only hold letters, numbers, dots, slashes and dashes.',
        ];
    }

    /**
     * The switch cannot go off while a product of this unit holds a part.
     *
     * 0.5 pcs could then never be sold, returned or adjusted away, because
     * every form would ask for a whole number.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unit = $this->route('unit');

                if (! $unit?->allows_decimals || $this->boolean('allows_decimals')) {
                    return;
                }

                $holding = $unit->productsHoldingFractions();

                if ($holding > 0) {
                    $validator->errors()->add(
                        'allows_decimals',
                        "{$holding} ".str('product')->plural($holding).' of this unit still hold a quantity with decimals, '
                            .'in stock or in the reorder level. Bring them to a whole number first.'
                    );
                }

                $packed = $unit->productsPackedInParts();

                if ($packed > 0) {
                    $validator->errors()->add(
                        'allows_decimals',
                        "{$packed} ".str('product')->plural($packed).' of this unit '
                            .($packed === 1 ? 'is' : 'are')." also sold in another unit that holds part of a {$unit->code} "
                            .'(a roll of 75.5, say). Change those to whole numbers on the product first.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(MasterInput::clean($this->all(), [
            'code' => 'lower',
            'name' => 'trim',
        ]));

        $this->merge([
            'allows_decimals' => $this->boolean('allows_decimals'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
