<script setup>
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { usualSupplier } from '../../lib/usualSupplier'

/**
 * Ask for goods to be bought (phase 21).
 *
 * Nothing moves when it is saved: the request waits for somebody who may
 * approve it. The lines are typed like a purchase's — a product, a unit, a
 * quantity — at an estimated cost, so the order made from it can copy them.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
})

const form = useForm({
    department_id: '',
    supplier_id: '',
    needed_by: '',
    reason: '',
    notes: '',
    // Sent back untouched, so a double-clicked Save lands on the request it
    // already raised instead of raising a second one.
    idempotency_key: props.idempotencyKey,
    lines: [{ product_id: '', unit_id: '', quantity: 1, unit_cost: '0.00' }],
})

const headerFields = ['department_id', 'supplier_id', 'needed_by', 'reason', 'notes']

/*
 * The first product's usual supplier fills in the box (phase 30) — but only
 * until somebody picks a supplier, or "Any supplier", by hand. From then on
 * the form leaves their choice alone.
 */
const usual = computed(() => usualSupplier(form.lines, props.products, props.suppliers))
const filledIn = ref(null)
let chosenByHand = false

watch(usual, (id) => {
    if (chosenByHand) return

    filledIn.value = id
    form.supplier_id = id ?? ''
})

watch(
    () => form.supplier_id,
    (id) => {
        if (String(id ?? '') !== String(filledIn.value ?? '')) {
            chosenByHand = true
            filledIn.value = null
        }
    }
)

/** A screen brought back from history gets a fresh key on reload. */
watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Send this request for approval?',
        text: 'It cannot be edited afterwards. If something is wrong, close it and raise a new one.',
        confirmText: 'Yes, send it',
    })

    if (!agreed) return

    form.post('/purchase-requests', {
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
            heading="New purchase request"
            subheading="Ask for goods to be bought. Nothing is ordered until somebody approves it."
            back-href="/purchase-requests"
            back-label="Back to purchase requests"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/purchase-requests">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Send for approval</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <FormField
                    label="Why is it needed?"
                    for="reason"
                    required
                    :error="form.errors.reason"
                    hint="Whoever approves reads this first."
                >
                    <TextareaField
                        id="reason"
                        v-model="form.reason"
                        :rows="2"
                        :invalid="!!form.errors.reason"
                        placeholder="For example: running low on 2.0 mm wire, and a contractor order comes in next week."
                    />
                </FormField>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <FormField
                        label="Department"
                        for="department_id"
                        :error="form.errors.department_id"
                        hint="Who the goods are for. Kept under Masters → Departments."
                    >
                        <SelectField
                            id="department_id"
                            v-model="form.department_id"
                            :options="departments"
                            :invalid="!!form.errors.department_id"
                            placeholder="No department"
                        />
                    </FormField>

                    <FormField
                        label="Preferred supplier"
                        for="supplier_id"
                        :error="form.errors.supplier_id"
                        :hint="filledIn
                            ? 'Filled in from the product\'s usual supplier. Change it if you like.'
                            : 'Optional. Who you would buy from; the buyer may choose another.'"
                    >
                        <SelectField
                            id="supplier_id"
                            v-model="form.supplier_id"
                            :options="suppliers"
                            :invalid="!!form.errors.supplier_id"
                            placeholder="Any supplier"
                        />
                    </FormField>

                    <FormField
                        label="Needed by"
                        for="needed_by"
                        :error="form.errors.needed_by"
                        hint="Optional. Today or later."
                    >
                        <TextField
                            id="needed_by"
                            v-model="form.needed_by"
                            type="date"
                            :min="today || undefined"
                            :invalid="!!form.errors.needed_by"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when sent</span>
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                with-units
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                price-label="Est. Unit Cost"
            />

            <section class="card p-5 sm:p-6">
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :rows="2"
                        :invalid="!!form.errors.notes"
                        placeholder="Anything else the buyer should know: a brand, a size, a quote you have seen."
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/purchase-requests">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Send for approval</PrimaryButton>
        </div>
    </form>
</template>
