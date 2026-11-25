<?php

namespace App\Policies;

use App\Models\InventoryMovement;
use App\Models\User;

/**
 * Read-only, on purpose. The ledger is append-only: a row is never changed
 * or removed, only answered by another movement.
 */
class InventoryMovementPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('movements.view');
    }

    public function view(User $actor, InventoryMovement $movement): bool
    {
        return $actor->can('movements.view');
    }
}
