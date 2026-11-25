<script setup>
import { computed, watch } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { FileText } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { count, useVat } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    /** The active locations (phase 31.3), and the default one. */
    locations: { type: Array, default: () => [] },
    defaultLocationId: { type: Number, default: null },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
    /**
     * The order this delivery fills (phase 23): its supplier, and a line for
     * everything still to come, each carrying its `purchase_order_line_id`.
     */
    fromOrder: { type: Object, default: null },
})

/**
 * Whether this delivery carries VAT depends on the supplier, not on us.
 *
 * A VAT-registered supplier charges VAT whether or not the business is
 * registered, so this does not read the Tax Settings switch. The server
 * decides again from the supplier when it posts; this only draws what the
 * figure will be.
 */
const { configuredRate } = useVat()

const form = useForm({
    supplier_id: props.fromOrder?.supplier_id ?? '',
    purchase_order_id: props.fromOrder?.id ?? '',
    supplier_reference: '',
    purchase_date: props.dates.today ?? '',
    // Where the goods go on the shelf (phase 31.3): the order's Deliver to,
    // else the default location.
    location_id: props.fromOrder?.deliver_to_location_id ?? props.defaultLocationId ?? '',
    // Paid on delivery unless put on the supplier's account (phase 24).
    on_account: false,
    notes: '',
    // Sent back untouched, so a double-clicked Save lands on the purchase it
    // already made instead of making a second one.
    idempotency_key: props.idempotencyKey,
    lines: props.fromOrder?.lines?.length
        ? props.fromOrder.lines.map((line) => ({ ...line }))
        : [{ product_id: '', unit_id: '', quantity: 1, unit_cost: '0.00' }],
})

const supplier = computed(() =>
    props.suppliers.find((row) => String(row.value) === String(form.supplier_id))
)

const supplierIsVat = computed(() => !! supplier.value?.is_vat_registered)

const vatRate = computed(() => (supplierIsVat.value ? configuredRate.value : 0))

/** When a bill on account would fall due: the date plus the supplier's terms. */
const dueOn = computed(() => {
    if (!form.purchase_date) return null
    const terms = Number(supplier.value?.payment_terms_days ?? 0)
    const [y, m, d] = form.purchase_date.split('-').map(Number)
    const due = new Date(Date.UTC(y, m - 1, d + terms))

    return {
        terms,
        label: due.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }),
    }
})

const headerFields = ['supplier_id', 'supplier_reference', 'purchase_date', 'location_id', 'notes', 'purchase_order_id', 'on_account']

const receivingAt = computed(() =>
    props.locations.find((row) => String(row.value) === String(form.location_id))?.label ?? null
)

/**
 * Take whatever key the server last handed out.
 *
 * Pressing Back brings this screen out of the browser's history with the key
 * it carried the first time. The server refuses that, and the reload comes
 * with a fresh one — which has to reach the form, or saving again would be
 * refused for the same reason forever.
 */
watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    // Posting is not reversible in the ordinary way, so it is worth asking.
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Post this purchase?',
        text: receivingAt.value
            ? `The stock goes on the shelf at ${receivingAt.value} at once. A posted purchase cannot be edited afterwards, only voided.`
            : 'The stock goes on the shelf at once. A posted purchase cannot be edited afterwards, only voided.',
        confirmText: 'Yes, post it',
    })

    if (!agreed) return

    form.post('/purchases', {
        onError: (errors) => {
            // A field error already shows where it belongs. The alert is for
            // the ones that belong to no single box, such as a refusal from
            // the ledger itself.
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
            heading="New purchase"
            subheading="Record goods bought in. The stock goes on the shelf when you post it."
            back-href="/purchases"
            back-label="Back to purchases"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/purchases">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Save purchase</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <!-- Received against an order (phase 23) -->
            <section v-if="fromOrder" class="card p-4 sm:p-5">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700">
                        <FileText :size="18" />
                    </span>
                    <div class="min-w-0 text-sm">
                        <p class="text-ink">
                            Receiving against order
                            <Link :href="`/purchase-orders/${fromOrder.id}`" class="font-mono text-xs font-semibold text-brand-700 hover:underline">{{ fromOrder.reference }}</Link>
                            from {{ fromOrder.supplier }}.
                        </p>
                        <p class="mt-0.5 text-xs text-ink-subtle">
                            The lines below are what is still to come, at the agreed cost. Type what actually arrived and what the invoice says;
                            take off a line that did not come. Anything extra the order did not ask for goes on a line of its own.
                        </p>
                        <p v-if="form.errors.purchase_order_id" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.purchase_order_id }}</p>
                    </div>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                                <th class="py-1.5 pr-3 font-semibold">Product</th>
                                <th class="py-1.5 pr-3 text-right font-semibold">Ordered</th>
                                <th class="py-1.5 pr-3 text-right font-semibold">Received</th>
                                <th class="py-1.5 text-right font-semibold">Still to come</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in fromOrder.summary" :key="row.sku">
                                <td class="py-1.5 pr-3 text-ink">{{ row.name }} <span class="font-mono text-ink-subtle">{{ row.sku }}</span></td>
                                <td class="py-1.5 pr-3 text-right tabular-nums text-ink-muted">{{ count(row.ordered) }} {{ row.unit }}</td>
                                <td class="py-1.5 pr-3 text-right tabular-nums text-ink-muted">{{ count(row.received) }} {{ row.unit }}</td>
                                <td class="py-1.5 text-right tabular-nums font-semibold text-ink">{{ count(row.outstanding) }} {{ row.unit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Header -->
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-3">
                    <FormField
                        label="Supplier"
                        for="supplier_id"
                        required
                        :error="form.errors.supplier_id"
                    >
                        <!-- Fixed to the order's supplier when receiving against one. -->
                        <SelectField
                            id="supplier_id"
                            v-model="form.supplier_id"
                            :options="suppliers"
                            :invalid="!!form.errors.supplier_id"
                            :disabled="!!fromOrder"
                            placeholder="Pick a supplier"
                        />

                        <p
                            v-if="supplierIsVat"
                            class="mt-1.5 text-xs font-medium text-brand-700"
                        >
                            VAT supplier — the prices below include {{ configuredRate }}% VAT.
                        </p>
                    </FormField>

                    <FormField
                        label="Supplier invoice no."
                        for="supplier_reference"
                        :error="form.errors.supplier_reference"
                        hint="Optional. The number on their invoice, so the same delivery cannot be booked twice."
                    >
                        <TextField
                            id="supplier_reference"
                            v-model="form.supplier_reference"
                            :invalid="!!form.errors.supplier_reference"
                            placeholder="INV-2026-001"
                        />
                    </FormField>

                    <FormField
                        label="Date"
                        for="purchase_date"
                        required
                        :error="form.errors.purchase_date"
                        :hint="dates.min ? `On or after ${dates.min}, and not in the future.` : 'Not in the future.'"
                    >
                        <TextField
                            id="purchase_date"
                            v-model="form.purchase_date"
                            type="date"
                            :min="dates.min ?? undefined"
                            :max="dates.max ?? undefined"
                            :invalid="!!form.errors.purchase_date"
                        />
                    </FormField>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <FormField
                        label="Receive at"
                        for="location_id"
                        required
                        :error="form.errors.location_id"
                        :hint="fromOrder?.deliver_to_location_id
                            ? 'Where the order asked for it. Change it if the truck went elsewhere.'
                            : 'Where the goods go on the shelf.'"
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

                <div class="mt-5 rounded-field bg-surface-muted p-4">
                    <ToggleSwitch
                        v-model="form.on_account"
                        label="On the supplier's account"
                        :description="form.on_account && dueOn
                            ? `Not paid today. The bill is due on ${dueOn.label}${dueOn.terms ? `, ${dueOn.terms} days from delivery` : ', the day it was delivered'}, and is paid later under Supplier Payments.`
                            : 'Off: paid on delivery. On: the shop pays this bill later, by the supplier\'s payment terms.'"
                    />
                    <p v-if="form.errors.on_account" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors.on_account }}</p>
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
                        placeholder="Anything worth remembering about this delivery."
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/purchases">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save purchase</PrimaryButton>
        </div>
    </form>
</template>
