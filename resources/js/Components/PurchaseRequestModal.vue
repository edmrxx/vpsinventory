<script setup>
import { Link } from '@inertiajs/vue3'
import { Check, ExternalLink, FileText, Info, X, XCircle } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'
import { approveRequest, closeRequest, rejectRequest } from '../lib/purchaseRequests'

/**
 * A purchase request, read and answered without leaving the list.
 *
 * Its own dialog rather than another kind in `DocumentModal`: a request is
 * decided, not posted, and its buttons are Approve, Reject and Close. The
 * figures come from the controller's `detail()`, the same payload the full
 * page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The request's own address, e.g. "/purchase-requests/5". */
    requestUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()

const done = { onDone: () => emit('close') }

/** Approving, with the question saying so when it is one's own request. */
function approve(data) {
    approveRequest(data.request, { ...done, own: !!data.can?.ownRequest })
}
</script>

<template>
    <RecordModal :open="open" :url="requestUrl ? `${requestUrl}/preview` : null" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.request.reference }}</h3>
                        <ActionBadge :label="data.request.status_label" :tone="data.request.status_tone" />
                    </div>
                    <Link
                        :href="requestUrl"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                    >
                        <ExternalLink :size="14" />
                        Open full page to print
                    </Link>
                </div>

                <p class="rounded-field bg-surface-sunken p-3 text-sm whitespace-pre-line text-ink">{{ data.request.reason }}</p>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Raised by', value: data.request.requested_by },
                            { label: 'Raised', value: data.request.request_date },
                            { label: 'Needed by', value: data.request.needed_by ?? 'Not said' },
                            { label: 'Department', value: data.request.department ?? '—' },
                            { label: 'Preferred supplier', value: data.request.supplier ?? 'Any' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.request.decided_at"
                    class="rounded-field px-4 py-3 text-xs"
                    :class="data.request.status === 'rejected' ? 'bg-danger-50 text-danger-700' : 'bg-success-50 text-success-700'">
                    {{ data.request.status === 'rejected' ? 'Rejected' : 'Approved' }} on {{ data.request.decided_at }}<span v-if="data.request.decided_by"> by {{ data.request.decided_by }}</span>.
                    <span v-if="data.request.decision_note">{{ data.request.status === 'rejected' ? 'Reason' : 'Note' }}: {{ data.request.decision_note }}</span>
                </p>
                <p v-if="data.request.orders?.length" class="flex flex-wrap items-center gap-1.5 rounded-field bg-brand-50 px-4 py-3 text-xs text-ink">
                    <FileText :size="14" class="text-brand-700" />
                    Ordered on
                    <template v-for="(order, index) in data.request.orders" :key="order.id">
                        <span v-if="index > 0">,</span>
                        <Link v-if="data.can?.viewOrders" :href="`/purchase-orders/${order.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.reference }}</Link>
                        <span v-else class="font-mono font-semibold">{{ order.reference }}</span>
                    </template>
                </p>
                <p v-else-if="data.request.closed_at" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    Closed on {{ data.request.closed_at }}<span v-if="data.request.closed_by"> by {{ data.request.closed_by }}</span>.
                    Reason: {{ data.request.close_reason }}
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_7rem_6rem_6.5rem] sm:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">On hand</span>
                        <span class="text-right">Estimate</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div
                            v-for="line in data.request.lines"
                            :key="line.id"
                            class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_7rem_6rem_6.5rem] sm:items-center sm:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink sm:text-right">{{ line.quantity_label }}</span>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">{{ count(line.on_hand) }} {{ line.unit }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(line.line_total) }}</span>
                        </div>
                    </div>
                </section>

                <div class="ml-auto flex w-full items-baseline justify-between gap-3 sm:max-w-xs">
                    <span class="text-sm font-semibold text-ink">Estimated total</span>
                    <span class="tabular-nums text-base font-bold text-ink">{{ money(data.request.estimated_total) }}</span>
                </div>

                <p v-if="data.can?.ownRequest" class="flex items-start gap-2 rounded-field bg-info-50 px-4 py-3 text-xs text-info-700">
                    <Info :size="14" class="mt-px shrink-0" />
                    <span v-if="data.can.selfApproval">
                        You raised this request. You may still answer it, and the audit trail will say you answered your own.
                    </span>
                    <span v-else>You raised this request, so somebody else must approve or reject it.</span>
                </p>

                <div
                    v-if="data.can?.approve || data.can?.reject || data.can?.close || data.can?.order"
                    class="flex flex-wrap justify-end gap-2 border-t border-line pt-4"
                >
                    <PrimaryButton v-if="data.can.close" variant="secondary" @click="closeRequest(data.request, done)">
                        <XCircle :size="15" />
                        Close
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.reject" variant="danger" @click="rejectRequest(data.request, done)">
                        <X :size="15" />
                        Reject
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.approve" @click="approve(data)">
                        <Check :size="15" />
                        Approve
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.order" :href="`/purchase-orders/create?request=${data.request.id}`">
                        <FileText :size="15" />
                        Create order
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
