<script setup>
import { computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    products: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    reasons: { type: Array, default: () => [] },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
    /** The active locations, and the one "on hand" is shown for (phase 31.4). */
    locations: { type: Array, default: () => [] },
    locationId: { type: [Number, String, null], default: null },
})

const form = useForm({
    kind: 'increase',
    adjustment_date: props.dates.today ?? '',
    // Where the stock is counted in or out (phase 31.4); opening stock too.
    location_id: props.locationId ?? '',
    reason: 'count',
    notes: '',
    idempotency_key: props.idempotencyKey,
    lines: [{ product_id: '', quantity: 1, unit_cost: '0.00' }],
})

const headerFields = ['kind', 'adjustment_date', 'location_id', 'reason', 'notes']

// Another place: fetch the products again with what is on hand there.
watch(
    () => form.location_id,
    (id) => {
        if (!id || String(id) === String(props.locationId ?? '')) return

        router.reload({ only: ['products', 'locationId'], data: { location: id }, preserveState: true })
    }
)

/** Stock going out is costed by the batches it eats, so no cost is typed. */
const takesStockOut = computed(() => form.kind === 'decrease')

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Post this adjustment?',
        text: takesStockOut.value
            ? 'The stock leaves the shelf at once, oldest batch first.'
            : 'The stock goes on the shelf at once, as a new batch.',
        confirmText: 'Yes, post it',
    })

    if (!agreed) return

    form.post('/adjustments', {
        onError: (errors) => {
            const loose = Object.entries(errors)
                .filter(([key]) => !headerFields.includes(key) && !key.startsWith('lines'))
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="New adjustment"
            subheading="Correct stock that no purchase or sale explains."
            back-href="/adjustments"
            back-label="Back to adjustments"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/adjustments">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Save adjustment</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-3">
                    <FormField label="What kind" for="kind" required :error="form.errors.kind">
                        <SelectField
                            id="kind"
                            v-model="form.kind"
                            :options="kinds"
                            :invalid="!!form.errors.kind"
                        />
                    </FormField>

                    <FormField label="Reason" for="reason" required :error="form.errors.reason">
                        <SelectField
                            id="reason"
                            v-model="form.reason"
                            :options="reasons"
                            :invalid="!!form.errors.reason"
                        />
                    </FormField>

                    <FormField
                        label="Date"
                        for="adjustment_date"
                        required
                        :error="form.errors.adjustment_date"
                        :hint="dates.min ? `On or after ${dates.min}, and not in the future.` : 'Not in the future.'"
                    >
                        <TextField
                            id="adjustment_date"
                            v-model="form.adjustment_date"
                            type="date"
                            :min="dates.min ?? undefined"
                            :max="dates.max ?? undefined"
                            :invalid="!!form.errors.adjustment_date"
                        />
                    </FormField>

                    <FormField
                        label="Location"
                        for="location_id"
                        required
                        :error="form.errors.location_id"
                        hint="Where the stock is counted in or out."
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
                    <template v-if="takesStockOut">
                        The cost is not typed in: stock going out is costed by the batches it
                        comes from, oldest first.
                    </template>
                    <template v-else>
                        What goes in opens a new batch at the cost you enter, and later sales
                        are costed from it in turn.
                    </template>
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                :show-price="!takesStockOut"
                :show-stock="takesStockOut"
                document-noun="adjustment"
                price-label="Unit Cost"
            />

            <section class="card p-5 sm:p-6">
                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    hint="Worth saying more than the reason alone. This is what somebody reads a year from now."
                >
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :rows="2"
                        :invalid="!!form.errors.notes"
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/adjustments">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save adjustment</PrimaryButton>
        </div>
    </form>
</template>
