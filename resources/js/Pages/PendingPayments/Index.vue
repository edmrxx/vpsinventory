<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Banknote, FilterX } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import PendingPaymentModal from '../../Components/PendingPaymentModal.vue'
import PendingCancelModal from '../../Components/PendingCancelModal.vue'
import ReceivePendingPayment from '../../Components/ReceivePendingPayment.vue'
import { useMoney } from '../../lib/money'

/**
 * Orders the kiosk sent to the cashier (CR-2), newest first.
 *
 * Awaiting payment holds its goods until it is paid at the till, cancelled,
 * or the day closes; past that it reads Expired and holds nothing. The
 * green action opens the order to receive its money right here.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    payments: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    waiting: { type: Number, default: 0 },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'order_date', label: 'Sent', sortable: true, width: '140px' },
    { key: 'customer', label: 'Customer' },
    { key: 'location', label: 'Pay at', hideOnMobile: true, width: '140px' },
    { key: 'taken_by', label: 'Taken by', hideOnMobile: true, width: '140px' },
    { key: 'sale', label: 'Became sale', hideOnMobile: true, width: '140px' },
    { key: 'total_amount', label: 'Total', sortable: true, align: 'right', width: '130px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '150px' },
    { key: 'actions', label: '', align: 'right', width: '100px' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    location_id: props.filters.location_id ?? '',
    taken_by: props.filters.taken_by ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.location_id = incoming.location_id ?? ''
        filterState.taken_by = incoming.taken_by ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    location_id: filterState.location_id || undefined,
    taken_by: filterState.taken_by || undefined,
}))

const hasFilters = computed(() => Object.values(extraQuery.value).some((value) => value !== undefined))

function clearFilters() {
    filterState.status = ''
    filterState.location_id = ''
    filterState.taken_by = ''
}

function showWaiting() {
    clearFilters()
    filterState.status = 'waiting'
}

/** The order open in the dialog, or null; and the one being cancelled. */
const viewing = ref(null)
const cancelling = ref(null)

const table = ref(null)

function startCancel(order) {
    viewing.value = null
    cancelling.value = order
}

function cancelled() {
    cancelling.value = null
    table.value?.reload()
}

/** Receive payment here, in the back office: `{ order, box }`, or null. */
const receiving = ref(null)

function startReceive(payload) {
    viewing.value = null
    receiving.value = payload
}
</script>

<template>
    <div>
        <PageHeader
            heading="Pending Payments"
            subheading="Orders the kiosk sent to the cashier. Each holds its goods until it is paid at the till, cancelled, or the day closes."
        />

        <button
            v-if="waiting > 0 && filterState.status !== 'waiting'"
            type="button"
            class="card mb-4 flex w-full items-center justify-between gap-3 p-4 text-left text-sm transition-colors hover:bg-surface-sunken"
            @click="showWaiting"
        >
            <span class="text-ink">
                <strong class="font-semibold">{{ waiting }}</strong>
                {{ waiting === 1 ? 'order is' : 'orders are' }} waiting to be paid.
            </span>
            <span class="text-xs font-medium text-brand-700">Show them</span>
        </button>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <FilterSelect v-model="filterState.status" label="Status" any-label="All statuses" :options="options.statuses ?? []" />
                <FilterSelect v-model="filterState.location_id" label="Pay at" any-label="All locations" :options="options.locations ?? []" />
                <FilterSelect v-model="filterState.taken_by" label="Taken by" any-label="Anybody" :options="options.staff ?? []" />
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
            ref="table"
            :data="payments"
            :columns="columns"
            :filters="filters"
            url="/pending-payments"
            :only="['payments', 'filters', 'waiting']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, customer or note…"
            empty-title="Nothing sent to the cashier yet"
            empty-text="At the kiosk, tap Pending payment to send the counter here instead of taking the money."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold whitespace-nowrap text-brand-700 hover:underline"
                    :title="`Read order ${row.reference}`"
                    @click="viewing = row.id"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-order_date="{ row }">
                <span class="whitespace-nowrap">{{ row.order_date }}</span>
                <span class="block text-2xs text-ink-subtle">{{ row.sent_at }}</span>
            </template>

            <template #cell-customer="{ row }">
                <span class="font-medium text-ink">{{ row.customer ?? '—' }}</span>
                <span v-if="row.note" class="block text-2xs text-ink-subtle">{{ row.note }}</span>
            </template>

            <template #cell-sale="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.sale ?? '—' }}</span>
            </template>

            <template #cell-total_amount="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'cancelled' || row.status === 'expired' ? 'text-ink-subtle line-through' : 'text-ink'"
                >
                    {{ money(row.total_amount) }}
                </span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" label="View order" @click="viewing = row.id" />
                    <!-- Green: take the money for it, in the dialog. -->
                    <button
                        v-if="row.can?.receive"
                        type="button"
                        class="grid size-9 place-items-center rounded-field bg-success-50 text-success-700 transition hover:bg-success-500 hover:text-white"
                        :title="`Receive payment for ${row.reference}`"
                        :aria-label="`Receive payment for ${row.reference}`"
                        @click="viewing = row.id"
                    >
                        <Banknote :size="16" />
                    </button>
                </div>
            </template>
        </DataTable>

        <PendingPaymentModal
            :open="viewing !== null"
            :id="viewing"
            @close="viewing = null"
            @cancel="startCancel"
            @receive="startReceive"
        />
        <ReceivePendingPayment :order="receiving?.order ?? null" :box="receiving?.box ?? null" @close="receiving = null" />
        <PendingCancelModal :order="cancelling" @close="cancelling = null" @done="cancelled" />
    </div>
</template>
