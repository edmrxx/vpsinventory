<script setup>
import PrintLetterhead from './PrintLetterhead.vue'
import PrintFooter from './PrintFooter.vue'
import { computed } from 'vue'
import { useMoney } from '../lib/money'

/**
 * A purchase order on paper: the order sent to the supplier (phase 22).
 *
 * Until it is approved (phase 24.4) — waiting, or rejected — it carries a
 * large *DRAFT — NOT APPROVED* across the page and a blank "Approved by"
 * line, so it cannot pass for an order (Q7). The mark is read off
 * `order.draft`, which the server works out from the status.
 *
 * Drawn from the order's own `detail()` payload.
 */
const props = defineProps({
    order: { type: Object, required: true },
    terms: { type: String, default: '' },
})

const { money } = useMoney()

const vatRate = computed(() => Number(props.order.vat_rate ?? 0))
const vatable = computed(() => vatRate.value > 0)
</script>

<template>
<article class="relative card overflow-hidden p-6 sm:p-8 print:overflow-visible print:rounded-none print:border-0 print:p-0 print:shadow-none">
    <!-- Not approved: the paper cannot pass for an order (Q7, phase 24.4) -->
    <div
        v-if="order.draft"
        data-test="draft-mark"
        aria-hidden="true"
        class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center print:fixed"
    >
        <span class="-rotate-30 rounded-lg border-4 border-danger-700/40 px-6 py-3 text-center text-4xl font-black tracking-widest whitespace-nowrap text-danger-700/25 uppercase sm:text-6xl">
            Draft — not approved
        </span>
    </div>

    <!-- Who it is from -->
    <PrintLetterhead title="Purchase order">
        <p class="font-mono text-sm font-semibold text-ink">{{ order.reference }}</p>
        <p v-if="order.draft" class="mt-1 text-xs font-bold text-danger-700 uppercase">
            Draft — not approved<template v-if="order.status === 'rejected'"> · Rejected</template>
        </p>
        <p v-else-if="order.status === 'cancelled'" class="mt-1 text-xs font-bold text-danger-700 uppercase">Cancelled</p>
    </PrintLetterhead>

    <!-- Who it is to, and when -->
    <div class="grid gap-6 border-b border-line py-6 sm:grid-cols-2 print:grid-cols-2">
        <div>
            <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Supplier</p>
            <p class="mt-1 text-sm font-semibold text-ink">{{ order.supplier?.name }}</p>
            <p v-if="order.supplier?.address" class="text-xs whitespace-pre-line text-ink-muted">{{ order.supplier.address }}</p>
            <p v-if="order.supplier?.tin" class="text-xs text-ink-muted">TIN {{ order.supplier.tin }}</p>
            <p v-if="order.supplier?.contact_person || order.supplier?.phone" class="text-xs text-ink-muted">
                {{ [order.supplier.contact_person, order.supplier.phone].filter(Boolean).join(' · ') }}
            </p>
            <p v-if="order.supplier?.email" class="text-xs text-ink-muted">{{ order.supplier.email }}</p>
        </div>
        <dl class="grid grid-cols-2 gap-3 self-start">
            <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                <dt class="text-2xs text-ink-muted">Order date</dt>
                <dd class="mt-0.5 text-sm font-semibold text-ink">{{ order.order_date }}</dd>
            </div>
            <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                <dt class="text-2xs text-ink-muted">Deliver by</dt>
                <dd class="mt-0.5 text-sm font-semibold" :class="order.late ? 'text-warning-700 print:text-ink' : 'text-ink'">
                    {{ order.expected_date ?? 'As soon as possible' }}
                </dd>
            </div>
            <!-- Where to (phase 31.3). Left off an order that names no place,
                 so an old order reprints exactly as it did. -->
            <div
                v-if="order.deliver_to"
                data-test="deliver-to"
                class="col-span-2 rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white"
            >
                <dt class="text-2xs text-ink-muted">Deliver to</dt>
                <dd class="mt-0.5 text-sm font-semibold text-ink">{{ order.deliver_to.name }}</dd>
                <dd v-if="order.deliver_to.address" class="text-xs whitespace-pre-line text-ink-muted">{{ order.deliver_to.address }}</dd>
            </div>
        </dl>
    </div>

    <!-- The lines -->
    <table class="mt-6 w-full text-sm">
        <thead>
            <tr class="border-b border-line text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                <th class="py-2 pr-3 font-semibold">Product</th>
                <th class="py-2 pr-3 text-right font-semibold">Qty</th>
                <th class="hidden py-2 pr-3 text-right font-semibold sm:table-cell print:table-cell">
                    Unit cost<template v-if="vatable"> (incl. VAT)</template>
                </th>
                <th class="py-2 text-right font-semibold">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            <tr v-for="line in order.lines" :key="line.id">
                <td class="py-2 pr-3">
                    <p class="text-ink">{{ line.name }}</p>
                    <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                </td>
                <td class="py-2 pr-3 text-right tabular-nums whitespace-nowrap text-ink">{{ line.quantity_label }}</td>
                <td class="hidden py-2 pr-3 text-right tabular-nums whitespace-nowrap text-ink-muted sm:table-cell print:table-cell">
                    {{ money(line.unit_cost) }} / {{ line.unit_code }}
                </td>
                <td class="py-2 text-right tabular-nums text-ink">{{ money(line.line_total) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <template v-if="vatable">
                <tr class="border-t border-line">
                    <td colspan="2" class="pt-3 pr-3 text-xs text-ink-muted sm:hidden print:hidden">Net of VAT</td>
                    <td colspan="3" class="hidden pt-3 pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">Net of VAT</td>
                    <td class="pt-3 text-right tabular-nums text-ink-muted">{{ money(order.net_amount) }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="pr-3 text-xs text-ink-muted sm:hidden print:hidden">VAT ({{ vatRate }}%)</td>
                    <td colspan="3" class="hidden pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">VAT ({{ vatRate }}%)</td>
                    <td class="text-right tabular-nums text-ink-muted">{{ money(order.vat_amount) }}</td>
                </tr>
            </template>
            <tr class="border-t-2 border-ink/20">
                <td colspan="2" class="py-3 pr-3 text-sm font-semibold text-ink sm:hidden print:hidden">Total</td>
                <td colspan="3" class="hidden py-3 pr-3 text-right text-sm font-semibold text-ink sm:table-cell print:table-cell">Total</td>
                <td class="py-3 text-right tabular-nums text-base font-bold text-ink">{{ money(order.total_amount) }}</td>
            </tr>
        </tfoot>
    </table>

    <section v-if="order.notes" class="mt-6 break-inside-avoid">
        <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Notes</p>
        <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ order.notes }}</p>
    </section>

    <footer class="mt-10 grid gap-8 border-t border-line pt-6 text-xs text-ink-muted sm:grid-cols-2 print:grid-cols-2">
        <div>
            <p>Prepared by</p>
            <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ order.ordered_by ?? '' }}</p>
        </div>
        <div>
            <p>Approved by</p>
            <!-- The order's own approver (phase 24.4); blank on a draft.
                 An order from before then carries its request's approver. -->
            <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ order.approved_by ?? '\u00a0' }}</p>
            <p v-if="order.draft" class="mt-1 text-2xs font-semibold text-danger-700">Not approved. Do not send this order to the supplier.</p>
        </div>
    </footer>

    <PrintFooter :terms="terms" />
</article>
</template>
