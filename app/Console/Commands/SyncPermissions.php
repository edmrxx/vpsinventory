<?php

namespace App\Console\Commands;

use App\Support\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--prune : Also remove permissions that are no longer in the registry}';

    protected $description = 'Create the permissions listed in config/permissions.php and top up the seeded roles';

    public function handle(): int
    {
        $names = PermissionRegistry::names();

        if ($names === []) {
            $this->components->warn('The permission registry is empty. Nothing to do.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($names) {
            $existing = Permission::whereIn('name', $names)->pluck('name')->all();
            $missing = array_values(array_diff($names, $existing));

            foreach ($missing as $name) {
                Permission::create(['name' => $name, 'guard_name' => 'web']);
            }

            $this->components->info(
                $missing === []
                    ? 'All '.count($names).' permissions were already in place.'
                    : 'Added '.count($missing).' permission(s): '.implode(', ', $missing)
            );

            if ($this->option('prune')) {
                $stale = Permission::whereNotIn('name', $names)->pluck('name')->all();

                if ($stale !== []) {
                    Permission::whereIn('name', $stale)->delete();
                    $this->components->warn('Removed '.count($stale).' stale permission(s): '.implode(', ', $stale));
                }
            }

            // The admin role holds everything the wildcard grants,
            // including the permissions added a moment ago — but not the
            // opt-in ones. Those say where an account works rather than
            // what it may do, and handing them to Admin with the rest
            // would defeat the only thing they are for. `forRole()` is
            // the one place that rule lives, so this asks it rather than
            // repeating it.
            $roleModel = app(config('permission.models.role'));
            $adminName = PermissionRegistry::adminRole();

            $admin = $roleModel::where('name', $adminName)->first();

            if ($admin) {
                $forAdmin = PermissionRegistry::forRole($adminName);

                $admin->syncPermissions($forAdmin);

                $withheld = count($names) - count($forAdmin);

                $this->components->info(
                    "Role [{$admin->name}] now holds every permission"
                        .($withheld > 0 ? ", except {$withheld} opt-in." : '.')
                );
            }

            // Every other seeded role is topped up, never trimmed. A new
            // module would otherwise leave Staff behind on every phase,
            // while a sync that replaced the set would throw away whatever
            // an admin granted by hand on the Roles screen.
            foreach (PermissionRegistry::seededRoles() as $roleName) {
                if ($roleName === $adminName) {
                    continue;
                }

                $role = $roleModel::where('name', $roleName)->first();

                if (! $role) {
                    continue;
                }

                $held = $role->permissions->pluck('name')->all();
                $missing = array_values(array_intersect(
                    array_diff(PermissionRegistry::forRole($roleName), $held),
                    $names
                ));

                if ($missing === []) {
                    $this->components->info("Role [{$roleName}] was already up to date.");

                    continue;
                }

                $role->givePermissionTo($missing);

                $this->components->info(
                    "Role [{$roleName}] gained ".count($missing).' permission(s): '
                        .implode(', ', $missing).'.'
                );
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }
}
