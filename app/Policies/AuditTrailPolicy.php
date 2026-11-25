<?php

namespace App\Policies;

use App\Models\AuditTrail;
use App\Models\User;

/**
 * The audit trail is read-only on purpose.
 *
 * Nothing may create, change or remove a row through the app, so only the
 * two reading abilities are defined. Every other ability falls through and
 * is refused by Laravel.
 */
class AuditTrailPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('audit.view');
    }

    public function view(User $actor, AuditTrail $trail): bool
    {
        return $actor->can('audit.view');
    }
}
