<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PaymentMethodPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('payment_methods.view');
    }

    public function view(User $actor, PaymentMethod $method): bool
    {
        return $actor->can('payment_methods.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('payment_methods.create');
    }

    public function update(User $actor, PaymentMethod $method): bool
    {
        return $actor->can('payment_methods.update');
    }

    /**
     * A method a sale was paid with stays, so that sale keeps saying how it
     * was paid. Switch it off instead. The cash method stays whatever.
     */
    public function delete(User $actor, PaymentMethod $method): Response
    {
        if (! $actor->can('payment_methods.delete')) {
            return Response::deny('You may not delete payment methods.');
        }

        if ($method->isCash()) {
            return Response::deny('The cash method stays: the till gives change from it.');
        }

        $used = $this->paymentCount($method);

        if ($used > 0) {
            return Response::deny(
                "{$used} ".($used === 1 ? 'sale was' : 'sales were')
                    .' paid with this method, so it stays. Switch it off instead.'
            );
        }

        // A customer paying an invoice keeps saying how it was paid too.
        $collected = $this->customerPaymentCount($method);

        if ($collected > 0) {
            return Response::deny(
                "{$collected} customer ".($collected === 1 ? 'payment was' : 'payments were')
                    .' made with this method, so it stays. Switch it off instead.'
            );
        }

        return Response::allow();
    }

    /** The cash method is never switched off. */
    public function updateStatus(User $actor, PaymentMethod $method): Response
    {
        if (! $actor->can('payment_methods.status')) {
            return Response::deny('You may not switch payment methods on or off.');
        }

        if ($method->isCash() && $method->is_active) {
            return Response::deny('The cash method cannot be switched off: the till gives change from it.');
        }

        return Response::allow();
    }

    /**
     * How many payments name this method.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks nothing extra.
     */
    protected function paymentCount(PaymentMethod $method): int
    {
        return (int) ($method->payments_count ?? $method->payments()->count());
    }

    protected function customerPaymentCount(PaymentMethod $method): int
    {
        return (int) ($method->customer_payments_count ?? $method->customerPayments()->count())
            + (int) ($method->supplier_payments_count ?? $method->supplierPayments()->count())
            + (int) ($method->purchases_count ?? $method->purchases()->count());
    }
}
