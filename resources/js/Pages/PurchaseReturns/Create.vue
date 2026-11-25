<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ReturnLines from '../../Components/ReturnLines.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { useMoney } from '../../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    purchase: { type: Object, required: true },
    reasons: { type: Array, default: () => [] },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
})

const { money } = useMoney()

const form = useForm({
    purchase_id: props.purchase.id,
    return_date: props.dates.today ?? '',
    reason: props.reasons[0]?.value ?? '',
    notes: '',
    idempotency_key: props.idempotencyKey,
    lines: props.purchase.lines.map((line) => ({ purchase_line_id: line.id, quantity: 0 })),
})

const headerFields = ['purchase_id', 'return_date', 'reason', 'notes']

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

async function submit() {
    const agreed = await confirmAction({
        icon: 'question',
        title: 'Post this return?',
        text: 'The stock comes off the shelf at once, out of this delivery\'s own batch. A return cannot be undone.',
        confirmText: 'Yes, post it',
    })

    if (!agreed) return

    form.post('/purchase-returns', {
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
            :heading="`Return against ${purchase.reference}`"
            :subheading="`${purchase.supplier ?? 'Unknown supplier'} · delivered ${purchase.purchase_date}`"
            :back-href="`/purchases/${purchase.id}`"
            back-label="Back to the delivery"
            :crumbs="[
                { label: 'Purchases', href: '/purchases' },
                { label: purchase.reference, href: `/purchases/${purchase.id}` },
            ]"
        >
            <template #actions>
                <PrimaryButton variant="secondary" :href="`/purchases/${purchase.id}`">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing">Save return</PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-3">
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
                        for="return_date"
                        required
                        :error="form.errors.return_date"
                        :hint="dates.min ? `On or after ${dates.min}, and not in the future.` : 'Not in the future.'"
                    >
                        <TextField
                            id="return_date"
                            v-model="form.return_date"
                            type="date"
                            :min="dates.min ?? undefined"
                            :max="dates.max ?? undefined"
                            :invalid="!!form.errors.return_date"
                        />
                    </FormField>

                    <div class="self-end rounded-field bg-surface-sunken p-3">
                        <p class="text-xs text-ink-muted">The delivery came to</p>
                        <p class="mt-0.5 tabular-nums text-base font-bold text-ink">
                            {{ money(purchase.total_amount) }}
                        </p>
                    </div>
                </div>

                <p class="mt-4 text-xs text-ink-subtle">
                    Only what is still on the shelf from this delivery can go back. Anything
                    already sold has gone, and no paperwork brings it back from a customer.
                </p>
            </section>

            <ReturnLines
                v-model="form.lines"
                :lines="purchase.lines"
                :errors="form.errors"
                :disabled="form.processing"
                price-field="unit_cost"
                price-label="Unit Cost"
            />

            <section class="card p-5 sm:p-6">
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :rows="2"
                        :invalid="!!form.errors.notes"
                        placeholder="Anything worth remembering about this return."
                    />
                </FormField>
            </section>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <PrimaryButton variant="secondary" :href="`/purchases/${purchase.id}`">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save return</PrimaryButton>
        </div>
    </form>
</template>
