<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { FilterX, Plus } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import SupplierPaymentModal from '../../Components/SupplierPaymentModal.vue'
import TableAction from '../../Components/TableAction.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { useMoney } from '../../lib/money'

/** Money paid to suppliers against their bills (phase 24). */
defineOptions({ layout: AppLayout })

const props = defineProps({
    payments: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'payment_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'method', label: 'Paid by', hideOnMobile: true },
    { key: 'bills_count', label: 'Bills', align: 'right', width: '80px', hideOnMobile: true },
    { key: 'amount', label: 'Amount', sortable: true, align: 'right', width: '130px' },
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const keys = ['supplier_id', 'status', 'from', 'to']

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

/** The payment open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Supplier Payments"
            subheading="Money paid to suppliers against what the shop owes them. A recorded payment is never edited, only voided."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/supplier-payments/create">
                    <Plus :size="16" />
                    Pay a supplier
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect v-model="filterState.supplier_id" label="Supplier" any-label="All suppliers" :options="options.suppliers ?? []" />
                <FilterSelect v-model="filterState.status" label="Status" any-label="All statuses" :options="options.statuses ?? []" />

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
            :data="payments"
            :columns="columns"
            :filters="filters"
            url="/supplier-payments"
            :only="['payments', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, supplier or bank number…"
            empty-title="No supplier payments yet"
            empty-text="When the shop pays a bill put on a supplier's account, record it here and it comes off the bill."
        >
            <template #cell-reference="{ row }">
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read payment ${row.reference}`"
                    @click="viewing = `/supplier-payments/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-supplier="{ row }">
                <span class="font-medium text-ink">{{ row.supplier ?? '—' }}</span>
            </template>

            <template #cell-method="{ row }">
                <span class="text-ink-muted">{{ row.method ?? (Number(row.credit_used) > 0 ? 'Supplier credit' : '—') }}</span>
                <span v-if="row.method_reference" class="block font-mono text-2xs text-ink-subtle">{{ row.method_reference }}</span>
            </template>

            <template #cell-bills_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.bills_count }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.status === 'voided' ? 'text-ink-subtle line-through' : 'text-ink'"
                >
                    {{ money(row.amount) }}
                </span>
                <span v-if="Number(row.credit_used) > 0" class="block text-2xs text-success-700">+ {{ money(row.credit_used) }} credit</span>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View payment" @click="viewing = `/supplier-payments/${row.id}`" />
                </div>
            </template>
        </DataTable>

        <SupplierPaymentModal :open="viewing !== null" :payment-url="viewing" @close="viewing = null" />
    </div>
</template>
