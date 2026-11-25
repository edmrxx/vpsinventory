<script setup>
import { computed, nextTick, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Check, ClipboardList, FileText, Info, Printer, Scale, Send, X, XCircle } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import PurchaseRequestPrint from '../../Components/PurchaseRequestPrint.vue'
import CanvassPanel from '../../Components/CanvassPanel.vue'
import AbstractPrint from '../../Components/AbstractPrint.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { count, useMoney } from '../../lib/money'
import { approveRequest, closeRequest, rejectRequest } from '../../lib/purchaseRequests'

/**
 * One purchase request: what was asked for, why, and the answer.
 *
 * Read from the same `detail()` payload as the dialog on the list.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    request: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

usePrintLayout()

const details = computed(() => [
    { label: 'Department', value: props.request.department ?? '—' },
    { label: 'Raised by', value: props.request.requested_by ?? '—' },
    { label: 'Raised', value: props.request.requested_at ?? props.request.request_date },
    { label: 'Needed by', value: props.request.needed_by ?? 'Not said' },
    { label: 'Preferred supplier', value: props.request.supplier ?? 'Any' },
])

/*
 * Two papers from one page (phase 24.1): the request itself, and the Request
 * for Quotation a supplier fills in. The screen is left off the paper; the
 * chosen one is put on it.
 */
const printing = ref('request')

async function print(mode) {
    printing.value = mode
    await nextTick()
    window.print()
}

/** A line is below its reorder level: worth seeing beside the request. */
function isLow(line) {
    return Number(line.reorder_level) > 0 && Number(line.on_hand) <= Number(line.reorder_level)
}
</script>

<template>
    <div>
        <!-- Only on paper: the request, or the request for quotation -->
        <AbstractPrint v-if="printing === 'abstract'" class="hidden print:block" :request="request" />
        <PurchaseRequestPrint v-else class="hidden print:block" :request="request" :mode="printing" />

        <div class="print:hidden">
        <PageHeader
            :heading="request.reference"
            subheading="Purchase request"
            back-href="/purchase-requests"
            back-label="Back to purchase requests"
        >
            <template #actions>
                <PrimaryButton variant="secondary" @click="print('request')">
                    <Printer :size="15" />
                    Print PR
                </PrimaryButton>
                <PrimaryButton v-if="can.printRfq" variant="secondary" @click="print('rfq')">
                    <Send :size="15" />
                    Print RFQ
                </PrimaryButton>
                <PrimaryButton v-if="request.canvass?.quoted" variant="secondary" @click="print('abstract')">
                    <Scale :size="15" />
                    Print Abstract
                </PrimaryButton>
                <PrimaryButton v-if="can.close" variant="secondary" @click="closeRequest(request)">
                    <XCircle :size="15" />
                    Close
                </PrimaryButton>
                <PrimaryButton v-if="can.reject" variant="danger" @click="rejectRequest(request)">
                    <X :size="15" />
                    Reject
                </PrimaryButton>
                <PrimaryButton v-if="can.approve" @click="approveRequest(request, { own: !!can.ownRequest })">
                    <Check :size="15" />
                    Approve
                </PrimaryButton>
                <PrimaryButton v-if="can.order" :href="`/purchase-orders/create?request=${request.id}`">
                    <FileText :size="15" />
                    Create order
                </PrimaryButton>
            </template>
        </PageHeader>

        <p v-if="can.ownRequest" class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-info-700">
            <Info :size="16" class="mt-px shrink-0" />
            <span v-if="can.selfApproval">
                You raised this request. You may still answer it, and the audit trail will say you answered your own.
            </span>
            <span v-else>You raised this request, so somebody else must approve or reject it. You may still close it.</span>
        </p>

        <p
            v-if="request.decided_at"
            class="card mb-4 p-4 text-xs font-medium"
            :class="request.status === 'rejected' ? 'text-danger-700' : 'text-success-700'"
        >
            {{ request.status === 'rejected' ? 'Rejected' : 'Approved' }} on {{ request.decided_at }}<span v-if="request.decided_by"> by {{ request.decided_by }}</span>.
            <span v-if="request.decision_note">{{ request.status === 'rejected' ? 'Reason' : 'Note' }}: {{ request.decision_note }}</span>
        </p>

        <!-- The orders it became: one by hand, or one per winning supplier -->
        <div v-if="request.orders?.length" class="card mb-4 p-4 text-xs font-medium text-ink">
            <p class="flex items-center gap-1.5">
                <FileText :size="15" class="text-brand-700" />
                Ordered on {{ request.orders.length === 1 ? 'one order' : `${request.orders.length} orders` }}:
            </p>
            <ul class="mt-2 space-y-1 pl-6">
                <li v-for="order in request.orders" :key="order.id" class="flex flex-wrap items-baseline gap-x-2">
                    <Link v-if="can.viewOrders" :href="`/purchase-orders/${order.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.reference }}</Link>
                    <span v-else class="font-mono font-semibold">{{ order.reference }}</span>
                    <span class="text-ink-muted">{{ order.supplier }} · ({{ order.status_label }})</span>
                </li>
            </ul>
        </div>

        <p v-else-if="request.closed_at" class="card mb-4 p-4 text-xs font-medium text-ink-muted">
            Closed on {{ request.closed_at }}<span v-if="request.closed_by"> by {{ request.closed_by }}</span>.
            Reason: {{ request.close_reason }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand">
                        <ClipboardList :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">{{ request.reference }}</p>
                        <div class="mt-1.5">
                            <ActionBadge :label="request.status_label" :tone="request.status_tone" />
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Why it is needed</p>
                    <p class="mt-1.5 text-sm whitespace-pre-line text-ink">{{ request.reason }}</p>
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

                <dl class="mt-6 rounded-field bg-surface-sunken p-4">
                    <div class="flex items-baseline justify-between">
                        <dt class="text-xs font-semibold text-ink">Estimated total</dt>
                        <dd class="text-2xl font-bold tabular-nums text-ink">{{ money(request.estimated_total) }}</dd>
                    </div>
                    <p class="mt-1 text-2xs text-ink-subtle">What the person asking expects it to cost. The real price comes with the order.</p>
                </dl>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_8rem_7rem_8rem_8rem] md:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">On hand now</span>
                        <span class="text-right">Est. Unit Cost</span>
                        <span class="text-right">Estimate</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in request.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:grid-cols-[1fr_8rem_7rem_8rem_8rem] md:items-center md:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink md:block md:text-right">
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Qty</span>
                                {{ line.quantity_label }}
                            </span>
                            <span class="flex items-baseline justify-between gap-3 tabular-nums text-sm md:block md:text-right"
                                :class="isLow(line) ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">On hand now</span>
                                {{ count(line.on_hand) }} {{ line.unit }}
                            </span>
                            <span class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-muted md:block md:text-right">
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Est. unit cost</span>
                                {{ money(line.unit_cost) }} / {{ line.unit_code }}
                            </span>
                            <span class="flex items-baseline justify-between gap-3 tabular-nums text-sm font-semibold text-ink md:block md:text-right">
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Estimate</span>
                                {{ money(line.line_total) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="request.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ request.notes }}</p>
                </section>
            </div>
        </div>

        <!-- The canvass (phase 24.2): suppliers' prices side by side, and the award -->
        <CanvassPanel class="mt-4" :request="request" :can="can" />
        </div>
    </div>
</template>
