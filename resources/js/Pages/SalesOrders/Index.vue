<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { FilterX, PackageCheck, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import SalesOrderModal from '../../Components/SalesOrderModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

/**
 * Every sales order, newest first (phase 29).
 *
 * Pending holds nothing; Reserved holds its stock until its date; past it,
 * Expired holds nothing. The orange action marks one this person may
 * approve.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    orders: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    pending: { type: Number, default: 0 },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'order_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'customer', label: 'Customer' },
    { key: 'reserve_until', label: 'Held until', sortable: true, hideOnMobile: true, width: '130px' },
    { key: 'sale', label: 'Became sale', hideOnMobile: true, width: '140px' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '70px' },
    { key: 'total_amount', label: 'Total', sortable: true, align: 'right', width: '130px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    customer_id: props.filters.customer_id ?? '',
    created_by: props.filters.created_by ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.customer_id = incoming.customer_id ?? ''
        filterState.created_by = incoming.created_by ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    customer_id: filterState.customer_id || undefined,
    created_by: filterState.created_by || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    filterState.status = ''
    filterState.customer_id = ''
    filterState.created_by = ''
    filterState.from = ''
    filterState.to = ''
}

function showPending() {
    clearFilters()
    filterState.status = 'pending'
}

/** The order open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Sales Orders"
            subheading="Customer orders. Approved, an order holds its stock until a date; it becomes a sale on the sale form."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/sales-orders/create">
                    <Plus :size="16" />
                    New order
                </PrimaryButton>
            </template>
        </PageHeader>

        <button
            v-if="pending > 0 && filterState.status !== 'pending'"
            type="button"
            class="card mb-4 flex w-full items-center justify-between gap-3 p-4 text-left text-sm transition-colors hover:bg-surface-sunken"
            @click="showPending"
        >
            <span class="text-ink">
                <strong class="font-semibold">{{ pending }}</strong>
                {{ pending === 1 ? 'order is' : 'orders are' }} waiting for approval.
            </span>
            <span class="text-xs font-medium text-brand-700">Show them</span>
        </button>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <FilterSelect v-model="filterState.status" label="Status" any-label="All statuses" :options="options.statuses ?? []" />
                <FilterSelect v-model="filterState.customer_id" label="Customer" any-label="All customers" :options="options.customers ?? []" />
                <FilterSelect v-model="filterState.created_by" label="Prepared by" any-label="Anybody" :options="options.staff ?? []" />

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
            url="/sales-orders"
            :only="['orders', 'filters', 'pending']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, customer or attention…"
            empty-title="No sales orders yet"
            empty-text="Take a customer's order, and approve it to hold the stock for them."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline"
                    :title="`Read order ${row.reference}`"
                    @click="viewing = `/sales-orders/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-order_date="{ row }">
                <span class="whitespace-nowrap">{{ row.order_date }}</span>
            </template>

            <template #cell-customer="{ row }">
                <span class="font-medium text-ink">{{ row.customer ?? '—' }}</span>
                <span v-if="row.attention" class="block text-2xs text-ink-subtle">Attn. {{ row.attention }}</span>
            </template>

            <template #cell-reserve_until="{ row }">
                <span class="whitespace-nowrap" :class="row.status === 'approved' ? 'font-semibold text-info-700' : 'text-ink-muted'">
                    {{ row.status === 'approved' || row.status === 'expired' ? row.reserve_until : '—' }}
                </span>
            </template>

            <template #cell-sale="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.sale ?? '—' }}</span>
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
                    <!-- Orange, the table's colour for attention, when this person may approve it. -->
                    <TableAction
                        :variant="row.can?.approve ? 'warning' : 'view'"
                        :icon="row.can?.approve ? PackageCheck : null"
                        :label="row.can?.approve ? 'Approve this order' : 'View order'"
                        @click="viewing = `/sales-orders/${row.id}`"
                    />
                </div>
            </template>
        </DataTable>

        <SalesOrderModal :open="viewing !== null" :order-url="viewing" @close="viewing = null" />
    </div>
</template>
