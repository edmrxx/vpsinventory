<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { FilePen, Info } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { documentTotal, lineCentavos, useMoney, useVat, vatSplit } from '../../lib/money'

/**
 * Offer a customer a price, valid until a date (phase 28).
 *
 * Nothing moves when it is saved and nothing is reserved: the sale made from
 * it later checks the shelf. The lines are typed like a sale's, so that sale
 * copies them. Opened with `?from=`, it quotes an earlier quotation again at
 * today's prices.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    customers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    customerId: { type: [Number, null], default: null },
    fromQuotation: { type: Object, default: null },
    today: { type: String, default: '' },
    validUntil: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

// Whether prices carry VAT follows the Tax Settings switch, as a sale's do.
const { rate: vatRate } = useVat()

const form = useForm({
    customer_id: props.customerId ?? '',
    attention: props.fromQuotation?.attention ?? '',
    valid_until: props.validUntil,
    mobilization: props.fromQuotation?.mobilization ?? '0.00',
    discount: '0.00',
    notes: props.fromQuotation?.notes ?? '',
    idempotency_key: props.idempotencyKey,
    lines: props.fromQuotation?.lines?.length
        ? props.fromQuotation.lines.map((line) => ({ ...line }))
        : [{ product_id: '', unit_id: '', quantity: 1, unit_price: '0.00' }],
})

const headerFields = ['customer_id', 'attention', 'valid_until', 'mobilization', 'discount', 'notes']

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
        title: 'Make this quotation?',
        text: 'Nothing leaves the shelf and nothing is reserved. A quotation cannot be edited afterwards; quote again instead.',
        confirmText: 'Yes, make it',
    })

    if (!agreed) return

    form.post('/quotations', {
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
            heading="New quotation"
            subheading="Offer a customer a price until a date. Nothing is reserved; the sale checks the shelf."
            back-href="/quotations"
            back-label="Back to quotations"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/quotations">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Make quotation</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="fromQuotation" class="card flex items-start gap-3 p-4 sm:p-5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                    <FilePen :size="18" />
                </span>
                <div class="min-w-0 text-sm">
                    <p class="text-ink">
                        Quoting
                        <Link :href="`/quotations/${fromQuotation.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ fromQuotation.reference }}</Link>
                        again, at today's prices.
                    </p>
                    <ul v-if="fromQuotation.dropped?.length" class="mt-1 space-y-0.5 text-xs font-medium text-warning-700">
                        <li v-for="note in fromQuotation.dropped" :key="note" class="flex items-start gap-1.5">
                            <Info :size="13" class="mt-px shrink-0" />
                            <span>{{ note }}</span>
                        </li>
                    </ul>
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
                            placeholder="Who it is for"
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

                    <FormField label="Valid until" for="valid_until" required :error="form.errors.valid_until"
                        hint="The last day these prices hold.">
                        <TextField
                            id="valid_until"
                            v-model="form.valid_until"
                            type="date"
                            :min="today || undefined"
                            :invalid="!!form.errors.valid_until"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when made</span>
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
                            hint="Taken off the whole quotation, as on a sale.">
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
                                placeholder="Printed on the quotation: delivery, terms, brand offered."
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
            <PrimaryButton variant="secondary" href="/quotations">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Make quotation</PrimaryButton>
        </div>
    </form>
</template>
