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
import PaymentMethodFormFields from './PaymentMethodFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { confirmAction, confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    methods: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    kinds: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
})

const columns = [
    { key: 'name', label: 'Method', sortable: true },
    { key: 'kind', label: 'Kind', width: '140px' },
    { key: 'requires_reference', label: 'Reference no.', align: 'center', width: '130px' },
    { key: 'payments_count', label: 'Payments', align: 'right', width: '110px' },
    { key: 'is_active', label: 'Status', sortable: true, align: 'center', width: '110px' },
    { key: 'actions', label: 'Actions', align: 'right', width: '110px' },
]

const statusOptions = [
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
]

const filterState = reactive({
    status: props.filters.status ?? '',
    kind: props.filters.kind ?? '',
})

watch(
    () => props.filters,
    (incoming) => {
        filterState.status = incoming.status ?? ''
        filterState.kind = incoming.kind ?? ''
    }
)

const extraQuery = computed(() => ({
    status: filterState.status || undefined,
    kind: filterState.kind || undefined,
}))

const hasFilters = computed(() => filterState.status !== '' || filterState.kind !== '')

function clearFilters() {
    filterState.status = ''
    filterState.kind = ''
}

/* Both forms open here, over the list. The row already holds every value,
   so the dialog opens with no request of its own. */
const modal = useFormModal({
    url: '/payment-methods',
    noun: 'payment method',
    label: (row) => row.name,
    fields: ['name', 'kind', 'requires_reference', 'is_active'],
    blank: {
        name: '',
        kind: 'ewallet',
        requires_reference: true,
        is_active: true,
    },
    fromRow: (row) => ({
        name: row.name ?? '',
        kind: row.kind ?? '',
        requires_reference: !!row.requires_reference,
        is_active: row.is_active,
    }),
})

const editingCash = computed(() => !!modal.editing.value?.is_cash)

async function removeMethod(method) {
    if (!(await confirmDelete({ name: method.name }))) return

    router.delete(`/payment-methods/${method.id}`, {
        preserveScroll: true,
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The payment method was not deleted.'),
    })
}

async function toggleStatus(method) {
    const turningOff = method.is_active

    const agreed = await confirmAction({
        icon: 'warning',
        title: turningOff ? 'Deactivate this payment method?' : 'Activate this payment method?',
        text: turningOff
            ? `${method.name} will no longer be offered at the till or on a new sale.`
            : `${method.name} can be picked at the till again.`,
        confirmText: turningOff ? 'Yes, deactivate' : 'Yes, activate',
    })

    if (!agreed) return

    router.patch(`/payment-methods/${method.id}/status`, {}, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The status did not change.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            heading="Payment Methods"
            subheading="The ways a customer may pay at the till and on a sale."
        >
            <template #actions>
                <PrimaryButton v-if="can.create" @click="modal.openCreate()">
                    <Plus :size="16" />
                    New payment method
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
                    v-model="filterState.kind"
                    label="Kind"
                    any-label="Every kind"
                    :options="kinds"
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
            :data="methods"
            :columns="columns"
            :filters="filters"
            url="/payment-methods"
            :only="['methods', 'filters']"
            :extra-query="extraQuery"
            search-placeholder="Search a method…"
            empty-title="No payment methods yet"
            empty-text="Add a way to pay, such as GCash."
        >
            <template #cell-name="{ row }">
                <span class="font-medium text-ink">{{ row.name }}</span>
                <span
                    v-if="row.is_cash"
                    class="ml-2 inline-flex rounded-full bg-success-50 px-2 py-0.5 text-2xs font-semibold text-success-700"
                    title="Change is given from this method. It cannot be deleted or switched off."
                >
                    Gives change
                </span>
            </template>

            <template #cell-kind="{ row }">
                <span class="text-sm text-ink-muted">{{ row.kind_label }}</span>
            </template>

            <template #cell-requires_reference="{ row }">
                <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="row.requires_reference ? 'bg-brand-50 text-brand-700' : 'bg-surface-sunken text-ink-muted'"
                >
                    {{ row.requires_reference ? 'Required' : 'Not asked' }}
                </span>
            </template>

            <template #cell-payments_count="{ row }">
                <span class="font-mono text-xs text-ink-muted">{{ row.payments_count }}</span>
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
                        :label="row.is_active ? 'Deactivate payment method' : 'Activate payment method'"
                        @click="toggleStatus(row)"
                    />

                    <TableAction
                        variant="edit"
                        :disabled="!row.can.update"
                        label="Edit payment method"
                        @click="modal.openEdit(row)"
                    />

                    <TableAction
                        variant="delete"
                        :disabled="!row.can.delete"
                        label="Delete payment method"
                        @click="removeMethod(row)"
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
            <PaymentMethodFormFields :form="modal.form" :kinds="kinds" :is-cash="editingCash" />

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
