<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StockTransferPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('transfers.view');
    }

    public function view(User $actor, StockTransfer $transfer): bool
    {
        return $actor->can('transfers.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('transfers.create');
    }

    /**
     * Only while what it brought in is untouched; the ledger decides that,
     * not this.
     */
    public function void(User $actor, StockTransfer $transfer): Response
    {
        if (! $actor->can('transfers.void')) {
            return Response::deny('You may not void a transfer.');
        }

        if (! $transfer->isPosted()) {
            return Response::deny('This transfer is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
