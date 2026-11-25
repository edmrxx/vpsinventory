<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX, Package, Plus, Power } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import ProductModal from '../../Components/ProductModal.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import Modal from '../../Components/Modal.vue'
import ProductFormFields from './ProductFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { productPickers } from '../../lib/productPickers'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const columns = [
    { key: 'sku', label: 'SKU', sortable: true, width: '140px' },
    { key: 'name', label: 'Product Name', sortable: true },
    { key: 'category', label: 'Category', hideOnMobile: true, width: '130px' },
    { key: 'unit', label: 'Unit', align: 'center', width: '80px' },
    { key: 'cost', label: 'Cost', sortable: true, align: 'right', width: '110px' },
    { key: 'selling_price', label: 'Selling Price', sortable: true, align: 'right', width: '130px' },
    { key: 'on_hand', label: 'Stock', sortable: true, align: 'right', width: '90px' },
    {
        key: 'reorder_level',
        label: 'Reorder Level',
        sortable: true,
        align: 'right',
        width: '130px',
        hideOnMobile: true,
    },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '140px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const stockOptions = [
    { value: 'low', label: 'At or below reorder level' },
    { value: 'out', label: 'Out of stock' },
]

/* The filters this page owns. The DataTable carries them on every change. */
const filterState = reactive({
    category_id: props.filters.category_id ?? '',
    unit_id: props.filters.unit_id ?? '',
    brand: props.filters.brand ?? '',
    supplier_id: props.filters.supplier_id ?? '',
    status: props.filters.status ?? '',
    stock: props.filters.stock ?? '',
})

// Follow the server, for example after the back button.
watch(
    () => props.filters,
    (incoming) => {
        filterState.category_id = incoming.category_id ?? ''
        filterState.unit_id = incoming.unit_id ?? ''
        filterState.brand = incoming.brand ?? ''
        filterState.supplier_id = incoming.supplier_id ?? ''
        filterState.status = incoming.status ?? ''
        filterState.stock = incoming.stock ?? ''
    }
)

/** Empty choices are dropped, so the address bar only holds real filters. */
const extraQuery = computed(() => ({
    category_id: filterState.category_id || undefined,
    unit_id: filterState.unit_id || undefined,
    brand: filterState.brand || undefined,
    supplier_id: filterState.supplier_id || undefined,
    status: filterState.status || undefined,
    stock: filterState.stock || undefined,
}))

const hasFilters = computed(() =>
    Object.values(extraQuery.value).some((value) => value !== undefined)
)

function clearFilters() {
    filterState.category_id = ''
    filterState.unit_id = ''
    filterState.brand = ''
    filterState.supplier_id = ''
    filterState.status = ''
    filterState.stock = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
/**
 * The product read in a dialog over the list (24 November 2026), so the
 * sort, filters and page stay as they are. "Open full page" has the rest.
 */
const viewing = ref(null)

const modal = useFormModal({
    url: '/products',
    noun: 'product',
    label: (row) => row.sku,
    fields: [
        'sku', 'barcode', 'name', 'brand', 'model', 'description', 'category_id',
        'unit_id', 'preferred_supplier_id', 'cost', 'selling_price', 'reorder_level', 'photo',
        // Each other unit's error shows under its own box.
        'units', 'units.*',
    ],
    blank: {
        sku: '',
        barcode: '',
        name: '',
        brand: '',
        model: '',
        description: '',
        category_id: '',
        unit_id: '',
        preferred_supplier_id: '',
        cost: '0.00',
        selling_price: '0.00',
        reorder_level: '0',
        is_active: true,
        // The file itself, and the tick that takes an existing one off.
        photo: null,
        remove_photo: false,
        // A box of 100, a roll of 75 m: [{ unit_id, factor, selling_price }]
        units: [],
    },
    fromRow: (row) => ({
        sku: row.sku ?? '',
        barcode: row.barcode ?? '',
        name: row.name ?? '',
        brand: row.brand ?? '',
        model: row.model ?? '',
        description: row.description ?? '',
        category_id: row.category_id ?? '',
        unit_id: row.unit_id ?? '',
        preferred_supplier_id: row.preferred_supplier_id ?? '',
        cost: row.cost ?? '0.00',
        selling_price: row.selling_price ?? '0.00',
        reorder_level: row.reorder_level ?? 0,
        is_active: row.is_active,
        photo: null,
        remove_photo: false,
        units: (row.other_units ?? []).map((other) => ({
            unit_id: other.unit_id,
            factor: other.factor,
            selling_price: other.selling_price ?? '',
        })),
    }),
})

/* The filter bar lists every category and unit, including the ones switched
   off. The form shows only the active ones, plus whichever the product being
   edited already points at, so an edit never loses its own value. */
const pickers = computed(() =>
    productPickers(props.options, modal.editing.value)
)

async function removeProduct(product) {
    if (!(await confirmDelete({ name: `${product.sku} — ${product.name}` }))) return

    router.delete(`/products/${product.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The product was not deleted.'),
    })
}

async function toggleStatus(product) {
    const turningOff = product.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this product?' : 'Activate this product?',
        text: turningOff
            ? `${product.name} will no longer appear on a new purchase or sale.`
            : `${product.name} can be traded again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/products/${product.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Product Master"
            subheading="Every product, its price and what is on hand."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New product
                </PrimaryButton>
            </template>
        </PageHeader>

        <!-- Filters -->
        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <FilterSelect
                    v-model="filterState.category_id"
                    label="Category"
                    any-label="All categories"
                    :options="options.categories ?? []"
                />

                <FilterSelect
                    v-model="filterState.unit_id"
                    label="Unit"
                    any-label="All units"
                    :options="options.units ?? []"
                />

                <FilterSelect
                    v-model="filterState.brand"
                    label="Brand"
                    any-label="All brands"
                    :options="options.brands ?? []"
                />

                <FilterSelect
                    v-model="filterState.supplier_id"
                    label="Preferred supplier"
                    any-label="Any supplier"
                    :options="options.suppliers ?? []"
                />

                <FilterSelect
                    v-model="filterState.stock"
                    label="Stock"
                    any-label="Any stock level"
                    :options="stockOptions"
                />

                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="Active and inactive"
                    :options="statusOptions"
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
            :data="products"
            :columns="columns"
            :filters="filters"
            url="/products"
            :only="['products', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search name, SKU, barcode or brand…"
            empty-title="No products yet"
            empty-text="Add the first product, then bring stock in with a purchase."
        >
            <template #cell-sku="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-muted">{{ row.sku }}</span>
            </template>

            <template #cell-name="{ row }">
                <div class="flex items-center gap-2.5">
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
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-ink">{{ row.name }}</span>
                        <!-- Brand and model under the name (phase 30), so two
                             paints called the same are told apart without a
                             column of their own squeezing the table. -->
                        <span
                            v-if="row.brand || row.model"
                            class="block truncate text-xs text-ink-subtle"
                        >
                            {{ [row.brand, row.model].filter(Boolean).join(' · ') }}
                        </span>
                    </span>
                </div>
            </template>


            <template #cell-category="{ row }">
                <span class="text-ink-muted">{{ row.category ?? '—' }}</span>
            </template>

            <template #cell-unit="{ row }">
                <span class="text-xs text-ink-muted">{{ row.unit ?? '—' }}</span>
            </template>

            <template #cell-cost="{ row }">
                <span class="tabular-nums text-ink-muted">{{ money(row.cost) }}</span>
            </template>

            <template #cell-selling_price="{ row }">
                <span class="tabular-nums font-medium text-ink">
                    {{ money(row.selling_price) }}
                </span>
            </template>

            <!-- Stock turns red once it reaches the reorder level. -->
            <template #cell-on_hand="{ row }">
                <span
                    class="tabular-nums font-semibold"
                    :class="row.is_low_stock ? 'text-danger-700' : 'text-ink'"
                    :title="row.is_low_stock ? 'At or below the reorder level' : undefined"
                >
                    {{ count(row.on_hand) }}
                </span>
            </template>

            <template #cell-reorder_level="{ row }">
                <span class="tabular-nums text-ink-muted">{{ count(row.reorder_level) }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" label="View product" @click="viewing = row.id" />

                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate product' : 'Activate product'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit product"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete product"
                        @click="removeProduct(row)"
                    />
                </div>
            </template>
        </DataTable>

        <ProductModal :open="viewing !== null" :id="viewing" @close="viewing = null" />

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <ProductFormFields
                :form="modal.form"
                :categories="pickers.categories"
                :units="pickers.units"
                :suppliers="pickers.suppliers"
                :brands="options.brands ?? []"
                :mode="modal.mode.value"
                :on-hand="modal.editing.value?.on_hand ?? null"
                :photo-url="modal.editing.value?.photo_url ?? null"
            />

            <template #footer>
                <PrimaryButton
                    variant="secondary"
                    :disabled="modal.form.processing"
                    @click="modal.close()"
                >
                    Cancel
                </PrimaryButton>
                <PrimaryButton :loading="modal.form.processing" @click="modal.submit()">
                    {{ modal.submitLabel.value }}
                </PrimaryButton>
            </template>
        </Modal>
    </div>
</template>
