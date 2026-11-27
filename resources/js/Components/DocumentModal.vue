<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ExternalLink, Printer } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { Swal, toastError } from '../lib/swal'
import { count, useMoney } from '../lib/money'
import { printSlip } from '../lib/slip'

/**
 * A purchase, a sale, an adjustment, a return or a transfer, read without
 * leaving the list.
 *
 * One dialog for all three, because they answer the same questions: what,
 * when, from or to whom, how much. The figures come from the controller's
 * own `detail()` payload — the same one the full page renders — so the two
 * can never disagree.
 *
 * This is a summary, not a copy of the page. A dialog that tried to be the
 * page would be a cramped one. Anything that needs room — a return, with
 * its own line items — still opens properly, and "Open full page" is always
 * there for a record somebody wants to keep, print or send.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /**
     * The document's own address, e.g. "/purchases/5".
     *
     * Taken as a path rather than a kind and an id, because the movement
     * ledger already builds exactly this through `DocumentLink`, and the
     * dialog is then the same call from a list screen and from a ledger
     * row. The record is read from the same path plus "/preview".
     */
    documentUrl: { type: String, default: null },
    /**
     * Leave the cost and the margin off, whatever the account may read.
     *
     * The till faces a customer across a counter, and a margin on display
     * is a margin somebody can read upside down. The server still decides
     * who may be *sent* those figures; this only decides whether a screen
     * that may see them should show them.
     */
    hideCost: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const { money } = useMoney()

/** What each screen's payload calls its record, and what to call it aloud. */
const kinds = {
    purchases: { key: 'purchase', noun: 'purchase', price: 'unit_cost', priceLabel: 'Unit cost' },
    sales: { key: 'sale', noun: 'sale', price: 'unit_price', priceLabel: 'Price' },
    adjustments: { key: 'adjustment', noun: 'adjustment', price: 'unit_cost', priceLabel: 'Unit cost' },
    'sales-returns': { key: 'salesReturn', noun: 'sales return', price: 'unit_refund', priceLabel: 'Refund' },
    'purchase-returns': { key: 'purchaseReturn', noun: 'purchase return', price: 'unit_cost', priceLabel: 'Unit cost' },
    transfers: { key: 'transfer', noun: 'transfer', price: 'unit_cost', priceLabel: 'Unit cost' },
}

const path = computed(() => props.documentUrl?.split('/')[1] ?? null)

/**
 * The rate the document was posted at, which only a purchase or a sale
 * carries. Zero everywhere else, and then the dialog is unchanged.
 */
function vatRateOf(doc) {
    return Number(doc?.vat_rate ?? 0)
}

/** Full class strings: a Tailwind class built at runtime is never generated. */
function lineGridOf(doc) {
    return vatRateOf(doc) > 0
        ? 'sm:grid-cols-[1fr_3rem_5rem_5rem_4.5rem_5rem]'
        : 'sm:grid-cols-[1fr_4rem_6rem_6rem]'
}
const kind = computed(() => kinds[path.value] ?? null)
const url = computed(() => (props.documentUrl ? `${props.documentUrl}/preview` : null))

/** The record itself, whichever of the five it is. */
function recordOf(data) {
    return data[kind.value?.key] ?? {}
}

function detailsOf(doc) {
    switch (path.value) {
        case 'purchases':
            return [
                { label: 'Supplier', value: doc.supplier ?? '—' },
                { label: 'Their invoice no.', value: doc.supplier_reference ?? '—' },
                { label: 'Date', value: doc.purchase_date ?? '—' },
                // Where the goods went on the shelf (phase 31.3).
                { label: 'Received at', value: doc.location ?? '—' },
                { label: 'Posted by', value: doc.posted_by ?? '—' },
                // Received against an order (phase 23).
                ...(doc.order ? [{ label: 'Against order', value: doc.order.reference }] : []),
                // On the supplier's account (phase 24).
                ...(Number(doc.on_account) > 0
                    ? [{ label: 'Still owed', value: Number(doc.balance_due) > 0 ? `${money(doc.balance_due)}, due ${doc.due_date ?? '—'}` : 'Paid in full' }]
                    : []),
            ]
        case 'sales':
            return [
                { label: 'Customer', value: doc.customer ?? '—' },
                { label: 'Date', value: doc.sale_date ?? '—' },
                // Where the goods left the shelf (phase 31.4).
                { label: 'Sold from', value: doc.location ?? '—' },
                { label: 'Posted', value: doc.posted_at ?? '—' },
                { label: 'Posted by', value: doc.posted_by ?? '—' },
                // Fulfilled a sales order (phase 29).
                ...(doc.sales_order ? [{ label: 'Fulfilled order', value: doc.sales_order.reference }] : []),
                // Made from a quotation (phase 28).
                ...(doc.quotation ? [{ label: 'From quotation', value: doc.quotation.reference }] : []),
            ]
        case 'sales-returns':
            return [
                { label: 'Against', value: doc.sale_reference ?? '—' },
                { label: 'Customer', value: doc.customer ?? '—' },
                { label: 'Date', value: doc.return_date ?? '—' },
                // Back into the batches its sale ate, so where it was sold.
                { label: 'Back to', value: doc.location ?? '—' },
                { label: 'Reason', value: doc.reason ?? '—' },
            ]
        case 'purchase-returns':
            return [
                { label: 'Against', value: doc.purchase_reference ?? '—' },
                { label: 'Supplier', value: doc.supplier ?? '—' },
                { label: 'Date', value: doc.return_date ?? '—' },
                // Out of the batch its delivery made, so where it was received.
                { label: 'Taken from', value: doc.location ?? '—' },
                { label: 'Reason', value: doc.reason ?? '—' },
            ]
        case 'transfers':
            // Stock moved between locations (phase 31.5).
            return [
                { label: 'From', value: doc.from ?? '—' },
                { label: 'To', value: doc.to ?? '—' },
                { label: 'Date', value: doc.transfer_date ?? '—' },
                { label: 'Posted by', value: doc.posted_by ?? '—' },
            ]
        default:
            return [
                { label: 'Kind', value: doc.kind_label ?? '—' },
                { label: 'Reason', value: doc.reason ?? '—' },
                { label: 'Date', value: doc.adjustment_date ?? '—' },
                // Where the stock was counted in or out (phase 31.4).
                { label: 'Location', value: doc.location ?? '—' },
                { label: 'Posted by', value: doc.posted_by ?? '—' },
            ]
    }
}

function priceOf(line) {
    // A purchase line typed by the box is priced per box, which is what its
    // total is made of. The piece cost sits under the quantity instead.
    if (line.converted && line.unit_price !== undefined) return line.unit_price

    return line[kind.value?.price]
}

function lineTotalOf(line) {
    return line.line_total ?? line.line_refund ?? line.line_cost ?? line.total_cost
}

/**
 * The two VAT rows, between the discount and the total.
 *
 * On a sale the split comes from what was charged, after the discount, so
 * it will not match the lines added up. That is correct: VAT follows the
 * money.
 */
function vatRowsOf(doc) {
    const rate = vatRateOf(doc)

    if (rate <= 0) return []

    return [
        { label: 'Net of VAT', value: doc.net_amount },
        { label: `VAT (${rate}%)`, value: doc.vat_amount },
    ]
}

function totalsOf(doc) {
    if (path.value === 'sales') {
        return [
            { label: 'Subtotal', value: doc.subtotal },
            // Bringing the goods to the site (phase 28.1), added to the total.
            ...(Number(doc.mobilization) > 0
                ? [{ label: 'Mobilization', value: `+${money(doc.mobilization)}`, raw: true }]
                : []),
            ...(Number(doc.discount) > 0
                ? [{ label: 'Discount', value: `−${money(doc.discount)}`, raw: true }]
                : []),
            ...vatRowsOf(doc),
            { label: 'Total', value: doc.total_amount, strong: true },
            ...(doc.total_cost !== undefined && !props.hideCost
                ? [
                      { label: 'Cost of goods', value: doc.total_cost },
                      { label: 'Gross profit', value: doc.gross_profit, strong: true, good: true },
                  ]
                : []),
        ]
    }

    if (path.value === 'sales-returns') {
        return [
            ...vatRowsOf(doc),
            ...(Number(doc.credited_amount) > 0
                ? [
                      { label: 'Taken off what is owed', value: doc.credited_amount },
                      { label: 'Handed back as cash', value: doc.cash_refund },
                  ]
                : []),
            {
                label: Number(doc.credited_amount) > 0 ? 'Value of the return' : 'Refunded',
                value: doc.total_amount,
                strong: true,
            },
            ...(doc.total_cost !== undefined && !props.hideCost
                ? [{ label: 'Cost returned', value: doc.total_cost }]
                : []),
        ]
    }

    if (path.value === 'purchase-returns') {
        return [
            ...vatRowsOf(doc),
            { label: 'Value', value: doc.total_cost, strong: true },
        ]
    }

    if (path.value === 'purchases') {
        return [
            ...vatRowsOf(doc),
            { label: 'Total', value: doc.total_amount, strong: true },
        ]
    }

    if (path.value === 'transfers') {
        return [{ label: 'Value moved, at cost', value: doc.total_cost, strong: true }]
    }

    return [{ label: 'Value', value: doc.total_cost, strong: true }]
}

/**
 * Void, from the dialog.
 *
 * The same warning the page gives, and the same reason is demanded. On
 * success the dialog shuts and the list reloads in place, which is one
 * step fewer than the page needed.
 */
async function voidDocument(doc) {
    const noun = kind.value.noun

    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning',
        title: `Void this ${noun}?`,
        text:
            path.value === 'transfers'
                ? 'The goods go back where they came from, into the batches they left. Say why.'
                : 'The stock it moved goes straight back. Say why.',
        input: 'text',
        inputPlaceholder: 'For example: wrong supplier, duplicate entry',
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

    router.post(`${props.documentUrl}/void`, { reason }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) =>
            toastError(Object.values(errors)[0] ?? `The ${noun} was not voided.`),
    })
}
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <!-- Who, when, and what state it is in -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">
                            {{ recordOf(data).reference }}
                        </h3>
                        <!-- A return has no status: it is never voided,
                             so there is nothing for a badge to say. -->
                        <ActionBadge
                            v-if="recordOf(data).status_label"
                            :label="recordOf(data).status_label"
                            :tone="recordOf(data).status_tone"
                        />
                    </div>

                    <div class="flex items-center gap-4">
                        <!-- A sale's slip, for the customer: printed from
                             this same payload, never with its cost. -->
                        <button
                            v-if="path === 'sales'"
                            type="button"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                            @click="printSlip(recordOf(data))"
                        >
                            <Printer :size="14" />
                            Print slip
                        </button>
                        <Link
                            :href="documentUrl"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline"
                        >
                            <ExternalLink :size="14" />
                            Open full page
                        </Link>
                    </div>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in detailsOf(recordOf(data))"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">
                            {{ detail.value }}
                        </dd>
                    </div>
                </dl>

                <!-- Why it was voided, if it was -->
                <p
                    v-if="recordOf(data).voided_at"
                    class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700"
                >
                    Voided on {{ recordOf(data).voided_at }}.
                    <span v-if="recordOf(data).void_reason">
                        Reason: {{ recordOf(data).void_reason }}
                    </span>
                </p>

                <!-- The lines -->
                <section class="overflow-hidden rounded-field border border-line">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:gap-3"
                        :class="lineGridOf(recordOf(data))"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">
                            {{ kind?.priceLabel
                            }}<template v-if="vatRateOf(recordOf(data)) > 0"> (incl. VAT)</template>
                        </span>
                        <template v-if="vatRateOf(recordOf(data)) > 0">
                            <span class="text-right">Net of VAT</span>
                            <span class="text-right">VAT ({{ vatRateOf(recordOf(data)) }}%)</span>
                        </template>
                        <span class="text-right">Total</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="line in recordOf(data).lines"
                            :key="line.id"
                            class="grid gap-1 px-4 py-2.5 sm:items-center sm:gap-3"
                            :class="lineGridOf(recordOf(data))"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">
                                <!-- Typed by the box: 2 box, with the pieces under it. -->
                                <template v-if="line.converted">
                                    {{ count(line.unit_quantity) }} {{ line.unit_code }}
                                    <span class="block text-2xs text-ink-subtle">
                                        {{ count(line.quantity) }} {{ line.unit }}
                                    </span>
                                </template>
                                <template v-else>{{ count(line.quantity) }}</template>
                            </span>
                            <span class="tabular-nums text-sm text-ink-muted sm:text-right">
                                {{ money(priceOf(line)) }}
                            </span>
                            <template v-if="vatRateOf(recordOf(data)) > 0">
                                <span class="tabular-nums text-sm text-ink-muted sm:text-right">
                                    {{ money(line.net_total) }}
                                </span>
                                <span class="tabular-nums text-sm text-ink-subtle sm:text-right">
                                    {{ money(line.vat_total) }}
                                </span>
                            </template>
                            <span class="tabular-nums text-sm font-semibold text-ink sm:text-right">
                                {{ money(lineTotalOf(line)) }}
                            </span>
                        </div>
                    </div>
                </section>

                <!-- What it came to -->
                <dl class="ml-auto w-full space-y-1.5 sm:max-w-xs">
                    <div
                        v-for="total in totalsOf(recordOf(data))"
                        :key="total.label"
                        class="flex items-baseline justify-between gap-3"
                    >
                        <dt :class="total.strong ? 'text-sm font-semibold text-ink' : 'text-xs text-ink-muted'">
                            {{ total.label }}
                        </dt>
                        <dd
                            class="tabular-nums"
                            :class="[
                                total.strong ? 'text-base font-bold' : 'text-sm',
                                total.good ? 'text-success-700' : 'text-ink',
                            ]"
                        >
                            {{ total.raw ? total.value : money(total.value) }}
                        </dd>
                    </div>
                </dl>

                <!-- How a sale was paid -->
                <div
                    v-if="path === 'sales' && (recordOf(data).payments ?? []).length"
                    class="ml-auto w-full rounded-field border border-line p-3 sm:max-w-xs"
                >
                    <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Paid by</p>
                    <dl class="mt-1.5 space-y-1.5">
                        <div v-for="payment in recordOf(data).payments" :key="payment.id">
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-xs text-ink">{{ payment.method }}</dt>
                                <dd class="tabular-nums text-sm text-ink">{{ money(payment.amount) }}</dd>
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
                        v-if="Number(recordOf(data).change) > 0"
                        class="mt-2 flex items-baseline justify-between border-t border-line pt-1.5"
                    >
                        <span class="text-xs text-ink-muted">Change given</span>
                        <span class="tabular-nums text-sm text-ink">{{ money(recordOf(data).change) }}</span>
                    </div>
                </div>

                <!-- What was not paid at the counter -->
                <dl
                    v-if="path === 'sales' && Number(recordOf(data).on_account) > 0"
                    class="ml-auto w-full space-y-1 rounded-field border border-line p-3 sm:max-w-xs"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs text-ink">On account</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(recordOf(data).on_account) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs text-ink-muted">Still owed</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(recordOf(data).balance_due) }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs text-ink-muted">Due</dt>
                        <dd class="text-sm text-ink">
                            {{ recordOf(data).due_date }}<span
                                v-if="recordOf(data).terms_days !== null && recordOf(data).terms_days !== undefined"
                                class="text-ink-muted"
                            >
                                · {{ recordOf(data).terms_days }} {{ recordOf(data).terms_days === 1 ? 'day' : 'days' }}</span>
                        </dd>
                    </div>
                    <!-- What the customer has paid against it since. -->
                    <div
                        v-for="paid in recordOf(data).account_payments ?? []"
                        :key="paid.id"
                        class="flex items-baseline justify-between gap-3 border-t border-line pt-1"
                    >
                        <dt class="text-2xs text-ink-muted">
                            Paid {{ paid.payment_date }} · <span class="font-mono">{{ paid.reference }}</span>
                        </dt>
                        <dd class="tabular-nums text-sm text-success-700">−{{ money(paid.amount) }}</dd>
                    </div>
                </dl>

                <p v-if="recordOf(data).notes" class="rounded-field bg-surface-sunken p-3 text-xs text-ink-muted">
                    {{ recordOf(data).notes }}
                </p>

                <!-- What can still be done to it -->
                <div
                    v-if="data.can?.void || data.can?.return"
                    class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4"
                >
                    <PrimaryButton
                        v-if="data.can?.return"
                        variant="secondary"
                        :href="`/${path === 'sales' ? 'sales-returns' : 'purchase-returns'}/create?${path === 'sales' ? 'sale' : 'purchase'}=${recordOf(data).id}`"
                    >
                        Return
                    </PrimaryButton>
                    <PrimaryButton
                        v-if="data.can?.void"
                        variant="danger"
                        @click="voidDocument(recordOf(data))"
                    >
                        Void
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
