<script setup>
import { Link } from '@inertiajs/vue3'
import { Ban, Copy, ExternalLink, PackageOpen, Receipt } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'
import { cancelQuotation } from '../lib/quotations'

/**
 * A quotation, read without leaving the list (phase 28).
 *
 * A summary: the full page is the one that prints. The figures come from the
 * controller's `detail()`, the same payload the page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The quotation's own address, e.g. "/quotations/5". */
    quotationUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()
</script>

<template>
    <RecordModal :open="open" :url="quotationUrl ? `${quotationUrl}/preview` : null" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.quotation.reference }}</h3>
                        <ActionBadge :label="data.quotation.status_label" :tone="data.quotation.status_tone" />
                    </div>
                    <Link :href="quotationUrl" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline">
                        <ExternalLink :size="14" />
                        Open full page to print
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Customer', value: data.quotation.customer?.name },
                            { label: 'Attention', value: data.quotation.attention ?? '—' },
                            { label: 'Date', value: data.quotation.quote_date },
                            { label: 'Valid until', value: data.quotation.valid_until },
                            { label: 'Prepared by', value: data.quotation.prepared_by },
                            { label: 'Became', value: data.quotation.sale?.reference ?? data.quotation.order?.reference ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.quotation.cancelled_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Cancelled on {{ data.quotation.cancelled_at }}<span v-if="data.quotation.cancelled_by"> by {{ data.quotation.cancelled_by }}</span>.
                    Reason: {{ data.quotation.cancel_reason }}
                </p>
                <p v-if="data.quotation.status === 'expired'" class="rounded-field bg-warning-50 px-4 py-3 text-xs text-warning-700">
                    Expired on {{ data.quotation.valid_until }}. Quote it again to make a sale.
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_8rem_6rem_7rem] sm:gap-3">
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">On hand</span>
                        <span class="text-right">Total</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div v-for="line in data.quotation.lines" :key="line.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_8rem_6rem_7rem] sm:items-center sm:gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }} · {{ money(line.unit_price) }} / {{ line.unit_code }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink sm:text-right">{{ line.quantity_label }}</span>
                            <span class="tabular-nums text-sm sm:text-right" :class="line.short && data.quotation.status === 'open' ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                {{ count(line.on_hand) }} {{ line.unit }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(line.line_total) }}</span>
                        </div>
                    </div>
                </section>

                <div class="ml-auto w-full space-y-1 sm:max-w-xs">
                    <div v-if="Number(data.quotation.mobilization) > 0" class="flex items-baseline justify-between gap-3 text-xs text-ink-muted">
                        <span>Mobilization</span>
                        <span class="tabular-nums">+{{ money(data.quotation.mobilization) }}</span>
                    </div>
                    <div v-if="Number(data.quotation.discount) > 0" class="flex items-baseline justify-between gap-3 text-xs text-ink-muted">
                        <span>Discount</span>
                        <span class="tabular-nums">−{{ money(data.quotation.discount) }}</span>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-sm font-semibold text-ink">Total</span>
                        <span class="tabular-nums text-base font-bold text-ink">{{ money(data.quotation.total_amount) }}</span>
                    </div>
                </div>

                <div v-if="data.can?.cancel || data.can?.sell || data.can?.requote || data.can?.order" class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <PrimaryButton v-if="data.can.cancel" variant="danger" @click="cancelQuotation(data.quotation, { onDone: () => emit('close') })">
                        <Ban :size="15" />
                        Cancel
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.requote" variant="secondary" :href="`/quotations/create?from=${data.quotation.id}`">
                        <Copy :size="15" />
                        Quote again
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.order" variant="secondary" :href="`/sales-orders/create?quotation=${data.quotation.id}`">
                        <PackageOpen :size="15" />
                        Make an order
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.sell" :href="`/sales/create?quotation=${data.quotation.id}`">
                        <Receipt :size="15" />
                        Make a sale
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
