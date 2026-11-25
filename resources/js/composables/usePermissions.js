import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Permission checks for the front end.
 *
 * These only hide things on screen. The server checks every request as well,
 * so a hidden button is a courtesy, never the guard.
 */
export function usePermissions() {
    const page = usePage()

    const permissions = computed(() => page.props.auth?.user?.permissions ?? [])
    const roles = computed(() => page.props.auth?.user?.roles ?? [])

    /** True when the user holds the named permission. */
    const can = (permission) => {
        if (!permission) return true

        return permissions.value.includes(permission)
    }

    /** True when the user holds at least one of the named permissions. */
    const canAny = (...names) => names.flat().some((name) => can(name))

    /** True when the user holds every named permission. */
    const canAll = (...names) => names.flat().every((name) => can(name))

    /** True when the user has the named role. */
    const hasRole = (role) => roles.value.includes(role)

    return {
        permissions,
        roles,
        can,
        canAny,
        canAll,
        hasRole,
        /** The first role name, for display in the top bar. */
        primaryRole: computed(() => roles.value[0] ?? null),
    }
}
