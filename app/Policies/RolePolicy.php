<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->can('roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('roles.create');
    }

    /**
     * The admin role is locked. It always holds every permission, so letting
     * someone take permissions off it would lock everybody out.
     */
    public function update(User $actor, Role $role): Response
    {
        if (! $actor->can('roles.update')) {
            return Response::deny('You may not edit roles.');
        }

        if ($role->isAdmin()) {
            return Response::deny('The administrator role always holds every permission and cannot be edited.');
        }

        return Response::allow();
    }

    /**
     * A role the app needs, or a role still in use, cannot be deleted.
     */
    public function delete(User $actor, Role $role): Response
    {
        if (! $actor->can('roles.delete')) {
            return Response::deny('You may not delete roles.');
        }

        if ($role->isProtected()) {
            return Response::deny("The [{$role->name}] role is built in and cannot be deleted.");
        }

        // The list screen already counted the users with withCount(), so the
        // loaded figure is used when it is there. Querying here instead cost
        // one query per row.
        $inUse = $role->users_count ?? $role->users()->count();

        if ($inUse > 0) {
            return Response::deny(
                $inUse === 1
                    ? 'One user still has this role. Move that user first.'
                    : "{$inUse} users still have this role. Move them first."
            );
        }

        return Response::allow();
    }
}
