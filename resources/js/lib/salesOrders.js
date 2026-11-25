import { router } from '@inertiajs/vue3'
import { Swal, toastError } from './swal'

/**
 * Approve a sales order — reserving its stock until a date — or cancel one,
 * releasing what it held (phase 29).
 *
 * Shared by the order's page and the dialog on the list, so both ask the
 * same question. The server decides again with the products locked.
 */

const swalClasses = {
    popup: 'maf-swal-popup',
    title: 'maf-swal-title',
    actions: 'maf-swal-actions',
    confirmButton: 'maf-swal-confirm',
    cancelButton: 'maf-swal-cancel',
}

function send(url, payload, { onDone, failure }) {
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? failure),
    })
}

/**
 * @param {{ id:number, reference:string, reserve_until_iso?:string }} order
 * @param {{ onDone?:Function, today?:string }} options
 */
export async function approveOrder(order, { onDone, today } = {}) {
    const start = order.reserve_until_iso && (!today || order.reserve_until_iso >= today) ? order.reserve_until_iso : today

    const { value: reserveUntil, isConfirmed } = await Swal.fire({
        icon: 'question',
        title: `Approve ${order.reference}?`,
        text: 'Its stock is set aside for this customer until the day below. Nobody else can buy it meanwhile.',
        input: 'date',
        inputValue: start ?? '',
        inputAttributes: today ? { min: today } : {},
        showCancelButton: true,
        confirmButtonText: 'Yes, reserve it',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => (!value ? 'Pick the last day the stock is held.' : undefined),
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    send(`/sales-orders/${order.id}/approve`, { reserve_until: reserveUntil }, {
        onDone,
        failure: 'The order was not approved.',
    })
}

/** @param {{ id:number, reference:string, status?:string }} order */
export async function cancelOrder(order, { onDone } = {}) {
    const releasing = order.status === 'approved'

    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: releasing ? `Release ${order.reference}?` : `Cancel ${order.reference}?`,
        text: releasing
            ? 'The stock it holds is free to sell again at once. Say why.'
            : 'It cannot be approved or sold afterwards. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: customer changed their mind',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: releasing ? 'Yes, release it' : 'Yes, cancel it',
        cancelButtonText: 'Keep it',
        confirmButtonColor: 'oklch(0.55 0.21 25)',
        inputValidator: (value) =>
            (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined,
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    send(`/sales-orders/${order.id}/cancel`, { reason }, {
        onDone,
        failure: 'The order was not cancelled.',
    })
}
