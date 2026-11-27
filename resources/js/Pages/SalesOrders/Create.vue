<script setup>
import { computed, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { FilePen } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { documentTotal, lineCentavos, useMoney, useVat, vatSplit } from '../../lib/money'

/**
 * Take a customer's order (phase 29).
 *
 * Saved pending, it holds nothing. Approved — here at once, for somebody who
 * may approve, or later — its stock is reserved until a date, and nobody
 * else can buy it meanwhile. The lines are typed like a sale's, so the sale
 * that fulfils it copies them. Opened with `?quotation=`, it takes a live
 * quotation as the order.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    customers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    customerId: { type: [Number, null], default: null },
    fromQuotation: { type: Object, default: null },
    today: { type: String, default: '' },
    reserveUntil: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
    can: { type: Object, default: () => ({}) },
    /** The active locations, and the one the stock figures are for (phase 31.4). */
    locations: { type: Array, default: () => [] },
    locationId: { type: [Number, String, null], default: null },
})

const { money } = useMoney()

// Whether prices carry VAT follows the Tax Settings switch, as a sale's do.
const { rate: vatRate } = useVat()

const form = useForm({
    customer_id: props.customerId ?? '',
    quotation_id: props.fromQuotation?.id ?? '',
    attention: props.fromQuotation?.attention ?? '',
    reserve_until: props.reserveUntil,
    // Where the stock is held (phase 31.4).
    location_id: props.locationId ?? '',
    // Approve and reserve now, for somebody who may approve.
    approve: !!props.can.approve,
    mobilization: props.fromQuotation?.mobilization ?? '0.00',
    discount: props.fromQuotation?.discount ?? '0.00',
    notes: props.fromQuotation?.notes ?? '',
    idempotency_key: props.idempotencyKey,
    lines: props.fromQuotation?.lines?.length
        ? props.fromQuotation.lines.map((line) => ({ ...line }))
        : [{ product_id: '', unit_id: '', quantity: 1, unit_price: '0.00' }],
})

const headerFields = ['customer_id', 'attention', 'reserve_until', 'location_id', 'mobilization', 'discount', 'notes', 'approve', 'quotation_id']

// Another place: fetch the products again with what is free there.
watch(
    () => form.location_id,
    (id) => {
        if (!id || String(id) === String(props.locationId ?? '')) return

        router.reload({ only: ['products', 'locationId'], data: { location: id }, preserveState: true })
    }
)

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
        title: form.approve ? 'Take this order and reserve its stock?' : 'Take this order?',
        text: form.approve
            ? 'Its stock is set aside until the date given, and nobody else can buy it meanwhile. An order cannot be edited afterwards.'
            : 'It reserves nothing until somebody who may approve approves it. An order cannot be edited afterwards.',
        confirmText: 'Yes, take it',
    })

    if (!agreed) return

    form.post('/sales-orders', {
        onError: (errors) => {
            const loose = Object.entries(errors)
                .filter(([key]) => !headerFields.includes(key) && !key.startsWith('lines.'))
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="New sales order"
            subheading="Take a customer's order. Approved, its stock is held for them until a date."
            back-href="/sales-orders"
            back-label="Back to sales orders"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/sales-orders">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Take order</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="fromQuotation" class="card flex items-start gap-3 p-4 sm:p-5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                    <FilePen :size="18" />
                </span>
                <div class="min-w-0 text-sm">
                    <p class="text-ink">
                        From quotation
                        <Link :href="`/quotations/${fromQuotation.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ fromQuotation.reference }}</Link>,
                        at the quoted prices. The quotation becomes Accepted when the order is taken.
                    </p>
                    <p v-if="form.errors.quotation_id" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.quotation_id }}</p>
                </div>
            </section>

            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-3">
                    <FormField label="Customer" for="customer_id" required :error="form.errors.customer_id"
                        hint="Walk-in is fine for somebody without an account.">
                        <SelectField
                            id="customer_id"
                            v-model="form.customer_id"
                            :options="customers"
                            :invalid="!!form.errors.customer_id"
                            :disabled="!!fromQuotation"
                            placeholder="Who placed it"
                        />
                    </FormField>

                    <FormField label="Attention" for="attention" :error="form.errors.attention"
                        hint="Optional. The person it is addressed to.">
                        <TextField
                            id="attention"
                            v-model="form.attention"
                            maxlength="160"
                            :invalid="!!form.errors.attention"
                            placeholder="For example: Engr. Santos"
                        />
                    </FormField>

                    <FormField label="Hold stock until" for="reserve_until" required :error="form.errors.reserve_until"
                        hint="Once approved, the last day the stock is set aside.">
                        <TextField
                            id="reserve_until"
                            v-model="form.reserve_until"
                            type="date"
                            :min="today || undefined"
                            :invalid="!!form.errors.reserve_until"
                        />
                    </FormField>

                    <FormField label="Hold at" for="location_id" required :error="form.errors.location_id"
                        hint="Where the stock is set aside. The sale that fulfils it starts here.">
                        <SelectField
                            id="location_id"
                            v-model="form.location_id"
                            :options="locations"
                            :invalid="!!form.errors.location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>
                </div>

                <div v-if="can.approve" class="mt-5 rounded-field bg-surface-muted p-4">
                    <ToggleSwitch
                        v-model="form.approve"
                        label="Approve and reserve the stock now"
                        :description="form.approve
                            ? 'The stock is set aside as soon as the order is taken. If any of it is not free, the order is refused and nothing is saved.'
                            : 'Off: the order is saved pending and holds nothing until it is approved.'"
                    />
                    <p v-if="form.errors.approve" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.approve }}</p>
                </div>
                <p v-else class="mt-5 text-xs text-ink-subtle">The order is saved pending. It holds no stock until somebody who may approve approves it.</p>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when taken</span>
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
                        <FormField label="Discount" for="discount" :error="form.errors.discount"
                            hint="Taken off the whole order, as on a sale.">
                            <TextField
                                id="discount"
                                v-model="form.discount"
                                type="number"
                                min="0"
                                step="0.01"
                                :invalid="!!form.errors.discount"
                            />
                        </FormField>
                        <FormField label="Notes" for="notes" :error="form.errors.notes">
                            <TextareaField
                                id="notes"
                                v-model="form.notes"
                                :rows="2"
                                :invalid="!!form.errors.notes"
                                placeholder="Delivery, pick-up date, anything agreed."
                            />
                        </FormField>
                    </div>

                    <dl class="space-y-2 self-start rounded-field bg-surface-sunken p-4">
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
                            <dd class="tabular-nums text-sm text-ink">−{{ money(form.discount) }}</dd>
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
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/sales-orders">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Take order</PrimaryButton>
        </div>
    </form>
</template>
