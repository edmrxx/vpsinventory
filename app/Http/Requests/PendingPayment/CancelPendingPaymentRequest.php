<?php

namespace App\Http\Requests\PendingPayment;

use Illuminate\Foundation\Http\FormRequest;

/** Cancelling a pending payment, which always says why. */
class CancelPendingPaymentRequest extends FormRequest
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
            'reason.required' => 'Say why this order is being cancelled.',
            'reason.min' => 'Say why this order is being cancelled, in a few words.',
        ];
    }
}
