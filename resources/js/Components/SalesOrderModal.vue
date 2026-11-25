<script setup>
import { Link } from '@inertiajs/vue3'
import { Ban, Check, ExternalLink, Receipt } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'
import { approveOrder, cancelOrder } from '../lib/salesOrders'

/**
 * A sales order, read and answered without leaving the list (phase 29).
 *
 * The figures come from the controller's `detail()`, the same payload the
 * full page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The order's own address, e.g. "/sales-orders/5". */
    orderUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()
const today = new Date().toISOString().slice(0, 10)
const done = { onDone: () => emit('close'), today }
</script>

<template>
    <RecordModal :open="open" :url="orderUrl ? `${orderUrl}/preview` : null" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.order.reference }}</h3>
                        <ActionBadge :label="data.order.status_label" :tone="data.order.status_tone" />
                    </div>
                    <Link :href="orderUrl" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline">
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Customer', value: data.order.customer },
                            { label: 'Attention', value: data.order.attention ?? '—' },
                            { label: 'Held at', value: data.order.location ?? '—' },
                            { label: 'Taken', value: data.order.order_date },
                            { label: 'Held until', value: data.order.status === 'approved' || data.order.status === 'expired' ? data.order.reserve_until : 'Not approved yet' },
                            { label: 'From quotation', value: data.order.quotation?.reference ?? '—' },
                            { label: 'Became sale', value: data.order.sale?.reference ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.order.cancelled_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Cancelled on {{ data.order.cancelled_at }}<span v-if="data.order.cancelled_by"> by {{ data.order.cancelled_by }}</span>.
                    Reason: {{ data.order.cancel_reason }}
                </p>
                <p v-if="data.order.status === 'expired'" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    Its hold lapsed on {{ data.order.reserve_until }}: it reserves nothing now, but can still become a sale while the stock is free.
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_8rem_6rem_7rem] sm:gap-3">
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Free</span>
                        <span class="text-right">Total</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div v-for="line in data.order.lines" :key="line.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_8rem_6rem_7rem] sm:items-center sm:gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }} · {{ money(line.unit_price) }} / {{ line.unit_code }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink sm:text-right">{{ line.quantity_label }}</span>
                            <span class="tabular-nums text-sm sm:text-right" :class="line.short && data.order.status === 'pending' ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                {{ count(line.free) }} {{ line.unit }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(line.line_total) }}</span>
                        </div>
                    </div>
                </section>

                <div class="ml-auto flex w-full items-baseline justify-between gap-3 sm:max-w-xs">
                    <span class="text-sm font-semibold text-ink">Total</span>
                    <span class="tabular-nums text-base font-bold text-ink">{{ money(data.order.total_amount) }}</span>
                </div>

                <div v-if="data.can?.cancel || data.can?.approve || data.can?.sell" class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <PrimaryButton v-if="data.can.cancel" variant="danger" @click="cancelOrder(data.order, done)">
                        <Ban :size="15" />
                        {{ data.order.status === 'approved' ? 'Release' : 'Cancel' }}
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.approve" variant="secondary" @click="approveOrder(data.order, done)">
                        <Check :size="15" />
                        Approve and reserve
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.sell" :href="`/sales/create?order=${data.order.id}`">
                        <Receipt :size="15" />
                        Make the sale
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
