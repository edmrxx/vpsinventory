<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { Ban } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import { Swal, toastError } from '../../lib/swal'
import { useMoney } from '../../lib/money'

/**
 * One supplier payment: who was paid, how, with what credit, and which bills
 * it paid (phase 24).
 *
 * Read from the same `detail()` payload as the dialog on the list.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    payment: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const details = computed(() => [
    { label: 'Supplier', value: props.payment.supplier },
    { label: 'Date paid', value: props.payment.payment_date },
    { label: 'Paid by', value: props.payment.method ?? 'Supplier credit only' },
    { label: 'Supplier credit used', value: Number(props.payment.credit_used) > 0 ? money(props.payment.credit_used) : 'None' },
    { label: 'Reference', value: props.payment.method_reference ?? '—' },
    { label: 'Recorded', value: props.payment.posted_at ?? '—' },
    { label: 'Recorded by', value: props.payment.posted_by ?? '—' },
])

/** A bill opens in the purchase dialog, like everywhere else. */
const viewing = ref(null)

function viewBill(event, row) {
    if (!props.can.viewPurchases) return
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return

    event.preventDefault()
    viewing.value = `/purchases/${row.purchase_id}`
}

async function voidPayment() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this payment?',
        text: `The bills it paid will be owed ${money(Number(props.payment.amount) + Number(props.payment.credit_used))} again, and any credit it used goes back to the supplier. Say why.`,
        input: 'text',
        inputPlaceholder: 'For example: cheque bounced, wrong supplier',
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

    router.post(`/supplier-payments/${props.payment.id}/void`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The payment was not voided.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="payment.reference"
            :subheading="`Payment to ${payment.supplier}`"
            back-href="/supplier-payments"
            back-label="Back to supplier payments"
        >
            <template #actions>
                <PrimaryButton variant="secondary" :href="`/suppliers/${payment.supplier_id}`">
                    Supplier
                </PrimaryButton>
                <PrimaryButton v-if="can.void" variant="danger" @click="voidPayment">
                    <Ban :size="15" />
                    Void
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-ink">Amount paid</p>
                    <ActionBadge :label="payment.status_label" :tone="payment.status_tone" />
                </div>
                <p class="mt-2 tabular-nums text-3xl font-bold tracking-tight text-ink"
                    :class="payment.status === 'voided' ? 'text-ink-subtle line-through' : ''">
                    {{ money(payment.amount) }}
                </p>

                <dl class="mt-6 space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>

                <p v-if="Number(payment.credit_used) > 0" class="mt-4 text-xs text-ink-muted">
                    The bills took {{ money(Number(payment.amount) + Number(payment.credit_used)) }} in all: the money above and {{ money(payment.credit_used) }} of supplier credit.
                </p>
            </section>

            <section class="card overflow-hidden lg:col-span-2">
                <div class="border-b border-line px-5 py-4 sm:px-6">
                    <h3 class="text-sm font-semibold text-ink">Bills it paid</h3>
                    <p class="text-xs text-ink-subtle">"Owed now" is what each bill is still owed today, after this and anything since.</p>
                </div>

                <p v-if="payment.voided_at" class="border-b border-line bg-danger-50 px-5 py-3 text-xs text-danger-700 sm:px-6">
                    Voided on {{ payment.voided_at }}<span v-if="payment.voided_by"> by {{ payment.voided_by }}</span>.
                    <span v-if="payment.void_reason">Reason: {{ payment.void_reason }}.</span>
                    The bills it paid are owed that amount again.
                </p>

                <div
                    class="hidden border-b border-line bg-surface-muted px-5 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_7rem_7rem_7rem] sm:gap-3 sm:px-6"
                >
                    <span>Bill</span>
                    <span>Due</span>
                    <span class="text-right">Paid here</span>
                    <span class="text-right">Owed now</span>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="row in payment.allocations" :key="row.id">
                        <component
                            :is="can.viewPurchases ? 'a' : 'div'"
                            :href="can.viewPurchases ? `/purchases/${row.purchase_id}` : undefined"
                            class="grid gap-1 px-5 py-3 sm:grid-cols-[1fr_7rem_7rem_7rem] sm:items-center sm:gap-3 sm:px-6"
                            :class="can.viewPurchases ? 'transition-colors hover:bg-surface-sunken' : ''"
                            @click="viewBill($event, row)"
                        >
                            <span class="min-w-0">
                                <span class="block font-mono text-xs font-semibold text-ink">{{ row.reference }}</span>
                                <span class="block text-2xs text-ink-subtle">Delivered {{ row.purchase_date }}<template v-if="row.supplier_reference"> · invoice {{ row.supplier_reference }}</template></span>
                            </span>
                            <span class="text-sm text-ink-muted">{{ row.due_date ?? '—' }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(row.amount) }}</span>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">{{ money(row.balance_due) }}</span>
                        </component>
                    </li>
                </ul>

                <p v-if="payment.notes" class="border-t border-line px-5 py-4 text-sm text-ink-muted sm:px-6">
                    {{ payment.notes }}
                </p>
            </section>
        </div>

        <DocumentModal :open="viewing !== null" :document-url="viewing" @close="viewing = null" />
    </div>
</template>
