<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { Ban, Scale } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { Swal, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    adjustment: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const details = computed(() => [
    { label: 'Kind', value: props.adjustment.kind_label },
    { label: 'Reason', value: props.adjustment.reason ?? '—' },
    { label: 'Date', value: props.adjustment.adjustment_date ?? '—' },
    { label: 'Location', value: props.adjustment.location ?? '—' },
    { label: 'Posted', value: props.adjustment.posted_at ?? '—' },
    { label: 'Posted by', value: props.adjustment.posted_by ?? '—' },
])

const takesStockOut = computed(() => props.adjustment.kind === 'decrease')

async function voidAdjustment() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this adjustment?',
        text: takesStockOut.value
            ? 'The stock goes back into the batches it came from.'
            : 'The stock comes back off the shelf. Only possible while none of it has moved.',
        input: 'text',
        inputPlaceholder: 'For example: counted twice, entered by mistake',
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

    router.post(`/adjustments/${props.adjustment.id}/void`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The adjustment was not voided.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="adjustment.reference"
            :subheading="`${adjustment.kind_label} · ${adjustment.adjustment_date}`"
            back-href="/adjustments"
            back-label="Back to adjustments"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.void && adjustment.status === 'posted'"
                    variant="danger"
                    @click="voidAdjustment"
                >
                    <Ban :size="15" />
                    Void
                </PrimaryButton>
            </template>
        </PageHeader>

        <p
            v-if="adjustment.status === 'voided'"
            class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700"
        >
            <Ban :size="16" class="mt-px shrink-0" />
            <span>Voided on {{ adjustment.voided_at }}.</span>
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand"
                    >
                        <Scale :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">
                            {{ adjustment.reference }}
                        </p>
                        <div class="mt-1.5 flex gap-1.5">
                            <ActionBadge :label="adjustment.kind_label" :tone="adjustment.kind_tone" />
                            <ActionBadge :label="adjustment.status_label" :tone="adjustment.status_tone" />
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
                    <p class="text-xs text-ink-muted">
                        {{ takesStockOut ? 'Value taken out' : 'Value brought in' }}
                    </p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-ink">
                        {{ money(adjustment.total_cost) }}
                    </p>
                </div>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_5rem_8rem_8rem] md:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Unit cost</span>
                        <span class="text-right">Value</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in adjustment.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:grid-cols-[1fr_5rem_8rem_8rem] md:items-center md:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">
                                {{ count(line.quantity) }}
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">
                                {{ money(line.unit_cost) }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">
                                {{ money(line.total_cost) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="adjustment.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ adjustment.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
