<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX, Plus, Power } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import SupplierModal from '../../Components/SupplierModal.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import Modal from '../../Components/Modal.vue'
import SupplierFormFields from './SupplierFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    suppliers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'code', label: 'Code', sortable: true, width: '140px' },
    { key: 'name', label: 'Supplier', sortable: true },
    { key: 'contact_person', label: 'Contact', sortable: true, hideOnMobile: true },
    { key: 'phone', label: 'Phone', hideOnMobile: true },
    { key: 'is_vat_registered', label: 'VAT / Non-VAT', align: 'center', width: '140px' },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '140px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const vatOptions = [
    { value: 'registered', label: 'VAT-registered only' },
    { value: 'not-registered', label: 'Non-VAT only' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    vat: props.filters.vat ?? '',
})

// Follow the server, for example after the back button.
watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.vat = incoming.vat ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    vat: filterState.vat || undefined,
}))

const hasFilters = computed(() => filterState.status !== '' || filterState.vat !== '')

function clearFilters() {
    filterState.status = ''
    filterState.vat = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
/**
 * The supplier read in a dialog over the list (24 November 2026), so the
 * sort, filters and page stay as they are. "Open full page" has the rest.
 */
const viewing = ref(null)

/** Edit straight from the dialog: the row on screen holds every value. */
function editFromDialog(id) {
    const row = props.suppliers.data.find((r) => String(r.id) === String(id))

    viewing.value = null

    if (row) modal.openEdit(row)
}

const modal = useFormModal({
    url: '/suppliers',
    noun: 'supplier',
    label: (row) => row.code,
    fields: ['code', 'name', 'contact_person', 'email', 'phone', 'tin', 'address', 'notes'],
    blank: {
        code: '',
        name: '',
        contact_person: '',
        email: '',
        phone: '',
        tin: '',
        is_vat_registered: false,
        payment_terms_days: 0,
        address: '',
        notes: '',
        is_active: true,
    },
    fromRow: (row) => ({
        code: row.code ?? '',
        name: row.name ?? '',
        contact_person: row.contact_person ?? '',
        email: row.email ?? '',
        phone: row.phone ?? '',
        tin: row.tin ?? '',
        is_vat_registered: row.is_vat_registered ?? false,
        payment_terms_days: row.payment_terms_days ?? 0,
        address: row.address ?? '',
        notes: row.notes ?? '',
        is_active: row.is_active,
    }),
})

async function removeSupplier(supplier) {
    if (!(await confirmDelete({ name: supplier.name }))) return

    router.delete(`/suppliers/${supplier.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The supplier was not deleted.'),
    })
}

/**
 * Switch a supplier between VAT-registered and Non-VAT.
 *
 * The pill itself is the switch. A fifth action icon would have no colour
 * it is allowed to wear — the table standard keeps blue, green, red and
 * orange for View, Edit, Delete and Warning — and the row already carries
 * four of them.
 */
async function toggleVat(supplier) {
    const becomingVat = !supplier.is_vat_registered

    const agreed = await confirmAction({
        icon: 'question',
        title: becomingVat ? 'Make this supplier VAT-registered?' : 'Make this supplier Non-VAT?',
        text: becomingVat
            ? `${supplier.name} will be listed as VAT-registered.`
            : `${supplier.name} will be listed as Non-VAT.`,
        confirmText: 'Yes, change it',
    })

    if (!agreed) return

    router.patch(`/suppliers/${supplier.id}/vat`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The supplier did not change.'),
    })
}

async function toggleStatus(supplier) {
    const turningOff = supplier.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this supplier?' : 'Activate this supplier?',
        text: turningOff
            ? `${supplier.name} will no longer appear on a new purchase.`
            : `${supplier.name} can be picked on a purchase again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/suppliers/${supplier.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Suppliers"
            subheading="Who goods are bought from."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New supplier
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
                    v-model="filterState.vat"
                    label="VAT / Non-VAT"
                    any-label="Both"
                    :options="vatOptions"
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
            :data="suppliers"
            :columns="columns"
            :filters="filters"
            url="/suppliers"
            :only="['suppliers', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search code, name or contact…"
            empty-title="No suppliers yet"
            empty-text="Add the first supplier to get started."
        >
            <template #cell-code="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-muted">{{ row.code }}</span>
            </template>

            <template #cell-name="{ row }">
                <span class="font-medium text-ink">{{ row.name }}</span>
            </template>

            <template #cell-contact_person="{ row }">
                <span class="text-ink-muted">{{ row.contact_person ?? '—' }}</span>
            </template>

            <template #cell-phone="{ row }">
                <span class="text-ink-muted">{{ row.phone ?? '—' }}</span>
            </template>

            <template #cell-is_vat_registered="{ row }">
                <button
                    type="button"
                    class="inline-flex items-center rounded-full px-2.5 py-1 text-2xs font-semibold tracking-wide uppercase transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                    :class="row.is_vat_registered
                        ? 'bg-brand-50 text-brand-700 hover:bg-brand-100'
                        : 'bg-surface-sunken text-ink-muted hover:bg-surface-muted'"
                    :disabled="!row.can.update"
                    :title="row.can.update ? 'Click to change' : 'You may not edit suppliers'"
                    @click="toggleVat(row)"
                >
                    {{ row.vat_label }}
                </button>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" label="View supplier" @click="viewing = row.id" />

                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate supplier' : 'Activate supplier'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit supplier"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete supplier"
                        @click="removeSupplier(row)"
                    />
                </div>
            </template>
        </DataTable>

        <SupplierModal :open="viewing !== null" :id="viewing" @close="viewing = null" @edit="editFromDialog" />

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <SupplierFormFields :form="modal.form" :mode="modal.mode.value" />

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
