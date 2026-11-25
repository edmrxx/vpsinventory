<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { Banknote, FileText, Pencil, Trash2, TriangleAlert, UserRound } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import Modal from '../../Components/Modal.vue'
import CustomerFormFields from './CustomerFormFields.vue'
import { useFormModal } from '../../composables/useFormModal'
import { useMoney } from '../../lib/money'
import StatusPill from '../../Components/StatusPill.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import { confirmDelete, toastError } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const { money } = useMoney()

const props = defineProps({
    customer: { type: Object, required: true },
    /** Limit, owed and what is left. Null for walk-in. */
    account: { type: Object, default: null },
    /** Overdue and over the limit, as of today. */
    flags: { type: Object, default: () => ({}) },
    unpaid: { type: Array, default: () => [] },
    sales: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
})

const details = computed(() => [
    { label: 'Contact person', value: props.customer.contact_person ?? '—' },
    { label: 'Phone', value: props.customer.phone ?? '—' },
    { label: 'Email', value: props.customer.email ?? '—' },
    { label: 'TIN', value: props.customer.tin ?? '—' },
    { label: 'Added', value: props.customer.created_at ?? '—' },
    { label: 'Last changed', value: props.customer.updated_at ?? '—' },
])

/* Editing opens the same dialog as the list, over this screen. */
const modal = useFormModal({
    url: '/customers',
    noun: 'customer',
    label: (row) => row.code,
    fields: ['code', 'name', 'contact_person', 'email', 'phone', 'tin', 'address', 'notes', 'credit_limit', 'payment_terms_days'],
    blank: {
        code: '', name: '', contact_person: '', email: '',
        phone: '', tin: '', address: '', notes: '', is_active: true,
        credit_limit: '0.00', payment_terms_days: 0,
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

/**
 * A recent sale opens in a dialog over this page, like every list does.
 * The link keeps its real address, so a ctrl-click or a middle-click still
 * opens the full page in a new tab; only a plain click is kept here.
 */
const viewing = ref(null)

function viewSale(event, sale) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return

    event.preventDefault()
    viewing.value = `/sales/${sale.id}`
}

async function removeCustomer() {
    if (!(await confirmDelete({ name: props.customer.name }))) return

    router.delete(`/customers/${props.customer.id}`, {
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? 'The customer was not deleted.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="customer.name"
            :subheading="customer.code"
            back-href="/customers"
            back-label="Back to customers"
        >
            <template #actions>
                <PrimaryButton v-if="can.statement" variant="secondary" :href="`/customers/${customer.id}/statement`">
                    <FileText :size="15" />
                    Statement
                </PrimaryButton>
                <PrimaryButton
                    v-if="can.pay && Number(account?.balance ?? 0) > 0"
                    variant="secondary"
                    :href="`/customer-payments/create?customer=${customer.id}`"
                >
                    <Banknote :size="15" />
                    Record payment
                </PrimaryButton>
                <PrimaryButton
                    v-if="can.update"
                    variant="secondary"
                    @click="modal.openEdit(customer)"
                >
                    <Pencil :size="15" />
                    Edit
                </PrimaryButton>
                <PrimaryButton v-if="can.delete" variant="danger" @click="removeCustomer">
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
                        <UserRound :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-base font-semibold text-ink">{{ customer.name }}</p>
                        <div class="mt-1.5">
                            <StatusPill :active="customer.is_active" />
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
                    {{ customer.address ?? 'No address on file.' }}
                </p>

                <h3 class="mt-7 text-sm font-semibold text-ink">Notes</h3>
                <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">
                    {{ customer.notes ?? 'No notes.' }}
                </p>

                <template v-if="account">
                    <h3 class="mt-7 flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                        Credit
                        <span v-if="flags.overdue"
                            class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-px text-2xs font-semibold text-warning-700">
                            <TriangleAlert :size="11" /> {{ money(flags.overdue_amount) }} overdue
                        </span>
                        <span v-if="flags.over_limit"
                            class="inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-px text-2xs font-semibold text-danger-700">
                            <TriangleAlert :size="11" /> Over the limit
                        </span>
                    </h3>

                    <p v-if="Number(account.limit) === 0 && Number(account.balance) === 0" class="mt-2 text-sm text-ink-subtle">
                        No credit. Every sale to this customer is paid at the counter.
                    </p>

                    <template v-else>
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Limit</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(account.limit) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Owed</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(account.balance) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Credit left</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(account.available) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Usual terms</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-ink">
                                    {{ account.terms_days }} {{ account.terms_days === 1 ? 'day' : 'days' }}
                                </dd>
                            </div>
                        </dl>

                        <ul v-if="unpaid.length" class="mt-3 divide-y divide-line">
                            <li v-for="sale in unpaid" :key="sale.id">
                                <a
                                    :href="`/sales/${sale.id}`"
                                    class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:bg-surface-sunken"
                                    @click="viewSale($event, sale)"
                                >
                                    <span class="min-w-0">
                                        <span class="block font-mono text-xs font-semibold text-ink">
                                            {{ sale.reference }}
                                        </span>
                                        <span class="block text-2xs text-ink-subtle">
                                            {{ sale.sale_date }} ·
                                            <span :class="sale.overdue ? 'font-semibold text-warning-700' : ''">
                                                {{ sale.overdue ? 'was due' : 'due' }} {{ sale.due_date }}
                                            </span>
                                        </span>
                                    </span>
                                    <span class="tabular-nums shrink-0 text-sm font-semibold text-ink">
                                        {{ money(sale.balance_due) }}
                                    </span>
                                </a>
                            </li>
                        </ul>
                    </template>
                </template>

                <h3 class="mt-7 text-sm font-semibold text-ink">Recent sales</h3>

                <p v-if="sales.length === 0" class="mt-2 text-sm text-ink-subtle">
                    Nothing has been sold to this customer yet.
                </p>

                <ul v-else class="mt-3 divide-y divide-line">
                    <li v-for="sale in sales" :key="sale.id">
                        <a
                            :href="`/sales/${sale.id}`"
                            class="flex items-center justify-between gap-3 py-2.5 transition-colors hover:bg-surface-sunken"
                            @click="viewSale($event, sale)"
                        >
                            <span class="min-w-0">
                                <span class="block font-mono text-xs font-semibold text-ink">
                                    {{ sale.reference }}
                                </span>
                                <span class="block text-2xs text-ink-subtle">{{ sale.sale_date }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <ActionBadge :label="sale.status_label" :tone="sale.status_tone" />
                                <span class="tabular-nums text-sm font-semibold text-ink">
                                    {{ money(sale.total_amount) }}
                                </span>
                            </span>
                        </a>
                    </li>
                </ul>
            </section>
        </div>

        <Modal
            :open="modal.open.value"
            :title="modal.title.value"
            :subtitle="modal.subtitle.value"
            :busy="modal.form.processing"
            size="lg"
            @close="modal.close()"
        >
            <CustomerFormFields :form="modal.form" mode="edit"
                :is-walk-in="customer.is_walk_in ?? false"
                :can-credit="can.credit ?? false" />

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

        <DocumentModal
            :open="viewing !== null"
            :document-url="viewing"
            @close="viewing = null"
        />
    </div>
</template>
