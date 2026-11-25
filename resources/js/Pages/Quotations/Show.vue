<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Ban, Copy, PackageOpen, Printer, Receipt, TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import PrintLetterhead from '../../Components/PrintLetterhead.vue'
import PrintFooter from '../../Components/PrintFooter.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { count, useMoney } from '../../lib/money'
import { cancelQuotation } from '../../lib/quotations'

/**
 * One quotation, laid out as the quotation handed to the customer.
 *
 * Built to be printed: the app's sidebar and top bar stay off the paper
 * (`print:` classes in AppLayout), and so do the controls and the stock
 * notes. It says in the component, not a setting, that it is not an
 * official receipt or an invoice. Read from the same `detail()` payload as
 * the dialog on the list.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    quotation: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()
const page = usePage()
const printing = computed(() => page.props.printing ?? {})

usePrintLayout()

const vatRate = computed(() => Number(props.quotation.vat_rate ?? 0))
const short = computed(() => props.quotation.lines.filter((line) => line.short))

function printQuotation() {
    window.print()
}
</script>

<template>
    <div>
        <PageHeader
            class="print:hidden"
            :heading="quotation.reference"
            :subheading="`Quotation for ${quotation.customer?.name ?? 'a customer'}`"
            back-href="/quotations"
            back-label="Back to quotations"
        >
            <template #actions>
                <PrimaryButton v-if="can.cancel" variant="danger" @click="cancelQuotation(quotation)">
                    <Ban :size="15" />
                    Cancel
                </PrimaryButton>
                <PrimaryButton v-if="can.requote" variant="secondary" :href="`/quotations/create?from=${quotation.id}`">
                    <Copy :size="15" />
                    Quote again
                </PrimaryButton>
                <PrimaryButton v-if="can.order" variant="secondary" :href="`/sales-orders/create?quotation=${quotation.id}`">
                    <PackageOpen :size="15" />
                    Make an order
                </PrimaryButton>
                <PrimaryButton v-if="can.sell" variant="secondary" :href="`/sales/create?quotation=${quotation.id}`">
                    <Receipt :size="15" />
                    Make a sale
                </PrimaryButton>
                <PrimaryButton @click="printQuotation">
                    <Printer :size="15" />
                    Print
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
            <ActionBadge :label="quotation.status_label" :tone="quotation.status_tone" />
            <span class="text-xs text-ink-subtle">
                Made {{ quotation.prepared_at }}<template v-if="quotation.prepared_by"> by {{ quotation.prepared_by }}</template>.
            </span>
            <span v-if="quotation.order" class="text-xs text-ink-subtle">
                Became order
                <Link :href="`/sales-orders/${quotation.order.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ quotation.order.reference }}</Link>
                on {{ quotation.accepted_at }}.
            </span>
            <span v-if="quotation.sale" class="text-xs text-ink-subtle">
                Became sale
                <Link v-if="can.viewSales" :href="`/sales/${quotation.sale.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ quotation.sale.reference }}</Link>
                <span v-else class="font-mono">{{ quotation.sale.reference }}</span>
                ({{ quotation.sale.status_label }}) on {{ quotation.accepted_at }}.
            </span>
        </div>

        <p v-if="quotation.status === 'expired'" class="card mb-4 p-4 text-xs font-medium text-warning-700 print:hidden">
            This quotation expired on {{ quotation.valid_until }}. Prices may have moved: quote it again to make a sale.
        </p>
        <p v-if="quotation.cancelled_at" class="card mb-4 p-4 text-xs font-medium text-danger-700 print:hidden">
            Cancelled on {{ quotation.cancelled_at }}<span v-if="quotation.cancelled_by"> by {{ quotation.cancelled_by }}</span>.
            Reason: {{ quotation.cancel_reason }}
        </p>
        <div v-if="quotation.status === 'open' && short.length" class="card mb-4 p-4 text-xs font-medium text-warning-700 print:hidden">
            <p class="flex items-center gap-1.5"><TriangleAlert :size="14" /> Not enough on the shelf now — a quotation reserves nothing:</p>
            <ul class="mt-1 ml-5 list-disc">
                <li v-for="line in short" :key="line.id">{{ line.name }}: quoted {{ line.quantity_label }}, on hand {{ count(line.on_hand) }} {{ line.unit }}</li>
            </ul>
        </div>

        <article class="card p-6 sm:p-8 print:rounded-none print:border-0 print:p-0 print:shadow-none">
            <PrintLetterhead title="Quotation">
                <p class="font-mono text-sm font-semibold text-ink">{{ quotation.reference }}</p>
                <p v-if="quotation.status === 'cancelled'" class="mt-1 text-xs font-bold text-danger-700 uppercase">Cancelled</p>
            </PrintLetterhead>

            <div class="grid gap-6 border-b border-line py-6 sm:grid-cols-2 print:grid-cols-2">
                <div>
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Quoted to</p>
                    <p class="mt-1 text-sm font-semibold text-ink">{{ quotation.customer?.name }}</p>
                    <p v-if="quotation.attention" class="text-xs text-ink-muted">Attention: {{ quotation.attention }}</p>
                    <p v-if="quotation.customer?.address" class="text-xs whitespace-pre-line text-ink-muted">{{ quotation.customer.address }}</p>
                    <p v-if="quotation.customer?.tin" class="text-xs text-ink-muted">TIN {{ quotation.customer.tin }}</p>
                    <p v-if="quotation.customer?.contact_person || quotation.customer?.phone" class="text-xs text-ink-muted">
                        {{ [quotation.customer.contact_person, quotation.customer.phone].filter(Boolean).join(' · ') }}
                    </p>
                </div>
                <dl class="grid grid-cols-2 gap-3 self-start">
                    <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                        <dt class="text-2xs text-ink-muted">Date</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-ink">{{ quotation.quote_date }}</dd>
                    </div>
                    <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                        <dt class="text-2xs text-ink-muted">Valid until</dt>
                        <dd class="mt-0.5 text-sm font-semibold" :class="quotation.status === 'expired' ? 'text-warning-700 print:text-ink' : 'text-ink'">
                            {{ quotation.valid_until }}
                        </dd>
                    </div>
                </dl>
            </div>

            <table class="mt-6 w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        <th class="py-2 pr-3 font-semibold">Product</th>
                        <th class="py-2 pr-3 text-right font-semibold">Qty</th>
                        <th class="hidden py-2 pr-3 text-right font-semibold sm:table-cell print:table-cell">
                            Unit price<template v-if="vatRate > 0"> (incl. VAT)</template>
                        </th>
                        <th class="py-2 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="line in quotation.lines" :key="line.id">
                        <td class="py-2 pr-3">
                            <p class="text-ink">{{ line.name }}</p>
                            <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                        </td>
                        <td class="py-2 pr-3 text-right tabular-nums whitespace-nowrap text-ink">{{ line.quantity_label }}</td>
                        <td class="hidden py-2 pr-3 text-right tabular-nums whitespace-nowrap text-ink-muted sm:table-cell print:table-cell">
                            {{ money(line.unit_price) }} / {{ line.unit_code }}
                        </td>
                        <td class="py-2 text-right tabular-nums text-ink">{{ money(line.line_total) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-line">
                        <td colspan="2" class="pt-3 pr-3 text-xs text-ink-muted sm:hidden print:hidden">Subtotal</td>
                        <td colspan="3" class="hidden pt-3 pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">Subtotal</td>
                        <td class="pt-3 text-right tabular-nums text-ink-muted">{{ money(quotation.subtotal) }}</td>
                    </tr>
                    <tr v-if="Number(quotation.discount) > 0">
                        <td colspan="2" class="pr-3 text-xs text-ink-muted sm:hidden print:hidden">Discount</td>
                        <td colspan="3" class="hidden pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">Discount</td>
                        <td class="text-right tabular-nums text-ink-muted">−{{ money(quotation.discount) }}</td>
                    </tr>
                    <template v-if="vatRate > 0">
                        <tr>
                            <td colspan="2" class="pr-3 text-xs text-ink-muted sm:hidden print:hidden">Net of VAT</td>
                            <td colspan="3" class="hidden pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">Net of VAT</td>
                            <td class="text-right tabular-nums text-ink-muted">{{ money(quotation.net_amount) }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" class="pr-3 text-xs text-ink-muted sm:hidden print:hidden">VAT ({{ vatRate }}%)</td>
                            <td colspan="3" class="hidden pr-3 text-right text-xs text-ink-muted sm:table-cell print:table-cell">VAT ({{ vatRate }}%)</td>
                            <td class="text-right tabular-nums text-ink-muted">{{ money(quotation.vat_amount) }}</td>
                        </tr>
                    </template>
                    <tr class="border-t-2 border-ink/20">
                        <td colspan="2" class="py-3 pr-3 text-sm font-semibold text-ink sm:hidden print:hidden">Total</td>
                        <td colspan="3" class="hidden py-3 pr-3 text-right text-sm font-semibold text-ink sm:table-cell print:table-cell">Total</td>
                        <td class="py-3 text-right tabular-nums text-base font-bold text-ink">{{ money(quotation.total_amount) }}</td>
                    </tr>
                </tfoot>
            </table>

            <section v-if="quotation.notes" class="mt-6 break-inside-avoid">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Notes</p>
                <p class="mt-1 text-sm whitespace-pre-line text-ink">{{ quotation.notes }}</p>
            </section>

            <footer class="mt-8 border-t border-line pt-4 text-2xs text-ink-subtle">
                <p>Prices hold until {{ quotation.valid_until }}. Stock is not set aside for this quotation and is confirmed when the sale is made.</p>
                <p class="mt-1">Prepared by {{ quotation.prepared_by ?? '—' }}.</p>
                <p class="mt-1 font-semibold text-ink-muted">This is a quotation, not an official receipt or an invoice.</p>
            </footer>

            <PrintFooter :terms="printing.quotation_terms" />
        </article>
    </div>
</template>
