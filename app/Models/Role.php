<?php

namespace App\Models;

use App\Concerns\Auditable;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The app's own Role model.
 *
 * It only exists so Laravel finds App\Policies\RolePolicy by name, and so
 * later phases have a place for role helpers.
 */
class Role extends SpatieRole
{
    use Auditable;

    /**
     * Permissions are stored once, under the web guard, and the API reads
     * the same set. This has to be pinned, because the auth:sanctum
     * middleware makes sanctum the default guard for the request, and the
     * sanctum guard names no user provider of its own.
     */
    protected $guard_name = 'web';

    /**
     * Is this a role the app needs to keep working?
     */
    public function isProtected(): bool
    {
        return \App\Support\PermissionRegistry::isProtected($this->name);
    }

    /**
     * Is this the role that holds every permission?
     */
    public function isAdmin(): bool
    {
        return $this->name === \App\Support\PermissionRegistry::adminRole();
    }
}
