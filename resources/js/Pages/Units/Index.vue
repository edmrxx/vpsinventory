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
import UnitFormFields from './UnitFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    units: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'code', label: 'Code', sortable: true, width: '120px' },
    { key: 'name', label: 'Unit', sortable: true },
    { key: 'allows_decimals', label: 'Decimals', align: 'center', width: '110px' },
    { key: 'products_count', label: 'Products', align: 'right', width: '110px' },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '110px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const measureOptions = [
    { value: 'whole', label: 'Whole numbers only' },
    { value: 'decimals', label: 'Allows decimals' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    measure: props.filters.measure ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.measure = incoming.measure ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    measure: filterState.measure || undefined,
}))

const hasFilters = computed(() => filterState.status !== '' || filterState.measure !== '')

function clearFilters() {
    filterState.status = ''
    filterState.measure = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
const modal = useFormModal({
    url: '/units',
    noun: 'unit',
    label: (row) => row.code,
    fields: ['code', 'name', 'allows_decimals'],
    blank: {
        code: '',
        name: '',
        allows_decimals: false,
        is_active: true,
    },
    fromRow: (row) => ({
        code: row.code ?? '',
        name: row.name ?? '',
        allows_decimals: !!row.allows_decimals,
        is_active: row.is_active,
    }),
})

async function removeUnit(unit) {
    if (!(await confirmDelete({ name: unit.name }))) return

    router.delete(`/units/${unit.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The unit was not deleted.'),
    })
}

async function toggleStatus(unit) {
    const turningOff = unit.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this unit?' : 'Activate this unit?',
        text: turningOff
            ? `${unit.name} will no longer appear on a new product.`
            : `${unit.name} can be picked on a product again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/units/${unit.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Units"
            subheading="The units a product is counted in."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New unit
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

                <FilterSelect
                    v-model="filterState.measure"
                    label="Counted in"
                    any-label="Whole and decimal"
                    :options="measureOptions"
                />

                <div>
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
            :data="units"
            :columns="columns"
            :filters="filters"
            url="/units"
            :only="['units', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search code or name…"
            empty-title="No units yet"
            empty-text="Add the first unit, such as pcs or box."
        >
            <template #cell-code="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-muted">{{ row.code }}</span>
            </template>

            <template #cell-name="{ row }">
                <span class="font-medium text-ink">{{ row.name }}</span>
            </template>

            <template #cell-allows_decimals="{ row }">
                <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="row.allows_decimals ? 'bg-brand-50 text-brand-700' : 'bg-surface-sunken text-ink-muted'"
                >
                    {{ row.allows_decimals ? 'Yes' : 'Whole only' }}
                </span>
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
                        :label="row.is_active ? 'Deactivate unit' : 'Activate unit'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit unit"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete unit"
                        @click="removeUnit(row)"
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
            <UnitFormFields :form="modal.form" />

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
