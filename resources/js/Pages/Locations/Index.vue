<script setup>
import { computed, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX, Plus, Power, Star, Store, Warehouse } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import Modal from '../../Components/Modal.vue'
import LocationFormFields from './LocationFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    locations: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'name', label: 'Location', sortable: true },
    { key: 'type', label: 'Type', sortable: true, width: '130px' },
    { key: 'address', label: 'Address', hideOnMobile: true },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '150px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const filterState = reactive({
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.type = incoming.type ?? ''
        filterState.status = incoming.status ?? ''
    }
)

const extraQuery = computed(() => ({
    type: filterState.type || undefined,
    status: filterState.status || undefined,
}))

const hasFilters = computed(() => filterState.type !== '' || filterState.status !== '')

function clearFilters() {
    filterState.type = ''
    filterState.status = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
const modal = useFormModal({
    url: '/locations',
    noun: 'location',
    label: (row) => row.name,
    fields: ['name', 'type', 'address', 'description'],
    blank: {
        name: '',
        type: '',
        address: '',
        description: '',
        is_active: true,
    },
    fromRow: (row) => ({
        name: row.name ?? '',
        type: row.type ?? '',
        address: row.address ?? '',
        description: row.description ?? '',
        is_active: row.is_active,
    }),
})

async function removeLocation(location) {
    if (!(await confirmDelete({ name: location.name }))) return

    router.delete(`/locations/${location.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The location was not deleted.'),
    })
}

/**
 * Every document posts at the default location until phases 31.3 and 31.4
 * let it name one (phase 31.2). The server refuses while the current default
 * still holds stock, and says why.
 */
async function makeDefault(location) {
    const agreed = await confirmAction({
        icon: 'question',
        title: `Make ${location.name} the default?`,
        text: 'Every sale, delivery, return and adjustment will post its stock here.',
        confirmText: 'Yes, make it the default',
    })

    if (!agreed) return

    router.patch(`/locations/${location.id}/default`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The default did not change.'),
    })
}

async function toggleStatus(location) {
    const turningOff = location.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this location?' : 'Activate this location?',
        text: turningOff
            ? `${location.name} will no longer be offered on a new document.`
            : `${location.name} can be picked again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/locations/${location.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Locations"
            subheading="The store, the warehouse and any other place that holds stock. Every document posts at the default location for now."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New location
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="card mb-4 p-4">
            <div class="grid items-end gap-3 sm:grid-cols-3">
                <FilterSelect
                    v-model="filterState.type"
                    label="Type"
                    any-label="Stores and warehouses"
                    :options="types"
                />

                <FilterSelect
                    v-model="filterState.status"
                    label="Status"
                    any-label="Active and inactive"
                    :options="statusOptions"
                />

                <div>
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                        @click="clearFilters"
                    >
                        <FilterX :size="15" />
                        Clear filters
                    </button>
                </div>
            </div>
        </div>

        <DataTable
            :data="locations"
            :columns="columns"
            :filters="filters"
            url="/locations"
            :only="['locations', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search name, address or description…"
            empty-title="No locations yet"
            empty-text="Add the places that hold stock, for example the store and the warehouse."
        >
            <template #cell-name="{ row }">
                <span class="inline-flex items-center gap-2">
                    <span class="font-medium text-ink">{{ row.name }}</span>
                    <span
                        v-if="row.is_default"
                        class="rounded-full bg-brand-50 px-2 py-0.5 text-2xs font-semibold text-brand-700"
                        title="Every document posts its stock here"
                    >Default</span>
                </span>
            </template>

            <template #cell-type="{ row }">
                <span
                    class="inline-flex items-center gap-1.5 text-ink-muted"
                >
                    <Store v-if="row.type === 'store'" :size="14" class="text-ink-subtle" />
                    <Warehouse v-else :size="14" class="text-ink-subtle" />
                    {{ row.type_label }}
                </span>
            </template>

            <template #cell-address="{ row }">
                <span class="text-ink-muted">{{ row.address ?? '—' }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction
                        v-if="!row.is_default"
                        variant="view"
                        :icon="Star"
                        :disabled="!row.can.makeDefault"
                        label="Make this the default location"
                        @click="makeDefault(row)"
                    />

                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate location' : 'Activate location'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit location"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete location"
                        @click="removeLocation(row)"
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
            <LocationFormFields :form="modal.form" :types="types" />

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
