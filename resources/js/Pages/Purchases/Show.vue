<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Ban, FileText, Info, Landmark, ShoppingCart, Undo2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import { Swal, toastError } from '../../lib/swal'
import { count, useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    purchase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

/**
 * The rate this delivery was booked at, not the one in the settings today.
 *
 * Zero on everything bought before the business registered for VAT, and the
 * columns simply do not appear.
 */
const vatRate = computed(() => Number(props.purchase.vat_rate ?? 0))
const vatable = computed(() => vatRate.value > 0)

/** Full class strings: a Tailwind class built at runtime is never generated. */
const lineGrid = computed(() =>
    vatable.value
        ? 'md:grid-cols-[1fr_3.5rem_6.5rem_6.5rem_5rem_6.5rem]'
        : 'md:grid-cols-[1fr_5rem_8rem_8rem]'
)

const details = computed(() => [
    { label: 'Supplier', value: props.purchase.supplier ?? '—' },
    { label: 'Their invoice no.', value: props.purchase.supplier_reference ?? '—' },
    { label: 'Date', value: props.purchase.purchase_date ?? '—' },
    { label: 'Received at', value: props.purchase.location ?? '—' },
    { label: 'Posted', value: props.purchase.posted_at ?? '—' },
    { label: 'Posted by', value: props.purchase.posted_by ?? '—' },
    ...(props.purchase.order ? [{ label: 'Against order', value: props.purchase.order.reference }] : []),
])

async function voidPurchase() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: 'Void this purchase?',
        text: 'The stock it brought in comes straight back off the shelf. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: wrong supplier, duplicate delivery',
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

    router.post(`/purchases/${props.purchase.id}/void`, { reason }, {
        preserveScroll: true,
        onError: (errors) => toastError(Object.values(errors)[0] ?? 'The purchase was not voided.'),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="purchase.reference"
            :subheading="`${purchase.supplier ?? 'Unknown supplier'} · ${purchase.purchase_date}`"
            back-href="/purchases"
            back-label="Back to purchases"
        >
            <template #actions>
                <PrimaryButton
                    v-if="can.return"
                    variant="secondary"
                    :href="`/purchase-returns/create?purchase=${purchase.id}`"
                >
                    <Undo2 :size="15" />
                    Return
                </PrimaryButton>
                <PrimaryButton v-if="can.void" variant="danger" @click="voidPurchase">
                    <Ban :size="15" />
                    Void
                </PrimaryButton>
            </template>
        </PageHeader>

        <!-- The order this delivery was received against (phase 23) -->
        <p v-if="purchase.order" class="card mb-4 flex flex-wrap items-center gap-1.5 p-4 text-xs font-medium text-ink">
            <FileText :size="15" class="text-brand-700" />
            Received against order
            <Link v-if="can.viewOrder" :href="`/purchase-orders/${purchase.order.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ purchase.order.reference }}</Link>
            <span v-else class="font-mono font-semibold">{{ purchase.order.reference }}</span>.
            <span class="text-ink-muted">Voiding it puts these goods back on the order as still to come.</span>
        </p>

        <!-- The bill, when it went on the supplier's account (phase 24) -->
        <section v-if="Number(purchase.on_account) > 0" class="card mb-4 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                        <Landmark :size="18" />
                    </span>
                    <div class="text-sm">
                        <p class="font-semibold text-ink">On the supplier's account</p>
                        <p class="text-xs text-ink-muted">
                            {{ money(purchase.on_account) }} went on account<span v-if="purchase.due_date">, due {{ purchase.due_date }}</span>.
                            <template v-if="purchase.status === 'posted'">
                                <strong :class="Number(purchase.balance_due) > 0 ? (purchase.overdue ? 'text-warning-700' : 'text-ink') : 'text-success-700'">
                                    {{ Number(purchase.balance_due) > 0 ? `Still owed: ${money(purchase.balance_due)}` : 'Paid in full' }}
                                </strong>
                                <span v-if="purchase.overdue" class="font-semibold text-warning-700"> · past due</span>
                            </template>
                        </p>
                    </div>
                </div>
                <PrimaryButton v-if="can.pay" variant="secondary" :href="`/supplier-payments/create?supplier=${purchase.supplier_id}`">
                    <Landmark :size="15" />
                    Pay supplier
                </PrimaryButton>
            </div>
            <ul v-if="purchase.payments?.length" class="mt-3 space-y-1 border-t border-line pt-3 text-xs">
                <li v-for="payment in purchase.payments" :key="payment.reference" class="flex flex-wrap items-center gap-2">
                    <Link v-if="can.viewPayments" :href="`/supplier-payments/${payment.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ payment.reference }}</Link>
                    <span v-else class="font-mono font-semibold">{{ payment.reference }}</span>
                    <span class="text-ink-muted">{{ payment.payment_date }}</span>
                    <span class="tabular-nums" :class="payment.status === 'voided' ? 'text-ink-subtle line-through' : 'text-ink'">{{ money(payment.amount) }}</span>
                    <span v-if="payment.status === 'voided'" class="text-danger-700">voided</span>
                </li>
            </ul>
        </section>

        <!-- Why the Void button is not there -->
        <p
            v-if="can.voidBlockedByStock"
            class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-warning-700"
        >
            <Info :size="16" class="mt-px shrink-0" />
            <span v-if="can.return">
                Some of this stock has already moved, so the purchase can no longer be voided.
                Use <strong>Return</strong> above to send back what is still on the shelf.
            </span>
            <span v-else>
                This delivery has already gone out again, so there is nothing left to void or
                return. Correct the stock with an adjustment instead.
            </span>
        </p>

        <p
            v-if="purchase.status === 'voided'"
            class="card mb-4 flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700"
        >
            <Ban :size="16" class="mt-px shrink-0" />
            <span>
                Voided on {{ purchase.voided_at }} by {{ purchase.voided_by ?? 'someone' }}:
                {{ purchase.void_reason }}
            </span>
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <div class="flex items-center gap-4">
                    <span
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-600 text-ink-inverse shadow-brand"
                    >
                        <ShoppingCart :size="24" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm font-semibold text-ink">
                            {{ purchase.reference }}
                        </p>
                        <div class="mt-1.5">
                            <ActionBadge :label="purchase.status_label" :tone="purchase.status_tone" />
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
                    <template v-if="vatable">
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Net of VAT</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(purchase.net_amount) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">VAT ({{ vatRate }}%)</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(purchase.vat_amount) }}</dd>
                        </div>
                    </template>
                    <div
                        class="flex items-baseline justify-between"
                        :class="vatable ? 'border-t border-line pt-2' : ''"
                    >
                        <dt class="text-xs font-semibold text-ink">Total</dt>
                        <dd class="text-2xl font-bold tabular-nums text-ink">
                            {{ money(purchase.total_amount) }}
                        </dd>
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
                            Unit Cost<template v-if="vatable"> (incl. VAT)</template>
                        </span>
                        <span v-if="vatable" class="text-right">Net of VAT</span>
                        <span v-if="vatable" class="text-right">VAT ({{ vatRate }}%)</span>
                        <span class="text-right">Total</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in purchase.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:items-center md:gap-3"
                            :class="lineGrid"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                                <!-- Against an order: goods it did not ask for, and a cost
                                     different from the one agreed (phase 23). -->
                                <p v-if="purchase.order && !line.on_order" class="mt-0.5 text-2xs font-semibold text-warning-700">Not on the order</p>
                                <p v-if="line.agreed_piece_cost" class="mt-0.5 text-2xs font-semibold text-warning-700">
                                    Agreed {{ money(line.agreed_piece_cost) }} / {{ line.unit }}, invoiced {{ money(line.unit_cost) }}
                                </p>
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
                                <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">Unit cost</span>
                                <!-- Per box as bought, and what one piece came to. -->
                                <span v-if="line.converted">
                                    {{ money(line.unit_price) }} / {{ line.unit_code }}
                                    <span class="block text-2xs text-ink-subtle">
                                        {{ money(line.unit_cost) }} / {{ line.unit }}
                                    </span>
                                </span>
                                <template v-else>{{ money(line.unit_cost) }}</template>
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
                                {{ money(line.line_total) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section v-if="purchase.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ purchase.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
