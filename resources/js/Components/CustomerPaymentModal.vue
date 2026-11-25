<script setup>
import { Link, router } from '@inertiajs/vue3'
import { ExternalLink, FileText } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { Swal, toastError } from '../lib/swal'
import { useMoney } from '../lib/money'

/**
 * A customer payment, read without leaving the list.
 *
 * Its own dialog rather than a sixth kind in `DocumentModal`: a payment has
 * invoices where a document has products, and its void moves money, not
 * stock. The figures come from the controller's `detail()`, the same
 * payload the full page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The payment's own address, e.g. "/customer-payments/5". */
    paymentUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()

async function voidPayment(payment) {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this payment?',
        text: `The invoices it paid will owe ${money(payment.amount)} again. Say why.`,
        input: 'text',
        inputPlaceholder: 'For example: cheque bounced, wrong customer',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, void it',
        cancelButtonText: 'Cancel',
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

    router.post(`${props.paymentUrl}/void`, { reason }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The payment was not voided.'),
    })
}
</script>

<template>
    <RecordModal :open="open" :url="paymentUrl ? `${paymentUrl}/preview` : null" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.payment.reference }}</h3>
                        <ActionBadge :label="data.payment.status_label" :tone="data.payment.status_tone" />
                    </div>
                    <div class="flex items-center gap-4">
                        <Link
                            :href="`/customers/${data.payment.customer_id}/statement`"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                        >
                            <FileText :size="14" />
                            Statement
                        </Link>
                        <Link
                            :href="paymentUrl"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                        >
                            <ExternalLink :size="14" />
                            Open full page
                        </Link>
                    </div>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Customer', value: data.payment.customer },
                            { label: 'Date paid', value: data.payment.payment_date },
                            { label: 'Paid by', value: data.payment.method },
                            { label: 'Reference', value: data.payment.method_reference ?? '—' },
                            { label: 'Recorded', value: data.payment.posted_at ?? '—' },
                            { label: 'Recorded by', value: data.payment.posted_by ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.payment.voided_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Voided on {{ data.payment.voided_at }}<span v-if="data.payment.voided_by"> by {{ data.payment.voided_by }}</span>.
                    <span v-if="data.payment.void_reason">Reason: {{ data.payment.void_reason }}</span>
                    The invoices it paid owe that amount again.
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_6.5rem_6.5rem_6.5rem] sm:gap-3"
                    >
                        <span>Invoice</span>
                        <span>Due</span>
                        <span class="text-right">Paid here</span>
                        <span class="text-right">Owes now</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div
                            v-for="row in data.payment.allocations"
                            :key="row.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_6.5rem_6.5rem_6.5rem] sm:items-center sm:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-ink">{{ row.reference }}</p>
                                <p class="text-2xs text-ink-subtle">Sold {{ row.sale_date }}</p>
                            </div>
                            <span class="text-sm text-ink-muted">{{ row.due_date ?? '—' }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(row.amount) }}</span>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">{{ money(row.balance_due) }}</span>
                        </div>
                    </div>
                </section>

                <div class="ml-auto flex w-full items-baseline justify-between gap-3 sm:max-w-xs">
                    <span class="text-sm font-semibold text-ink">Amount paid</span>
                    <span class="tabular-nums text-base font-bold text-ink">{{ money(data.payment.amount) }}</span>
                </div>

                <p v-if="data.payment.notes" class="rounded-field bg-surface-sunken p-3 text-xs text-ink-muted">
                    {{ data.payment.notes }}
                </p>

                <p class="text-2xs text-ink-subtle">This records money received. It is not an official receipt.</p>

                <div v-if="data.can?.void" class="flex justify-end border-t border-line pt-4">
                    <PrimaryButton variant="danger" @click="voidPayment(data.payment)">Void</PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
