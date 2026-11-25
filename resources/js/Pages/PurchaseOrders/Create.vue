<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { ClipboardList } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { useVat } from '../../lib/money'

/**
 * Place an order with a supplier (phase 22).
 *
 * Opened from an approved request, it starts with that request's supplier
 * and lines at their estimated cost; change them to what the supplier
 * agreed. Nothing moves when it is placed: the stock arrives with the
 * delivery, posted under Purchases.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    /** The active locations (phase 31.3), and the default one. */
    locations: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    fromRequest: { type: Object, default: null },
    today: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
})

const { configuredRate } = useVat()

const form = useForm({
    supplier_id: props.fromRequest?.supplier_id ?? '',
    purchase_request_id: props.fromRequest?.id ?? '',
    order_date: props.today,
    expected_date: props.fromRequest?.expected_date ?? '',
    // Where the supplier is to deliver (phase 31.3). Starts on the default;
    // may be cleared.
    deliver_to_location_id: props.defaultLocationId ?? '',
    notes: props.fromRequest?.notes ?? '',
    idempotency_key: props.idempotencyKey,
    lines: props.fromRequest?.lines?.length
        ? props.fromRequest.lines.map((line) => ({ ...line }))
        : [{ product_id: '', unit_id: '', quantity: 1, unit_cost: '0.00' }],
})

/** A VAT supplier's prices include VAT; the order shows the split. */
const supplierIsVat = computed(() =>
    !!props.suppliers.find((row) => String(row.value) === String(form.supplier_id))?.is_vat_registered
)
const vatRate = computed(() => (supplierIsVat.value ? configuredRate.value : 0))

const headerFields = ['supplier_id', 'order_date', 'expected_date', 'deliver_to_location_id', 'notes']

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Place this order?',
        text: props.fromRequest
            ? `Request ${props.fromRequest.reference} closes as ordered. An order is never edited; a wrong one is cancelled.`
            : 'An order is never edited afterwards; a wrong one is cancelled and placed again.',
        confirmText: 'Yes, place it',
    })

    if (!agreed) return

    form.post('/purchase-orders', {
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
            heading="New purchase order"
            subheading="Order goods from a supplier. The stock arrives when the delivery is posted."
            back-href="/purchase-orders"
            back-label="Back to purchase orders"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/purchase-orders">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Place order</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="fromRequest" class="card flex items-start gap-3 p-4 sm:p-5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                    <ClipboardList :size="18" />
                </span>
                <div class="min-w-0 text-sm">
                    <p class="text-ink">
                        From request
                        <Link :href="`/purchase-requests/${fromRequest.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">
                            {{ fromRequest.reference }}
                        </Link>
                        <span v-if="fromRequest.requested_by" class="text-ink-muted"> · raised by {{ fromRequest.requested_by }}</span>
                    </p>
                    <p class="mt-0.5 text-ink-muted">{{ fromRequest.reason }}</p>
                    <p class="mt-1 text-xs text-ink-subtle">The costs below are the request's estimates. Change them to what the supplier agreed.</p>
                </div>
            </section>

            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Supplier" for="supplier_id" required :error="form.errors.supplier_id || form.errors.purchase_request_id">
                        <SelectField
                            id="supplier_id"
                            v-model="form.supplier_id"
                            :options="suppliers"
                            :invalid="!!form.errors.supplier_id"
                            placeholder="Pick a supplier"
                        />
                        <p v-if="supplierIsVat" class="mt-1.5 text-xs font-medium text-brand-700">
                            VAT supplier — the costs below include {{ configuredRate }}% VAT.
                        </p>
                    </FormField>

                    <FormField label="Order date" for="order_date" required :error="form.errors.order_date" hint="Not in the future.">
                        <TextField
                            id="order_date"
                            v-model="form.order_date"
                            type="date"
                            :max="today || undefined"
                            :invalid="!!form.errors.order_date"
                        />
                    </FormField>

                    <FormField label="Expected delivery" for="expected_date" :error="form.errors.expected_date" hint="Optional. When the supplier said it would arrive.">
                        <TextField
                            id="expected_date"
                            v-model="form.expected_date"
                            type="date"
                            :min="form.order_date || undefined"
                            :invalid="!!form.errors.expected_date"
                        />
                    </FormField>

                    <FormField
                        label="Deliver to"
                        for="deliver_to_location_id"
                        :error="form.errors.deliver_to_location_id"
                        hint="Optional. Printed on the order; fills in Receive at when the goods arrive."
                    >
                        <SelectField
                            id="deliver_to_location_id"
                            v-model="form.deliver_to_location_id"
                            :options="locations"
                            :invalid="!!form.errors.deliver_to_location_id"
                            placeholder="Not said"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when placed</span>
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                with-units
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                :price-label="vatRate > 0 ? 'Unit Cost (incl. VAT)' : 'Unit Cost'"
                :vat-rate="vatRate"
            />

            <section class="card p-5 sm:p-6">
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :rows="2"
                        :invalid="!!form.errors.notes"
                        placeholder="Printed on the order: delivery instructions, terms agreed, a quote number."
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/purchase-orders">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Place order</PrimaryButton>
        </div>
    </form>
</template>
