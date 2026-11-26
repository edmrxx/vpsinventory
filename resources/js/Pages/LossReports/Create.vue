<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Camera, X } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import LineItems from '../../Components/LineItems.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'

defineOptions({ layout: AppLayout })

const props = defineProps({
    /** Each product with what is free to report at the location, in `on_hand`. */
    products: { type: Array, default: () => [] },
    locations: { type: Array, default: () => [] },
    locationId: { type: [Number, String, null], default: null },
    reasons: { type: Array, default: () => [] },
    /** The reasons where the goods are there to photograph. */
    photographable: { type: Array, default: () => [] },
    photoRequired: { type: Boolean, default: true },
    maxPhotos: { type: Number, default: 5 },
    idempotencyKey: { type: String, default: '' },
})

const form = useForm({
    location_id: props.locationId ?? '',
    reason: '',
    notes: '',
    idempotency_key: props.idempotencyKey,
    lines: [{ product_id: '', quantity: 1 }],
    photos: [],
})

const headerFields = ['location_id', 'reason', 'notes', 'photos']

const needsPhoto = computed(() => props.photoRequired && props.photographable.includes(form.reason))

// Another place: fetch the products again with what is free there.
watch(
    () => form.location_id,
    (id) => {
        if (!id || String(id) === String(props.locationId ?? '')) return
        router.reload({ only: ['products', 'locationId'], data: { location: id }, preserveState: true })
    }
)

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

/* ------------------------------------------------------------ Photos */

const picker = ref(null)
const previews = ref([])

function addPhotos(event) {
    const room = props.maxPhotos - form.photos.length
    const files = Array.from(event.target.files ?? []).slice(0, Math.max(0, room))

    for (const file of files) {
        form.photos.push(file)
        previews.value.push(URL.createObjectURL(file))
    }

    event.target.value = ''
}

function removePhoto(index) {
    URL.revokeObjectURL(previews.value[index])
    form.photos.splice(index, 1)
    previews.value.splice(index, 1)
}

onBeforeUnmount(() => previews.value.forEach((url) => URL.revokeObjectURL(url)))

const photoError = computed(() =>
    form.errors.photos ?? Object.entries(form.errors).find(([key]) => key.startsWith('photos.'))?.[1] ?? null
)

/* ------------------------------------------------------------ Send */

async function submit() {
    const place = props.locations.find((l) => String(l.value) === String(form.location_id))?.label ?? 'the location'

    const agreed = await confirmAction({
        icon: 'question',
        title: 'Report this loss?',
        text: `The goods are held aside at ${place}: nobody can sell or move them until an approver writes them off or rejects the report.`,
        confirmText: 'Yes, report it',
    })

    if (!agreed) return

    form.post('/loss-reports', {
        onError: (errors) => {
            const loose = Object.entries(errors)
                .filter(([key]) => !headerFields.includes(key) && !key.startsWith('lines') && !key.startsWith('photos'))
                .map(([, message]) => message)

            if (loose.length) showValidationErrors(loose)
        },
    })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="Report a loss"
            subheading="Damaged, expired, rusted, stolen or missing goods. Nothing is written off until it is approved."
            back-href="/loss-reports"
            back-label="Back to loss reports"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/loss-reports">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Report loss</PrimaryButton>
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
                        hint="Where the goods are. They are held aside there."
                    >
                        <SelectField
                            id="location_id"
                            v-model="form.location_id"
                            :options="locations"
                            :invalid="!!form.errors.location_id"
                            placeholder="Pick a location"
                        />
                    </FormField>

                    <FormField label="What happened" for="reason" required :error="form.errors.reason">
                        <SelectField
                            id="reason"
                            v-model="form.reason"
                            :options="reasons"
                            :invalid="!!form.errors.reason"
                            placeholder="Pick a reason"
                        />
                    </FormField>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Quantities are in each product's own unit. Only what is free can be reported: what
                    customer orders hold, or another report already holds, is not. Leave the goods
                    where they are counted — they are still on the books until written off.
                </p>
            </section>

            <LineItems
                v-model="form.lines"
                :products="products"
                :errors="form.errors"
                :disabled="form.processing"
                :show-price="false"
                show-stock
                document-noun="report"
            />

            <section class="card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">
                            Photos
                            <span v-if="needsPhoto" class="text-danger-600">*</span>
                        </h3>
                        <p class="mt-1 text-xs text-ink-muted">
                            <template v-if="needsPhoto">At least one photo of the goods is needed for this reason.</template>
                            <template v-else>Optional. Up to {{ maxPhotos }}; JPG, PNG or WebP, 4 MB each.</template>
                        </p>
                    </div>
                    <PrimaryButton
                        variant="secondary"
                        :disabled="form.photos.length >= maxPhotos || form.processing"
                        @click="picker?.click()"
                    >
                        <Camera :size="15" />
                        Add photo
                    </PrimaryButton>
                    <input
                        ref="picker"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        class="hidden"
                        @change="addPhotos"
                    />
                </div>

                <div v-if="previews.length" class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-5">
                    <div v-for="(url, index) in previews" :key="url" class="relative overflow-hidden rounded-field border border-line">
                        <img :src="url" alt="" class="aspect-square w-full object-cover" />
                        <button
                            type="button"
                            class="absolute top-1 right-1 grid size-6 place-items-center rounded-full bg-scrim/70 text-ink-inverse hover:bg-scrim"
                            :aria-label="`Remove photo ${index + 1}`"
                            @click="removePhoto(index)"
                        >
                            <X :size="13" />
                        </button>
                    </div>
                </div>

                <p v-if="photoError" class="mt-3 text-xs font-medium text-danger-700">{{ photoError }}</p>
            </section>

            <section class="card p-5 sm:p-6">
                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    hint="What happened, and where the goods are set aside."
                >
                    <TextareaField id="notes" v-model="form.notes" :rows="2" :invalid="!!form.errors.notes" />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" href="/loss-reports">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Report loss</PrimaryButton>
        </div>
    </form>
</template>
