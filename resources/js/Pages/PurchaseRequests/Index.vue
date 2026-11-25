<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { ClipboardCheck, FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import PurchaseRequestModal from '../../Components/PurchaseRequestModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

/**
 * Every purchase request, newest first (phase 21).
 *
 * A row opens in a dialog, where whoever may approve answers it. Nothing
 * here moves stock or money: a request only asks.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    requests: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    waiting: { type: Number, default: 0 },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'request_date', label: 'Raised', sortable: true, width: '120px' },
    { key: 'reason', label: 'Reason' },
    { key: 'requested_by', label: 'Raised by', hideOnMobile: true, width: '150px' },
    { key: 'needed_by', label: 'Needed by', hideOnMobile: true, width: '120px' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '70px' },
    { key: 'estimated_total', label: 'Estimate', sortable: true, align: 'right', width: '130px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    department_id: props.filters.department_id ?? '',
    requested_by: props.filters.requested_by ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.supplier_id = incoming.supplier_id ?? ''
        filterState.department_id = incoming.department_id ?? ''
        filterState.requested_by = incoming.requested_by ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    supplier_id: filterState.supplier_id || undefined,
    department_id: filterState.department_id || undefined,
    requested_by: filterState.requested_by || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    filterState.status = ''
    filterState.supplier_id = ''
    filterState.department_id = ''
    filterState.requested_by = ''
    filterState.from = ''
    filterState.to = ''
}

function showWaiting() {
    clearFilters()
    filterState.status = 'pending'
}

/** The request open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Purchase Requests"
            subheading="Goods somebody has asked to be bought. Approved or rejected by somebody else; never edited."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/purchase-requests/create">
                    <Plus :size="16" />
                    New request
                </PrimaryButton>
            </template>
        </PageHeader>

        <button
            v-if="waiting > 0 && filterState.status !== 'pending'"
            type="button"
            class="card mb-4 flex w-full items-center justify-between gap-3 p-4 text-left text-sm transition-colors hover:bg-surface-sunken"
            @click="showWaiting"
        >
            <span class="text-ink">
                <strong class="font-semibold">{{ waiting }}</strong>
                {{ waiting === 1 ? 'request is' : 'requests are' }} waiting for approval.
            </span>
            <span class="text-xs font-medium text-brand-700">Show them</span>
        </button>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="All statuses"
                    :options="options.statuses ?? []"
                />
                <FilterSelect
                    v-model="filterState.supplier_id"
                    label="Preferred supplier"
                    any-label="All suppliers"
                    :options="options.suppliers ?? []"
                />
                <FilterSelect
                    v-model="filterState.department_id"
                    label="Department"
                    any-label="All departments"
                    :options="options.departments ?? []"
                />
                <FilterSelect
                    v-model="filterState.requested_by"
                    label="Raised by"
                    any-label="Anybody"
                    :options="options.staff ?? []"
                />

                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">From</span>
                    <input
                        v-model="filterState.from"
                        type="date"
                        :max="filterState.to || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">To</span>
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
            :data="requests"
            :columns="columns"
            :filters="filters"
            url="/purchase-requests"
            :only="['requests', 'filters', 'waiting']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, reason or supplier…"
            empty-title="No purchase requests yet"
            empty-text="Ask for what needs buying, and somebody who may approve will answer it."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline"
                    :title="`Read request ${row.reference}`"
                    @click="viewing = `/purchase-requests/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-request_date="{ row }">
                <span class="whitespace-nowrap">{{ row.request_date }}</span>
            </template>

            <template #cell-reason="{ row }">
                <span class="block min-w-0 text-ink">{{ row.reason }}</span>
                <span v-if="row.department || row.supplier" class="block text-2xs text-ink-subtle">
                    <template v-if="row.department">{{ row.department }}</template><template v-if="row.department && row.supplier"> · </template><template v-if="row.supplier">From {{ row.supplier }}</template>
                </span>
            </template>

            <template #cell-requested_by="{ row }">
                <span class="text-ink-muted">{{ row.requested_by ?? '—' }}</span>
            </template>

            <template #cell-needed_by="{ row }">
                <span class="whitespace-nowrap text-ink-muted">{{ row.needed_by ?? '—' }}</span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-estimated_total="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'rejected' || row.status === 'closed' ? 'text-ink-subtle' : 'text-ink'"
                >
                    {{ money(row.estimated_total) }}
                </span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
                <!-- How far its canvass has got (phase 24.2) -->
                <span v-if="row.awarded" class="mt-1 block text-2xs font-semibold text-brand-700">Awarded</span>
                <span v-else-if="row.quotes_count" class="mt-1 block text-2xs text-ink-subtle">{{ row.quotes_count }} quote(s)</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <!-- Orange, the table's colour for something needing
                         attention, when this person may answer it. -->
                    <TableAction
                        :variant="row.can?.decide ? 'warning' : 'view'"
                        :icon="row.can?.decide ? ClipboardCheck : null"
                        :label="row.can?.decide ? 'Answer this request' : 'View request'"
                        @click="viewing = `/purchase-requests/${row.id}`"
                    />
                </div>
            </template>
        </DataTable>

        <PurchaseRequestModal
            :open="viewing !== null"
            :request-url="viewing"
            @close="viewing = null"
        />
    </div>
</template>
