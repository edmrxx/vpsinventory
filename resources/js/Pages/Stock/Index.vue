<script setup>
import { computed, reactive, watch, ref } from 'vue'
import { FilterX, Package } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import ProductModal from '../../Components/ProductModal.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    stock: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

/* Narrowed to one location (phase 31.2), a column says what sits there,
   beside the total. */
const locationName = computed(() =>
    (props.options.locations ?? []).find((l) => String(l.value) === String(props.filters.location_id))?.label ?? null
)

const columns = computed(() => [
    { key: 'sku', label: 'SKU', sortable: true, width: '140px' },
    { key: 'name', label: 'Product', sortable: true },
    // Narrowed to a location the row carries two more figures, so the
    // category gives way (its filter stays) and the status stays in view.
    ...(locationName.value ? [] : [{ key: 'category', label: 'Category', hideOnMobile: true, width: '130px' }]),
    ...(locationName.value
        ? [{ key: 'at_location', label: `At ${locationName.value}`, align: 'right', width: '110px' }]
        : []),
    { key: 'on_hand', label: locationName.value ? 'All locations' : 'On hand', sortable: true, align: 'right', width: '110px' },
    // Held for customer orders, and what is left to sell (phase 29). With a
    // location chosen, what orders hold there and what is free there (31.4).
    { key: 'reserved', label: 'Reserved or held', align: 'right', width: '120px', hideOnMobile: true },
    { key: 'available', label: 'Available', align: 'right', width: '110px' },
    { key: 'reorder_level', label: 'Reorder', sortable: true, align: 'right', width: '100px', hideOnMobile: true },
    { key: 'stock_value', label: 'Value', sortable: true, align: 'right', width: '140px' },
    { key: 'is_active', label: 'Status', align: 'center', width: '110px', hideOnMobile: true },
    { key: 'actions', label: '', align: 'right', width: '70px' },
])

const filterState = reactive({
    location_id: props.filters.location_id ?? '',
    category_id: props.filters.category_id ?? '',
    stock: props.filters.stock ?? '',
    status: props.filters.status ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.location_id = incoming.location_id ?? ''
        filterState.category_id = incoming.category_id ?? ''
        filterState.stock = incoming.stock ?? ''
        filterState.status = incoming.status ?? ''
    }
)

const extraQuery = computed(() => ({
    location_id: filterState.location_id || undefined,
    category_id: filterState.category_id || undefined,
    stock: filterState.stock || undefined,
    status: filterState.status || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.location_id = ''
    filterState.category_id = ''
    filterState.stock = ''
    filterState.status = ''
}

/* The figures above the table cover everything, not just this page. */
const cards = computed(() => [
    { label: 'Products', value: count(props.summary.products ?? 0), hint: 'Active' },
    { label: 'Units on hand', value: count(props.summary.units ?? 0) },
    { label: 'Stock value', value: money(props.summary.value ?? 0), hint: 'At what the batches cost' },
    {
        label: 'Low stock',
        value: count(props.summary.low_stock ?? 0),
        hint: 'At or below reorder level',
        alarming: (props.summary.low_stock ?? 0) > 0,
    },
])

/** The product open in the dialog, or null. */
const viewing = ref(null)
</script>

<template>
    <div>
        <PageHeader
            heading="Stock on Hand"
            subheading="What is on the shelf, and what it is worth at the cost it was bought for."
        />

        <!-- The whole warehouse, not just this page -->
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="card in cards" :key="card.label" class="card p-4">
                <p class="text-xs text-ink-muted">{{ card.label }}</p>
                <p
                    class="mt-1 text-2xl font-bold tabular-nums"
                    :class="card.alarming ? 'text-danger-700' : 'text-ink'"
                >
                    {{ card.value }}
                </p>
                <p v-if="card.hint" class="mt-0.5 text-2xs text-ink-subtle">{{ card.hint }}</p>
            </div>
        </div>

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <FilterSelect
                    v-model="filterState.location_id"
                    label="Location"
                    any-label="All locations"
                    :options="options.locations ?? []"
                />
                <FilterSelect
                    v-model="filterState.category_id"
                    label="Category"
                    any-label="All categories"
                    :options="options.categories ?? []"
                />
                <FilterSelect
                    v-model="filterState.stock"
                    label="Stock"
                    any-label="Any stock level"
                    :options="options.stock ?? []"
                />
                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="Active and inactive"
                    :options="options.statuses ?? []"
                />
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
            :data="stock"
            :columns="columns"
            :filters="filters"
            url="/stock"
            :only="['stock', 'filters', 'summary']"
            :extra-query="extraQuery"
            search-placeholder="Search SKU, name or category…"
            empty-title="Nothing on the shelf"
            empty-text="Bring stock in with a purchase, or enter what you already had as an opening adjustment."
        >
            <template #cell-sku="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-muted">{{ row.sku }}</span>
            </template>

            <template #cell-name="{ row }">
                <button
                    type="button"
                    class="flex items-center gap-2.5 text-left hover:underline"
                    @click="viewing = row.id"
                >
                    <!-- A fixed box either way, so the rows do not jump
                         about while the pictures load. -->
                    <span
                        class="grid size-9 shrink-0 place-items-center overflow-hidden rounded-field border border-line bg-surface-sunken"
                    >
                        <img
                            v-if="row.photo_url"
                            :src="row.photo_url"
                            :alt="row.name"
                            loading="lazy"
                            class="size-full object-cover"
                        />
                        <Package v-else :size="14" class="text-ink-subtle" />
                    </span>
                    <span class="min-w-0 truncate font-medium text-ink">{{ row.name }}</span>
                </button>
            </template>

            <template #cell-category="{ row }">
                <span class="text-ink-muted">{{ row.category ?? '—' }}</span>
            </template>

            <template #cell-reserved="{ row }">
                <span
                    class="tabular-nums"
                    :class="Number(row.reserved) > 0 ? 'text-info-700' : 'text-ink-subtle'"
                    :title="Number(row.held_for_losses) > 0
                        ? `${count(Number(row.reserved) - Number(row.held_for_losses))} reserved for customer orders, ${count(row.held_for_losses)} held for a loss report`
                        : undefined"
                >
                    {{ Number(row.reserved) > 0 ? count(row.reserved) : '—' }}
                </span>
            </template>

            <template #cell-available="{ row }">
                <span class="tabular-nums font-semibold" :class="Number(row.reserved) > 0 ? 'text-ink' : 'text-ink-muted'">
                    {{ count(row.available) }}
                </span>
            </template>

            <template #cell-on_hand="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.is_low_stock ? 'text-danger-700' : 'text-ink'"
                    :title="row.is_low_stock ? 'At or below the reorder level' : undefined"
                >
                    {{ count(row.on_hand) }}
                    <span class="text-2xs font-normal text-ink-subtle">{{ row.unit }}</span>
                </span>
            </template>

            <template #cell-at_location="{ row }">
                <span class="tabular-nums font-semibold text-ink">
                    {{ count(row.at_location) }}
                    <span class="text-2xs font-normal text-ink-subtle">{{ row.unit }}</span>
                </span>
            </template>

            <template #cell-reorder_level="{ row }">
                <span class="tabular-nums text-ink-muted">{{ count(row.reorder_level) }}</span>
            </template>

            <template #cell-stock_value="{ row }">
                <span class="tabular-nums font-semibold text-ink">{{ money(row.stock_value) }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <TableAction
                        variant="view"
                        label="Read this product"
                        @click="viewing = row.id"
                    />
                </div>
            </template>
        </DataTable>

        <ProductModal :open="viewing !== null" :id="viewing" @close="viewing = null" />
    </div>
</template>
