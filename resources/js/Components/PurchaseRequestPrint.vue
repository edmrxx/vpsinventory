<script setup>
import { computed } from 'vue'
import PrintLetterhead from './PrintLetterhead.vue'
import PrintFooter from './PrintFooter.vue'
import { useMoney } from '../lib/money'

/**
 * A purchase request on paper (phase 24.1), in one of two forms:
 *
 * - **request** — the request itself, for the department's file and for
 *   whoever approves it: what is needed, why, the estimate, and the two
 *   signatures. One not yet approved says so across the top.
 * - **rfq** — a Request for Quotation to hand a supplier: the same items and
 *   quantities with **empty price columns** for the supplier to fill in. It
 *   never shows what we expect to pay — a supplier who saw our estimate
 *   would quote up to it. Only an approved request prints one.
 *
 * Drawn from the request's own `detail()` payload, and printed through the
 * shared letterhead and footer, so Settings → Print Layouts reaches it.
 */
const props = defineProps({
    request: { type: Object, required: true },
    /** 'request' | 'rfq' */
    mode: { type: String, default: 'request' },
})

const { money } = useMoney()

const isRfq = computed(() => props.mode === 'rfq')

/** Said across the top of a request nobody has approved yet. */
const stamp = computed(() => ({
    pending: 'Waiting for approval',
    rejected: 'Rejected',
    closed: 'Closed',
}[props.request.status] ?? null))

const printedOn = new Date().toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' })
</script>

<template>
    <article class="text-ink">
        <PrintLetterhead :title="isRfq ? 'Request for quotation' : 'Purchase request'">
            <p class="font-mono text-sm font-semibold">{{ request.reference }}</p>
            <p class="text-xs text-ink-muted">{{ isRfq ? `Date ${printedOn}` : `Raised ${request.request_date}` }}</p>
            <p v-if="!isRfq && stamp" class="mt-1 text-xs font-bold text-danger-700 uppercase">{{ stamp }}</p>
        </PrintLetterhead>

        <!-- The request -->
        <template v-if="!isRfq">
            <div class="grid grid-cols-2 gap-6 border-b border-line py-5 text-sm">
                <dl class="space-y-1.5">
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-ink-muted">Department</dt><dd class="font-semibold">{{ request.department ?? '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-ink-muted">Raised by</dt><dd>{{ request.requested_by ?? '—' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-ink-muted">Needed by</dt><dd>{{ request.needed_by ?? 'Not said' }}</dd></div>
                    <div class="flex gap-2"><dt class="w-28 shrink-0 text-ink-muted">Supplier</dt><dd>{{ request.supplier ?? 'Any' }}</dd></div>
                </dl>
                <div>
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Why it is needed</p>
                    <p class="mt-1 whitespace-pre-line">{{ request.reason }}</p>
                </div>
            </div>
        </template>

        <!-- The request for quotation -->
        <template v-else>
            <div class="grid grid-cols-2 gap-6 border-b border-line py-5 text-sm">
                <div>
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">To (supplier)</p>
                    <p class="mt-6 border-b border-ink/40"></p>
                    <p class="mt-5 border-b border-ink/40"></p>
                </div>
                <div class="text-sm">
                    <p>
                        Please quote your best price for the items below and return this form to us<template v-if="request.needed_by">
                        by <span class="font-semibold">{{ request.needed_by }}</span></template>.
                    </p>
                    <p class="mt-2 text-xs text-ink-muted">Prices are understood to include VAT. Leave an item blank if you do not carry it.</p>
                    <p v-if="request.department" class="mt-2 text-xs text-ink-muted">For our {{ request.department }} department.</p>
                </div>
            </div>
        </template>

        <table class="mt-5 w-full text-sm">
            <thead>
                <tr class="border-b border-line text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    <th class="w-8 py-2 pr-2 font-semibold">#</th>
                    <th class="py-2 pr-3 font-semibold">Item</th>
                    <th class="py-2 pr-3 text-right font-semibold">Qty</th>
                    <th class="py-2 pr-3 text-right font-semibold">{{ isRfq ? 'Unit price' : 'Est. unit cost' }}</th>
                    <th class="py-2 text-right font-semibold">{{ isRfq ? 'Amount' : 'Estimate' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(line, index) in request.lines" :key="line.id" class="break-inside-avoid border-b border-line">
                    <td class="py-2.5 pr-2 align-top text-ink-muted">{{ index + 1 }}</td>
                    <td class="py-2.5 pr-3 align-top">
                        <p class="font-medium">{{ line.name }}</p>
                        <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                    </td>
                    <td class="py-2.5 pr-3 text-right align-top tabular-nums whitespace-nowrap">{{ line.quantity_label }}</td>
                    <template v-if="isRfq">
                        <!-- Left empty for the supplier to write in -->
                        <td class="w-36 py-2.5 pr-3 align-bottom"><span class="block border-b border-ink/40">&nbsp;</span></td>
                        <td class="w-36 py-2.5 align-bottom"><span class="block border-b border-ink/40">&nbsp;</span></td>
                    </template>
                    <template v-else>
                        <td class="py-2.5 pr-3 text-right align-top tabular-nums whitespace-nowrap">{{ money(line.unit_cost) }} / {{ line.unit_code }}</td>
                        <td class="py-2.5 text-right align-top font-semibold tabular-nums">{{ money(line.line_total) }}</td>
                    </template>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="pt-3 pr-3 text-right text-xs font-semibold">{{ isRfq ? 'Total' : 'Estimated total' }}</td>
                    <td class="pt-3 text-right">
                        <span v-if="isRfq" class="block border-b border-ink/40">&nbsp;</span>
                        <span v-else class="text-base font-bold tabular-nums">{{ money(request.estimated_total) }}</span>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- What the supplier adds, then signs -->
        <section v-if="isRfq" class="mt-8 grid grid-cols-3 gap-6 text-xs break-inside-avoid">
            <div><p class="text-ink-muted">Delivery within (days)</p><p class="mt-5 border-b border-ink/40"></p></div>
            <div><p class="text-ink-muted">Payment terms</p><p class="mt-5 border-b border-ink/40"></p></div>
            <div><p class="text-ink-muted">Prices valid until</p><p class="mt-5 border-b border-ink/40"></p></div>
        </section>

        <section v-if="!isRfq && request.notes" class="mt-6 break-inside-avoid">
            <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Notes</p>
            <p class="mt-1 text-sm whitespace-pre-line">{{ request.notes }}</p>
        </section>

        <footer class="mt-10 grid grid-cols-2 gap-8 border-t border-line pt-6 text-xs text-ink-muted break-inside-avoid">
            <template v-if="isRfq">
                <div>
                    <p>Quoted by (name and signature)</p>
                    <p class="mt-8 border-t border-ink/30 pt-1">&nbsp;</p>
                </div>
                <div>
                    <p>Date</p>
                    <p class="mt-8 border-t border-ink/30 pt-1">&nbsp;</p>
                </div>
            </template>
            <template v-else>
                <div>
                    <p>Requested by</p>
                    <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ request.requested_by ?? ' ' }}</p>
                </div>
                <div>
                    <p>Approved by</p>
                    <p class="mt-8 border-t border-ink/30 pt-1 font-semibold text-ink">{{ request.status !== 'rejected' && request.decided_by ? request.decided_by : ' ' }}</p>
                </div>
            </template>
        </footer>

        <PrintFooter />
    </article>
</template>
