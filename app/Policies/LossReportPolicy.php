<?php

namespace App\Policies;

use App\Models\LossReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what with a loss report (phase 33).
 *
 * Anybody who finds a loss reports it: Staff included. Writing it off is the
 * approver's (`losses.approve`, Admin), because a write-off is the quietest
 * way to make stock disappear. An approver may approve their own report; the
 * trail says so, as with counts and sales orders.
 *
 * No query here: a list screen asks this for every row.
 */
class LossReportPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('losses.view');
    }

    public function view(User $actor, LossReport $report): bool
    {
        return $actor->can('losses.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('losses.create');
    }

    public function approve(User $actor, LossReport $report): Response
    {
        if (! $actor->can('losses.approve')) {
            return Response::deny('You may not approve a loss.');
        }

        if (! $report->isPending()) {
            return Response::deny('This loss report has already been answered.');
        }

        return Response::allow();
    }

    public function reject(User $actor, LossReport $report): Response
    {
        return $this->approve($actor, $report);
    }

    /** Withdraw it: the reporter their own, an approver any — only while it waits. */
    public function cancel(User $actor, LossReport $report): Response
    {
        if (! $report->isPending()) {
            return Response::deny('This loss report has already been answered.');
        }

        if ($actor->can('losses.approve')) {
            return Response::allow();
        }

        if ($actor->can('losses.create') && $report->wasReportedBy($actor)) {
            return Response::allow();
        }

        return Response::deny('You may withdraw only a loss you reported.');
    }
}
