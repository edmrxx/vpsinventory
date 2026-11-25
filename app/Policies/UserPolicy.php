<?php

namespace App\Policies;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.update');
    }

    /**
     * Deleting a user must never leave the app without an administrator, and
     * nobody may delete their own account.
     */
    public function delete(User $actor, User $user): Response
    {
        if (! $actor->can('users.delete')) {
            return Response::deny('You may not delete users.');
        }

        if ($actor->is($user)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($this->isLastAdmin($user)) {
            return Response::deny('This is the last administrator. Give the role to someone else first.');
        }

        return Response::allow();
    }

    /**
     * Turning an account off is as strong as deleting it, so it follows the
     * same two rules.
     */
    public function updateStatus(User $actor, User $user): Response
    {
        if (! $actor->can('users.status')) {
            return Response::deny('You may not change the status of users.');
        }

        if ($actor->is($user)) {
            return Response::deny('You cannot change your own status.');
        }

        if ($user->is_active && $this->isLastAdmin($user)) {
            return Response::deny('This is the last active administrator. Give the role to someone else first.');
        }

        return Response::allow();
    }

    /**
     * The ids of every active administrator.
     *
     * Read once and kept, because the list screen asks about every row and
     * the answer cannot change inside one request. Without this, a page
     * holding ten administrators ran ten of these queries.
     *
     * @var list<int>|null
     */
    protected ?array $activeAdminIds = null;

    /**
     * Is this the only active user left holding the admin role?
     */
    protected function isLastAdmin(User $user): bool
    {
        $adminRole = PermissionRegistry::adminRole();

        if (! $user->hasRole($adminRole)) {
            return false;
        }

        $this->activeAdminIds ??= User::role($adminRole)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $others = array_diff($this->activeAdminIds, [(int) $user->getKey()]);

        return $others === [];
    }
}
