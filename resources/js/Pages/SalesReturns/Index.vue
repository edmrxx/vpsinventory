<script setup>
import { computed, reactive, watch, ref } from 'vue'
import { FilterX } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import DataTable from '../../Components/DataTable.vue'
import PageHeader from '../../Components/PageHeader.vue'
import TableAction from '../../Components/TableAction.vue'
import { useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    returns: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'return_date', label: 'Date', sortable: true, width: '120px' },
    { key: 'sale_reference', label: 'Against', width: '140px' },
    { key: 'customer', label: 'Customer', hideOnMobile: true },
    { key: 'reason', label: 'Reason', hideOnMobile: true },
    { key: 'lines_count', label: 'Items', align: 'right', width: '80px' },
    { key: 'total_amount', label: 'Refund', sortable: true, align: 'right', width: '130px' },
    { key: 'actions', label: '', align: 'right', width: '70px' },
]

const filterState = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '' })

watch(
    () => props.filters,
    (incoming) => {
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

const extraQuery = computed(() => ({
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() => filterState.from !== '' || filterState.to !== '')

function clearFilters() {
    filterState.from = ''
    filterState.to = ''
}

/** The return open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Sales Returns"
            subheading="Goods a customer brought back. A return always starts from the sale it undoes."
        />

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
            :data="returns"
            :columns="columns"
            :filters="filters"
            url="/sales-returns"
            :only="['returns', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference, reason or the sale…"
            empty-title="No sales returns yet"
            empty-text="A return starts from a sale: open one and use its Return button."
        >
            <template #cell-reference="{ row }">
                <!-- The reference opens the record, the same as the view
                     button beside it. -->
                <button
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="`Read return ${row.reference}`"
                    @click="viewing = `/sales-returns/${row.id}`"
                >
                    {{ row.reference }}
                </button>
            </template>

            <template #cell-sale_reference="{ row }">
                <a :href="`/sales/${row.sale_id}`" class="font-mono text-xs text-brand-700 hover:underline">
                    {{ row.sale_reference }}
                </a>
            </template>

            <template #cell-customer="{ row }">
                <span class="text-ink-muted">{{ row.customer ?? '—' }}</span>
            </template>

            <template #cell-reason="{ row }">
                <span class="text-ink-muted">{{ row.reason }}</span>
            </template>

            <template #cell-lines_count="{ row }">
                <span class="tabular-nums text-ink-muted">{{ row.lines_count }}</span>
            </template>

            <template #cell-total_amount="{ row }">
                <span class="tabular-nums font-semibold text-ink">{{ money(row.total_amount) }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction variant="view" label="View return" @click="viewing = `/sales-returns/${row.id}`" />
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
