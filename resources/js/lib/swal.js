import Swal from 'sweetalert2'
import { router } from '@inertiajs/vue3'

/**
 * Global SweetAlert2 helpers.
 *
 * Every alert in the app goes through this file, so the wording, the colours
 * and the button order stay the same everywhere. The classes come from the
 * design tokens in resources/css/app.css.
 */

const token = (name, fallback) => {
    if (typeof window === 'undefined') return fallback
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(name)
        .trim()
    return value || fallback
}

const baseClasses = {
    popup: 'maf-swal-popup',
    title: 'maf-swal-title',
    htmlContainer: 'maf-swal-text',
    actions: 'maf-swal-actions',
    confirmButton: 'maf-swal-confirm',
    cancelButton: 'maf-swal-cancel',
    denyButton: 'maf-swal-deny',
}

const Dialog = Swal.mixin({
    buttonsStyling: true,
    reverseButtons: true,
    focusCancel: true,
    showClass: { popup: 'swal2-show' },
    customClass: baseClasses,
})

const Toaster = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true,
    customClass: { ...baseClasses, popup: 'maf-swal-toast' },
    didOpen: (el) => {
        el.addEventListener('mouseenter', Swal.stopTimer)
        el.addEventListener('mouseleave', Swal.resumeTimer)
    },
})

/* -------------------------------------------------------------------------- */
/* Toast                                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Short message in the top-right corner.
 *
 * @param {string} message
 * @param {'success'|'error'|'warning'|'info'|'question'} type
 * @param {object} options
 */
export function toast(message, type = 'success', options = {}) {
    return Toaster.fire({ icon: type, title: message, ...options })
}

export const toastSuccess = (message, options) => toast(message, 'success', options)
export const toastError = (message, options) =>
    toast(message, 'error', { timer: 4800, ...options })
export const toastWarning = (message, options) => toast(message, 'warning', options)
export const toastInfo = (message, options) => toast(message, 'info', options)

/* -------------------------------------------------------------------------- */
/* Confirm                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Plain yes/no question. Resolves to true when the user agrees.
 *
 * @returns {Promise<boolean>}
 */
export async function confirmAction({
    title = 'Are you sure?',
    text = '',
    confirmText = 'Yes, continue',
    cancelText = 'Cancel',
    icon = 'question',
    confirmColor = token('--color-brand-600', '#4f46e5'),
} = {}) {
    const result = await Dialog.fire({
        icon,
        title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        confirmButtonColor: confirmColor,
    })

    return result.isConfirmed
}

/**
 * Delete confirmation. Red button, and the name of the record in the text.
 *
 * @returns {Promise<boolean>}
 */
export function confirmDelete({
    title = 'Delete this record?',
    name = '',
    text = name
        ? `"${name}" will be removed. You cannot undo this.`
        : 'This record will be removed. You cannot undo this.',
    confirmText = 'Yes, delete it',
    cancelText = 'Keep it',
} = {}) {
    return confirmAction({
        icon: 'warning',
        title,
        text,
        confirmText,
        cancelText,
        confirmColor: token('--color-danger-500', '#dc2626'),
    })
}

/**
 * Ask first, then delete. Pass the URL to call.
 *
 * @param {string} url
 * @param {object} options  Dialog copy plus Inertia visit options.
 */
export async function confirmDeleteThen(url, options = {}) {
    const { onSuccess, successMessage = 'Deleted.', ...dialog } = options

    if (!(await confirmDelete(dialog))) return false

    router.delete(url, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (successMessage) toastSuccess(successMessage)
            onSuccess?.(page)
        },
    })

    return true
}

/* -------------------------------------------------------------------------- */
/* Logout                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * Logout confirmation, then the POST to /logout.
 *
 * @returns {Promise<boolean>}
 */
export async function confirmLogout() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Log out?',
        text: 'Your session will be closed on this device.',
        confirmText: 'Yes, log out',
        cancelText: 'Stay signed in',
    })

    if (!agreed) return false

    router.post('/logout', {}, { onFinish: () => Swal.close() })

    return true
}

/* -------------------------------------------------------------------------- */
/* Change at the till                                                         */
/* -------------------------------------------------------------------------- */

/**
 * The sale is in: say the change, large, because it is the figure the
 * cashier is about to count out of the drawer.
 *
 * @param {{ change: string, text?: string, printable?: boolean }} options  `change` already
 *   formatted. `printable` adds a Print slip button, answered as `isDenied`.
 */
export function showChange({ change, text = 'The sale was posted.', printable = false }) {
    return Dialog.fire({
        icon: 'success',
        title: `Change: ${change}`,
        text,
        confirmButtonText: 'Next customer',
        showCancelButton: false,
        // The slip, while the customer is still at the counter. Comes back
        // as `isDenied`; Next customer stays the button Enter presses.
        showDenyButton: printable,
        denyButtonText: 'Print slip',
        // Drawn like a Cancel button: red is kept for what cannot be undone.
        customClass: { ...baseClasses, denyButton: 'maf-swal-cancel' },
        focusCancel: false,
        focusConfirm: true,
    })
}

/* -------------------------------------------------------------------------- */
/* Validation errors                                                          */
/* -------------------------------------------------------------------------- */

/**
 * Show server validation errors in one alert.
 *
 * Field errors already appear under each input, so pass the whole error bag
 * and this only lists them when you ask for it. Use it for general errors
 * or for forms with no visible fields.
 *
 * @param {object|string[]|string} errors
 */
export function showValidationErrors(errors, { title = 'Please check the form', icon = 'error' } = {}) {
    const list = Array.isArray(errors)
        ? errors
        : typeof errors === 'string'
          ? [errors]
          : Object.values(errors ?? {}).flat()

    if (list.length === 0) return

    const html =
        list.length === 1
            ? `<p class="maf-swal-text">${escapeHtml(list[0])}</p>`
            : `<ul class="maf-swal-error-list">${list
                  .map((message) => `<li>${escapeHtml(message)}</li>`)
                  .join('')}</ul>`

    return Dialog.fire({
        icon,
        title,
        html,
        confirmButtonText: 'Got it',
        confirmButtonColor: token('--color-brand-600', '#4f46e5'),
    })
}

/* -------------------------------------------------------------------------- */
/* Session expired                                                            */
/* -------------------------------------------------------------------------- */

let sessionAlertOpen = false

/**
 * Tell the user the session is gone, then send them to the login page.
 * It never opens two times, even when several requests fail together.
 */
export async function sessionExpired({ redirectTo = '/login' } = {}) {
    if (sessionAlertOpen) return
    sessionAlertOpen = true

    await Dialog.fire({
        icon: 'warning',
        title: 'Session expired',
        text: 'You were away too long. Please log in again.',
        confirmButtonText: 'Go to login',
        confirmButtonColor: token('--color-brand-600', '#4f46e5'),
        showCancelButton: false,
        allowOutsideClick: false,
        allowEscapeKey: false,
    })

    sessionAlertOpen = false

    if (window.location.pathname !== redirectTo) {
        window.location.href = redirectTo
    }
}

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
}

/** The raw SweetAlert2 instance, for one-off alerts. */
export { Swal }

/** Everything in one object, exposed on the app as `$swal`. */
export default {
    toast,
    toastSuccess,
    toastError,
    toastWarning,
    toastInfo,
    confirmAction,
    confirmDelete,
    confirmDeleteThen,
    confirmLogout,
    showValidationErrors,
    sessionExpired,
    raw: Swal,
}
