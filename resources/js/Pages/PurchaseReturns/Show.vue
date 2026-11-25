<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Undo2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    purchaseReturn: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

// The comparison view; the rate arrives with the payload. What the supplier
// gives back is what the goods cost, so that one value is what is split.
const vatRate = computed(() => Number(props.purchaseReturn.vat_rate ?? 0))
const vatable = computed(() => vatRate.value > 0)

/** Full class strings: a Tailwind class built at runtime is never generated. */
const lineGrid = computed(() =>
    vatable.value
        ? 'md:grid-cols-[1fr_3.5rem_6.5rem_6.5rem_5rem_6.5rem]'
        : 'md:grid-cols-[1fr_5rem_8rem_8rem]'
)

const details = computed(() => [
    { label: 'Against', value: props.purchaseReturn.purchase_reference ?? '—' },
    { label: 'Supplier', value: props.purchaseReturn.supplier ?? '—' },
    { label: 'Date', value: props.purchaseReturn.return_date ?? '—' },
    { label: 'Taken from', value: props.purchaseReturn.location ?? '—' },
    { label: 'Reason', value: props.purchaseReturn.reason ?? '—' },
    { label: 'Posted', value: props.purchaseReturn.posted_at ?? '—' },
    { label: 'Posted by', value: props.purchaseReturn.posted_by ?? '—' },
])
</script>

<template>
    <div>
        <PageHeader
            :heading="purchaseReturn.reference"
            :subheading="`Against ${purchaseReturn.purchase_reference} · ${purchaseReturn.return_date}`"
            back-href="/purchase-returns"
            back-label="Back to purchase returns"
        />

        <p class="card mb-4 flex items-start gap-2.5 p-4 text-xs text-ink-muted">
            <Undo2 :size="16" class="mt-px shrink-0" />
            <span>
                The stock came off the shelf out of this delivery's own batch, at what it cost there.
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
                                :href="`/purchases/${purchaseReturn.purchase_id}`"
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
                            <dd class="tabular-nums text-sm text-ink">{{ money(purchaseReturn.net_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">VAT ({{ vatRate }}%)</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(purchaseReturn.vat_amount) }}</dd>
                        </div>
                    </template>
                    <div
                        class="flex items-baseline justify-between"
                        :class="vatable ? 'border-t border-line pt-2' : ''"
                    >
                        <dt class="text-xs text-ink-muted">Value returned</dt>
                        <dd class="text-2xl font-bold tabular-nums text-ink">
                            {{ money(purchaseReturn.total_cost) }}
                        </dd>
                    </div>
                </dl>

                <!-- How it was settled (phase 24). A return from before then
                     carries neither figure and was refunded in cash. -->
                <dl
                    v-if="Number(purchaseReturn.credited_amount) > 0 || Number(purchaseReturn.supplier_credit_amount) > 0"
                    class="mt-3 space-y-1.5 text-xs"
                >
                    <div v-if="Number(purchaseReturn.credited_amount) > 0" class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Taken off the unpaid bill</dt>
                        <dd class="tabular-nums text-ink">{{ money(purchaseReturn.credited_amount) }}</dd>
                    </div>
                    <div v-if="Number(purchaseReturn.supplier_credit_amount) > 0" class="flex justify-between gap-3">
                        <dt class="text-ink-muted">Kept as credit with the supplier</dt>
                        <dd class="tabular-nums font-semibold text-success-700">{{ money(purchaseReturn.supplier_credit_amount) }}</dd>
                    </div>
                </dl>

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
                            Unit cost<template v-if="vatable"> (incl. VAT)</template>
                        </span>
                        <span v-if="vatable" class="text-right">Net of VAT</span>
                        <span v-if="vatable" class="text-right">VAT ({{ vatRate }}%)</span>
                        <span class="text-right">Total</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in purchaseReturn.lines"
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
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Unit cost</span>
                                {{ money(line.unit_cost) }}
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
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm font-semibold text-ink md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Total</span>
                                {{ money(line.line_cost) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="purchaseReturn.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ purchaseReturn.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
