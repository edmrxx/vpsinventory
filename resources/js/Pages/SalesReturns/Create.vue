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
    sale: { type: Object, required: true },
    reasons: { type: Array, default: () => [] },
    dates: { type: Object, default: () => ({}) },
    idempotencyKey: { type: String, default: '' },
})

const { money } = useMoney()

const form = useForm({
    sale_id: props.sale.id,
    return_date: props.dates.today ?? '',
    reason: props.reasons[0]?.value ?? '',
    notes: '',
    idempotency_key: props.idempotencyKey,
    lines: props.sale.lines.map((line) => ({ sale_line_id: line.id, quantity: 0 })),
})

const headerFields = ['sale_id', 'return_date', 'reason', 'notes']

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
        text: 'The stock goes back on the shelf at once, into the batches it came out of. A return cannot be undone.',
        confirmText: 'Yes, post it',
    })

    if (!agreed) return

    form.post('/sales-returns', {
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
            :heading="`Return against ${sale.reference}`"
            :subheading="`${sale.customer ?? 'Unknown customer'} · sold ${sale.sale_date}`"
            :back-href="`/sales/${sale.id}`"
            back-label="Back to the sale"
            :crumbs="[
                { label: 'Sales', href: '/sales' },
                { label: sale.reference, href: `/sales/${sale.id}` },
            ]"
        >
            <template #actions>
                <PrimaryButton variant="secondary" :href="`/sales/${sale.id}`">Cancel</PrimaryButton>
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
                        <p class="text-xs text-ink-muted">The sale came to</p>
                        <p class="mt-0.5 tabular-nums text-base font-bold text-ink">
                            {{ money(sale.total_amount) }}
                        </p>
                    </div>
                </div>

                <p v-if="Number(sale.balance_due) > 0" class="mt-4 text-xs text-ink-subtle">
                    {{ money(sale.balance_due) }} of this sale is still owed. A return comes off that first,
                    and only the rest is handed back as cash.
                </p>

                <p v-if="Number(sale.mobilization) > 0" class="mt-4 text-xs text-ink-subtle">
                    This sale included {{ money(sale.mobilization) }} mobilization. A return gives back goods
                    only, so that charge is not refunded.
                </p>

                <p v-if="Number(sale.discount) > 0" class="mt-4 text-xs text-ink-subtle">
                    This sale had {{ money(sale.discount) }} taken off, so a refund is worked out on
                    what was actually charged, not on the list price.
                </p>
            </section>

            <ReturnLines
                v-model="form.lines"
                :lines="sale.lines"
                :errors="form.errors"
                :disabled="form.processing"
                price-field="unit_price"
                price-label="Price"
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
            <PrimaryButton variant="secondary" :href="`/sales/${sale.id}`">Cancel</PrimaryButton>
            <PrimaryButton type="submit" :loading="form.processing">Save return</PrimaryButton>
        </div>
    </form>
</template>
