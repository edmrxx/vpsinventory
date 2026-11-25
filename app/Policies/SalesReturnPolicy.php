<?php

namespace App\Policies;

use App\Models\SalesReturn;
use App\Models\User;

/**
 * A return is never edited, deleted or voided.
 *
 * Undoing an undo leaves a history nobody can follow. A return entered
 * wrongly is put right with another return, or with an adjustment.
 */
class SalesReturnPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('sales_returns.view');
    }

    public function view(User $actor, SalesReturn $return): bool
    {
        return $actor->can('sales_returns.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales_returns.create');
    }
}
