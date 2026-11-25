<script setup>
import { computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { ArrowRight } from '@lucide/vue'
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
    /** Each product with what is free to move at the source, in `on_hand`. */
    products: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    fromLocationId: { type: [Number, String, null], default: null },
    toLocationId: { type: [Number, String, null], default: null },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
})

const form = useForm({
    from_location_id: props.fromLocationId ?? '',
    to_location_id: props.toLocationId ?? '',
    transfer_date: props.dates.today ?? '',
    notes: '',
    idempotency_key: props.idempotencyKey,
    lines: [{ product_id: '', quantity: 1 }],
})

const headerFields = ['from_location_id', 'to_location_id', 'transfer_date', 'notes']

const label = (id) => props.locations.find((l) => String(l.value) === String(id))?.label ?? null

/** Never to where the goods already are. */
const destinations = computed(() =>
    props.locations.filter((l) => String(l.value) !== String(form.from_location_id))
)

// Another source: fetch the products again with what is free there.
watch(
    () => form.from_location_id,
    (id) => {
        if (String(id) === String(form.to_location_id)) form.to_location_id = ''
        if (!id || String(id) === String(props.fromLocationId ?? '')) return

        router.reload({ only: ['products', 'fromLocationId'], data: { from: id }, preserveState: true })
    }
)

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Post this transfer?',
        text: `The goods leave ${label(form.from_location_id) ?? 'the source'} and arrive at ${label(form.to_location_id) ?? 'the destination'} at once, at the cost they already carry.`,
        confirmText: 'Yes, move them',
    })

    if (!agreed) return

    form.post('/transfers', {
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
            heading="New transfer"
            subheading="Move stock from one location to another. Nothing is bought or sold."
            back-href="/transfers"
            back-label="Back to transfers"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/transfers">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Save transfer</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid items-start gap-5 sm:grid-cols-[1fr_auto_1fr_1fr]">
                    <FormField
                        label="From"
                        for="from_location_id"
                        required
                        :error="form.errors.from_location_id"
                        hint="Where the goods are now."
                    >
                        <SelectField
                            id="from_location_id"
                            v-model="form.from_location_id"
                            :options="locations"
                            :invalid="!!form.errors.from_location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>

                    <ArrowRight :size="18" class="mx-auto hidden text-ink-subtle sm:mt-9 sm:block" aria-hidden="true" />

                    <FormField
                        label="To"
                        for="to_location_id"
                        required
                        :error="form.errors.to_location_id"
                        hint="Where the goods are going."
                    >
                        <SelectField
                            id="to_location_id"
                            v-model="form.to_location_id"
                            :options="destinations"
                            :invalid="!!form.errors.to_location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>

                    <FormField
                        label="Date"
                        for="transfer_date"
                        required
                        :error="form.errors.transfer_date"
                        :hint="dates.min ? `On or after ${dates.min}, and not in the future.` : 'Not in the future.'"
                    >
                        <TextField
                            id="transfer_date"
                            v-model="form.transfer_date"
                            type="date"
                            :min="dates.min ?? undefined"
                            :max="dates.max ?? undefined"
                            :invalid="!!form.errors.transfer_date"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    The goods leave the oldest batches at the source and arrive as batches of the
                    same cost, so the stock is worth exactly what it was. Quantities are in each
                    product's own unit. What customer orders hold at the source cannot be moved.
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                :show-price="false"
                show-stock
                document-noun="transfer"
            />

            <section class="card p-5 sm:p-6">
                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    hint="For example who carried it, or the delivery it came from."
                >
                    <TextareaField id="notes" v-model="form.notes" :rows="2" :invalid="!!form.errors.notes" />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/transfers">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save transfer</PrimaryButton>
        </div>
    </form>
</template>
