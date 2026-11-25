<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { FilterX, Landmark } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import TableAction from '../../Components/TableAction.vue'
import { useMoney } from '../../lib/money'

/**
 * Every bill the shop still owes a supplier, one row each (phase 24).
 *
 * The client's request of 12 November: "show what we owe our suppliers".
 * Filtered by supplier, whether it is past due, and the month it falls due.
 * The figures come from `SupplierAccount`, the same place the supplier's
 * page and the dashboard read; the cards and the aging add up the filtered
 * rows, not just the page.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    today: { type: String, required: true },
    bills: { type: Object, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Bill', sortable: true, width: '150px' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'purchase_date', label: 'Delivered', sortable: true, width: '115px', hideOnMobile: true },
    { key: 'due_date', label: 'Due', sortable: true, width: '140px' },
    { key: 'on_account', label: 'On account', align: 'right', width: '120px', hideOnMobile: true },
    { key: 'balance_due', label: 'Still owed', sortable: true, align: 'right', width: '130px' },
    { key: 'actions', label: '', align: 'right', width: '90px' },
]

const keys = ['supplier_id', 'status', 'month']

const filterState = reactive(Object.fromEntries(keys.map((key) => [key, props.filters[key] ?? ''])))

watch(
    () => props.filters,
    (incoming) => keys.forEach((key) => (filterState[key] = incoming[key] ?? ''))
)

const extraQuery = computed(() => Object.fromEntries(keys.map((key) => [key, filterState[key] || undefined])))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    keys.forEach((key) => (filterState[key] = ''))
}

const statusOptions = [
    { value: 'overdue', label: 'Past due' },
    { value: 'current', label: 'Not yet due' },
]

/** The bill open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader heading="Payables" :subheading="`Every bill the shop still owes a supplier, as of ${today}.`" />

        <!-- What the filtered list adds up to -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">To pay</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ money(totals.total) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Past due</p>
                <p class="mt-1 tabular-nums text-lg font-bold" :class="Number(totals.overdue) > 0 ? 'text-warning-700' : 'text-ink'">
                    {{ money(totals.overdue) }}
                </p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Bills</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ totals.bills }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Suppliers</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink">{{ totals.suppliers }}</p>
            </div>
        </div>

        <!-- How far past due, over the same rows -->
        <div class="card mb-4 p-4">
            <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Aging</p>
            <dl class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5">
                <div v-for="bucket in totals.buckets" :key="bucket.key" class="rounded-field border border-line p-2.5">
                    <dt class="text-2xs text-ink-muted">{{ bucket.label }}</dt>
                    <dd class="mt-0.5 tabular-nums text-sm font-semibold"
                        :class="bucket.key !== 'current' && Number(bucket.amount) > 0 ? 'text-warning-700' : 'text-ink'">
                        {{ money(bucket.amount) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <FilterSelect v-model="filterState.supplier_id" label="Supplier" any-label="All suppliers" :options="options.suppliers ?? []" />
                <FilterSelect v-model="filterState.status" label="Status" any-label="Past due and not yet due" :options="statusOptions" />
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">Falls due in</span>
                    <input
                        v-model="filterState.month"
                        type="month"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
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
            :data="bills"
            :columns="columns"
            :filters="filters"
            url="/payables"
            :only="['bills', 'totals', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search bill, invoice no. or supplier…"
            empty-title="Nothing to pay here"
            empty-text="No bill is still owed for these filters."
        >
            <template #cell-reference="{ row }">
                <button
                    v-if="can.viewPurchases"
                    type="button"
                    class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline"
                    :title="`Read purchase ${row.reference}`"
                    @click="viewing = `/purchases/${row.id}`"
                >
                    {{ row.reference }}
                </button>
                <span v-else class="font-mono text-xs font-semibold text-ink">{{ row.reference }}</span>
                <span v-if="row.supplier_reference" class="block font-mono text-2xs text-ink-subtle">{{ row.supplier_reference }}</span>
            </template>

            <template #cell-supplier="{ row }">
                <Link :href="`/suppliers/${row.supplier_id}`" class="font-medium text-ink hover:underline">{{ row.supplier }}</Link>
            </template>

            <template #cell-purchase_date="{ row }">
                <span class="whitespace-nowrap text-ink-muted">{{ row.purchase_date }}</span>
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

            <template #cell-balance_due="{ row }">
                <span class="tabular-nums font-semibold text-ink">{{ money(row.balance_due) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1">
                    <TableAction v-if="can.viewPurchases" variant="view" label="View bill" @click="viewing = `/purchases/${row.id}`" />
                    <Link
                        v-if="can.pay"
                        :href="`/supplier-payments/create?supplier=${row.supplier_id}`"
                        class="grid size-8 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        :title="`Pay ${row.supplier}`"
                        :aria-label="`Pay ${row.supplier}`"
                    >
                        <Landmark :size="15" />
                    </Link>
                </div>
            </template>
        </DataTable>

        <DocumentModal :open="viewing !== null" :document-url="viewing" @close="viewing = null" />
    </div>
</template>
