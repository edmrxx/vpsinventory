<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a count sheet (phase 32).
 *
 * Counting is ordinary work: Staff start a count, enter figures and hand it
 * in. Approving posts adjustments — a decrease among them, the quietest way
 * to make a loss disappear — so it stays with whoever holds
 * `stock_counts.approve` (Admin). Approving one's own is allowed and said in
 * the trail, as with sales orders.
 *
 * No query here: a list screen asks this for every row.
 */
class StockCountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('stock_counts.view');
    }

    public function view(User $actor, StockCount $count): bool
    {
        return $actor->can('stock_counts.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('stock_counts.create');
    }

    /** Enter counted figures: only while counting. */
    public function count(User $actor, StockCount $count): Response
    {
        if (! $actor->can('stock_counts.create')) {
            return Response::deny('You may not enter counted figures.');
        }

        if (! $count->isCounting()) {
            return Response::deny('This count has been handed in, so its figures can no longer be changed.');
        }

        return Response::allow();
    }

    public function submit(User $actor, StockCount $count): Response
    {
        if (! $actor->can('stock_counts.create')) {
            return Response::deny('You may not hand in a count.');
        }

        if (! $count->isCounting()) {
            return Response::deny('This count is not being counted, so there is nothing to hand in.');
        }

        return Response::allow();
    }

    public function approve(User $actor, StockCount $count): Response
    {
        if (! $actor->can('stock_counts.approve')) {
            return Response::deny('You may not approve a count.');
        }

        if (! $count->isSubmitted()) {
            return Response::deny('This count is not waiting for approval.');
        }

        return Response::allow();
    }

    /** Send it back for a recount: the approver's other answer. */
    public function sendBack(User $actor, StockCount $count): Response
    {
        return $this->approve($actor, $count);
    }

    /**
     * Give it up while open: the approver any count, a counter only one
     * they started.
     */
    public function cancel(User $actor, StockCount $count): Response
    {
        if (! $count->isOpen()) {
            return Response::deny('This count is finished, so there is nothing to cancel.');
        }

        if ($actor->can('stock_counts.approve')) {
            return Response::allow();
        }

        if ($actor->can('stock_counts.create') && (int) $count->started_by === (int) $actor->id) {
            return Response::allow();
        }

        return Response::deny('You may cancel only a count you started.');
    }
}
