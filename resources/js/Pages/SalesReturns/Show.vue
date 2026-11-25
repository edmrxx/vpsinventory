<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Undo2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    salesReturn: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

// The comparison view. The rate comes with the payload, so the page follows
// the switch the same way a sale does.
const vatRate = computed(() => Number(props.salesReturn.vat_rate ?? 0))
const vatable = computed(() => vatRate.value > 0)

/** Full class strings: a Tailwind class built at runtime is never generated. */
const lineGrid = computed(() => {
    if (vatable.value) {
        return props.can.viewCost
            ? 'md:grid-cols-[1fr_3rem_5.5rem_5.5rem_4.5rem_5.5rem_5.5rem]'
            : 'md:grid-cols-[1fr_3.5rem_6.5rem_6.5rem_5rem_6.5rem]'
    }

    return props.can.viewCost
        ? 'md:grid-cols-[1fr_4rem_7rem_7rem_7rem]'
        : 'md:grid-cols-[1fr_5rem_8rem_8rem]'
})

const details = computed(() => [
    { label: 'Against', value: props.salesReturn.sale_reference ?? '—' },
    { label: 'Customer', value: props.salesReturn.customer ?? '—' },
    { label: 'Date', value: props.salesReturn.return_date ?? '—' },
    { label: 'Back to', value: props.salesReturn.location ?? '—' },
    { label: 'Reason', value: props.salesReturn.reason ?? '—' },
    { label: 'Posted', value: props.salesReturn.posted_at ?? '—' },
    { label: 'Posted by', value: props.salesReturn.posted_by ?? '—' },
])
</script>

<template>
    <div>
        <PageHeader
            :heading="salesReturn.reference"
            :subheading="`Against ${salesReturn.sale_reference} · ${salesReturn.return_date}`"
            back-href="/sales-returns"
            back-label="Back to sales returns"
        />

        <p class="card mb-4 flex items-start gap-2.5 p-4 text-xs text-ink-muted">
            <Undo2 :size="16" class="mt-px shrink-0" />
            <span>
                The stock went back into the batches it came out of, at what it cost there.
                A return is never voided: a mistake is corrected with another return or with
                an adjustment.
            </span>
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <dl class="space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">
                            <Link
                                v-if="detail.label === 'Against'"
                                :href="`/sales/${salesReturn.sale_id}`"
                                class="font-mono text-brand-700 hover:underline"
                            >
                                {{ detail.value }}
                            </Link>
                            <template v-else>{{ detail.value }}</template>
                        </dd>
                    </div>
                </dl>

                <dl class="mt-6 space-y-2 rounded-field bg-surface-sunken p-4">
                    <template v-if="vatable">
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Net of VAT</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(salesReturn.net_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">VAT ({{ vatRate }}%)</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(salesReturn.vat_amount) }}</dd>
                        </div>
                    </template>
                    <div
                        class="flex items-baseline justify-between"
                        :class="vatable ? 'border-t border-line pt-2' : ''"
                    >
                        <dt class="text-xs text-ink-muted">
                            {{ Number(salesReturn.credited_amount) > 0 ? 'Value of the return' : 'Handed back' }}
                        </dt>
                        <dd class="text-2xl font-bold tabular-nums text-ink">
                            {{ money(salesReturn.total_amount) }}
                        </dd>
                    </div>
                    <template v-if="Number(salesReturn.credited_amount) > 0">
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Taken off what is owed</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(salesReturn.credited_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Handed back as cash</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(salesReturn.cash_refund) }}</dd>
                        </div>
                    </template>
                </dl>

                <div v-if="can.viewCost" class="mt-4 rounded-field border border-line p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-ink-muted">Cost of goods back</span>
                        <span class="tabular-nums text-sm text-ink">{{ money(salesReturn.total_cost) }}</span>
                    </div>
                </div>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:gap-3"
                        :class="lineGrid"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">
                            Refund each<template v-if="vatable"> (incl. VAT)</template>
                        </span>
                        <span v-if="vatable" class="text-right">Net of VAT</span>
                        <span v-if="vatable" class="text-right">VAT ({{ vatRate }}%)</span>
                        <span v-if="can.viewCost" class="text-right">Cost</span>
                        <span class="text-right">Total</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in salesReturn.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:items-center md:gap-3"
                            :class="lineGrid"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-muted md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Qty</span>
                                {{ count(line.quantity) }}
                            </span>
                            <span
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-muted md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Refund each</span>
                                {{ money(line.unit_refund) }}
                            </span>
                            <span
                                v-if="vatable"
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-muted md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Net of VAT</span>
                                {{ money(line.net_total) }}
                            </span>
                            <span
                                v-if="vatable"
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-subtle md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">VAT</span>
                                {{ money(line.vat_total) }}
                            </span>
                            <span
                                v-if="can.viewCost"
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-subtle md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Cost</span>
                                {{ money(line.line_cost) }}
                            </span>
                            <span
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm font-semibold text-ink md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Total</span>
                                {{ money(line.line_refund) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="salesReturn.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ salesReturn.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
