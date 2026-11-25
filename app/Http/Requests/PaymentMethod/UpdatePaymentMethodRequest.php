<?php

namespace App\Http\Requests\PaymentMethod;

use App\Models\PaymentMethod;
use Illuminate\Validation\Validator;

class UpdatePaymentMethodRequest extends StorePaymentMethodRequest
{
    /**
     * Everything a new method is checked for, and one more: the cash method
     * stays cash, and stays on.
     *
     * The till gives change from it, and a sale posted with no payment named
     * is recorded against it. Renaming it is fine — "Cash on hand" — but
     * turning it into GCash or switching it off would leave the counter
     * with no drawer.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                /** @var PaymentMethod|null $method */
                $method = $this->route('payment_method');

                if (! $method?->isCash()) {
                    return;
                }

                if (! $validator->errors()->has('kind') && $this->input('kind') !== $method->kind->value) {
                    $validator->errors()->add(
                        'kind',
                        'This is the cash method. Change is given from it, so it stays cash.'
                    );
                }

                if (! $this->boolean('is_active')) {
                    $validator->errors()->add(
                        'is_active',
                        'The cash method cannot be switched off: the till gives change from it.'
                    );
                }
            },
        ];
    }
}
