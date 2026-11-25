<?php

namespace App\Policies;

use App\Models\CustomerPayment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerPaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('customer_payments.view');
    }

    public function view(User $actor, CustomerPayment $payment): bool
    {
        return $actor->can('customer_payments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('customer_payments.create');
    }

    /**
     * A posted payment is never edited or deleted. There is deliberately no
     * update() or delete() here: the routes do not exist either. No query
     * here: it runs once per row on the list.
     */
    public function void(User $actor, CustomerPayment $payment): Response
    {
        if (! $actor->can('customer_payments.void')) {
            return Response::deny('You may not void a customer payment.');
        }

        if (! $payment->isPosted()) {
            return Response::deny('This payment is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
