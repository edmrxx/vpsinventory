<script setup>
import { reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { FilterX } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PayablesTabs from '../../Components/PayablesTabs.vue'
import { useMoney } from '../../lib/money'

/**
 * What the shop paid its suppliers in a period (phase 24.5, CR-5).
 *
 * The client's request after the canvass meeting: which suppliers were paid
 * in cash on delivery and which are payables. One row per supplier — bought,
 * paid on delivery, paid later, still owed — and how the money went out,
 * by method. Every figure comes from `SupplierAccount::paidSummary()` and
 * opens the list behind it. This month until another period is picked.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    today: { type: String, required: true },
    period: { type: String, required: true },
    summary: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const state = reactive({
    supplier_id: props.filters.supplier_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
})

watch(
    () => ({ ...state }),
    (next) => {
        router.get(
            '/payables/paid',
            {
                supplier_id: next.supplier_id || undefined,
                from: next.from || undefined,
                to: next.to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        )
    }
)

function clearFilters() {
    state.supplier_id = ''
    state.from = ''
    state.to = ''
}

/** The list behind a figure, narrowed to the supplier and the period. */
function deliveries(supplierId) {
    return `/purchases?${new URLSearchParams({ supplier_id: supplierId, from: props.filters.from, to: props.filters.to, status: 'posted' })}`
}

function laterPayments(supplierId) {
    return `/supplier-payments?${new URLSearchParams({ supplier_id: supplierId, from: props.filters.from, to: props.filters.to, status: 'posted' })}`
}
</script>

<template>
    <div>
        <PageHeader heading="Payables" :subheading="`What was paid to suppliers, ${period}.`" />
        <PayablesTabs current="paid" />

        <div class="card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <FilterSelect
                    v-model="state.supplier_id"
                    label="Supplier"
                    any-label="All suppliers"
                    :options="options.suppliers ?? []"
                />
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">From</span>
                    <input
                        v-model="state.from"
                        type="date"
                        :max="state.to || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">To</span>
                    <input
                        v-model="state.to"
                        type="date"
                        :min="state.from || undefined"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
            </div>
            <button
                v-if="state.supplier_id"
                type="button"
                class="mt-3 inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                @click="clearFilters"
            >
                <FilterX :size="15" />
                Clear filters
            </button>
        </div>

        <!-- What the period comes to -->
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Paid on delivery</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink" data-total="on_delivery">{{ money(summary.totals.on_delivery) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Paid later</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink" data-total="later">{{ money(summary.totals.later) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Total paid</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-success-700" data-total="paid">{{ money(summary.totals.paid) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Still owed, as of {{ today }}</p>
                <p class="mt-1 tabular-nums text-lg font-bold text-ink" data-total="owed">{{ money(summary.totals.owed) }}</p>
                <p v-if="Number(summary.totals.overdue) > 0" class="text-2xs font-semibold text-warning-700">
                    {{ money(summary.totals.overdue) }} past due
                </p>
            </div>
        </div>

        <!-- By supplier -->
        <section class="card mb-4 overflow-hidden">
            <div class="border-b border-line px-5 py-3.5">
                <h3 class="text-sm font-semibold text-ink">By supplier</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    Bought and paid in the period; still owed as of today. A supplier paid on delivery in cash owes nothing.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-muted text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        <tr>
                            <th class="px-5 py-2.5 text-left">Supplier</th>
                            <th class="px-3 py-2.5 text-right">Bought</th>
                            <th class="px-3 py-2.5 text-right">Paid on delivery</th>
                            <th class="px-3 py-2.5 text-right">Paid later</th>
                            <th class="px-3 py-2.5 text-right">Still owed</th>
                            <th class="px-5 py-2.5 text-right">Past due</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-if="!summary.rows.length">
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-ink-subtle">
                                Nothing bought, paid or owed in this period.
                            </td>
                        </tr>
                        <tr v-for="row in summary.rows" :key="row.supplier_id" class="transition-colors hover:bg-surface-muted">
                            <td class="px-5 py-2.5">
                                <Link :href="`/suppliers/${row.supplier_id}`" class="font-medium text-ink hover:text-brand-700">
                                    {{ row.supplier }}
                                </Link>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">
                                <Link v-if="can.viewPurchases && Number(row.bought) > 0" :href="deliveries(row.supplier_id)" class="text-ink hover:text-brand-700">
                                    {{ money(row.bought) }}
                                </Link>
                                <span v-else class="text-ink-subtle">{{ money(row.bought) }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(row.on_delivery) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">
                                <Link v-if="Number(row.later) > 0" :href="laterPayments(row.supplier_id)" class="text-ink hover:text-brand-700">
                                    {{ money(row.later) }}
                                </Link>
                                <span v-else class="text-ink-subtle">{{ money(row.later) }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">
                                <Link v-if="Number(row.owed) > 0" :href="`/payables?supplier_id=${row.supplier_id}`" class="font-semibold text-ink hover:text-brand-700">
                                    {{ money(row.owed) }}
                                </Link>
                                <span v-else class="text-ink-subtle">{{ money(row.owed) }}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums" :class="Number(row.overdue) > 0 ? 'font-semibold text-warning-700' : 'text-ink-subtle'">
                                {{ money(row.overdue) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="summary.rows.length" class="border-t border-line-strong bg-surface-muted font-semibold">
                        <tr>
                            <td class="px-5 py-2.5 text-ink">Total</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(summary.totals.bought) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(summary.totals.on_delivery) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(summary.totals.later) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(summary.totals.owed) }}</td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-ink">{{ money(summary.totals.overdue) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- By method -->
        <section class="card overflow-hidden">
            <div class="border-b border-line px-5 py-3.5">
                <h3 class="text-sm font-semibold text-ink">How it was paid</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    Cash, cheque, bank or e-wallet. A delivery from before 27 November 2026 shows as <em>Not recorded</em>.
                </p>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                    <tr>
                        <th class="px-5 py-2.5 text-left">Method</th>
                        <th class="px-3 py-2.5 text-right">On delivery</th>
                        <th class="px-3 py-2.5 text-right">Later</th>
                        <th class="px-5 py-2.5 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-if="!summary.methods.length">
                        <td colspan="4" class="px-5 py-6 text-center text-sm text-ink-subtle">Nothing paid in this period.</td>
                    </tr>
                    <tr v-for="method in summary.methods" :key="method.name">
                        <td class="px-5 py-2.5 text-ink">{{ method.name }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(method.on_delivery) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-ink">{{ money(method.later) }}</td>
                        <td class="px-5 py-2.5 text-right tabular-nums font-semibold text-ink">{{ money(method.total) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
