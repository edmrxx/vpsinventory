import { router } from '@inertiajs/vue3'
import { Swal, toastError } from './swal'

/**
 * Cancel a quotation, asking why first (phase 28).
 *
 * Shared by the quotation's page and the dialog on the list, so both ask the
 * same question. The server decides again under the row lock.
 *
 * @param {{ id:number, reference:string }} quotation
 */
export async function cancelQuotation(quotation, { onDone } = {}) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Cancel ${quotation.reference}?`,
        text: 'A cancelled quotation cannot become a sale. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: customer bought elsewhere, prices changed',
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

    router.post(`/quotations/${quotation.id}/cancel`, { reason }, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The quotation was not cancelled.'),
    })
}

