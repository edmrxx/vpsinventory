<script setup>
import { computed, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { FilePen, PackageOpen, TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import PaymentEntry from '../../Components/PaymentEntry.vue'
import { creditHintFor } from '../../lib/payments'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { documentTotal, lineCentavos, useMoney, useVat, vatSplit } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    customers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    walkInId: { type: [Number, String, null], default: null },
    paymentMethods: { type: Array, default: () => [] },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
    can: { type: Object, default: () => ({}) },
    /**
     * The quotation this sale is made from (phase 28): its customer, lines
     * at the quoted prices, discount, and what is short on the shelf now.
     */
    fromQuotation: { type: Object, default: null },
    /** The sales order this sale fulfils (phase 29). */
    fromOrder: { type: Object, default: null },
    /**
     * The active locations, and the one the products' figures are for
     * (phase 31.4): the one picked, else where the order holds its stock,
     * else the default.
     */
    locations: { type: Array, default: () => [] },
    locationId: { type: [Number, String, null], default: null },
})

/** Whichever document this sale is made from, if any. */
const source = props.fromQuotation ?? props.fromOrder

const { money } = useMoney()

// The rate the sale would be posted at today. The VAT comes off the total
// after the discount, exactly as the server works it out.
const { rate: vatRate } = useVat()

const form = useForm({
    // The counter sale is what most sales are, so it starts there.
    customer_id: source?.customer_id ?? props.walkInId ?? '',
    // Sent back so the quotation is accepted, or the order fulfilled, when
    // the sale posts.
    quotation_id: props.fromQuotation?.id ?? '',
    sales_order_id: props.fromOrder?.id ?? '',
    sale_date: props.dates.today ?? '',
    // Where the goods leave the shelf (phase 31.4).
    location_id: props.locationId ?? '',
    mobilization: source?.mobilization ?? '0.00',
    discount: source?.discount ?? '0.00',
    notes: source?.notes ?? '',
    idempotency_key: props.idempotencyKey,
    lines: source?.lines?.length
        ? source.lines.map((line) => ({ ...line }))
        : [{ product_id: '', unit_id: '', quantity: 1, unit_price: '0.00' }],
    // Cash for the whole amount until somebody says otherwise: most sales
    // are. The box follows the total while nobody has typed in it.
    payments: props.paymentMethods.length
        ? [{ payment_method_id: props.paymentMethods[0].value, amount: '0.00', reference: '' }]
        : [],
    // How much goes on the customer's account. Empty is none.
    on_account: '',
    // In how many days it falls due, agreed on this sale (phase 18.1).
    // Empty is the customer's usual terms.
    terms_days: '',
})

const pickedCustomer = computed(() =>
    props.customers.find((customer) => String(customer.value) === String(form.customer_id))
)

/** The picked customer's credit, or null when they have none. */
const account = computed(() => pickedCustomer.value?.credit ?? null)

/** Why "On account" is not offered: said, so nobody hunts for it. */
const creditHint = computed(() => creditHintFor(pickedCustomer.value))

// Credit belongs to one customer, so it does not carry over to another.
watch(
    () => form.customer_id,
    () => {
        form.on_account = ''
        form.terms_days = ''
    }
)

const headerFields = ['customer_id', 'sale_date', 'location_id', 'mobilization', 'discount', 'notes', 'quotation_id', 'sales_order_id']

// Another place: fetch the products again with that place's figures, so
// "on hand" and the stock warning are about where the goods really leave.
watch(
    () => form.location_id,
    (id) => {
        if (!id || String(id) === String(props.locationId ?? '')) return

        router.reload({
            only: ['products', 'locationId', 'fromQuotation'],
            data: { location: id },
            preserveState: true,
        })
    }
)

/** Shown while typing. The server works the real figures out again. */
const subtotal = computed(() =>
    form.lines.reduce((sum, line) => sum + lineCentavos(line.unit_price, line.quantity), 0) / 100
)

// Goods less the discount, plus mobilization (phase 28.1): SaleTotals' sum.
const total = computed(() => documentTotal(subtotal.value, form.discount, form.mobilization))

const vat = computed(() => vatSplit(total.value, vatRate.value))

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Post this sale?',
        text: 'The stock leaves the shelf at once. A posted sale cannot be edited afterwards, only voided.',
        confirmText: 'Yes, post it',
    })

    if (!agreed) return

    form.post('/sales', {
        onError: (errors) => {
            const loose = Object.entries(errors)
                .filter(
                    ([key]) =>
                        !headerFields.includes(key) &&
                        !key.startsWith('lines.') &&
                        !key.startsWith('payments') &&
                        key !== 'on_account' &&
                        key !== 'terms_days'
                )
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="New sale"
            subheading="Record goods sold. The stock leaves the shelf when you post it."
            back-href="/sales"
            back-label="Back to sales"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/sales">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Save sale</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <!-- Made from a quotation (phase 28) -->
            <section v-if="fromQuotation" class="card p-4 sm:p-5">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                        <FilePen :size="18" />
                    </span>
                    <div class="min-w-0 text-sm">
                        <p class="text-ink">
                            From quotation
                            <Link :href="`/quotations/${fromQuotation.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ fromQuotation.reference }}</Link>
                            for {{ fromQuotation.customer }}, valid until {{ fromQuotation.valid_until }}.
                        </p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            The lines below are at the quoted prices. Stock is checked again now and when the sale posts: a quotation reserves nothing.
                        </p>
                        <p v-if="form.errors.quotation_id" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.quotation_id }}</p>
                        <ul v-if="fromQuotation.short?.length" class="mt-2 space-y-0.5 text-xs font-medium text-warning-700">
                            <li v-for="line in fromQuotation.short" :key="line" class="flex items-start gap-1.5">
                                <TriangleAlert :size="13" class="mt-px shrink-0" />
                                <span>Short now: {{ line }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Fulfilling a sales order (phase 29) -->
            <section v-if="fromOrder" class="card p-4 sm:p-5">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                        <PackageOpen :size="18" />
                    </span>
                    <div class="min-w-0 text-sm">
                        <p class="text-ink">
                            Fulfilling order
                            <Link :href="`/sales-orders/${fromOrder.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ fromOrder.reference }}</Link>
                            for {{ fromOrder.customer }}.
                        </p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            <template v-if="fromOrder.expired">Its reservation lapsed on {{ fromOrder.reserve_until }}, so the stock is checked like any sale.</template>
                            <template v-else>The stock reserved for it until {{ fromOrder.reserve_until }} is free for this sale to take.</template>
                        </p>
                        <p v-if="form.errors.sales_order_id" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.sales_order_id }}</p>
                    </div>
                </div>
            </section>

            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-3">
                    <FormField label="Customer" for="customer_id" required :error="form.errors.customer_id">
                        <!-- Fixed to the quotation's customer when made from one. -->
                        <SelectField
                            id="customer_id"
                            v-model="form.customer_id"
                            :options="customers"
                            :invalid="!!form.errors.customer_id"
                            :disabled="!!source"
                            placeholder="Pick a customer"
                        />
                    </FormField>

                    <FormField
                        label="Date"
                        for="sale_date"
                        required
                        :error="form.errors.sale_date"
                        :hint="dates.min ? `On or after ${dates.min}, and not in the future.` : 'Not in the future.'"
                    >
                        <TextField
                            id="sale_date"
                            v-model="form.sale_date"
                            type="date"
                            :min="dates.min ?? undefined"
                            :max="dates.max ?? undefined"
                            :invalid="!!form.errors.sale_date"
                        />
                    </FormField>

                    <FormField
                        label="Sell from"
                        for="location_id"
                        required
                        :error="form.errors.location_id"
                        :hint="fromOrder && String(form.location_id) === String(fromOrder.location_id)
                            ? 'Where the order holds its stock.'
                            : 'The stock shown below is what is free here.'"
                    >
                        <SelectField
                            id="location_id"
                            v-model="form.location_id"
                            :options="locations"
                            :invalid="!!form.errors.location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when posted</span>
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                with-units
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                :price-label="vatRate > 0 ? 'Unit Price (incl. VAT)' : 'Unit Price'"
                price-field="unit_price"
                product-price-field="price"
                show-stock
                :warn-below-cost="can.viewCost"
            />

            <!-- What it comes to -->
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-5">
                        <FormField
                            label="Mobilization"
                            for="mobilization"
                            :error="form.errors.mobilization"
                            hint="Bringing the goods to the site. Added to the total, never discounted."
                        >
                            <TextField
                                id="mobilization"
                                v-model="form.mobilization"
                                type="number"
                                min="0"
                                step="0.01"
                                :invalid="!!form.errors.mobilization"
                            />
                        </FormField>
                        <FormField
                            label="Discount"
                            for="discount"
                            :error="form.errors.discount"
                            hint="Taken off the whole sale, not a single line."
                        >
                            <TextField
                                id="discount"
                                v-model="form.discount"
                                type="number"
                                min="0"
                                step="0.01"
                                :invalid="!!form.errors.discount"
                            />
                        </FormField>
                    </div>

                    <dl class="space-y-2 self-end rounded-field bg-surface-sunken p-4">
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Subtotal</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(subtotal) }}</dd>
                        </div>
                        <div v-if="Number(form.mobilization) > 0" class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Mobilization</dt>
                            <dd class="tabular-nums text-sm text-ink">+{{ money(form.mobilization) }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-xs text-ink-muted">Discount</dt>
                            <dd class="tabular-nums text-sm text-ink">
                                −{{ money(form.discount) }}
                            </dd>
                        </div>
                        <template v-if="vatRate > 0">
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">Net of VAT</dt>
                                <dd class="tabular-nums text-sm text-ink">{{ money(vat.net) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-xs text-ink-muted">VAT ({{ vatRate }}%)</dt>
                                <dd class="tabular-nums text-sm text-ink">{{ money(vat.vat) }}</dd>
                            </div>
                        </template>
                        <div class="flex items-center justify-between border-t border-line pt-2">
                            <dt class="text-xs font-semibold text-ink">Total</dt>
                            <dd class="tabular-nums text-lg font-bold text-ink">{{ money(total) }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <!-- How it was paid -->
            <section class="card p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-ink">Payment</h3>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    Tap more than one to split it. Cash is what was handed over; the change is worked out.
                </p>

                <div class="mt-4 max-w-xl">
                    <PaymentEntry
                        v-model="form.payments"
                        v-model:on-account="form.on_account"
                        v-model:terms-days="form.terms_days"
                        :account="account"
                        :may-extend-terms="!!can.extendTerms"
                        :sale-date="form.sale_date"
                        :credit-hint="creditHint"
                        :methods="paymentMethods"
                        :total="total"
                        :errors="form.errors"
                        :disabled="form.processing"
                    />
                </div>
            </section>

            <section class="card p-5 sm:p-6">
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :rows="2"
                        :invalid="!!form.errors.notes"
                        placeholder="Anything worth remembering about this sale."
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/sales">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save sale</PrimaryButton>
        </div>
    </form>
</template>
