<?php

namespace App\Policies;

use App\Models\PendingPayment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may send, read, cancel and pay a pending payment (CR-2).
 *
 * At the kiosk it comes with the kiosk itself (the user's GO! of 24
 * November 2026): **anyone who may sign in there (`kiosk.use`) may send
 * one**, with no tick of its own, so every kiosk role — made now or later —
 * has *Pending payment* beside *Take payment*. **Anyone who may record a
 * sale may take its money, and cancel anybody's**; the sender may always
 * cancel their own. A kiosk-only tablet is a role with `kiosk.use` and
 * without `sales.create`: it sends, and never takes money.
 * `pending_payments.view` opens the back-office list.
 *
 * No update() or delete(). No query in here: it runs once per row.
 */
class PendingPaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('pending_payments.view');
    }

    public function view(User $actor, PendingPayment $pending): bool
    {
        return $actor->can('pending_payments.view')
            || $actor->can('sales.create')
            || (int) $pending->taken_by === (int) $actor->id;
    }

    /** Anyone who may use the kiosk may send the counter to the cashier. */
    public function create(User $actor): bool
    {
        return $actor->can('kiosk.use');
    }

    public function cancel(User $actor, PendingPayment $pending): Response
    {
        $own = (int) $pending->taken_by === (int) $actor->id;

        if (! $own && ! $actor->can('sales.create')) {
            return Response::deny('You may cancel only the orders you sent.');
        }

        if (! $pending->isPending()) {
            return Response::deny('This order is already '.strtolower($pending->standing()['label']).'.');
        }

        return Response::allow();
    }

    /** Take the money: still waiting, and this person may sell. */
    public function pay(User $actor, PendingPayment $pending): bool
    {
        return $pending->isWaiting() && $actor->can('sales.create');
    }
}
