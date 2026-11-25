<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may read, make and cancel a quotation (phase 28).
 *
 * A quotation moves no stock and owes nothing, so Staff hold all three —
 * quoting is ordinary selling work. Turning one into a sale needs
 * `sales.create` as any sale does. No update() or delete(): the routes do
 * not exist either. No query in here: it runs once per row.
 */
class QuotationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('quotations.view');
    }

    public function view(User $actor, Quotation $quotation): bool
    {
        return $actor->can('quotations.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('quotations.create');
    }

    public function cancel(User $actor, Quotation $quotation): Response
    {
        if (! $actor->can('quotations.cancel')) {
            return Response::deny('You may not cancel quotations.');
        }

        if (! $quotation->isOpen()) {
            return Response::deny('This quotation is already '.strtolower($quotation->standing()['label']).'.');
        }

        return Response::allow();
    }

    /** Turn it into a sale: still live, and this person may sell. */
    public function sell(User $actor, Quotation $quotation): bool
    {
        return $quotation->isLive() && $actor->can('sales.create');
    }
}
