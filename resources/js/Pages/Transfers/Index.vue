<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ArrowRight, FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    transfers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'transfer_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'route', label: 'From → To' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '80px' },
    { key: 'total_cost', label: 'Value', sortable: true, align: 'right', width: '130px', hideOnMobile: true },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const keys = ['from_location_id', 'to_location_id', 'status', 'from', 'to', 'posted_by']

const filterState = reactive(Object.fromEntries(keys.map((key) => [key, props.filters[key] ?? ''])))

watch(
    () => props.filters,
    (incoming) => keys.forEach((key) => (filterState[key] = incoming[key] ?? ''))
)

const extraQuery = computed(() =>
    Object.fromEntries(keys.map((key) => [key, filterState[key] || undefined]))
)

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    keys.forEach((key) => (filterState[key] = ''))
}

/** The document open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Stock Transfers"
            subheading="Stock moved from one location to another, at the cost it already carries."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/transfers/create">
                    <Plus :size="16" />
                    New transfer
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <FilterSelect
                    v-model="filterState.from_location_id"
                    label="From"
                    any-label="Any location"
                    :options="options.locations ?? []"
                />
                <FilterSelect
                    v-model="filterState.to_location_id"
                    label="To"
                    any-label="Any location"
                    :options="options.locations ?? []"
                />
                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="All statuses"
                    :options="options.statuses ?? []"
                />
                <FilterSelect
                    v-model="filterState.posted_by"
                    label="Staff"
                    any-label="All staff"
                    :options="options.staff ?? []"
                />
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">Date from</span>
                    <input
                        v-model="filterState.from"
                        type="date"
                        :max="filterState.to || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">Date to</span>
                    <input
                        v-model="filterState.to"
                        type="date"
                        :min="filterState.from || undefined"
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
            :data="transfers"
            :columns="columns"
            :filters="filters"
            url="/transfers"
            :only="['transfers', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or notes…"
            empty-title="No transfers yet"
            empty-text="Goods received at the warehouse reach the store through a transfer."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read transfer ${row.reference}`"
                    @click="viewing = `/transfers/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-route="{ row }">
                <span class="inline-flex flex-wrap items-center gap-1.5 text-sm text-ink">
                    {{ row.from ?? '—' }}
                    <ArrowRight :size="14" class="text-ink-subtle" />
                    {{ row.to ?? '—' }}
                </span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-total_cost="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'voided' ? 'text-ink-subtle line-through' : 'text-ink'"
                >
                    {{ money(row.total_cost) }}
                </span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View transfer" @click="viewing = `/transfers/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <DocumentModal :open="viewing !== null" :document-url="viewing" @close="viewing = null" />
    </div>
</template>
