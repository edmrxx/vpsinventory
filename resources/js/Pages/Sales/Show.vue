<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Ban, Printer, Receipt, Undo2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { Swal, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'
import { printSlip } from '../../lib/slip'

defineOptions({ layout: AppLayout })

const props = defineProps({
    sale: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const details = computed(() => [
    { label: 'Customer', value: props.sale.customer ?? '—' },
    { label: 'Date', value: props.sale.sale_date ?? '—' },
    { label: 'Sold from', value: props.sale.location ?? '—' },
    { label: 'Posted', value: props.sale.posted_at ?? '—' },
    { label: 'Posted by', value: props.sale.posted_by ?? '—' },
    // Fulfilled a sales order (phase 29).
    ...(props.sale.sales_order ? [{ label: 'Fulfilled order', value: props.sale.sales_order.reference }] : []),
    // Made from a quotation (phase 28).
    ...(props.sale.quotation ? [{ label: 'From quotation', value: props.sale.quotation.reference }] : []),
])

/**
 * The rate this sale was posted at, not the one in the settings today.
 *
 * The VAT in the footer is taken from what was charged, after the discount,
 * so the line splits will not add up to it when a discount was given. The
 * footer follows the money, which is the figure that counts.
 */
const vatRate = computed(() => Number(props.sale.vat_rate ?? 0))
const vatable = computed(() => vatRate.value > 0)

/**
 * Full class strings. A Tailwind class built at runtime is never generated,
 * so all four shapes are written out rather than pieced together.
 */
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

const margin = computed(() => {
    if (!props.can.viewCost) return null

    const total = Number(props.sale.total_amount ?? 0)

    return {
        profit: Number(props.sale.gross_profit ?? 0),
        percent: total > 0 ? Math.round((Number(props.sale.gross_profit ?? 0) / total) * 100) : null,
    }
})

async function voidSale() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this sale?',
        text: 'The stock goes back into the batches it came out of. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: customer returned it, wrong order',
        inputAttributes: { maxlength: 255 },
        showCancelButton: true,
        confirmButtonText: 'Yes, void it',
        cancelButtonText: 'Cancel',
        confirmButtonColor: 'oklch(0.55 0.21 25)',
        inputValidator: (value) =>
            (value ?? '').trim().length < 3 ? 'Please say why, in a few words.' : undefined,
        customClass: {
            popup: 'maf-swal-popup',
            title: 'maf-swal-title',
            actions: 'maf-swal-actions',
            confirmButton: 'maf-swal-confirm',
            cancelButton: 'maf-swal-cancel',
        },
    })

    if (!isConfirmed) return

    router.post(`/sales/${props.sale.id}/void`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The sale was not voided.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="sale.reference"
            :subheading="`${sale.customer ?? 'Unknown customer'} · ${sale.sale_date}`"
            back-href="/sales"
            back-label="Back to sales"
        >
            <template #actions>
                <!-- The customer's copy: no cost, never an official receipt. -->
                <PrimaryButton variant="secondary" @click="printSlip(sale)">
                    <Printer :size="15" />
                    Print slip
                </PrimaryButton>
                <PrimaryButton
                    v-if="can.return"
                    variant="secondary"
                    :href="`/sales-returns/create?sale=${sale.id}`"
                >
                    <Undo2 :size="15" />
                    Return
                </PrimaryButton>
                <PrimaryButton v-if="can.void" variant="danger" @click="voidSale">
                    <Ban :size="15" />
                    Void
                </PrimaryButton>
            </template>
        </PageHeader>

        <p
            v-if="sale.status === 'voided'"
            class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700"
        >
            <Ban :size="16" class="mt-px shrink-0" />
            <span>
                Voided on {{ sale.voided_at }} by {{ sale.voided_by ?? 'someone' }}:
                {{ sale.void_reason }}
            </span>
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand"
                    >
                        <Receipt :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">
                            {{ sale.reference }}
                        </p>
                        <div class="mt-1.5">
                            <ActionBadge :label="sale.status_label" :tone="sale.status_tone" />
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
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>

                <dl class="mt-6 space-y-2 rounded-field bg-surface-sunken p-4">
                    <div class="flex items-center justify-between">
                        <dt class="text-xs text-ink-muted">Subtotal</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(sale.subtotal) }}</dd>
                    </div>
                    <div v-if="Number(sale.mobilization) > 0" class="flex items-center justify-between">
                        <dt class="text-xs text-ink-muted">Mobilization</dt>
                        <dd class="tabular-nums text-sm text-ink">+{{ money(sale.mobilization) }}</dd>
                    </div>
                    <div v-if="Number(sale.discount) > 0" class="flex items-center justify-between">
                        <dt class="text-xs text-ink-muted">Discount</dt>
                        <dd class="tabular-nums text-sm text-ink">−{{ money(sale.discount) }}</dd>
                    </div>
                    <template v-if="vatable">
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Net of VAT</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(sale.net_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">VAT ({{ vatRate }}%)</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(sale.vat_amount) }}</dd>
                        </div>
                    </template>
                    <div class="flex items-center justify-between border-t border-line pt-2">
                        <dt class="text-xs font-semibold text-ink">Total</dt>
                        <dd class="tabular-nums text-xl font-bold text-ink">
                            {{ money(sale.total_amount) }}
                        </dd>
                    </div>
                </dl>

                <!-- How it was paid -->
                <div v-if="(sale.payments ?? []).length" class="mt-4 rounded-field border border-line p-4">
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Paid by</p>
                    <dl class="mt-2 space-y-2.5">
                        <div v-for="payment in sale.payments" :key="payment.id">
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-sm text-ink">{{ payment.method }}</dt>
                                <dd class="tabular-nums text-sm font-semibold text-ink">{{ money(payment.amount) }}</dd>
                            </div>
                            <p v-if="payment.tendered !== null" class="text-2xs text-ink-subtle">
                                Received {{ money(payment.tendered) }}
                            </p>
                            <p v-if="payment.reference" class="font-mono text-2xs break-all text-ink-subtle">
                                Ref {{ payment.reference }}
                            </p>
                        </div>
                    </dl>
                    <div
                        v-if="Number(sale.change) > 0"
                        class="mt-3 flex items-baseline justify-between border-t border-line pt-2"
                    >
                        <span class="text-xs text-ink-muted">Change given</span>
                        <span class="tabular-nums text-sm font-semibold text-ink">{{ money(sale.change) }}</span>
                    </div>
                </div>

                <!-- What was not paid at the counter -->
                <dl v-if="Number(sale.on_account) > 0" class="mt-4 space-y-2 rounded-field border border-line p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-ink">On account</dt>
                        <dd class="tabular-nums text-sm font-semibold text-ink">{{ money(sale.on_account) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs text-ink-muted">Still owed</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(sale.balance_due) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs text-ink-muted">Due</dt>
                        <dd class="text-sm text-ink">
                            {{ sale.due_date }}<span v-if="sale.terms_days !== null && sale.terms_days !== undefined" class="text-ink-muted">
                                · {{ sale.terms_days }} {{ sale.terms_days === 1 ? 'day' : 'days' }}</span>
                        </dd>
                    </div>
                    <!-- What the customer has paid against it since. -->
                    <div
                        v-for="paid in sale.account_payments ?? []"
                        :key="paid.id"
                        class="flex items-baseline justify-between gap-3 border-t border-line pt-2"
                    >
                        <dt class="text-xs text-ink-muted">
                            Paid {{ paid.payment_date }} ·
                            <Link :href="`/customer-payments/${paid.id}`" class="font-mono text-brand-700 hover:underline">{{ paid.reference }}</Link>
                        </dt>
                        <dd class="tabular-nums text-sm text-success-700">−{{ money(paid.amount) }}</dd>
                    </div>
                </dl>

                <!-- Only reaches the screen for the people allowed to see it -->
                <div v-if="margin" class="mt-4 rounded-field border border-line p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-ink-muted">Cost of goods</span>
                        <span class="tabular-nums text-sm text-ink">{{ money(sale.total_cost) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                        <span class="text-xs font-semibold text-ink">Gross profit</span>
                        <span
                            class="tabular-nums text-base font-bold"
                            :class="margin.profit < 0 ? 'text-danger-700' : 'text-success-700'"
                        >
                            {{ money(margin.profit) }}
                            <span v-if="margin.percent !== null" class="text-xs font-medium text-ink-subtle">
                                ({{ margin.percent }}%)
                            </span>
                        </span>
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
                            Price<template v-if="vatable"> (incl. VAT)</template>
                        </span>
                        <span v-if="vatable" class="text-right">Net of VAT</span>
                        <span v-if="vatable" class="text-right">VAT ({{ vatRate }}%)</span>
                        <span v-if="can.viewCost" class="text-right">Cost</span>
                        <span class="text-right">Total</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in sale.lines"
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
                                <!-- Typed by the box: 2 box, with the pieces under it. -->
                                <span v-if="line.converted">
                                    {{ count(line.unit_quantity) }} {{ line.unit_code }}
                                    <span class="block text-2xs text-ink-subtle">
                                        {{ count(line.quantity) }} {{ line.unit }}
                                    </span>
                                </span>
                                <template v-else>{{ count(line.quantity) }}</template>
                            </span>
                            <span
                                class="flex items-baseline justify-between gap-3 tabular-nums text-sm text-ink-muted md:block md:text-right"
                            >
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Price</span>
                                {{ money(line.unit_price) }}<template v-if="line.converted"> / {{ line.unit_code }}</template>
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
                                {{ money(line.line_total) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="sale.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ sale.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
