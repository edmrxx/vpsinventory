<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Camera, FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import LossReportModal from '../../Components/LossReportModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    reports: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'report_date', label: 'Reported', sortable: true, width: '120px' },
    { key: 'location', label: 'Location' },
    { key: 'reason', label: 'Reason' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '90px' },
    { key: 'total_cost', label: 'Written off', align: 'right', width: '130px', hideOnMobile: true },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '150px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const keys = ['location_id', 'reason', 'status', 'from', 'to', 'reported_by']

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
            heading="Loss Reports"
            subheading="Damaged, expired, rusted, stolen or missing goods: held aside until an approver writes them off."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/loss-reports/create">
                    <Plus :size="16" />
                    Report a loss
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
                    v-model="filterState.reason"
                    label="Reason"
                    any-label="Any reason"
                    :options="options.reasons ?? []"
                />
                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="All statuses"
                    :options="options.statuses ?? []"
                />
                <FilterSelect
                    v-model="filterState.reported_by"
                    label="Reported by"
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
            :data="reports"
            :columns="columns"
            :filters="filters"
            url="/loss-reports"
            :only="['reports', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or notes…"
            empty-title="No losses reported"
            empty-text="Found damaged, expired or missing goods? Report them here, with a photo."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read loss report ${row.reference}`"
                    @click="viewing = `/loss-reports/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-reason="{ row }">
                <span class="inline-flex items-center gap-1.5 text-sm text-ink">
                    {{ row.reason }}
                    <Camera v-if="row.photos_count" :size="13" class="text-ink-subtle" :aria-label="`${row.photos_count} photo(s)`" />
                </span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-total_cost="{ row }">
                <span v-if="row.status === 'approved'" class="tabular-nums font-semibold text-ink">{{ money(row.total_cost) }}</span>
                <span v-else class="text-ink-subtle">—</span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View loss report" @click="viewing = `/loss-reports/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <LossReportModal :open="viewing !== null" :report-url="viewing" @close="viewing = null" />
    </div>
</template>
