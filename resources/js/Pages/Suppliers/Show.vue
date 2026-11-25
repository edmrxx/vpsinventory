<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Landmark, Pencil, Trash2, Truck } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import Modal from '../../Components/Modal.vue'
import SupplierFormFields from './SupplierFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { useMoney } from '../../lib/money'
import StatusPill from '../../Components/StatusPill.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const { money } = useMoney()

const props = defineProps({
    supplier: { type: Object, required: true },
    purchases: { type: Array, default: () => [] },
    /** What the shop owes this supplier (phase 24); null without payables. */
    account: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
})

const details = computed(() => [
    { label: 'Contact person', value: props.supplier.contact_person ?? '—' },
    { label: 'Phone', value: props.supplier.phone ?? '—' },
    { label: 'Email', value: props.supplier.email ?? '—' },
    { label: 'TIN', value: props.supplier.tin ?? '—' },
    { label: 'VAT / Non-VAT', value: props.supplier.vat_label ?? '—' },
    { label: 'Added', value: props.supplier.created_at ?? '—' },
    { label: 'Last changed', value: props.supplier.updated_at ?? '—' },
])

/* Editing opens the same dialog as the list, over this screen. */
const modal = useFormModal({
    url: '/suppliers',
    noun: 'supplier',
    label: (row) => row.code,
    fields: ['code', 'name', 'contact_person', 'email', 'phone', 'tin', 'payment_terms_days', 'address', 'notes'],
    blank: {
        code: '', name: '', contact_person: '', email: '',
        phone: '', tin: '', payment_terms_days: 0, address: '', notes: '', is_active: true,
    },
    fromRow: (row) => ({
        code: row.code ?? '',
        name: row.name ?? '',
        contact_person: row.contact_person ?? '',
        email: row.email ?? '',
        phone: row.phone ?? '',
        tin: row.tin ?? '',
        payment_terms_days: row.payment_terms_days ?? 0,
        address: row.address ?? '',
        notes: row.notes ?? '',
        is_active: row.is_active,
    }),
})

async function removeSupplier() {
    if (!(await confirmDelete({ name: props.supplier.name }))) return

    router.delete(`/suppliers/${props.supplier.id}`, {
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The supplier was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="supplier.name"
            :subheading="supplier.code"
            back-href="/suppliers"
            back-label="Back to suppliers"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.update"
                    variant="secondary"
                    @click="modal.openEdit(supplier)"
                >
                    <Pencil :size="15" />
                    Edit
                </PrimaryButton>
                <PrimaryButton v-if="can.delete" variant="danger" @click="removeSupplier">
                    <Trash2 :size="15" />
                    Delete
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand"
                    >
                        <Truck :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-ink">{{ supplier.name }}</p>
                        <div class="mt-1.5">
                            <StatusPill :active="supplier.is_active" />
                        </div>
                    </div>
                </div>

                <dl class="mt-6 space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">
                            {{ detail.value }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="card p-5 sm:p-6 lg:col-span-2">
                <h3 class="text-sm font-semibold text-ink">Address</h3>
                <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">
                    {{ supplier.address ?? 'No address on file.' }}
                </p>

                <h3 class="mt-7 text-sm font-semibold text-ink">Notes</h3>
                <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">
                    {{ supplier.notes ?? 'No notes.' }}
                </p>

                <h3 class="mt-7 text-sm font-semibold text-ink">Recent purchases</h3>

                <p v-if="purchases.length === 0" class="mt-2 text-sm text-ink-subtle">
                    Nothing has been bought from this supplier yet.
                </p>

                <ul v-else class="mt-3 divide-y divide-line">
                    <li v-for="purchase in purchases" :key="purchase.id">
                        <Link
                            :href="`/purchases/${purchase.id}`"
                            class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:bg-surface-sunken"
                        >
                            <span class="min-w-0">
                                <span class="block font-mono text-xs font-semibold text-ink">
                                    {{ purchase.reference }}
                                </span>
                                <span class="block text-2xs text-ink-subtle">
                                    {{ purchase.purchase_date }}
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <ActionBadge :label="purchase.status_label" :tone="purchase.status_tone" />
                                <span class="tabular-nums text-sm font-semibold text-ink">
                                    {{ money(purchase.total_amount) }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <!-- What the shop owes this supplier (phase 24) -->
        <section v-if="account" class="card mt-4 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Account</h3>
                    <p class="text-xs text-ink-subtle">
                        Payment terms: {{ account.terms_days }} {{ account.terms_days === 1 ? 'day' : 'days' }}{{ account.terms_days === 0 ? ' (due on delivery)' : '' }}.
                    </p>
                </div>
                <PrimaryButton v-if="can.pay && Number(account.owed) > 0" variant="secondary" :href="`/supplier-payments/create?supplier=${supplier.id}`">
                    <Landmark :size="15" />
                    Pay supplier
                </PrimaryButton>
            </div>

            <dl class="mt-4 grid grid-cols-3 gap-3">
                <div class="rounded-field bg-surface-sunken p-3">
                    <dt class="text-2xs text-ink-muted">Owed to them</dt>
                    <dd class="mt-0.5 tabular-nums text-base font-bold text-ink">{{ money(account.owed) }}</dd>
                </div>
                <div class="rounded-field bg-surface-sunken p-3">
                    <dt class="text-2xs text-ink-muted">Past due</dt>
                    <dd class="mt-0.5 tabular-nums text-base font-bold" :class="Number(account.overdue) > 0 ? 'text-warning-700' : 'text-ink'">{{ money(account.overdue) }}</dd>
                </div>
                <div class="rounded-field bg-surface-sunken p-3">
                    <dt class="text-2xs text-ink-muted">Credit with them</dt>
                    <dd class="mt-0.5 tabular-nums text-base font-bold" :class="Number(account.credit) > 0 ? 'text-success-700' : 'text-ink'">{{ money(account.credit) }}</dd>
                </div>
            </dl>

            <ul v-if="account.bills.length" class="mt-4 divide-y divide-line border-t border-line">
                <li v-for="bill in account.bills" :key="bill.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>
                        <Link :href="`/purchases/${bill.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ bill.reference }}</Link>
                        <span v-if="bill.supplier_reference" class="ml-2 text-xs text-ink-subtle">invoice {{ bill.supplier_reference }}</span>
                    </span>
                    <span class="text-xs" :class="bill.overdue ? 'font-semibold text-warning-700' : 'text-ink-muted'">Due {{ bill.due_date ?? '—' }}</span>
                    <span class="tabular-nums font-semibold text-ink">{{ money(bill.balance_due) }}</span>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-ink-subtle">Nothing is owed to this supplier.</p>
        </section>

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <SupplierFormFields :form="modal.form" mode="edit" />

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
