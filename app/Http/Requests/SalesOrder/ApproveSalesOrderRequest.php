<?php

namespace App\Http\Requests\SalesOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** Approving an order: until when its stock is held. */
class ApproveSalesOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reserve_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.Carbon::today()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reserve_until.required' => 'Say until when the stock is held.',
            'reserve_until.after_or_equal' => 'The stock cannot be held until a day that has already passed.',
        ];
    }
}
