import { router } from '@inertiajs/vue3'
import { Swal, confirmAction, toastError } from './swal'

/**
 * Approve, reject or withdraw a loss report (phase 33).
 *
 * Shared by the report's page and the dialog on the list, so both ask the
 * same question. The server decides again with the report row locked.
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

async function askWhy({ title, text, placeholder, confirmText }) {
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
        inputValidator: (v) => ((v ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined),
        customClass: swalClasses,
    })

    return isConfirmed ? value : null
}

/** @param {{ id:number, reference:string, location:string, reason_label:string }} report */
export async function approveLoss(report, { onDone } = {}) {
    const agreed = await confirmAction({
        icon: 'warning',
        title: `Write off ${report.reference}?`,
        text: `The goods come off the books at ${report.location} today, at the cost of the oldest batches, as a loss (${report.reason_label.toLowerCase()}). It shows under Losses on the dashboard.`,
        confirmText: 'Yes, write it off',
        confirmColor: 'oklch(0.55 0.21 25)',
    })

    if (!agreed) return

    send(`/loss-reports/${report.id}/approve`, {}, { onDone, failure: 'The loss was not written off.' })
}

/** @param {{ id:number, reference:string }} report */
export async function rejectLoss(report, { onDone } = {}) {
    const reason = await askWhy({
        title: `Reject ${report.reference}?`,
        text: 'Nothing is written off, and the goods are free to sell again. Say why.',
        placeholder: 'For example: found on the wrong shelf, still sellable',
        confirmText: 'Yes, reject it',
    })

    if (reason === null) return

    send(`/loss-reports/${report.id}/reject`, { reason }, { onDone, failure: 'The report was not rejected.' })
}

/** @param {{ id:number, reference:string }} report */
export async function withdrawLoss(report, { onDone } = {}) {
    const reason = await askWhy({
        title: `Withdraw ${report.reference}?`,
        text: 'Nothing is written off, and the goods are free to sell again. Say why.',
        placeholder: 'For example: reported twice',
        confirmText: 'Yes, withdraw it',
    })

    if (reason === null) return

    send(`/loss-reports/${report.id}/cancel`, { reason }, { onDone, failure: 'The report was not withdrawn.' })
}
