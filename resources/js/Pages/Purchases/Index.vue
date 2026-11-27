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
    purchases: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = computed(() => [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'purchase_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'supplier_reference', label: 'Invoice No.', hideOnMobile: true, width: '140px' },
    // Where the goods went on the shelf (phase 31.3).
    { key: 'location', label: 'Received at', hideOnMobile: true, width: '140px' },
    { key: 'lines_count', label: 'Items', align: 'right', width: '80px' },
    { key: 'total_amount', label: 'Total', sortable: true, align: 'right', width: '130px' },
    // Paid or owing (phase 24.5), only for those who may see supplier money.
    ...(props.can.seePayment ? [{ key: 'payment', label: 'Payment', width: '150px' }] : []),
    { key: 'status', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
])

const filterState = reactive({
    supplier_id: props.filters.supplier_id ?? '',
    location_id: props.filters.location_id ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    posted_by: props.filters.posted_by ?? '',
    payment: props.filters.payment ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.supplier_id = incoming.supplier_id ?? ''
        filterState.location_id = incoming.location_id ?? ''
        filterState.status = incoming.status ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
        filterState.posted_by = incoming.posted_by ?? ''
        filterState.payment = incoming.payment ?? ''
    }
)

const extraQuery = computed(() => ({
    supplier_id: filterState.supplier_id || undefined,
    location_id: filterState.location_id || undefined,
    status: filterState.status || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
    posted_by: filterState.posted_by || undefined,
    payment: filterState.payment || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.supplier_id = ''
    filterState.location_id = ''
    filterState.status = ''
    filterState.from = ''
    filterState.to = ''
    filterState.posted_by = ''
    filterState.payment = ''
}

/** The document open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Purchases"
            subheading="Goods bought in. A posted purchase is never edited, only voided."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" href="/purchases/create">
                    <Plus :size="16" />
                    New purchase
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect
                    v-model="filterState.supplier_id"
                    label="Supplier"
                    any-label="All suppliers"
                    :options="options.suppliers ?? []"
                />

                <FilterSelect
                    v-model="filterState.location_id"
                    label="Received at"
                    any-label="All locations"
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
                <FilterSelect
                    v-if="can.seePayment"
                    v-model="filterState.payment"
                    label="Payment"
                    any-label="Paid or owing"
                    :options="options.payments ?? []"
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
            :data="purchases"
            :columns="columns"
            :filters="filters"
            url="/purchases"
            :only="['purchases', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, invoice no. or supplier…"
            empty-title="No purchases yet"
            empty-text="Record the first delivery and the stock goes on the shelf."
        >
            <template #cell-reference="{ row }">
                <!-- The reference opens the record, the same as the view
                     button beside it. It is the first thing the eye lands
                     on and reads like a link already. -->
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read purchase ${row.reference}`"
                    @click="viewing = `/purchases/${row.id}`"
                >
                    {{ row.reference }}
                </button>
                <span v-if="row.order" class="block font-mono text-2xs text-ink-subtle">{{ row.order }}</span>
            </template>

            <template #cell-supplier="{ row }">
                <span class="font-medium text-ink">{{ row.supplier ?? '—' }}</span>
            </template>

            <template #cell-supplier_reference="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.supplier_reference ?? '—' }}</span>
            </template>

            <template #cell-location="{ row }">
                <span class="text-ink-muted">{{ row.location ?? '—' }}</span>
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
                <span v-if="row.status === 'posted' && Number(row.balance_due) > 0" class="block text-2xs font-semibold text-warning-700">
                    owes {{ money(row.balance_due) }}
                </span>
            </template>

            <template #cell-payment="{ row }">
                <template v-if="row.payment">
                    <ActionBadge :label="row.payment.label" :tone="row.payment.tone" />
                    <span v-if="row.payment.detail" class="mt-0.5 block text-2xs text-ink-subtle tabular-nums">{{ row.payment.detail }}</span>
                </template>
            </template>

            <template #cell-status="{ row }">
                <ActionBadge :label="row.status_label" :tone="row.status_tone" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View purchase" @click="viewing = `/purchases/${row.id}`" />
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
