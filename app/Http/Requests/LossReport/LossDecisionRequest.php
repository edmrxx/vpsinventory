<?php

namespace App\Http\Requests\LossReport;

use Illuminate\Foundation\Http\FormRequest;

/** Rejecting or withdrawing a loss report says why. */
class LossDecisionRequest extends FormRequest
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
            'reason.required' => 'Say why, in a few words.',
            'reason.min' => 'Say why, in a few words.',
        ];
    }
}
