<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SalePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales.view');
    }

    public function view(User $actor, Sale $sale): bool
    {
        return $actor->can('sales.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.create');
    }

    /**
     * What the goods cost, and so what the sale earned, is held back behind
     * its own permission. A margin is not something everyone who records a
     * sale needs to see.
     */
    public function viewCost(User $actor, ?Sale $sale = null): bool
    {
        return $actor->can('sales.view_cost');
    }

    /**
     * The summaries. They only add up documents the person can already
     * open one at a time, so this is its own small permission rather than
     * a stricter one.
     */
    public function viewReports(User $actor): bool
    {
        return $actor->can('reports.view');
    }

    /**
     * A posted sale is never edited or deleted. There is deliberately no
     * update() or delete() here: the routes do not exist either.
     */
    public function void(User $actor, Sale $sale): Response
    {
        if (! $actor->can('sales.void')) {
            return Response::deny('You may not void a sale.');
        }

        if (! $sale->isPosted()) {
            return Response::deny('This sale is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
