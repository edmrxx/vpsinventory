<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Ban, Check, PackageCheck, PackageX, Printer, X } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import PurchaseOrderPrint from '../../Components/PurchaseOrderPrint.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { count, useMoney } from '../../lib/money'
import { approveOrder, cancelOrder, closeOrderShort, rejectOrder } from '../../lib/purchaseOrders'

/**
 * One purchase order, laid out as the order sent to the supplier.
 *
 * Built to be printed: the app's sidebar and top bar stay off the paper
 * (`print:` classes in AppLayout), and so do the controls and notes here.
 * Read from the same `detail()` payload as the dialog on the list.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    order: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()
const page = usePage()
const printing = computed(() => page.props.printing ?? {})

usePrintLayout()

function printOrder() {
    window.print()
}
</script>

<template>
    <div>
        <PageHeader
            class="print:hidden"
            :heading="order.reference"
            :subheading="`Purchase order to ${order.supplier?.name ?? 'a supplier'}`"
            back-href="/purchase-orders"
            back-label="Back to purchase orders"
        >
            <template #actions>
                <PrimaryButton v-if="can.reject" variant="danger" @click="rejectOrder(order)">
                    <X :size="15" />
                    Reject
                </PrimaryButton>
                <PrimaryButton v-if="can.approve" @click="approveOrder(order)">
                    <Check :size="15" />
                    Approve
                </PrimaryButton>
                <PrimaryButton v-if="can.cancel" variant="danger" @click="cancelOrder(order)">
                    <Ban :size="15" />
                    Cancel order
                </PrimaryButton>
                <PrimaryButton v-if="can.close" variant="secondary" @click="closeOrderShort(order)">
                    <PackageX :size="15" />
                    Close short
                </PrimaryButton>
                <PrimaryButton v-if="can.receive" variant="secondary" :href="`/purchases/create?order=${order.id}`">
                    <PackageCheck :size="15" />
                    Receive delivery
                </PrimaryButton>
                <PrimaryButton :variant="order.draft ? 'secondary' : 'primary'" @click="printOrder">
                    <Printer :size="15" />
                    {{ order.draft ? 'Print draft' : 'Print' }}
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
            <ActionBadge :label="order.status_label" :tone="order.status_tone" />
            <ActionBadge v-if="order.late" label="Late" tone="warning" />
            <span class="text-xs text-ink-subtle">
                Placed {{ order.ordered_at }}<template v-if="order.ordered_by"> by {{ order.ordered_by }}</template>.
            </span>
            <span v-if="order.request" class="text-xs text-ink-subtle">
                From request
                <Link v-if="can.viewRequest" :href="`/purchase-requests/${order.request.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.request.reference }}</Link>
                <span v-else class="font-mono">{{ order.request.reference }}</span><template v-if="order.request.requested_by">, raised by {{ order.request.requested_by }}</template>.
            </span>
        </div>

        <p v-if="order.status === 'pending_approval'" class="card mb-4 border-l-4 border-l-warning-500 p-4 text-xs font-medium text-warning-700 print:hidden">
            Waiting for approval. Until it is approved it prints only as a draft, and nothing can be received against it.
            <span v-if="can.ownOrder" class="block">You placed this order, so somebody else must approve or reject it.</span>
        </p>
        <p v-if="order.rejected_at" class="card mb-4 p-4 text-xs font-medium text-danger-700 print:hidden">
            Rejected on {{ order.rejected_at }}<span v-if="order.rejected_by"> by {{ order.rejected_by }}</span>.
            Reason: {{ order.reject_reason }}
        </p>
        <p v-else-if="order.approved_at && !order.draft" class="mb-4 text-xs text-success-700 print:hidden">
            Approved {{ order.approved_at }}<span v-if="order.approved_by"> by {{ order.approved_by }}</span>.
        </p>

        <p v-if="order.cancelled_at" class="card mb-4 p-4 text-xs font-medium text-danger-700 print:hidden">
            Cancelled on {{ order.cancelled_at }}<span v-if="order.cancelled_by"> by {{ order.cancelled_by }}</span>.
            Reason: {{ order.cancel_reason }}
        </p>
        <p v-if="order.closed_at" class="card mb-4 p-4 text-xs font-medium text-ink-muted print:hidden">
            Closed short on {{ order.closed_at }}<span v-if="order.closed_by"> by {{ order.closed_by }}</span>.
            Reason: {{ order.close_reason }}
        </p>

        <!-- Receiving: on screen only, never on the paper sent to the supplier -->
        <section class="card mb-4 overflow-hidden print:hidden">
            <div class="border-b border-line px-5 py-4 sm:px-6">
                <h3 class="text-sm font-semibold text-ink">Receiving</h3>
                <p class="text-xs text-ink-subtle">What has come in against this order, and what is still to come.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line bg-surface-muted text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                            <th class="px-5 py-2 font-semibold sm:px-6">Product</th>
                            <th class="px-3 py-2 text-right font-semibold">Ordered</th>
                            <th class="px-3 py-2 text-right font-semibold">Received</th>
                            <th class="px-5 py-2 text-right font-semibold sm:px-6">Still to come</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in order.lines" :key="line.id">
                            <td class="px-5 py-2.5 sm:px-6">
                                <p class="text-ink">{{ line.name }}</p>
                                <p v-for="change in line.cost_changes" :key="change.purchase" class="text-2xs font-semibold text-warning-700">
                                    {{ change.purchase }} invoiced {{ money(change.piece_cost) }} / {{ line.unit }}, agreed {{ money(line.agreed_piece_cost) }}
                                </p>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap text-ink-muted">{{ count(line.quantity) }} {{ line.unit }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap text-ink">{{ count(line.received) }} {{ line.unit }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold sm:px-6"
                                :class="Number(line.outstanding) > 0 ? 'text-ink' : 'text-success-700'">
                                {{ Number(line.outstanding) > 0 ? `${count(line.outstanding)} ${line.unit}` : 'In full' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="order.unordered.length" class="border-t border-line px-5 py-3 text-xs sm:px-6">
                <p class="font-semibold text-warning-700">Delivered but not on the order</p>
                <p v-for="(row, i) in order.unordered" :key="i" class="text-ink-muted">
                    {{ row.purchase }}: {{ count(row.quantity) }} {{ row.unit }} {{ row.name }} <span class="font-mono text-ink-subtle">{{ row.sku }}</span>
                </p>
            </div>

            <div class="border-t border-line px-5 py-3 sm:px-6">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Deliveries</p>
                <!-- What its deliveries were paid and still owe (phase 24.5). -->
                <p v-if="order.payment" class="mt-1 text-xs text-ink-muted" data-order-payment>
                    Paid <strong class="tabular-nums text-success-700">{{ money(order.payment.paid) }}</strong>
                    · Owed <strong class="tabular-nums" :class="Number(order.payment.owed) > 0 ? 'text-warning-700' : 'text-ink'">{{ money(order.payment.owed) }}</strong>
                </p>
                <p v-if="!order.deliveries.length" class="mt-1 text-xs text-ink-subtle">Nothing has been received against this order yet.</p>
                <ul v-else class="mt-1 space-y-1">
                    <li v-for="delivery in order.deliveries" :key="delivery.id" class="flex flex-wrap items-center gap-2 text-xs">
                        <Link v-if="can.viewPurchases" :href="`/purchases/${delivery.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ delivery.reference }}</Link>
                        <span v-else class="font-mono font-semibold">{{ delivery.reference }}</span>
                        <span class="text-ink-muted">{{ delivery.purchase_date }}</span>
                        <span v-if="delivery.location" class="text-ink-muted">· received at {{ delivery.location }}</span>
                        <span v-if="delivery.supplier_reference" class="text-ink-subtle">· invoice {{ delivery.supplier_reference }}</span>
                        <span class="tabular-nums text-ink">{{ money(delivery.total_amount) }}</span>
                        <ActionBadge :label="delivery.status_label" :tone="delivery.status_tone" />
                    </li>
                </ul>
            </div>
        </section>

        <PurchaseOrderPrint :order="order" :terms="printing.purchase_order_terms ?? ''" />
    </div>
</template>
