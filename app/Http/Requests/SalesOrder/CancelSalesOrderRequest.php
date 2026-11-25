<?php

namespace App\Http\Requests\SalesOrder;

use Illuminate\Foundation\Http\FormRequest;

/** Cancelling or releasing a sales order, which always says why. */
class CancelSalesOrderRequest extends FormRequest
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
            'reason.required' => 'Say why this order is being cancelled or released.',
            'reason.min' => 'Say why this order is being cancelled or released, in a few words.',
        ];
    }
}
