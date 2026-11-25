import { router } from '@inertiajs/vue3'
import { Swal, confirmDelete, toastError } from './swal'

/**
 * The canvass on a purchase request (phase 24.2), on screen.
 *
 * The server's `QuoteAbstract` decides what is lowest and whether an award
 * stands; these only draw the same answer while somebody chooses, and ask
 * before the steps that need asking.
 */

/**
 * The award the form starts from: the lowest price on every item somebody
 * quoted. With a tie, the first supplier recorded.
 *
 * @returns {Record<number, number>} request line id => quote line id
 */
export function defaultChoices(canvass) {
    const choices = {}

    for (const line of canvass?.lines ?? []) {
        if (!line.quoted) continue

        const lowest = line.cells.find((cell) => cell?.lowest)
        if (lowest) choices[line.line_id] = lowest.quote_line_id
    }

    return choices
}

/** Is this choice dearer than the lowest, so a reason is needed? */
export function needsReason(line, quoteLineId) {
    const cell = line.cells.find((c) => c && Number(c.quote_line_id) === Number(quoteLineId))

    return Boolean(cell) && !cell.lowest
}

/** What the award comes to, from the chosen cells. */
export function awardTotal(canvass, choices) {
    let cents = 0

    for (const line of canvass?.lines ?? []) {
        const cell = line.cells.find((c) => c && Number(c.quote_line_id) === Number(choices[line.line_id]))
        if (cell) cents += Math.round(Number(cell.line_total) * 100)
    }

    return cents / 100
}

const swalClasses = {
    popup: 'maf-swal-popup',
    title: 'maf-swal-title',
    actions: 'maf-swal-actions',
    confirmButton: 'maf-swal-confirm',
    cancelButton: 'maf-swal-cancel',
}

export async function removeQuote(request, supplier) {
    if (!(await confirmDelete({ title: 'Remove this quote?', name: `${supplier.name}'s prices` }))) return

    router.delete(`/purchase-requests/${request.id}/quotes/${supplier.quote_id}`, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The quote was not removed.'),
    })
}

export async function reopenAward(request) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Reopen the award on ${request.reference}?`,
        text: 'The choices are cleared, and the quotes can be changed again. The audit trail keeps your reason.',
        input: 'text',
        inputPlaceholder: 'For example: the supplier can no longer deliver',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, reopen it',
        cancelButtonText: 'Cancel',
        inputValidator: (value) => ((value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined),
        customClass: swalClasses,
    })

    if (!isConfirmed) return

    router.post(`/purchase-requests/${request.id}/award/reopen`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The award was not reopened.'),
    })
}
