import { router } from '@inertiajs/vue3'
import { Swal, toastError } from './swal'

/**
 * Approve, reject and close a purchase request.
 *
 * Shared by the request's page and the dialog on the list, so both ask the
 * same question in the same words. Each one asks first, then posts; the
 * server decides again under the row lock, and a refusal comes back as a
 * toast.
 */

const swalClasses = {
    popup: 'maf-swal-popup',
    title: 'maf-swal-title',
    actions: 'maf-swal-actions',
    confirmButton: 'maf-swal-confirm',
    cancelButton: 'maf-swal-cancel',
}

const needsWords = (value) =>
    (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined

function send(url, payload, { onDone, failure }) {
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? failure),
    })
}

/**
 * @param {{ id:number, reference:string }} request
 * @param {{ onDone?:Function, own?:boolean }} options  `own`: the person approving raised it.
 */
export async function approveRequest(request, { onDone, own = false } = {}) {
    const { value: note, isConfirmed } = await Swal.fire({
        icon: 'question',
        title: `Approve ${request.reference}?`,
        text: own
            ? 'You raised this request yourself. The audit trail will record that you approved your own request.'
            : 'It can then be ordered. Add a note for the buyer if you like.',
        input: 'text',
        inputPlaceholder: 'Optional, for example: buy the cheaper brand',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, approve it',
        cancelButtonText: 'Cancel',
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    send(`/purchase-requests/${request.id}/approve`, { note: (note ?? '').trim() || null }, {
        onDone,
        failure: 'The request was not approved.',
    })
}

/** @param {{ id:number, reference:string }} request */
export async function rejectRequest(request, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Reject ${request.reference}?`,
        text: 'The person who asked will see your reason.',
        input: 'text',
        inputPlaceholder: 'For example: we still have stock in the warehouse',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, reject it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'oklch(0.55 0.21 25)',
        inputValidator: needsWords,
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    send(`/purchase-requests/${request.id}/reject`, { reason }, {
        onDone,
        failure: 'The request was not rejected.',
    })
}

/** @param {{ id:number, reference:string }} request */
export async function closeRequest(request, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Close ${request.reference}?`,
        text: 'Nobody can approve or order it after this. Say why it is no longer needed.',
        input: 'text',
        inputPlaceholder: 'For example: raised twice, or no longer needed',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, close it',
        cancelButtonText: 'Cancel',
        inputValidator: needsWords,
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    send(`/purchase-requests/${request.id}/close`, { reason }, {
        onDone,
        failure: 'The request was not closed.',
    })
}
