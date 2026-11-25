import { router } from '@inertiajs/vue3'
import { Swal, confirmAction, toastError } from './swal'
import { count as quantity } from './money'

/**
 * Hand in, approve, send back or cancel a count sheet (phase 32).
 *
 * Shared by the count's page and the dialog on the list, so both ask the
 * same question. The server decides again with the count row locked.
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

async function askWhy({ title, text, placeholder, confirmText, danger = false }) {
    const { value, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title,
        text,
        input: 'text',
        inputPlaceholder: placeholder,
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Go back',
        ...(danger ? { confirmButtonColor: 'oklch(0.55 0.21 25)' } : {}),
        inputValidator: (v) => ((v ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined),
        customClass: swalClasses,
    })

    return isConfirmed ? value : null
}

/**
 * @param {{ id:number, reference:string }} stockCount
 * @param {{ counted:number, uncounted:number }} summary
 */
export async function submitCount(stockCount, summary, { onDone } = {}) {
    const agreed = await confirmAction({
        icon: 'question',
        title: `Hand in ${stockCount.reference}?`,
        text:
            `${quantity(summary.counted)} product(s) counted` +
            (summary.uncounted > 0 ? `, ${quantity(summary.uncounted)} not counted — those stay as the books have them.` : '.') +
            ' The figures cannot be changed afterwards unless the approver sends it back.',
        confirmText: 'Yes, hand it in',
    })

    if (!agreed) return

    send(`/stock-counts/${stockCount.id}/submit`, {}, { onDone, failure: 'The count was not handed in.' })
}

/**
 * @param {{ id:number, reference:string, location:string }} stockCount
 * @param {{ over:number, short:number, uncounted:number, gain_value:number, loss_value:number }} summary
 * @param {(value:number) => string} money
 */
export async function approveCount(stockCount, summary, money, { onDone } = {}) {
    const parts = []
    if (summary.over > 0) parts.push(`${quantity(summary.over)} product(s) over, about ${money(summary.gain_value)}`)
    if (summary.short > 0) parts.push(`${quantity(summary.short)} short, about ${money(summary.loss_value)}`)

    const agreed = await confirmAction({
        icon: 'question',
        title: `Approve ${stockCount.reference}?`,
        text:
            (parts.length
                ? `${parts.join('; ')}. The differences post today as adjustments at ${stockCount.location}.`
                : 'Everything counted matches the books, so nothing is posted.') +
            (summary.uncounted > 0 ? ` ${quantity(summary.uncounted)} not counted stay as they are.` : ''),
        confirmText: parts.length ? 'Yes, approve and post' : 'Yes, approve',
    })

    if (!agreed) return

    send(`/stock-counts/${stockCount.id}/approve`, {}, { onDone, failure: 'The count was not approved.' })
}

/** @param {{ id:number, reference:string }} stockCount */
export async function sendBackCount(stockCount, { onDone } = {}) {
    const reason = await askWhy({
        title: `Send ${stockCount.reference} back?`,
        text: 'The counters can change their figures again. What they entered stays. Say what to recount.',
        placeholder: 'For example: recount the paint aisle',
        confirmText: 'Yes, send it back',
    })

    if (reason === null) return

    send(`/stock-counts/${stockCount.id}/send-back`, { reason }, { onDone, failure: 'The count was not sent back.' })
}

/** @param {{ id:number, reference:string }} stockCount */
export async function cancelCount(stockCount, { onDone } = {}) {
    const reason = await askWhy({
        title: `Cancel ${stockCount.reference}?`,
        text: 'Nothing is posted, and the location is free for a new count. Say why.',
        placeholder: 'For example: started by mistake',
        confirmText: 'Yes, cancel it',
        danger: true,
    })

    if (reason === null) return

    send(`/stock-counts/${stockCount.id}/cancel`, { reason }, { onDone, failure: 'The count was not cancelled.' })
}
