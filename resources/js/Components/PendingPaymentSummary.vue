<script setup>
import { Link } from '@inertiajs/vue3'
import { Ban, Banknote, ExternalLink, Printer, Receipt } from '@lucide/vue'
import ActionBadge from './ActionBadge.vue'
import { count, useMoney } from '../lib/money'
import { printOrderSlip } from '../lib/slip'

/**
 * One order the kiosk sent to the cashier (CR-2), as its page and its
 * dialog both show it: who it is for, what it holds, what it comes to, and
 * what can still be done — receive the money here, take it at the till,
 * print the slip, or cancel it.
 */
const props = defineProps({
    /** The `pending` of the detail payload. */
    order: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    /** In the dialog, a link to the full page. */
    withFullPage: { type: Boolean, default: false },
})

defineEmits(['cancel', 'receive'])

const { money } = useMoney()

/** The till at the order's store, opened on this order to take its money. */
const tillHref = () => `/pos?location=${props.order.location_id}&pending=${props.order.id}`
</script>

<template>
    <div class="space-y-5" data-pending-summary>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2.5">
                    <h3 class="font-mono text-base font-bold text-ink">{{ order.reference }}</h3>
                    <ActionBadge :label="order.status_label" :tone="order.status_tone" />
                </div>
                <p class="text-xs text-ink-subtle">
                    Sent {{ order.sent_at }}<template v-if="order.taken_by"> by {{ order.taken_by }}</template>
                </p>
            </div>

            <Link
                v-if="withFullPage"
                :href="`/pending-payments/${order.id}`"
                class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
            >
                <ExternalLink :size="14" />
                Open full page
            </Link>
        </div>

        <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div
                v-for="detail in [
                    { label: 'Customer', value: order.customer ?? '—' },
                    { label: 'Pay at', value: order.location ?? '—' },
                    { label: 'Held until', value: order.status === 'pending' || order.status === 'expired' ? `closing, ${order.hold_until}` : '—' },
                    { label: 'Note', value: order.note ?? '—' },
                ]"
                :key="detail.label"
                class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
            >
                <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
            </div>
        </dl>

        <div class="overflow-hidden rounded-field border border-line">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Item</th>
                        <th class="px-3 py-2 text-right">Qty</th>
                        <th class="px-3 py-2 text-right">Price</th>
                        <th class="px-3 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="line in order.lines" :key="line.id">
                        <td class="px-3 py-2">
                            <span class="block text-ink">{{ line.name }}</span>
                            <span class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ count(line.quantity) }} {{ line.unit_code }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ money(line.unit_price) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-medium">{{ money(line.line_total) }}</td>
                    </tr>
                </tbody>
            </table>

            <dl class="space-y-1 border-t border-line bg-surface-muted px-3 py-2.5 text-sm">
                <div v-if="Number(order.discount) > 0" class="flex justify-between">
                    <dt class="text-ink-muted">Discount</dt>
                    <dd class="tabular-nums text-ink-muted">−{{ money(order.discount) }}</dd>
                </div>
                <div class="flex justify-between text-base font-bold">
                    <dt class="text-ink">To pay</dt>
                    <dd class="tabular-nums text-ink">{{ money(order.total_amount) }}</dd>
                </div>
            </dl>
        </div>

        <p v-if="order.sale" class="text-sm text-ink-muted">
            Paid {{ order.paid_at }} — it became sale
            <Link :href="`/sales/${order.sale.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.sale.reference }}</Link>.
        </p>
        <p v-if="order.cancelled_at" class="text-sm text-ink-muted">
            Cancelled {{ order.cancelled_at }}<template v-if="order.cancelled_by"> by {{ order.cancelled_by }}</template>:
            {{ order.cancel_reason }}
        </p>
        <p v-if="order.status === 'expired'" class="text-sm text-ink-muted">
            Not paid by closing, so it holds nothing any more and cannot be paid. Send it again from the kiosk.
        </p>

        <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
            <button
                v-if="order.status === 'pending'"
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                @click="printOrderSlip(order)"
            >
                <Printer :size="15" />
                Print slip
            </button>
            <button
                v-if="can.cancel"
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-danger-700 hover:bg-danger-50"
                @click="$emit('cancel', order)"
            >
                <Ban :size="15" />
                Cancel
            </button>
            <Link
                v-if="can.pay"
                :href="tillHref()"
                class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
            >
                <Receipt :size="15" />
                Open at the till
            </Link>
            <!-- Take the money right here (the user's GO! of 24 November 2026). -->
            <button
                v-if="can.receive"
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-field bg-success-500 px-3 text-sm font-semibold text-white hover:bg-success-700"
                data-receive
                @click="$emit('receive', order)"
            >
                <Banknote :size="15" />
                Receive payment
            </button>
        </div>
    </div>
</template>
