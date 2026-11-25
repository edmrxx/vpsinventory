<script setup>
import { computed, reactive } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { Banknote, Printer, TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import PrintLetterhead from '../../Components/PrintLetterhead.vue'
import PrintFooter from '../../Components/PrintFooter.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { useMoney } from '../../lib/money'

/**
 * A customer's statement of account: what was brought forward, every sale on
 * account, return and payment in the period, and the balance after each.
 *
 * Built to be printed and handed over. The app's sidebar and top bar stay off
 * the paper (`print:` classes in AppLayout), and so do the controls here.
 * It says, in the component and not in a setting, that it is not an
 * official receipt.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    customer: { type: Object, required: true },
    range: { type: Object, required: true },
    statement: { type: Object, required: true },
    account: { type: Object, required: true },
    aging: { type: Object, required: true },
    flags: { type: Object, default: () => ({}) },
    printedOn: { type: String, default: '' },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()
const page = usePage()

usePrintLayout()

const period = reactive({ from: props.range.from, to: props.range.to })

function apply() {
    router.get(`/customers/${props.customer.id}/statement`, { from: period.from, to: period.to }, {
        preserveScroll: true,
        preserveState: true,
    })
}

/** This customer's aging row, or nought in every bucket. */
const agingRow = computed(() => props.aging.rows[0] ?? null)

function printStatement() {
    window.print()
}

const kindLabel = { sale: 'Sale', return: 'Return', payment: 'Payment' }

function hrefOf(entry) {
    return { sale: '/sales/', return: '/sales-returns/', payment: '/customer-payments/' }[entry.kind] + entry.id
}
</script>

<template>
    <div>
        <PageHeader
            class="print:hidden"
            heading="Statement of account"
            :subheading="customer.name"
            :back-href="`/customers/${customer.id}`"
            back-label="Back to the customer"
            :crumbs="[
                { label: 'Customers', href: '/customers' },
                { label: customer.name, href: `/customers/${customer.id}` },
            ]"
        >
            <template #actions>
                <PrimaryButton v-if="can.pay" variant="secondary" :href="`/customer-payments/create?customer=${customer.id}`">
                    <Banknote :size="15" />
                    Record payment
                </PrimaryButton>
                <PrimaryButton @click="printStatement">
                    <Printer :size="15" />
                    Print
                </PrimaryButton>
            </template>
        </PageHeader>

        <!-- The period -->
        <div class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden">
            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">From</span>
                <input v-model="period.from" type="date" :max="period.to"
                    class="h-9 rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface" />
            </label>
            <label class="block">
                <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">To</span>
                <input v-model="period.to" type="date" :min="period.from"
                    class="h-9 rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface" />
            </label>
            <PrimaryButton variant="secondary" @click="apply">Show</PrimaryButton>
            <p class="text-xs text-ink-subtle">The aging at the bottom is always as of today.</p>
        </div>

        <article class="card p-6 sm:p-8 print:rounded-none print:border-0 print:p-0 print:shadow-none">
            <!-- Who it is from, and to -->
            <PrintLetterhead title="Statement of account">
                <p class="text-xs text-ink-muted">{{ range.label }}</p>
                <p class="text-2xs text-ink-subtle">Printed {{ printedOn }}</p>
            </PrintLetterhead>

            <div class="grid gap-6 border-b border-line py-6 sm:grid-cols-2">
                <div>
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Customer</p>
                    <p class="mt-1 text-sm font-semibold text-ink">{{ customer.name }}</p>
                    <p class="text-xs text-ink-muted">{{ customer.code }}</p>
                    <p v-if="customer.address" class="text-xs whitespace-pre-line text-ink-muted">{{ customer.address }}</p>
                    <p v-if="customer.tin" class="text-xs text-ink-muted">TIN {{ customer.tin }}</p>
                    <p v-if="customer.contact_person || customer.phone" class="text-xs text-ink-muted">
                        {{ [customer.contact_person, customer.phone].filter(Boolean).join(' · ') }}
                    </p>
                </div>
                <dl class="grid grid-cols-2 gap-3 self-start">
                    <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                        <dt class="text-2xs text-ink-muted">Owes today</dt>
                        <dd class="mt-0.5 tabular-nums text-base font-bold text-ink">{{ money(account.balance) }}</dd>
                    </div>
                    <div class="rounded-field bg-surface-sunken p-3 print:border print:border-line print:bg-white">
                        <dt class="text-2xs text-ink-muted">Credit limit · terms</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-ink">
                            {{ money(account.limit) }} · {{ account.terms_days }} {{ account.terms_days === 1 ? 'day' : 'days' }}
                        </dd>
                    </div>
                    <p v-if="flags.overdue || flags.over_limit" class="col-span-2 flex flex-wrap gap-2">
                        <span v-if="flags.overdue"
                            class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2.5 py-0.5 text-2xs font-semibold text-warning-700">
                            <TriangleAlert :size="12" /> Overdue
                        </span>
                        <span v-if="flags.over_limit"
                            class="inline-flex items-center gap-1 rounded-full bg-danger-50 px-2.5 py-0.5 text-2xs font-semibold text-danger-700">
                            <TriangleAlert :size="12" /> Over limit
                        </span>
                    </p>
                </dl>
            </div>

            <!-- The entries -->
            <table class="mt-6 w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        <th class="py-2 pr-3 font-semibold">Date</th>
                        <th class="py-2 pr-3 font-semibold">Reference</th>
                        <th class="hidden py-2 pr-3 font-semibold sm:table-cell print:table-cell">Details</th>
                        <th class="py-2 pr-3 text-right font-semibold">Charged</th>
                        <th class="py-2 pr-3 text-right font-semibold">Paid / credited</th>
                        <th class="py-2 text-right font-semibold">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr>
                        <td class="py-2 pr-3 text-ink-muted">{{ range.from_label }}</td>
                        <td class="py-2 pr-3 text-ink-muted" colspan="1">—</td>
                        <td class="hidden py-2 pr-3 text-ink-muted sm:table-cell print:table-cell">Balance brought forward</td>
                        <td class="py-2 pr-3"></td>
                        <td class="py-2 pr-3"></td>
                        <td class="py-2 text-right tabular-nums font-semibold text-ink">{{ money(statement.opening) }}</td>
                    </tr>
                    <tr v-for="entry in statement.entries" :key="`${entry.kind}-${entry.id}`">
                        <td class="py-2 pr-3 whitespace-nowrap text-ink-muted">{{ entry.date }}</td>
                        <td class="py-2 pr-3">
                            <Link :href="hrefOf(entry)" class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline print:text-ink print:no-underline">
                                {{ entry.reference }}
                            </Link>
                            <span class="block text-2xs text-ink-subtle sm:hidden print:hidden">{{ kindLabel[entry.kind] }}</span>
                        </td>
                        <td class="hidden py-2 pr-3 text-ink-muted sm:table-cell print:table-cell">{{ entry.description }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums text-ink">{{ entry.debit ? money(entry.debit) : '' }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums text-success-700 print:text-ink">{{ entry.credit ? money(entry.credit) : '' }}</td>
                        <td class="py-2 text-right tabular-nums text-ink">{{ money(entry.balance) }}</td>
                    </tr>
                    <tr v-if="!statement.entries.length">
                        <td colspan="6" class="py-6 text-center text-sm text-ink-subtle">Nothing was charged or paid in this period.</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-ink/20">
                        <td colspan="2" class="py-3 pr-3 text-sm font-semibold text-ink">Closing balance</td>
                        <td class="hidden sm:table-cell print:table-cell"></td>
                        <td class="py-3 pr-3 text-right tabular-nums text-ink-muted">{{ money(statement.charged) }}</td>
                        <td class="py-3 pr-3 text-right tabular-nums text-ink-muted">{{ money(statement.credited) }}</td>
                        <td class="py-3 text-right tabular-nums text-base font-bold text-ink">{{ money(statement.closing) }}</td>
                    </tr>
                </tfoot>
            </table>

            <!-- How far past due, today -->
            <section class="mt-8 break-inside-avoid">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Aging as of today</p>
                <dl class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-6">
                    <div v-for="bucket in aging.buckets" :key="bucket.key"
                        class="rounded-field border border-line p-2.5">
                        <dt class="text-2xs text-ink-muted">{{ bucket.label }}</dt>
                        <dd class="mt-0.5 tabular-nums text-sm font-semibold"
                            :class="bucket.key !== 'current' && Number(agingRow?.[bucket.key] ?? 0) > 0 ? 'text-warning-700' : 'text-ink'">
                            {{ money(agingRow?.[bucket.key] ?? 0) }}
                        </dd>
                    </div>
                    <div class="rounded-field border border-ink/20 p-2.5">
                        <dt class="text-2xs text-ink-muted">Total owed</dt>
                        <dd class="mt-0.5 tabular-nums text-sm font-bold text-ink">{{ money(agingRow?.total ?? 0) }}</dd>
                    </div>
                </dl>
            </section>

            <footer class="mt-8 border-t border-line pt-4 text-2xs text-ink-subtle">
                <p>Please check this statement against your records and tell us of any difference.</p>
                <p class="mt-1 font-semibold text-ink-muted">This is a statement of account, not an official receipt.</p>
            </footer>

            <PrintFooter />
        </article>
    </div>
</template>
