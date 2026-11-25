<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

/** Withdrawing a quotation, which always says why. */
class CancelQuotationRequest extends FormRequest
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
            'reason.required' => 'Say why this quotation is being cancelled.',
            'reason.min' => 'Say why this quotation is being cancelled, in a few words.',
        ];
    }
}
