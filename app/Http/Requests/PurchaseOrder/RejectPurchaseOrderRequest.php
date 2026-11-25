<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/** Saying no to an order waiting for approval (phase 24.4), which always says why. */
class RejectPurchaseOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this order is being rejected.',
            'reason.min' => 'Say why this order is being rejected, in a few words.',
        ];
    }
}
