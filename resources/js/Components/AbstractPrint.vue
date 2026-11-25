<script setup>
import { computed } from 'vue'
import PrintLetterhead from './PrintLetterhead.vue'
import PrintFooter from './PrintFooter.vue'
import { useMoney } from '../lib/money'

/**
 * The Abstract of Quotation on paper (phase 24.2): every supplier's price
 * for every item, side by side, the lowest marked with ★, and — once made —
 * the award, with the reason wherever it was not the lowest. Signed by who
 * prepared it and who awarded it.
 *
 * Drawn from the request's own `detail()` payload (`request.canvass`), the
 * same table the screen shows.
 */
const props = defineProps({
    request: { type: Object, required: true },
})

const { money } = useMoney()

const canvass = computed(() => props.request.canvass ?? { suppliers: [], lines: [] })
const awarded = computed(() => Boolean(canvass.value.awarded_at))
const printedOn = new Date().toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' })

const isAwarded = (line, cell) => cell && line.award && Number(line.award.quote_line_id) === Number(cell.quote_line_id)
</script>

<template>
    <article class="text-ink">
        <PrintLetterhead title="Abstract of quotation">
            <p class="font-mono text-sm font-semibold">{{ request.reference }}</p>
            <p class="text-xs text-ink-muted">Printed {{ printedOn }}</p>
            <p v-if="!awarded" class="mt-1 text-xs font-bold text-warning-700 uppercase">Not yet awarded</p>
        </PrintLetterhead>

        <div class="flex flex-wrap gap-x-8 gap-y-1 border-b border-line py-4 text-sm">
            <p><span class="text-ink-muted">Department</span> <span class="font-semibold">{{ request.department ?? '—' }}</span></p>
            <p><span class="text-ink-muted">Raised by</span> {{ request.requested_by ?? '—' }}</p>
            <p><span class="text-ink-muted">Needed by</span> {{ request.needed_by ?? 'Not said' }}</p>
            <p class="basis-full text-xs text-ink-muted">{{ request.reason }}</p>
        </div>

        <table class="mt-4 w-full text-xs">
            <thead>
                <tr class="border-b border-ink/40 text-left align-bottom">
                    <th class="py-2 pr-2 font-semibold">Item</th>
                    <th class="py-2 pr-2 text-right font-semibold">Qty</th>
                    <th v-for="supplier in canvass.suppliers" :key="supplier.quote_id" class="py-2 pl-2 text-right font-semibold">
                        {{ supplier.name }}
                        <span class="block font-normal text-ink-muted">
                            <template v-if="supplier.delivery_days !== null">{{ supplier.delivery_days }} day(s)</template>
                            <template v-if="supplier.delivery_days !== null && supplier.payment_terms"> · </template>
                            <template v-if="supplier.payment_terms">{{ supplier.payment_terms }}</template>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <template v-for="line in canvass.lines" :key="line.line_id">
                    <tr class="break-inside-avoid border-b border-line">
                        <td class="py-2 pr-2 align-top">
                            <p class="font-medium">{{ line.name }}</p>
                            <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                        </td>
                        <td class="py-2 pr-2 text-right align-top whitespace-nowrap">{{ line.quantity_label }}</td>
                        <td v-for="(cell, column) in line.cells" :key="column" class="py-2 pl-2 text-right align-top"
                            :class="isAwarded(line, cell) ? 'font-bold' : ''">
                            <template v-if="cell">
                                <span class="tabular-nums">{{ cell.lowest ? '★ ' : '' }}{{ money(cell.unit_price) }}</span>
                                <span class="block tabular-nums text-ink-muted">{{ money(cell.line_total) }}</span>
                                <span v-if="isAwarded(line, cell)" class="block text-2xs uppercase">Awarded</span>
                            </template>
                            <span v-else class="text-ink-subtle">—</span>
                        </td>
                    </tr>
                    <tr v-if="line.award?.reason" class="break-inside-avoid">
                        <td :colspan="2 + canvass.suppliers.length" class="pb-2 text-2xs text-ink-muted">
                            Not the lowest — awarded to {{ line.award.supplier }}: {{ line.award.reason }}
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot>
                <tr class="border-t border-ink/40">
                    <td colspan="2" class="py-2 pr-2 font-semibold">Total</td>
                    <td v-for="supplier in canvass.suppliers" :key="supplier.quote_id" class="py-2 pl-2 text-right">
                        <span class="font-semibold tabular-nums">{{ money(supplier.total) }}</span>
                        <span class="block text-2xs text-ink-muted">{{ supplier.items_quoted }} of {{ canvass.lines.length }} item(s)</span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <p class="mt-2 text-2xs text-ink-muted">★ the lowest price on the item. Prices are per unit, VAT included.</p>

        <p v-if="awarded" class="mt-4 text-sm">
            Award total: <span class="font-bold tabular-nums">{{ money(canvass.awarded_total) }}</span>
            <span class="text-ink-muted"> · lowest on every item: {{ money(canvass.lowest_total) }}</span>
        </p>

        <footer class="mt-10 grid grid-cols-2 gap-8 border-t border-line pt-6 text-xs text-ink-muted break-inside-avoid">
            <div>
                <p>Prepared by</p>
                <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ request.requested_by ?? ' ' }}</p>
            </div>
            <div>
                <p>Awarded by</p>
                <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ canvass.awarded_by ?? ' ' }}</p>
            </div>
        </footer>

        <PrintFooter />
    </article>
</template>
