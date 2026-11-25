<script setup>
import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX, Plus, Power } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import Modal from '../../Components/Modal.vue'
import CategoryFormFields from './CategoryFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    categories: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'name', label: 'Category', sortable: true },
    { key: 'description', label: 'Description', hideOnMobile: true },
    { key: 'products_count', label: 'Products', align: 'right', width: '110px' },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '110px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const filterState = reactive({ status: props.filters.status ?? '' })

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
    }
)

const extraQuery = computed(() => ({ status: filterState.status || undefined }))

const hasFilters = computed(() => filterState.status !== '')

function clearFilters() {
    filterState.status = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
const modal = useFormModal({
    url: '/categories',
    noun: 'category',
    label: (row) => row.name,
    fields: ['name', 'description'],
    blank: {
        name: '',
        description: '',
        is_active: true,
    },
    fromRow: (row) => ({
        name: row.name ?? '',
        description: row.description ?? '',
        is_active: row.is_active,
    }),
})

async function removeCategory(category) {
    if (!(await confirmDelete({ name: category.name }))) return

    router.delete(`/categories/${category.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The category was not deleted.'),
    })
}

async function toggleStatus(category) {
    const turningOff = category.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this category?' : 'Activate this category?',
        text: turningOff
            ? `${category.name} will no longer appear on a new product.`
            : `${category.name} can be picked on a product again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/categories/${category.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Categories"
            subheading="The groups a product is filed under."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New category
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid items-end gap-3 sm:grid-cols-3">
                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="Active and inactive"
                    :options="statusOptions"
                />

                <div class="sm:col-span-2">
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        @click="clearFilters"
                    >
                        <FilterX :size="15" />
                        Clear filter
                    </button>
                </div>
            </div>
        </div>

        <DataTable
            :data="categories"
            :columns="columns"
            :filters="filters"
            url="/categories"
            :only="['categories', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search name or description…"
            empty-title="No categories yet"
            empty-text="Add the first category, then the products that belong in it."
        >
            <template #cell-name="{ row }">
                <span class="font-medium text-ink">{{ row.name }}</span>
            </template>

            <template #cell-description="{ row }">
                <span class="text-ink-muted">{{ row.description ?? '—' }}</span>
            </template>

            <template #cell-products_count="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.products_count }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate category' : 'Activate category'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit category"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete category"
                        @click="removeCategory(row)"
                    />
                </div>
            </template>
        </DataTable>

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="md"
            @close="modal.close()"
        >
            <CategoryFormFields :form="modal.form" />

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
