<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PurchasePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('purchases.view');
    }

    public function view(User $actor, Purchase $purchase): bool
    {
        return $actor->can('purchases.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('purchases.create');
    }

    /**
     * A posted purchase is never edited or deleted. There is deliberately no
     * update() or delete() here: the routes do not exist either.
     */
    public function void(User $actor, Purchase $purchase): Response
    {
        if (! $actor->can('purchases.void')) {
            return Response::deny('You may not void a purchase.');
        }

        if (! $purchase->isPosted()) {
            return Response::deny('This purchase is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
