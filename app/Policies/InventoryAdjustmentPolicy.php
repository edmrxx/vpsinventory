<?php

namespace App\Policies;

use App\Models\InventoryAdjustment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InventoryAdjustmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('adjustments.view');
    }

    public function view(User $actor, InventoryAdjustment $adjustment): bool
    {
        return $actor->can('adjustments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('adjustments.create');
    }

    /**
     * An adjustment that brought stock in can only be voided while that
     * stock is untouched; the ledger decides that, not this.
     */
    public function void(User $actor, InventoryAdjustment $adjustment): Response
    {
        if (! $actor->can('adjustments.void')) {
            return Response::deny('You may not void an adjustment.');
        }

        if ($adjustment->status->value !== 'posted') {
            return Response::deny('This adjustment is not posted, so there is nothing to void.');
        }

        return Response::allow();
    }
}
