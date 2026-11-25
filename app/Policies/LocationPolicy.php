<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may change the store and warehouse list, and when (phases 31.1, 31.2).
 *
 * Since phase 31.2 stock sits at a location, so:
 *
 * - the **default** location — where every document posts — is never
 *   switched off or deleted;
 * - a location **holding stock** is not switched off: its stock would be
 *   stranded where no document could reach it;
 * - a location named on any **batch or movement** is never deleted, so the
 *   stock card keeps reading correctly. Switch it off instead.
 *
 * The list loads `holds_stock` and `has_history` with `Location::withUsage()`,
 * so a page of rows asks nothing extra.
 */
class LocationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('locations.view');
    }

    public function view(User $actor, Location $location): bool
    {
        return $actor->can('locations.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('locations.create');
    }

    public function update(User $actor, Location $location): bool
    {
        return $actor->can('locations.update');
    }

    public function delete(User $actor, Location $location): Response
    {
        if (! $actor->can('locations.delete')) {
            return Response::deny('You may not delete locations.');
        }

        if ($location->is_default) {
            return Response::deny('This is the default location, where every document posts. Make another location the default first.');
        }

        if ($location->hasHistory()) {
            return Response::deny('Stock has moved at this location, so its history needs it. Switch it off instead.');
        }

        return Response::allow();
    }

    /** Switching off: never the default, never one holding stock. Switching on: always. */
    public function updateStatus(User $actor, Location $location): Response
    {
        if (! $actor->can('locations.status')) {
            return Response::deny('You may not switch locations on or off.');
        }

        if (! $location->is_active) {
            return Response::allow();
        }

        return $this->refuseSwitchingOff($location) ?? Response::allow();
    }

    /**
     * Making another location the default (phase 31.2): it must be active,
     * and the current default must hold no stock, or the till would go on
     * offering stock that no longer sits where it sells from.
     *
     * The list passes `$current`, read once with its usage, so a page of
     * rows does not look the default up once per row.
     */
    public function makeDefault(User $actor, Location $location, ?Location $current = null): Response
    {
        if (! $actor->can('locations.update')) {
            return Response::deny('You may not change the default location.');
        }

        if ($location->is_default) {
            return Response::deny('This is already the default location.');
        }

        if (! $location->is_active) {
            return Response::deny('Switch this location on before making it the default.');
        }

        $current ??= Location::default();

        if ($current && $current->holdsStock()) {
            return Response::deny(
                "{$current->name} still holds stock, and every sale and delivery posts there. "
                    .'The default can change once stock can be moved between locations (phase 31.5). '
                    .'To use another name, rename this location instead.'
            );
        }

        return Response::allow();
    }

    /** Why a location may not be switched off, or null when it may. */
    public function refuseSwitchingOff(Location $location): ?Response
    {
        if ($location->is_default) {
            return Response::deny('This is the default location, where every document posts. It stays on.');
        }

        if ($location->holdsStock()) {
            return Response::deny('This location still holds stock. Move it out first, then switch it off.');
        }

        return null;
    }
}
