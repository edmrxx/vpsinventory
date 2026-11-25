import { router } from '@inertiajs/vue3'
import { Swal, toastError } from './swal'

/**
 * Cancel a purchase order, asking why first.
 *
 * Shared by the order's page and the dialog on the list, so both ask the
 * same question. The server decides again under the row lock.
 *
 * @param {{ id:number, reference:string }} order
 */
export async function cancelOrder(order, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Cancel ${order.reference}?`,
        text: 'Tell the supplier too. A cancelled order cannot be opened again. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: supplier out of stock, ordered elsewhere',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        cancelButtonText: 'Keep it',
        confirmButtonColor: 'oklch(0.55 0.21 25)',
        inputValidator: (value) =>
            (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined,
        customClass: {
            popup: 'maf-swal-popup',
            title: 'maf-swal-title',
            actions: 'maf-swal-actions',
            confirmButton: 'maf-swal-confirm',
            cancelButton: 'maf-swal-cancel',
        },
    })

    if (!isConfirmed) return

    router.post(`/purchase-orders/${order.id}/cancel`, { reason }, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The order was not cancelled.'),
    })
}

/**
 * Close a partly received order short: the rest is not coming.
 *
 * @param {{ id:number, reference:string }} order
 */
export async function closeOrderShort(order, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Close ${order.reference} short?`,
        text: 'What arrived stays received. Nothing more can be received against it. Say why the rest is not coming.',
        input: 'text',
        inputPlaceholder: 'For example: supplier discontinued the item',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, close it short',
        cancelButtonText: 'Keep it open',
        inputValidator: (value) =>
            (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined,
        customClass: {
            popup: 'maf-swal-popup',
            title: 'maf-swal-title',
            actions: 'maf-swal-actions',
            confirmButton: 'maf-swal-confirm',
            cancelButton: 'maf-swal-cancel',
        },
    })

    if (!isConfirmed) return

    router.post(`/purchase-orders/${order.id}/close`, { reason }, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The order was not closed.'),
    })
}

const swalClasses = {
    popup: 'maf-swal-popup',
    title: 'maf-swal-title',
    actions: 'maf-swal-actions',
    confirmButton: 'maf-swal-confirm',
    cancelButton: 'maf-swal-cancel',
}

/**
 * Approve an order waiting for approval (phase 24.4): it may then be sent to
 * the supplier, printed clean, and received against.
 *
 * @param {{ id:number, reference:string, supplier?:{name:string} }} order
 */
export async function approveOrder(order, { onDone } = {}) {
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        title: `Approve ${order.reference}?`,
        text: `It may then be sent to ${order.supplier?.name ?? 'the supplier'}, and it prints with your name on the "Approved by" line.`,
        showCancelButton: true,
        confirmButtonText: 'Yes, approve it',
        cancelButtonText: 'Cancel',
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    router.post(`/purchase-orders/${order.id}/approve`, {}, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The order was not approved.'),
    })
}

/**
 * Reject an order waiting for approval, saying why. Final: the goods are
 * ordered again on a new order.
 *
 * @param {{ id:number, reference:string }} order
 */
export async function rejectOrder(order, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Reject ${order.reference}?`,
        text: 'It cannot be sent or approved after this. The person who placed it will see your reason.',
        input: 'text',
        inputPlaceholder: 'For example: the price is too high, ask another supplier',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, reject it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'oklch(0.55 0.21 25)',
        inputValidator: (value) =>
            (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined,
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    router.post(`/purchase-orders/${order.id}/reject`, { reason }, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The order was not rejected.'),
    })
}
