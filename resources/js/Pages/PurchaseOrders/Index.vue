<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import PurchaseOrderModal from '../../Components/PurchaseOrderModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

/**
 * Every order placed with a supplier, newest first (phase 22).
 *
 * "Outstanding" is an order still open: placed, and not yet cancelled. Once
 * deliveries are posted against orders (phase 23), received ones leave it.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    outstanding: { type: Number, default: 0 },
    /** Placed and waiting for approval (phase 24.4). */
    awaitingApproval: { type: Number, default: 0 },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'order_date', label: 'Ordered', sortable: true, width: '120px' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'expected_date', label: 'Expected', sortable: true, hideOnMobile: true, width: '130px' },
    { key: 'request', label: 'Request', hideOnMobile: true, width: '140px' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '70px' },
    { key: 'total_amount', label: 'Total', sortable: true, align: 'right', width: '130px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    ordered_by: props.filters.ordered_by ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.supplier_id = incoming.supplier_id ?? ''
        filterState.ordered_by = incoming.ordered_by ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    supplier_id: filterState.supplier_id || undefined,
    ordered_by: filterState.ordered_by || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    filterState.status = ''
    filterState.supplier_id = ''
    filterState.ordered_by = ''
    filterState.from = ''
    filterState.to = ''
}

function showOutstanding() {
    clearFilters()
    filterState.status = 'open'
}

function showAwaiting() {
    clearFilters()
    filterState.status = 'pending_approval'
}

/** The order open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Purchase Orders"
            subheading="Goods ordered from suppliers. Stock arrives when the delivery is posted under Purchases."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/purchase-orders/create">
                    <Plus :size="16" />
                    New order
                </PrimaryButton>
            </template>
        </PageHeader>

        <button
            v-if="awaitingApproval > 0 && filterState.status !== 'pending_approval'"
            type="button"
            class="card mb-4 flex w-full items-center justify-between gap-3 bg-warning-50 p-4 text-left text-sm transition-colors hover:brightness-95"
            @click="showAwaiting"
        >
            <span class="text-warning-700">
                <strong class="font-semibold">{{ awaitingApproval }}</strong>
                {{ awaitingApproval === 1 ? 'order is' : 'orders are' }} waiting for approval, and cannot be sent or received yet.
            </span>
            <span class="text-xs font-medium text-warning-700">Show them</span>
        </button>

        <button
            v-if="outstanding > 0 && filterState.status !== 'open'"
            type="button"
            class="card mb-4 flex w-full items-center justify-between gap-3 p-4 text-left text-sm transition-colors hover:bg-surface-sunken"
            @click="showOutstanding"
        >
            <span class="text-ink">
                <strong class="font-semibold">{{ outstanding }}</strong>
                {{ outstanding === 1 ? 'order is' : 'orders are' }} still open with suppliers.
            </span>
            <span class="text-xs font-medium text-brand-700">Show them</span>
        </button>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FilterSelect v-model="filterState.status" label="Status" any-label="All statuses" :options="options.statuses ?? []" />
                <FilterSelect v-model="filterState.supplier_id" label="Supplier" any-label="All suppliers" :options="options.suppliers ?? []" />
                <FilterSelect v-model="filterState.ordered_by" label="Placed by" any-label="Anybody" :options="options.staff ?? []" />

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
            :data="orders"
            :columns="columns"
            :filters="filters"
            url="/purchase-orders"
            :only="['orders', 'filters', 'outstanding', 'awaitingApproval']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or supplier…"
            empty-title="No purchase orders yet"
            empty-text="Order from an approved request, or place an order directly."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline"
                    :title="`Read order ${row.reference}`"
                    @click="viewing = `/purchase-orders/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-order_date="{ row }">
                <span class="whitespace-nowrap">{{ row.order_date }}</span>
            </template>

            <template #cell-supplier="{ row }">
                <span class="font-medium text-ink">{{ row.supplier ?? '—' }}</span>
            </template>

            <template #cell-expected_date="{ row }">
                <span class="whitespace-nowrap" :class="row.late ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                    {{ row.expected_date ?? '—' }}<template v-if="row.late"> · late</template>
                </span>
            </template>

            <template #cell-request="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.request ?? '—' }}</span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-total_amount="{ row }">
                <span class="tabular-nums font-semibold" :class="row.status === 'cancelled' ? 'text-ink-subtle line-through' : 'text-ink'">
                    {{ money(row.total_amount) }}
                </span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View order" @click="viewing = `/purchase-orders/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <PurchaseOrderModal :open="viewing !== null" :order-url="viewing" @close="viewing = null" />
    </div>
</template>
