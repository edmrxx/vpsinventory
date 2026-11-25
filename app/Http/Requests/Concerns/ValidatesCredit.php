<?php

namespace App\Http\Requests\Concerns;

use App\Models\Customer;
use Illuminate\Validation\Validator;

/**
 * A customer's credit limit and payment terms, on either customer form.
 *
 * They need `customers.credit`, more than an ordinary edit: a limit decides
 * how much may leave the shop unpaid for. Without it the rules are simply
 * absent, so the two fields never reach `validated()` and whatever the
 * customer already has stays. Left out of the form, they stay too.
 */
trait ValidatesCredit
{
    protected function mayGrantCredit(): bool
    {
        return (bool) $this->user()?->can('customers.credit');
    }

    /**
     * @return array<string, mixed>
     */
    protected function creditRules(): array
    {
        if (! $this->mayGrantCredit()) {
            return [];
        }

        return [
            'credit_limit' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'payment_terms_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:365'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function creditMessages(): array
    {
        return [
            'credit_limit.min' => 'A credit limit cannot be below zero.',
            'credit_limit.decimal' => 'A credit limit has at most 2 decimals.',
            'payment_terms_days.integer' => 'Type the terms as a whole number of days.',
            'payment_terms_days.min' => 'Terms cannot be below zero.',
            'payment_terms_days.max' => 'Terms are at most 365 days.',
        ];
    }

    /** A box left empty means none, the same as zero. */
    protected function prepareCredit(): void
    {
        foreach (['credit_limit', 'payment_terms_days'] as $field) {
            if ($this->exists($field) && ! $this->filled($field)) {
                $this->merge([$field => 0]);
            }
        }
    }

    /**
     * Walk-in has no account to charge, and credit with no terms would be
     * due the day it was given.
     */
    protected function refuseUnusableCredit(Validator $validator, ?Customer $customer): void
    {
        if (! $this->mayGrantCredit()
            || $validator->errors()->hasAny(['credit_limit', 'payment_terms_days'])) {
            return;
        }

        $limit = (float) $this->input('credit_limit', $customer?->credit_limit ?? 0);
        $terms = (int) $this->input('payment_terms_days', $customer?->payment_terms_days ?? 0);

        if ($limit <= 0) {
            return;
        }

        if ($customer?->is_walk_in) {
            $validator->errors()->add('credit_limit', 'A walk-in sale is paid at the counter, so this customer cannot have credit.');

            return;
        }

        if ($terms < 1) {
            $validator->errors()->add('payment_terms_days', 'Type how many days this customer has to pay.');
        }
    }
}
