<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { ArrowRightLeft, Ban } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { Swal, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    transfer: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const details = computed(() => [
    { label: 'From', value: props.transfer.from ?? '—' },
    { label: 'To', value: props.transfer.to ?? '—' },
    { label: 'Date', value: props.transfer.transfer_date ?? '—' },
    { label: 'Posted', value: props.transfer.posted_at ?? '—' },
    { label: 'Posted by', value: props.transfer.posted_by ?? '—' },
])

async function voidTransfer() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this transfer?',
        text: `The goods go back to ${props.transfer.from}, into the batches they left. Only possible while none of what arrived at ${props.transfer.to} has been sold or moved on.`,
        input: 'text',
        inputPlaceholder: 'For example: wrong destination, entered twice',
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

    router.post(`/transfers/${props.transfer.id}/void`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The transfer was not voided.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="transfer.reference"
            :subheading="`${transfer.from} → ${transfer.to} · ${transfer.transfer_date}`"
            back-href="/transfers"
            back-label="Back to transfers"
        >
            <template #actions>
                <PrimaryButton v-if="can.void && transfer.status === 'posted'" variant="danger" @click="voidTransfer">
                    <Ban :size="15" />
                    Void
                </PrimaryButton>
            </template>
        </PageHeader>

        <p
            v-if="transfer.status === 'voided'"
            class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700"
        >
            <Ban :size="16" class="mt-px shrink-0" />
            <span>
                Voided on {{ transfer.voided_at }}<template v-if="transfer.voided_by"> by {{ transfer.voided_by }}</template>.
                <template v-if="transfer.void_reason"> Reason: {{ transfer.void_reason }}</template>
            </span>
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand">
                        <ArrowRightLeft :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">{{ transfer.reference }}</p>
                        <div class="mt-1.5 flex gap-1.5">
                            <ActionBadge :label="transfer.status_label" :tone="transfer.status_tone" />
                        </div>
                    </div>
                </div>

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

                <div class="mt-6 rounded-field bg-surface-sunken p-4">
                    <p class="text-xs text-ink-muted">Value moved, at cost</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-ink">{{ money(transfer.total_cost) }}</p>
                </div>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_6rem_8rem_8rem] md:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Unit cost</span>
                        <span class="text-right">Value</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in transfer.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:grid-cols-[1fr_6rem_8rem_8rem] md:items-center md:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">
                                {{ count(line.quantity) }} {{ line.unit }}
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">{{ money(line.unit_cost) }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">{{ money(line.total_cost) }}</span>
                        </div>
                    </div>
                </section>

                <section v-if="transfer.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ transfer.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
