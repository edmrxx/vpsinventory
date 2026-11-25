<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Ban, Check, PackageOpen, Receipt, TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { count, useMoney } from '../../lib/money'
import { approveOrder, cancelOrder } from '../../lib/salesOrders'

/**
 * One sales order: what the customer ordered, whether its stock is held,
 * and what became of it (phase 29).
 *
 * Read from the same `detail()` payload as the dialog on the list.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    order: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()
const today = new Date().toISOString().slice(0, 10)

const short = computed(() => props.order.lines.filter((line) => line.short))

const details = computed(() => [
    { label: 'Customer', value: props.order.customer },
    { label: 'Attention', value: props.order.attention ?? '—' },
    { label: 'Held at', value: props.order.location ?? '—' },
    { label: 'Taken', value: props.order.created_at },
    { label: 'Taken by', value: props.order.created_by ?? '—' },
    { label: 'Approved', value: props.order.approved_at ? `${props.order.approved_at} by ${props.order.approved_by ?? '—'}` : 'Not yet' },
    { label: 'Held until', value: props.order.status === 'approved' || props.order.status === 'expired' ? props.order.reserve_until : '—' },
])
</script>

<template>
    <div>
        <PageHeader
            :heading="order.reference"
            :subheading="`Sales order for ${order.customer}`"
            back-href="/sales-orders"
            back-label="Back to sales orders"
        >
            <template #actions>
                <PrimaryButton v-if="can.cancel" variant="danger" @click="cancelOrder(order)">
                    <Ban :size="15" />
                    {{ order.status === 'approved' ? 'Release' : 'Cancel' }}
                </PrimaryButton>
                <PrimaryButton v-if="can.approve" variant="secondary" @click="approveOrder(order, { today })">
                    <Check :size="15" />
                    Approve and reserve
                </PrimaryButton>
                <PrimaryButton v-if="can.sell" :href="`/sales/create?order=${order.id}`">
                    <Receipt :size="15" />
                    Make the sale
                </PrimaryButton>
            </template>
        </PageHeader>

        <p v-if="order.status === 'pending'" class="card mb-4 p-4 text-xs font-medium text-warning-700">
            Pending: this order holds no stock yet. Somebody who may approve sets it aside.
        </p>
        <p v-if="order.status === 'approved'" class="card mb-4 p-4 text-xs font-medium text-info-700">
            Its stock is reserved for {{ order.customer }} until {{ order.reserve_until }}. Nobody else can buy it meanwhile.
        </p>
        <p v-if="order.status === 'expired'" class="card mb-4 p-4 text-xs font-medium text-ink-muted">
            Its hold lapsed on {{ order.reserve_until }}: it reserves nothing now, but can still become a sale while the stock is free.
        </p>
        <p v-if="order.sale" class="card mb-4 flex flex-wrap items-center gap-1.5 p-4 text-xs font-medium text-ink">
            <PackageOpen :size="15" class="text-success-700" />
            Fulfilled as sale
            <Link v-if="can.viewSales" :href="`/sales/${order.sale.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.sale.reference }}</Link>
            <span v-else class="font-mono font-semibold">{{ order.sale.reference }}</span>
            <span class="text-ink-muted">on {{ order.fulfilled_at }}.</span>
        </p>
        <p v-if="order.cancelled_at" class="card mb-4 p-4 text-xs font-medium text-danger-700">
            Cancelled on {{ order.cancelled_at }}<span v-if="order.cancelled_by"> by {{ order.cancelled_by }}</span>.
            Reason: {{ order.cancel_reason }}
        </p>
        <div v-if="order.status === 'pending' && short.length" class="card mb-4 p-4 text-xs font-medium text-warning-700">
            <p class="flex items-center gap-1.5"><TriangleAlert :size="14" /> Not enough free stock to approve it now:</p>
            <ul class="mt-1 ml-5 list-disc">
                <li v-for="line in short" :key="line.id">{{ line.name }}: ordered {{ line.quantity_label }}, free {{ count(line.free) }} {{ line.unit }}</li>
            </ul>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <p class="font-mono text-sm font-semibold text-ink">{{ order.reference }}</p>
                    <ActionBadge :label="order.status_label" :tone="order.status_tone" />
                </div>
                <dl class="mt-5 space-y-3">
                    <div v-for="detail in details" :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>
                <p v-if="order.quotation" class="mt-4 text-xs text-ink-subtle">
                    From quotation
                    <Link v-if="can.viewQuotations" :href="`/quotations/${order.quotation.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.quotation.reference }}</Link>
                    <span v-else class="font-mono">{{ order.quotation.reference }}</span>.
                </p>
                <dl class="mt-6 space-y-2 rounded-field bg-surface-sunken p-4">
                    <div v-if="Number(order.discount) > 0" class="flex items-center justify-between">
                        <dt class="text-xs text-ink-muted">Discount</dt>
                        <dd class="tabular-nums text-sm text-ink">−{{ money(order.discount) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <dt class="text-xs font-semibold text-ink">Total</dt>
                        <dd class="text-2xl font-bold tabular-nums text-ink">{{ money(order.total_amount) }}</dd>
                    </div>
                </dl>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_8rem_7rem_8rem_8rem] md:gap-3">
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Free now</span>
                        <span class="text-right">Unit price</span>
                        <span class="text-right">Total</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div v-for="line in order.lines" :key="line.id" class="grid gap-1.5 p-4 md:grid-cols-[1fr_8rem_7rem_8rem_8rem] md:items-center md:gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink md:text-right">{{ line.quantity_label }}</span>
                            <span class="tabular-nums text-sm md:text-right" :class="line.short && order.status === 'pending' ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                {{ count(line.free) }} {{ line.unit }}
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">{{ money(line.unit_price) }} / {{ line.unit_code }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">{{ money(line.line_total) }}</span>
                        </div>
                    </div>
                </section>
                <section v-if="order.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ order.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
