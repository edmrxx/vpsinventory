<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Banknote, FileText, ListChecks, Printer, TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import EmptyPanel from '../../Components/EmptyPanel.vue'
import PrintLetterhead from '../../Components/PrintLetterhead.vue'
import PrintFooter from '../../Components/PrintFooter.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { useMoney } from '../../lib/money'

/**
 * Every balance, split by how far past due, as of today.
 *
 * One row per customer who owes anything. The figures come from
 * `CustomerCredit::aging()`, which the statement and the dashboard's Overdue
 * card read too, so the three cannot disagree. Read-only, like a report.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    asOf: { type: String, required: true },
    aging: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

/** Behind first, or everybody by name. */
const onlyOverdue = ref(false)

const rows = computed(() =>
    onlyOverdue.value ? props.aging.rows.filter((row) => row.overdue) : props.aging.rows
)

const pastDue = computed(() => props.aging.buckets.filter((bucket) => bucket.key !== 'current'))

const overdueTotal = computed(() =>
    pastDue.value.reduce((sum, bucket) => sum + Number(props.aging.totals[bucket.key] ?? 0), 0)
)

function printReport() {
    window.print()
}

usePrintLayout()

const printedAt = new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
</script>

<template>
    <div>
        <PageHeader
            class="print:hidden"
            heading="Aging"
            :subheading="`What every customer owes, by how far past due. As of ${asOf}.`"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/collectibles">
                    <ListChecks :size="15" />
                    Every invoice
                </PrimaryButton>
                <PrimaryButton variant="secondary" @click="printReport">
                    <Printer :size="15" />
                    Print
                </PrimaryButton>
            </template>
        </PageHeader>

        <!-- Only on paper: whose figures these are, and as of when -->
        <div class="mb-6 hidden print:block">
            <PrintLetterhead title="Aging of receivables">
                <p class="text-xs text-ink-muted">As of {{ asOf }}</p>
                <p class="text-2xs text-ink-subtle">Printed {{ printedAt }}</p>
            </PrintLetterhead>
        </div>

        <!-- The totals -->
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="bucket in aging.buckets" :key="bucket.key" class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">{{ bucket.label }}</p>
                <p class="mt-1 tabular-nums text-lg font-bold"
                    :class="bucket.key !== 'current' && Number(aging.totals[bucket.key]) > 0 ? 'text-warning-700' : 'text-ink'">
                    {{ money(aging.totals[bucket.key]) }}
                </p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Total owed</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ money(aging.totals.total) }}</p>
                <p class="text-2xs text-ink-subtle">{{ money(overdueTotal) }} past due</p>
            </div>
        </div>

        <EmptyPanel
            v-if="!aging.rows.length"
            heading="Nobody owes anything"
            text="When a sale goes on a customer's account, it appears here until it is paid."
        />

        <section v-else class="card overflow-hidden print:rounded-none print:border-0 print:shadow-none">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3 print:hidden">
                <p class="text-xs text-ink-subtle">{{ rows.length }} of {{ aging.rows.length }} customers</p>
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-ink">
                    <input v-model="onlyOverdue" type="checkbox" class="size-4 accent-brand-600" />
                    Only customers past due
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-sm">
                    <thead>
                        <tr class="border-b border-line bg-surface-muted text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                            <th class="px-5 py-2.5 font-semibold">Customer</th>
                            <th v-for="bucket in aging.buckets" :key="bucket.key" class="px-3 py-2.5 text-right font-semibold">
                                {{ bucket.label }}
                            </th>
                            <th class="px-3 py-2.5 text-right font-semibold">Total</th>
                            <th class="px-5 py-2.5 print:hidden"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.id" class="transition-colors hover:bg-surface-sunken">
                            <td class="px-5 py-2.5">
                                <Link :href="`/customers/${row.id}`" class="font-medium text-ink hover:underline">{{ row.name }}</Link>
                                <p class="text-2xs text-ink-subtle">
                                    {{ row.invoices }} invoice{{ row.invoices === 1 ? '' : 's' }} · limit {{ money(row.limit) }}
                                </p>
                                <p v-if="row.overdue || row.over_limit" class="mt-1 flex flex-wrap gap-1">
                                    <span v-if="row.overdue"
                                        class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-px text-2xs font-semibold text-warning-700">
                                        <TriangleAlert :size="11" /> Overdue
                                    </span>
                                    <span v-if="row.over_limit"
                                        class="inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-px text-2xs font-semibold text-danger-700">
                                        <TriangleAlert :size="11" /> Over limit
                                    </span>
                                </p>
                            </td>
                            <td v-for="bucket in aging.buckets" :key="bucket.key"
                                class="px-3 py-2.5 text-right tabular-nums"
                                :class="Number(row[bucket.key]) === 0 ? 'text-ink-subtle' : bucket.key === 'current' ? 'text-ink' : 'font-semibold text-warning-700'">
                                {{ Number(row[bucket.key]) === 0 ? '—' : money(row[bucket.key]) }}
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums font-bold text-ink">{{ money(row.total) }}</td>
                            <td class="px-5 py-2.5 print:hidden">
                                <div class="flex items-center justify-end gap-3">
                                    <Link :href="`/customers/${row.id}/statement`"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
                                        <FileText :size="13" /> Statement
                                    </Link>
                                    <Link v-if="can.pay" :href="`/customer-payments/create?customer=${row.id}`"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
                                        <Banknote :size="13" /> Payment
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-ink/20 font-semibold">
                            <td class="px-5 py-3 text-ink">Total</td>
                            <td v-for="bucket in aging.buckets" :key="bucket.key" class="px-3 py-3 text-right tabular-nums text-ink">
                                {{ money(aging.totals[bucket.key]) }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums font-bold text-ink">{{ money(aging.totals.total) }}</td>
                            <td class="print:hidden"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <p class="mt-4 text-xs text-ink-subtle">
            "Not yet due" includes what falls due today. An invoice is past due from the day after its due date.
            Voided sales count for nothing; returns and customer payments are already taken off.
        </p>

        <PrintFooter class="hidden print:block" />
    </div>
</template>
