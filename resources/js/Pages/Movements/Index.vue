<script setup>
import { computed, reactive, watch, ref } from 'vue'
import { FilterX } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    movements: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    // Not sortable on purpose: see MovementController.
    { key: 'movement_date', label: 'Date', width: '120px' },
    { key: 'reference', label: 'Reference', sortable: true, width: '150px' },
    { key: 'type', label: 'Type', sortable: true, width: '150px' },
    { key: 'product', label: 'Product' },
    { key: 'quantity_in', label: 'In', align: 'right', width: '80px' },
    { key: 'quantity_out', label: 'Out', align: 'right', width: '80px' },
    { key: 'balance_after', label: 'Balance', align: 'right', width: '100px' },
    // Only for the people allowed to see a margin; what a sale cost is its
    // cost of goods, and the server leaves it out entirely for anyone else.
    ...(props.can.viewCost
        ? [{ key: 'unit_cost', label: 'Unit Cost', align: 'right', width: '120px', hideOnMobile: true }]
        : []),
]

const filterState = reactive({
    product_id: props.filters.product_id ?? '',
    type: props.filters.type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.product_id = incoming.product_id ?? ''
        filterState.type = incoming.type ?? ''
        filterState.from = incoming.from ?? ''
        filterState.to = incoming.to ?? ''
    }
)

const extraQuery = computed(() => ({
    product_id: filterState.product_id || undefined,
    type: filterState.type || undefined,
    from: filterState.from || undefined,
    to: filterState.to || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.product_id = ''
    filterState.type = ''
    filterState.from = ''
    filterState.to = ''
}

/** The document open in the dialog, as its own address, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Inventory Movement"
            subheading="Every time stock moved, in the order it happened. Nothing here is ever edited."
        />

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect
                    v-model="filterState.product_id"
                    label="Product"
                    any-label="All products"
                    :options="options.products ?? []"
                />
                <FilterSelect
                    v-model="filterState.type"
                    label="Transaction type"
                    any-label="All types"
                    :options="options.types ?? []"
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
            :data="movements"
            :columns="columns"
            :filters="filters"
            url="/movements"
            :only="['movements', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search reference or SKU…"
            empty-title="Nothing has moved yet"
            empty-text="Record a purchase or a sale and it will appear here."
        >
            <template #cell-reference="{ row }">
                <button
                    v-if="row.document_url"
                    type="button"
                    class="font-mono text-xs font-semibold text-brand-700 hover:underline"
                    :title="row.document_label ? `Read this ${row.document_label}` : undefined"
                    @click="viewing = row.document_url"
                >
                    {{ row.reference }}
                </button>
                <span v-else class="font-mono text-xs text-ink-muted">{{ row.reference }}</span>
            </template>

            <template #cell-type="{ row }">
                <ActionBadge :label="row.type_label" :tone="row.type_tone" />
            </template>

            <template #cell-product="{ row }">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ row.product }}</p>
                    <p class="font-mono text-2xs text-ink-subtle">{{ row.sku }}</p>
                </div>
            </template>

            <template #cell-quantity_in="{ row }">
                <span v-if="row.quantity_in > 0" class="tabular-nums font-semibold text-success-700">
                    {{ count(row.quantity_in) }}
                </span>
                <span v-else class="text-ink-subtle">—</span>
            </template>

            <template #cell-quantity_out="{ row }">
                <span v-if="row.quantity_out > 0" class="tabular-nums font-semibold text-danger-700">
                    {{ count(row.quantity_out) }}
                </span>
                <span v-else class="text-ink-subtle">—</span>
            </template>

            <template #cell-balance_after="{ row }">
                <span class="tabular-nums font-semibold text-ink">{{ count(row.balance_after) }}</span>
            </template>

            <template #cell-unit_cost="{ row }">
                <span class="tabular-nums text-ink-muted">{{ money(row.unit_cost) }}</span>
            </template>
        </DataTable>

        <DocumentModal
            :open="viewing !== null"
            :document-url="viewing"
            @close="viewing = null"
        />
    </div>
</template>
