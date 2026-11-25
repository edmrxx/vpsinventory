<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { FilterX, Plus, Power } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import CustomerModal from '../../Components/CustomerModal.vue'
import DataTable from '../../Components/DataTable.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import StatusPill from '../../Components/StatusPill.vue'
import TableAction from '../../Components/TableAction.vue'
import Modal from '../../Components/Modal.vue'
import CustomerFormFields from './CustomerFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'
import { useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const { money } = useMoney()

const props = defineProps({
    customers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'code', label: 'Code', sortable: true, width: '140px' },
    { key: 'name', label: 'Customer', sortable: true },
    { key: 'contact_person', label: 'Contact', sortable: true, hideOnMobile: true },
    { key: 'phone', label: 'Phone', hideOnMobile: true },
    { key: 'balance', label: 'Balance', sortable: true, align: 'right', hideOnMobile: true },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '140px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const balanceOptions = [
    { value: 'owing', label: 'Owing only' },
    { value: 'overdue', label: 'Past due' },
    { value: 'over_limit', label: 'Over the limit' },
]

const filterState = reactive({ status: props.filters.status ?? '', balance: props.filters.balance ?? '' })

// Follow the server, for example after the back button.
watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.balance = incoming.balance ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    balance: filterState.balance || undefined,
}))

const hasFilters = computed(() => filterState.status !== '' || filterState.balance !== '')

function clearFilters() {
    filterState.status = ''
    filterState.balance = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
/**
 * The customer read in a dialog over the list (24 November 2026), so the
 * sort, filters and page stay as they are. "Open full page" has the rest.
 */
const viewing = ref(null)

/** Edit straight from the dialog: the row on screen holds every value. */
function editFromDialog(id) {
    const row = props.customers.data.find((r) => String(r.id) === String(id))

    viewing.value = null

    if (row) modal.openEdit(row)
}

const modal = useFormModal({
    url: '/customers',
    noun: 'customer',
    label: (row) => row.code,
    fields: ['code', 'name', 'contact_person', 'email', 'phone', 'tin', 'address', 'notes', 'credit_limit', 'payment_terms_days'],
    blank: {
        code: '',
        name: '',
        contact_person: '',
        email: '',
        phone: '',
        tin: '',
        address: '',
        notes: '',
        is_active: true,
        credit_limit: '0.00',
        payment_terms_days: 0,
    },
    fromRow: (row) => ({
        code: row.code ?? '',
        name: row.name ?? '',
        contact_person: row.contact_person ?? '',
        email: row.email ?? '',
        phone: row.phone ?? '',
        tin: row.tin ?? '',
        address: row.address ?? '',
        notes: row.notes ?? '',
        is_active: row.is_active,
        credit_limit: row.credit_limit ?? '0.00',
        payment_terms_days: row.payment_terms_days ?? 0,
    }),
})

async function removeCustomer(customer) {
    if (!(await confirmDelete({ name: customer.name }))) return

    router.delete(`/customers/${customer.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The customer was not deleted.'),
    })
}

async function toggleStatus(customer) {
    const turningOff = customer.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this customer?' : 'Activate this customer?',
        text: turningOff
            ? `${customer.name} will no longer appear on a new sale.`
            : `${customer.name} can be picked on a sale again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/customers/${customer.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Customers"
            subheading="Who goods are sold to. The walk-in row is used by counter sales."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New customer
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
                    v-model="filterState.balance"
                    label="Balance"
                    any-label="Owing or not"
                    :options="balanceOptions"
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
            :data="customers"
            :columns="columns"
            :filters="filters"
            url="/customers"
            :only="['customers', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search code, name or contact…"
            empty-title="No customers yet"
            empty-text="Add the first customer to get started."
        >
            <template #cell-code="{ row }">
                <span class="font-mono text-xs font-semibold text-ink-muted">{{ row.code }}</span>
            </template>

            <template #cell-name="{ row }">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-ink">{{ row.name }}</span>
                    <span
                        v-if="row.is_walk_in"
                        class="rounded-full bg-brand-50 px-2 py-0.5 text-2xs font-semibold text-brand-700 ring-1 ring-brand-200 ring-inset"
                    >
                        Walk-in
                    </span>
                </div>
            </template>

            <template #cell-balance="{ row }">
                <span v-if="Number(row.balance) > 0" class="tabular-nums font-semibold text-ink">
                    {{ money(row.balance) }}
                </span>
                <span v-else class="text-ink-subtle">—</span>
                <!-- Behind on what is owed, or owing more than the limit. -->
                <span v-if="row.flags?.overdue || row.flags?.over_limit" class="mt-0.5 flex justify-end gap-1">
                    <span v-if="row.flags.overdue"
                        class="rounded-full bg-warning-50 px-1.5 py-px text-2xs font-semibold text-warning-700"
                        :title="`${money(row.overdue)} is past its due date`">
                        Overdue
                    </span>
                    <span v-if="row.flags.over_limit"
                        class="rounded-full bg-danger-50 px-1.5 py-px text-2xs font-semibold text-danger-700"
                        :title="`Owes more than the limit of ${money(row.credit_limit)}`">
                        Over limit
                    </span>
                </span>
            </template>

            <template #cell-contact_person="{ row }">
                <span class="text-ink-muted">{{ row.contact_person ?? '—' }}</span>
            </template>

            <template #cell-phone="{ row }">
                <span class="text-ink-muted">{{ row.phone ?? '—' }}</span>
            </template>

            <template #cell-is_active="{ row }">
                <StatusPill :active="row.is_active" />
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-1.5">
                    <TableAction variant="view" label="View customer" @click="viewing = row.id" />

                    <TableAction
                        v-if="row.can.status"
                        variant="warning"
                        :icon="Power"
                        :label="row.is_active ? 'Deactivate customer' : 'Activate customer'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit customer"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete customer"
                        @click="removeCustomer(row)"
                    />
                </div>
            </template>
        </DataTable>

        <CustomerModal :open="viewing !== null" :id="viewing" @close="viewing = null" @edit="editFromDialog" />

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <CustomerFormFields
                :form="modal.form"
                :mode="modal.mode.value"
                :is-walk-in="modal.editing.value?.is_walk_in ?? false"
                :can-credit="can.credit ?? false"
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
