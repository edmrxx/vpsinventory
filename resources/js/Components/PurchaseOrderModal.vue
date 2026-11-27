<script setup>
import { Link } from '@inertiajs/vue3'
import { Ban, Check, ExternalLink, PackageCheck, PackageX, X } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'
import { approveOrder, cancelOrder, closeOrderShort, rejectOrder } from '../lib/purchaseOrders'

/**
 * A purchase order, read without leaving the list.
 *
 * A summary: the full page is the one that prints. The figures come from the
 * controller's `detail()`, the same payload the page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The order's own address, e.g. "/purchase-orders/5". */
    orderUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()

const done = { onDone: () => emit('close') }
</script>

<template>
    <RecordModal :open="open" :url="orderUrl ? `${orderUrl}/preview` : null" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.order.reference }}</h3>
                        <ActionBadge :label="data.order.status_label" :tone="data.order.status_tone" />
                        <ActionBadge v-if="data.order.late" label="Late" tone="warning" />
                    </div>
                    <Link
                        :href="orderUrl"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                    >
                        <ExternalLink :size="14" />
                        Open full page to print
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Supplier', value: data.order.supplier?.name },
                            { label: 'Ordered', value: data.order.order_date },
                            { label: 'Expected', value: data.order.expected_date ?? 'Not said' },
                            { label: 'Deliver to', value: data.order.deliver_to?.name ?? 'Not said' },
                            ...(data.order.received_at?.length ? [{ label: 'Received at', value: data.order.received_at.join(', ') }] : []),
                            ...(data.order.payment ? [{ label: 'Paid · Owed', value: `${money(data.order.payment.paid)} · ${money(data.order.payment.owed)}` }] : []),
                            { label: 'From request', value: data.order.request?.reference ?? 'Ordered directly' },
                            { label: 'Placed by', value: data.order.ordered_by },
                            { label: 'Approved by', value: data.order.draft ? 'Not approved yet' : (data.order.approved_by ?? (data.order.approved_at ? 'Before approval began' : null)) },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.order.status === 'pending_approval'" class="rounded-field bg-warning-50 px-4 py-3 text-xs text-warning-700">
                    Waiting for approval. It prints only as a draft, and nothing can be received against it yet.
                    <span v-if="data.can?.ownOrder" class="block">You placed this order, so somebody else must approve or reject it.</span>
                </p>

                <p v-if="data.order.rejected_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Rejected on {{ data.order.rejected_at }}<span v-if="data.order.rejected_by"> by {{ data.order.rejected_by }}</span>.
                    Reason: {{ data.order.reject_reason }}
                </p>

                <p v-if="data.order.closed_at" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    Closed short on {{ data.order.closed_at }}<span v-if="data.order.closed_by"> by {{ data.order.closed_by }}</span>.
                    Reason: {{ data.order.close_reason }}
                </p>

                <p v-if="data.order.cancelled_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Cancelled on {{ data.order.cancelled_at }}<span v-if="data.order.cancelled_by"> by {{ data.order.cancelled_by }}</span>.
                    Reason: {{ data.order.cancel_reason }}
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_8rem_7rem_7rem] sm:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Received</span>
                        <span class="text-right">Total</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div
                            v-for="line in data.order.lines"
                            :key="line.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_8rem_7rem_7rem] sm:items-center sm:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink sm:text-right">{{ line.quantity_label }}</span>
                            <span class="tabular-nums text-sm sm:text-right" :class="Number(line.outstanding) > 0 ? 'text-ink-muted' : 'text-success-700'">
                                {{ count(line.received) }} {{ line.unit }}
                            </span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(line.line_total) }}</span>
                        </div>
                    </div>
                </section>

                <div class="ml-auto flex w-full items-baseline justify-between gap-3 sm:max-w-xs">
                    <span class="text-sm font-semibold text-ink">Total</span>
                    <span class="tabular-nums text-base font-bold text-ink">{{ money(data.order.total_amount) }}</span>
                </div>

                <div v-if="data.can?.approve || data.can?.reject || data.can?.cancel || data.can?.close || data.can?.receive" class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <PrimaryButton v-if="data.can.reject" variant="danger" @click="rejectOrder(data.order, done)">
                        <X :size="15" />
                        Reject
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.approve" @click="approveOrder(data.order, done)">
                        <Check :size="15" />
                        Approve
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.cancel" variant="danger" @click="cancelOrder(data.order, done)">
                        <Ban :size="15" />
                        Cancel order
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.close" variant="secondary" @click="closeOrderShort(data.order, done)">
                        <PackageX :size="15" />
                        Close short
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.receive" :href="`/purchases/create?order=${data.order.id}`">
                        <PackageCheck :size="15" />
                        Receive delivery
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
