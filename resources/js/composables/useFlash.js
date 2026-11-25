import { watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { sessionExpired, toast } from '../lib/swal'

/**
 * Turn flash data from the server into alerts.
 *
 * Call it once inside a layout. Both layouts use it, so a flash message shows
 * whether the user is logged in or still on the login page.
 */
export function useFlash() {
    const page = usePage()

    watch(
        () => page.props.flash,
        (flash) => {
            if (!flash) return

            if (flash.sessionExpired) {
                sessionExpired()
                return
            }

            if (flash.success) toast(flash.success, 'success')
            if (flash.error) toast(flash.error, 'error', { timer: 4800 })
            if (flash.warning) toast(flash.warning, 'warning')
            if (flash.info) toast(flash.info, 'info')
        },
        { immediate: true, deep: true }
    )
}
