<?php

namespace App\Http\Requests\PurchaseRequest;

use App\Models\PurchaseRequest;
use App\Support\QuoteAbstract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The award (phase 24.2): which supplier's price wins each item, and why
 * when it is not the lowest. Weighed by `QuoteAbstract::weighAward()`, which
 * `CanvassDesk` asks again with the request row locked.
 */
class AwardPurchaseRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'choices' => ['required', 'array'],
            'choices.*' => ['nullable', 'integer'],
            'reasons' => ['nullable', 'array'],
            'reasons.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['choices.required' => 'Choose a supplier for each item.'];
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

                /** @var PurchaseRequest $request */
                $request = $this->route('purchaseRequest');

                foreach (QuoteAbstract::weighAward(QuoteAbstract::of($request), $this->input('choices', []), $this->input('reasons', []) ?? []) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
