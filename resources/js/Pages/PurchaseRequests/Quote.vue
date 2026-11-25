<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Save } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import { lineCentavos, useMoney } from '../../lib/money'
import { showValidationErrors } from '../../lib/swal'

/**
 * One supplier's prices for an approved request (phase 24.2), typed from
 * the Request for Quotation the supplier filled in.
 *
 * A price is per the unit the request line was typed in, VAT included. An
 * item left empty is one the supplier does not carry. Line items, so a page
 * of its own rather than a dialog.
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    request: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
})

const { money } = useMoney()

const form = useForm({
    supplier_id: '',
    delivery_days: '',
    payment_terms: '',
    notes: '',
    prices: Object.fromEntries(props.request.lines.map((line) => [line.id, ''])),
})

const lineTotal = (line) => {
    const price = form.prices[line.id]
    return price === '' || price === null ? null : lineCentavos(price, line.unit_quantity) / 100
}

const total = computed(() =>
    props.request.lines.reduce((sum, line) => sum + (lineTotal(line) ?? 0), 0)
)

const priced = computed(() => props.request.lines.filter((line) => lineTotal(line) !== null).length)

function submit() {
    form.post(`/purchase-requests/${props.request.id}/quotes`, {
        preserveScroll: true,
        onError: (errors) => showValidationErrors(errors),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="`Supplier quote for ${request.reference}`"
            :subheading="request.department ? `${request.department} department` : 'Type the prices the supplier wrote on the Request for Quotation.'"
            :back-href="`/purchase-requests/${request.id}`"
            back-label="Back to the request"
            :crumbs="[
                { label: 'Purchase requests', href: '/purchase-requests' },
                { label: request.reference, href: `/purchase-requests/${request.id}` },
            ]"
        />

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <h3 class="text-sm font-semibold text-ink">Supplier</h3>
                <div class="mt-4 space-y-5">
                    <FormField label="Supplier" for="supplier_id" required :error="form.errors.supplier_id"
                        hint="Only suppliers that have not quoted on this request yet.">
                        <SelectField
                            id="supplier_id"
                            v-model="form.supplier_id"
                            :options="suppliers"
                            :invalid="!!form.errors.supplier_id"
                            placeholder="Pick the supplier"
                        />
                    </FormField>
                    <FormField label="Delivery within (days)" for="delivery_days" :error="form.errors.delivery_days" hint="Optional.">
                        <TextField id="delivery_days" v-model="form.delivery_days" type="number" :invalid="!!form.errors.delivery_days" placeholder="3" />
                    </FormField>
                    <FormField label="Payment terms" for="payment_terms" :error="form.errors.payment_terms" hint="Optional.">
                        <TextField id="payment_terms" v-model="form.payment_terms" :invalid="!!form.errors.payment_terms" placeholder="30 days after delivery" />
                    </FormField>
                    <FormField label="Note" for="notes" :error="form.errors.notes" hint="Optional. Anything else the supplier said.">
                        <TextareaField id="notes" v-model="form.notes" :rows="2" :invalid="!!form.errors.notes" />
                    </FormField>
                </div>
            </section>

            <section class="card overflow-hidden lg:col-span-2">
                <div class="border-b border-line px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-ink">Prices</h3>
                    <p class="text-xs text-ink-subtle">Per unit, VAT included. Leave an item empty if the supplier does not carry it.</p>
                </div>

                <div class="divide-y divide-line">
                    <div
                        v-for="line in request.lines"
                        :key="line.id"
                        class="grid gap-2 px-5 py-3 sm:grid-cols-[1fr_7rem_10rem_8rem] sm:items-center sm:gap-3"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                            <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                        </div>
                        <span class="tabular-nums text-sm text-ink sm:text-right">{{ line.quantity_label }}</span>
                        <label class="block">
                            <span class="sr-only">Price per {{ line.unit_code }} for {{ line.name }}</span>
                            <span class="flex items-center gap-1.5">
                                <input
                                    v-model="form.prices[line.id]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-9 w-full rounded-field border bg-surface px-2 text-right text-sm text-ink tabular-nums focus:border-brand-300"
                                    :class="form.errors[`prices.${line.id}`] ? 'border-danger-500' : 'border-line'"
                                    placeholder="Not carried"
                                />
                                <span class="shrink-0 text-2xs text-ink-subtle">/ {{ line.unit_code }}</span>
                            </span>
                            <span v-if="form.errors[`prices.${line.id}`]" class="mt-1 block text-xs text-danger-700">{{ form.errors[`prices.${line.id}`] }}</span>
                        </label>
                        <span class="tabular-nums text-sm font-semibold sm:text-right" :class="lineTotal(line) === null ? 'text-ink-subtle' : 'text-ink'">
                            {{ lineTotal(line) === null ? '—' : money(lineTotal(line)) }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-surface-muted px-5 py-3.5">
                    <p class="text-sm text-ink">
                        {{ priced }} of {{ request.lines.length }} item(s) priced ·
                        <span class="font-bold tabular-nums">{{ money(total) }}</span>
                        <span v-if="form.errors.prices" class="block text-xs font-medium text-danger-700">{{ form.errors.prices }}</span>
                    </p>
                    <PrimaryButton :loading="form.processing" @click="submit">
                        <Save :size="15" />
                        Save quote
                    </PrimaryButton>
                </div>
            </section>
        </div>
    </div>
</template>
