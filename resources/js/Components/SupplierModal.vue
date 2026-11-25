<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Banknote, ExternalLink, Pencil } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import ActionBadge from './ActionBadge.vue'
import { useMoney } from '../lib/money'

/**
 * One supplier, read without leaving the list (24 November 2026).
 *
 * The summary: who they are, whether they charge VAT, and — for whoever may
 * see payables — what is owed, past due and held as credit, with the bills
 * behind it. Paying and editing are one tap away; "Open full page" has the
 * deliveries too.
 *
 * The payload is the controller's own `detail()`, the same one the page
 * renders, so the two cannot disagree.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    id: { type: [Number, String], default: null },
})

defineEmits(['close', 'edit'])

const { money } = useMoney()

const url = computed(() => (props.id ? `/suppliers/${props.id}/preview` : null))

const SHOWN = 6

const terms = (n) => (Number(n) === 0 ? 'Due on delivery' : `${n} ${Number(n) === 1 ? 'day' : 'days'}`)
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5" data-supplier-dialog>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2.5">
                            <h3 class="truncate text-base font-bold text-ink">{{ data.supplier.name }}</h3>
                            <ActionBadge
                                :label="data.supplier.is_active ? 'Active' : 'Inactive'"
                                :tone="data.supplier.is_active ? 'success' : 'neutral'"
                            />
                        </div>
                        <p class="font-mono text-xs text-ink-subtle">{{ data.supplier.code }}</p>
                    </div>

                    <Link
                        :href="`/suppliers/${data.supplier.id}`"
                        class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                    >
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Contact person', value: data.supplier.contact_person ?? '—' },
                            { label: 'Phone', value: data.supplier.phone ?? '—' },
                            { label: 'Email', value: data.supplier.email ?? '—' },
                            { label: 'VAT', value: data.supplier.vat_label ?? '—' },
                            { label: 'Payment terms', value: terms(data.supplier.payment_terms_days) },
                            { label: 'TIN', value: data.supplier.tin ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>

                <!-- What the shop owes them, for whoever may see payables. -->
                <section v-if="data.account">
                    <h4 class="text-sm font-semibold text-ink">What we owe</h4>

                    <dl class="mt-3 grid grid-cols-3 gap-3">
                        <div class="rounded-field bg-surface-sunken p-3">
                            <dt class="text-2xs text-ink-muted">Owed</dt>
                            <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(data.account.owed) }}</dd>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <dt class="text-2xs text-ink-muted">Past due</dt>
                            <dd
                                class="mt-0.5 tabular-nums text-sm font-semibold"
                                :class="Number(data.account.overdue) > 0 ? 'text-warning-700' : 'text-ink'"
                            >
                                {{ money(data.account.overdue) }}
                            </dd>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <dt class="text-2xs text-ink-muted">Their credit</dt>
                            <dd
                                class="mt-0.5 tabular-nums text-sm font-semibold"
                                :class="Number(data.account.credit) > 0 ? 'text-success-700' : 'text-ink'"
                            >
                                {{ money(data.account.credit) }}
                            </dd>
                        </div>
                    </dl>

                    <ul v-if="data.account.bills.length" class="mt-3 divide-y divide-line">
                        <li
                            v-for="bill in data.account.bills.slice(0, SHOWN)"
                            :key="bill.id"
                            class="flex items-center justify-between gap-3 py-2"
                        >
                            <span class="min-w-0">
                                <span class="block font-mono text-xs font-semibold text-ink">{{ bill.reference }}</span>
                                <span class="block text-2xs" :class="bill.overdue ? 'font-semibold text-warning-700' : 'text-ink-subtle'">
                                    {{ bill.overdue ? 'was due' : 'due' }} {{ bill.due_date ?? '—' }}
                                </span>
                            </span>
                            <span class="shrink-0 tabular-nums text-sm font-semibold text-ink">{{ money(bill.balance_due) }}</span>
                        </li>
                    </ul>
                    <p v-if="data.account.bills.length > SHOWN" class="mt-2 text-2xs text-ink-subtle">
                        And more bills — see them all on the full page.
                    </p>
                </section>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <Link
                        v-if="data.can.pay && Number(data.account?.owed ?? 0) > 0"
                        :href="`/supplier-payments/create?supplier=${data.supplier.id}`"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                    >
                        <Banknote :size="15" />
                        Pay bills
                    </Link>
                    <button
                        v-if="data.can.update"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                        @click="$emit('edit', data.supplier.id)"
                    >
                        <Pencil :size="15" />
                        Edit
                    </button>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
