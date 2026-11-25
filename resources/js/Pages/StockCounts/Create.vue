<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import { confirmAction } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    locations: { type: Array, default: () => [] },
    locationId: { type: [Number, String, null], default: null },
    categories: { type: Array, default: () => [] },
    /** Location id => the count still open there. */
    open: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
})

const form = useForm({
    location_id: props.locationId ?? '',
    category_id: '',
    notes: '',
    idempotency_key: props.idempotencyKey,
})

const label = (list, id) => list.find((o) => String(o.value) === String(id))?.label ?? null

/** A count still open at the location picked: another cannot start there. */
const blocking = computed(() => props.open[form.location_id] ?? null)

async function submit() {
    const where = label(props.locations, form.location_id) ?? 'this location'
    const what = label(props.categories, form.category_id) ?? 'every product'

    const agreed = await confirmAction({
        icon: 'question',
        title: 'Start the count now?',
        text: `What the books say for ${what} at ${where} is taken as of this moment — the cutoff. Count the shelves soon after: a sale between the cutoff and the count of that shelf would read as a shortfall.`,
        confirmText: 'Yes, start it',
    })

    if (!agreed) return

    form.post('/stock-counts')
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="New stock count"
            subheading="Count what is on the shelf at one location. Nothing moves until the count is approved."
            back-href="/stock-counts"
            back-label="Back to stock counts"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/stock-counts">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing" :disabled="!!blocking">Start count</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid items-start gap-5 sm:grid-cols-2">
                    <FormField
                        label="Location"
                        for="location_id"
                        required
                        :error="form.errors.location_id"
                        hint="The place being counted. One count at a time per location."
                    >
                        <SelectField
                            id="location_id"
                            v-model="form.location_id"
                            :options="locations"
                            :invalid="!!form.errors.location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>

                    <FormField
                        label="Counting"
                        for="category_id"
                        :error="form.errors.category_id"
                        hint="Leave empty to count every product, or pick one category for a smaller count."
                    >
                        <SelectField
                            id="category_id"
                            v-model="form.category_id"
                            :options="categories"
                            :invalid="!!form.errors.category_id"
                            placeholder="All products"
                        />
                    </FormField>
                </div>

                <p
                    v-if="blocking"
                    class="mt-5 flex items-start gap-2 rounded-field bg-warning-50 px-4 py-3 text-xs font-medium text-warning-700"
                >
                    <TriangleAlert :size="15" class="mt-px shrink-0" />
                    <span>
                        Count
                        <Link :href="`/stock-counts/${blocking.id}`" class="font-mono font-semibold underline">{{ blocking.reference }}</Link>
                        is still open here. Finish or cancel it before starting another.
                    </span>
                </p>

                <div class="mt-5 space-y-1.5 text-xs text-ink-subtle">
                    <p>
                        <strong class="font-semibold text-ink-muted">The cutoff.</strong>
                        Starting the count takes what the books say is at the location at that moment.
                        The till keeps selling: only the difference between what you count and that
                        figure is posted, on top of anything sold or received since.
                    </p>
                    <p>
                        <strong class="font-semibold text-ink-muted">Count soon after starting</strong>,
                        before the shop opens if you can. A sale from a shelf before somebody counts
                        it would read as a shortfall. The sheet shows the approver which lines moved
                        after the cutoff.
                    </p>
                    <p>
                        Counters do not see the book figures while they count. An approver checks the
                        differences, then approves — which posts them — or sends the sheet back.
                    </p>
                </div>
            </section>

            <section class="card p-5 sm:p-6">
                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    hint="For example who is counting, or which aisles."
                >
                    <TextareaField id="notes" v-model="form.notes" :rows="2" :invalid="!!form.errors.notes" />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/stock-counts">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing" :disabled="!!blocking">Start count</PrimaryButton>
        </div>
    </form>
</template>
