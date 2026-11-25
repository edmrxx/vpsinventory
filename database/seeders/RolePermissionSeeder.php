<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\PermissionRegistry;
use App\Services\AuditService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Create every permission in the registry, then the seeded roles.
     *
     * Running it again duplicates nothing, but it is not harmless: each
     * seeded role is synced back to exactly what `config/permissions.php`
     * grants it, so any permission an admin added by hand on the Roles
     * screen is dropped. To widen a role without losing those edits, tick
     * the boxes on the Roles screen instead.
     *
     * `permissions:sync` is the gentler command: it creates new permissions
     * and refreshes the Admin role only, leaving every other role alone.
     */
    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(fn () => $this->seed());
    }

    protected function seed(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionRegistry::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (PermissionRegistry::seededRoles() as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(PermissionRegistry::forRole($roleName));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
