<?php

namespace App\Http\Requests\StockCount;

use App\Models\StockCount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Starting a count (phase 32): where, and optionally one category.
 *
 * Whether another count is still open there is asked by
 * `StockCountDesk::start()` with the location row locked, not here: two
 * people pressing Start at once would both pass a check made before it.
 */
class StartCountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => [
                'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Pick the location being counted.',
            'location_id.exists' => 'Pick a location that is still active.',
            'category_id.exists' => 'Pick a category that still exists, or count everything.',
        ];
    }

    public function alreadySaved(): ?StockCount
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return StockCount::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
            'category_id' => $this->filled('category_id') ? $this->input('category_id') : null,
        ]);
    }
}
