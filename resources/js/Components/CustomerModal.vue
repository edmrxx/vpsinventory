<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Banknote, ExternalLink, FileText, Pencil, TriangleAlert } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import ActionBadge from './ActionBadge.vue'
import { useMoney } from '../lib/money'

/**
 * One customer, read without leaving the list (24 November 2026).
 *
 * Opening the customer's page used to lose the list behind it — the sort by
 * balance, the filters, the page — so the next customer meant starting
 * again. This is the summary: who they are, the account and what is unpaid,
 * with the statement, a payment and the edit one tap away. "Open full page"
 * has the rest.
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

const url = computed(() => (props.id ? `/customers/${props.id}/preview` : null))

/** The unpaid invoices shown here; the page lists them all. */
const SHOWN = 6

const days = (n) => `${n} ${Number(n) === 1 ? 'day' : 'days'}`
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5" data-customer-dialog>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2.5">
                            <h3 class="truncate text-base font-bold text-ink">{{ data.customer.name }}</h3>
                            <ActionBadge
                                :label="data.customer.is_active ? 'Active' : 'Inactive'"
                                :tone="data.customer.is_active ? 'success' : 'neutral'"
                            />
                        </div>
                        <p class="font-mono text-xs text-ink-subtle">{{ data.customer.code }}</p>
                    </div>

                    <Link
                        :href="`/customers/${data.customer.id}`"
                        class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                    >
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Contact person', value: data.customer.contact_person ?? '—' },
                            { label: 'Phone', value: data.customer.phone ?? '—' },
                            { label: 'Email', value: data.customer.email ?? '—' },
                            { label: 'TIN', value: data.customer.tin ?? '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>

                <!-- The account. Walk-in has none. -->
                <section v-if="data.account">
                    <h4 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                        Credit
                        <span
                            v-if="data.flags.overdue"
                            class="inline-flex items-center gap-1 rounded-full bg-warning-50 px-2 py-px text-2xs font-semibold text-warning-700"
                        >
                            <TriangleAlert :size="11" /> {{ money(data.flags.overdue_amount) }} overdue
                        </span>
                        <span
                            v-if="data.flags.over_limit"
                            class="inline-flex items-center gap-1 rounded-full bg-danger-50 px-2 py-px text-2xs font-semibold text-danger-700"
                        >
                            <TriangleAlert :size="11" /> Over the limit
                        </span>
                    </h4>

                    <p
                        v-if="Number(data.account.limit) === 0 && Number(data.account.balance) === 0"
                        class="mt-2 text-sm text-ink-subtle"
                    >
                        No credit. Every sale to this customer is paid at the counter.
                    </p>

                    <template v-else>
                        <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Limit</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(data.account.limit) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Owed</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(data.account.balance) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Credit left</dt>
                                <dd class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(data.account.available) }}</dd>
                            </div>
                            <div class="rounded-field bg-surface-sunken p-3">
                                <dt class="text-2xs text-ink-muted">Usual terms</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-ink">{{ days(data.account.terms_days) }}</dd>
                            </div>
                        </dl>

                        <ul v-if="data.unpaid.length" class="mt-3 divide-y divide-line">
                            <li
                                v-for="sale in data.unpaid.slice(0, SHOWN)"
                                :key="sale.id"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <span class="min-w-0">
                                    <span class="block font-mono text-xs font-semibold text-ink">{{ sale.reference }}</span>
                                    <span class="block text-2xs text-ink-subtle">
                                        {{ sale.sale_date }} ·
                                        <span :class="sale.overdue ? 'font-semibold text-warning-700' : ''">
                                            {{ sale.overdue ? 'was due' : 'due' }} {{ sale.due_date }}
                                        </span>
                                    </span>
                                </span>
                                <span class="shrink-0 tabular-nums text-sm font-semibold text-ink">{{ money(sale.balance_due) }}</span>
                            </li>
                        </ul>
                        <p v-if="data.unpaid.length > SHOWN" class="mt-2 text-2xs text-ink-subtle">
                            And {{ data.unpaid.length - SHOWN }} more unpaid — see them all on the full page.
                        </p>
                    </template>
                </section>

                <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <Link
                        v-if="data.can.statement"
                        :href="`/customers/${data.customer.id}/statement`"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                    >
                        <FileText :size="15" />
                        Statement
                    </Link>
                    <Link
                        v-if="data.can.pay && Number(data.account?.balance ?? 0) > 0"
                        :href="`/customer-payments/create?customer=${data.customer.id}`"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                    >
                        <Banknote :size="15" />
                        Record payment
                    </Link>
                    <button
                        v-if="data.can.update"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field border border-line-strong px-3 text-sm font-semibold text-ink hover:bg-surface-sunken"
                        @click="$emit('edit', data.customer.id)"
                    >
                        <Pencil :size="15" />
                        Edit
                    </button>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
