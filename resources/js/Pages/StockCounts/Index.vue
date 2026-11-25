<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StockCountModal from '../../Components/StockCountModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'

defineOptions({ layout: AppLayout })

const props = defineProps({
    counts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'count_date', label: 'Started', sortable: true, width: '120px' },
    { key: 'location', label: 'Location' },
    { key: 'scope', label: 'Counting', hideOnMobile: true },
    { key: 'progress', label: 'Counted', align: 'right', width: '120px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '120px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const keys = ['location_id', 'status', 'from', 'to', 'started_by']

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
            heading="Stock Counts"
            subheading="Count what is on the shelf. An approved count posts what was over and short as adjustments."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/stock-counts/create">
                    <Plus :size="16" />
                    New count
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <FilterSelect
                    v-model="filterState.location_id"
                    label="Location"
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
                    v-model="filterState.started_by"
                    label="Started by"
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
            :data="counts"
            :columns="columns"
            :filters="filters"
            url="/stock-counts"
            :only="['counts', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or notes…"
            empty-title="No counts yet"
            empty-text="Start a count, walk the shelves, enter what you find, and hand it in for approval."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read count ${row.reference}`"
                    @click="viewing = `/stock-counts/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-scope="{ row }">
                <span class="text-sm text-ink-muted">{{ row.scope }}</span>
            </template>

            <template #cell-progress="{ row }">
                <span class="tabular-nums text-sm" :class="row.counted_count === row.lines_count ? 'text-ink' : 'text-ink-muted'">
                    {{ row.counted_count.toLocaleString() }} of {{ row.lines_count.toLocaleString() }}
                </span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View count" @click="viewing = `/stock-counts/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <StockCountModal :open="viewing !== null" :count-url="viewing" @close="viewing = null" />
    </div>
</template>
