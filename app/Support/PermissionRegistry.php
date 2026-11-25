<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Reads config/permissions.php.
 *
 * Everything that needs the permission list — the seeder, the sync command,
 * the role form — goes through here, so the config file stays the one source
 * of truth.
 */
class PermissionRegistry
{
    /**
     * Every permission name, flat.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return static::groups()
            ->flatMap(fn (array $group) => array_keys($group['permissions']))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The groups, shaped for the role form.
     *
     * @return list<array{key:string,label:string,description:string,permissions:list<array{name:string,label:string}>}>
     */
    public static function forForm(): array
    {
        return static::groups()
            ->map(fn (array $group, string $key) => [
                'key' => $key,
                'label' => $group['label'] ?? ucfirst($key),
                'description' => $group['description'] ?? '',
                'permissions' => collect($group['permissions'])
                    ->map(fn (string $label, string $name) => [
                        'name' => $name,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The label for one permission, or the name when it has none.
     */
    public static function label(string $permission): string
    {
        return static::groups()
            ->flatMap(fn (array $group) => $group['permissions'])
            ->get($permission, $permission);
    }

    /**
     * Permissions for a seeded role. '*' means every permission.
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        $granted = config("permissions.roles.$role", []);

        // `*` means every permission except the opt-in ones. Those say
        // where an account works rather than what it may do, so handing
        // them out with the rest would defeat the point of having them.
        return $granted === '*'
            ? array_values(array_diff(static::names(), static::optIn()))
            : (array) $granted;
    }

    /**
     * Permissions that `*` never grants.
     *
     * Declared by marking a group `opt_in` in `config/permissions.php`.
     * They still appear on the Roles screen and are ticked by hand.
     *
     * @return list<string>
     */
    public static function optIn(): array
    {
        $names = [];

        foreach (config('permissions.groups', []) as $group) {
            if ($group['opt_in'] ?? false) {
                $names = array_merge($names, array_keys($group['permissions'] ?? []));
            }
        }

        return $names;
    }

    /**
     * The roles the seeder creates.
     *
     * @return list<string>
     */
    public static function seededRoles(): array
    {
        return array_keys(config('permissions.roles', []));
    }

    /**
     * Is this role one the app needs to keep?
     */
    public static function isProtected(string $role): bool
    {
        return in_array($role, config('permissions.protected_roles', []), true);
    }

    /**
     * The role that always holds every permission.
     */
    public static function adminRole(): string
    {
        return config('permissions.admin_role', 'Admin');
    }

    /**
     * @return Collection<string, array{label?:string,description?:string,permissions:array<string,string>}>
     */
    protected static function groups(): Collection
    {
        return collect(config('permissions.groups', []));
    }
}
