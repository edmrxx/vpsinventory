<script setup>
import { computed, reactive, watch, ref } from 'vue'
import { FilterX, Plus } from '@lucide/vue'
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
    sales: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'sale_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'customer', label: 'Customer' },
    { key: 'location', label: 'Sold from', hideOnMobile: true, width: '140px' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '80px' },
    { key: 'total_amount', label: 'Total', sortable: true, align: 'right', width: '130px' },
    // Only for the people allowed to see a margin; the server leaves the
    // figures out entirely for everybody else.
    ...(props.can.viewCost
        ? [{ key: 'gross_profit', label: 'Profit', align: 'right', width: '120px', hideOnMobile: true }]
        : []),
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const filterState = reactive({
    customer_id: props.filters.customer_id ?? '',
    location_id: props.filters.location_id ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    posted_by: props.filters.posted_by ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.customer_id = incoming.customer_id ?? ''
        filterState.location_id = incoming.location_id ?? ''
        filterState.status = incoming.status ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
        filterState.posted_by = incoming.posted_by ?? ''
    }
)

const extraQuery = computed(() => ({
    customer_id: filterState.customer_id || undefined,
    location_id: filterState.location_id || undefined,
    status: filterState.status || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
    posted_by: filterState.posted_by || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.customer_id = ''
    filterState.location_id = ''
    filterState.status = ''
    filterState.from = ''
    filterState.to = ''
    filterState.posted_by = ''
}

/** The document open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Sales"
            subheading="Goods sold. A posted sale is never edited, only voided."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/sales/create">
                    <Plus :size="16" />
                    New sale
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect
                    v-model="filterState.customer_id"
                    label="Customer"
                    any-label="All customers"
                    :options="options.customers ?? []"
                />

                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="All statuses"
                    :options="options.statuses ?? []"
                />
                <FilterSelect
                    v-model="filterState.location_id"
                    label="Location"
                    any-label="All locations"
                    :options="options.locations ?? []"
                />
                <FilterSelect
                    v-model="filterState.posted_by"
                    label="Staff"
                    any-label="All staff"
                    :options="options.staff ?? []"
                />

                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        From
                    </span>
                    <input
                        v-model="filterState.from"
                        type="date"
                        :max="filterState.to || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        To
                    </span>
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
            :data="sales"
            :columns="columns"
            :filters="filters"
            url="/sales"
            :only="['sales', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or customer…"
            empty-title="No sales yet"
            empty-text="Record the first sale and the stock leaves the shelf."
        >
            <template #cell-reference="{ row }">
                <!-- The reference opens the record, the same as the view
                     button beside it. It is the first thing the eye lands
                     on and reads like a link already. -->
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read sale ${row.reference}`"
                    @click="viewing = `/sales/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-customer="{ row }">
                <span class="font-medium text-ink">{{ row.customer ?? '—' }}</span>
            </template>

            <template #cell-gross_profit="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'voided'
                        ? 'text-ink-subtle line-through'
                        : (Number(row.gross_profit) < 0 ? 'text-danger-700' : 'text-success-700')"
                >
                    {{ money(row.gross_profit) }}
                </span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-total_amount="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'voided' ? 'text-ink-subtle line-through' : 'text-ink'"
                >
                    {{ money(row.total_amount) }}
                </span>
            </template>

            <template #cell-location="{ row }">
                <span class="text-ink-muted">{{ row.location ?? '—' }}</span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View sale" @click="viewing = `/sales/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <DocumentModal
            :open="viewing !== null"
            :document-url="viewing"
            @close="viewing = null"
        />
    </div>
</template>
