<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Banknote, FileText, FilterX } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import TableAction from '../../Components/TableAction.vue'
import { useMoney } from '../../lib/money'

/**
 * Every invoice still owed, one row each: what the business has to collect.
 *
 * Filtered by the month it falls due (or was sold), the customer, whether it
 * is past due, and who rang it up. The figures come from `CustomerCredit`,
 * the same place Aging and the statements read, so they cannot disagree.
 * The cards above the table add up the same filtered rows, not just the page.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    asOf: { type: String, required: true },
    invoices: { type: Object, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Invoice', sortable: true, width: '150px' },
    { key: 'customer', label: 'Customer' },
    { key: 'sale_date', label: 'Sold', sortable: true, width: '115px', hideOnMobile: true },
    { key: 'due_date', label: 'Due', sortable: true, width: '140px' },
    { key: 'on_account', label: 'On account', align: 'right', width: '120px', hideOnMobile: true },
    { key: 'settled', label: 'Paid / returned', align: 'right', width: '130px', hideOnMobile: true },
    { key: 'balance_due', label: 'Still owed', sortable: true, align: 'right', width: '130px' },
    { key: 'actions', label: '', align: 'right', width: '110px' },
]

const keys = ['customer_id', 'month', 'by', 'status', 'posted_by']

/** "Falls due in" is the default, so it is kept as empty: only "sold" is sent. */
const stateOf = (filters, key) => (key === 'by' ? (filters.by === 'sold' ? 'sold' : '') : (filters[key] ?? ''))

const filterState = reactive(Object.fromEntries(keys.map((key) => [key, stateOf(props.filters, key)])))

watch(
    () => props.filters,
    (incoming) => keys.forEach((key) => (filterState[key] = stateOf(incoming, key)))
)

const extraQuery = computed(() => ({
    customer_id: filterState.customer_id || undefined,
    month: filterState.month || undefined,
    // Only meaningful with a month picked; "due" is the default.
    by: filterState.month && filterState.by === 'sold' ? 'sold' : undefined,
    status: filterState.status || undefined,
    posted_by: filterState.posted_by || undefined,
}))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    keys.forEach((key) => (filterState[key] = ''))
}

const statusOptions = [
    { value: 'overdue', label: 'Past due' },
    { value: 'current', label: 'Not yet due' },
]

const byOptions = [{ value: 'sold', label: 'Was sold in' }]

/** The sale open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Collectibles"
            :subheading="`Every invoice a customer still owes, as of ${asOf}.`"
        />

        <!-- What the filtered list adds up to -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">To collect</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ money(totals.total) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Past due</p>
                <p class="mt-1 tabular-nums text-lg font-bold" :class="Number(totals.overdue) > 0 ? 'text-warning-700' : 'text-ink'">
                    {{ money(totals.overdue) }}
                </p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Invoices</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ totals.invoices }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Customers</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ totals.customers }}</p>
            </div>
        </div>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">Month</span>
                    <input
                        v-model="filterState.month"
                        type="month"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <FilterSelect
                    v-if="filterState.month"
                    v-model="filterState.by"
                    label="The month is when it"
                    any-label="Falls due in"
                    :options="byOptions"
                />
                <FilterSelect v-model="filterState.customer_id" label="Customer" any-label="All customers" :options="options.customers ?? []" />
                <FilterSelect v-model="filterState.status" label="Status" any-label="Past due and not yet due" :options="statusOptions" />
                <FilterSelect v-model="filterState.posted_by" label="Sold by" any-label="All staff" :options="options.staff ?? []" />
            </div>

            <button
                v-if="hasFilters"
                type="button"
                class="mt-3 inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                @click="clearFilters"
            >
                <FilterX :size="15" />
                Clear filters
            </button>
        </div>

        <DataTable
            :data="invoices"
            :columns="columns"
            :filters="filters"
            url="/collectibles"
            :only="['invoices', 'totals', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search invoice or customer…"
            empty-title="Nothing to collect here"
            empty-text="No invoice is still owed for these filters."
        >
            <template #cell-reference="{ row }">
                <button
                    v-if="can.viewSales"
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read sale ${row.reference}`"
                    @click="viewing = `/sales/${row.id}`"
                >
                    {{ row.reference }}
                </button>
                <span v-else class="font-mono text-xs font-semibold text-ink">{{ row.reference }}</span>
            </template>

            <template #cell-customer="{ row }">
                <Link :href="`/customers/${row.customer_id}`" class="font-medium text-ink hover:underline">{{ row.customer }}</Link>
            </template>

            <template #cell-sale_date="{ row }">
                <span class="whitespace-nowrap text-ink-muted">{{ row.sale_date }}</span>
            </template>

            <template #cell-due_date="{ row }">
                <span class="whitespace-nowrap" :class="row.days_overdue > 0 ? 'font-semibold text-warning-700' : 'text-ink-muted'">{{ row.due_date }}</span>
                <span v-if="row.days_overdue > 0" class="block text-2xs font-semibold text-warning-700">
                    {{ row.days_overdue }} day{{ row.days_overdue === 1 ? '' : 's' }} overdue
                </span>
            </template>

            <template #cell-on_account="{ row }">
                <span class="tabular-nums text-ink-muted">{{ money(row.on_account) }}</span>
            </template>

            <template #cell-settled="{ row }">
                <span class="tabular-nums text-ink-muted">{{ Number(row.settled) > 0 ? money(row.settled) : '—' }}</span>
            </template>

            <template #cell-balance_due="{ row }">
                <span class="tabular-nums font-semibold text-ink">{{ money(row.balance_due) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1">
                    <TableAction v-if="can.viewSales" variant="view" label="View sale" @click="viewing = `/sales/${row.id}`" />
                    <Link
                        :href="`/customers/${row.customer_id}/statement`"
                        class="grid size-8 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        :title="`Statement of ${row.customer}`"
                        :aria-label="`Statement of ${row.customer}`"
                    >
                        <FileText :size="15" />
                    </Link>
                    <Link
                        v-if="can.pay"
                        :href="`/customer-payments/create?customer=${row.customer_id}`"
                        class="grid size-8 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        :title="`Record a payment from ${row.customer}`"
                        :aria-label="`Record a payment from ${row.customer}`"
                    >
                        <Banknote :size="15" />
                    </Link>
                </div>
            </template>
        </DataTable>

        <DocumentModal :open="viewing !== null" :document-url="viewing" @close="viewing = null" />
    </div>
</template>
