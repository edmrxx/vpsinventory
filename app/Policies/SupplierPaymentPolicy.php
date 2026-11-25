<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may read, record and void a supplier payment (phase 24).
 *
 * Paying suppliers is the owner's job, so Staff hold none of these; Admin
 * has them through `*`. No update() or delete(): the routes do not exist
 * either. No query in here: it runs once per row on the list.
 */
class SupplierPaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('supplier_payments.view');
    }

    public function view(User $actor, SupplierPayment $payment): bool
    {
        return $actor->can('supplier_payments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('supplier_payments.create');
    }

    public function void(User $actor, SupplierPayment $payment): Response
    {
        if (! $actor->can('supplier_payments.void')) {
            return Response::deny('You may not void a supplier payment.');
        }

        if (! $payment->isPosted()) {
            return Response::deny('This payment is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
