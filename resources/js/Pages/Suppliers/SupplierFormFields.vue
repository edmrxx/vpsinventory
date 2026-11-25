<script setup>
import FormField from '../../Components/FormField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

/**
 * The fields shared by the new-supplier and edit-supplier forms.
 */
defineProps({
    form: { type: Object, required: true },
    /** "create" | "edit" */
    mode: { type: String, default: 'create' },
})
</script>

<template>
    <div class="space-y-6">
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Supplier</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                The code is what a purchase names, so keep it short and stable.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Code"
                    for="code"
                    required
                    :error="form.errors.code"
                    hint="Capitals, numbers, dots, dashes and underscores."
                >
                    <TextField
                        id="code"
                        v-model="form.code"
                        :invalid="!!form.errors.code"
                        placeholder="ABC-TRADING"
                    />
                </FormField>

                <FormField label="Name" for="name" required :error="form.errors.name">
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="ABC Trading Co."
                    />
                </FormField>
            </div>
        </section>

        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Contact</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">All optional.</p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Contact person"
                    for="contact_person"
                    :error="form.errors.contact_person"
                >
                    <TextField
                        id="contact_person"
                        v-model="form.contact_person"
                        :invalid="!!form.errors.contact_person"
                        placeholder="Maria Santos"
                    />
                </FormField>

                <FormField label="Phone" for="phone" :error="form.errors.phone">
                    <TextField
                        id="phone"
                        v-model="form.phone"
                        :invalid="!!form.errors.phone"
                        placeholder="0917 000 0000"
                    />
                </FormField>

                <FormField label="Email" for="email" :error="form.errors.email">
                    <TextField
                        id="email"
                        v-model="form.email"
                        type="email"
                        :invalid="!!form.errors.email"
                        placeholder="sales@example.com"
                    />
                </FormField>

                <FormField label="TIN" for="tin" :error="form.errors.tin">
                    <TextField
                        id="tin"
                        v-model="form.tin"
                        :invalid="!!form.errors.tin"
                        placeholder="000-000-000-000"
                    />
                </FormField>

                <FormField
                    label="VAT / Non-VAT"
                    :error="form.errors.is_vat_registered"
                    class="sm:col-span-2"
                >
                    <ToggleSwitch
                        v-model="form.is_vat_registered"
                        label="This supplier is VAT-registered"
                        description="A record of what the supplier is, kept on the supplier. It is not the VAT switch in Settings, which only changes how documents are drawn."
                    />
                </FormField>

                <FormField
                    label="Payment terms (days)"
                    for="payment_terms_days"
                    :error="form.errors.payment_terms_days"
                    hint="Days this supplier gives to pay a delivery put on account. 0 means due on delivery."
                >
                    <TextField
                        id="payment_terms_days"
                        v-model="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="365"
                        :invalid="!!form.errors.payment_terms_days"
                    />
                </FormField>

                <FormField
                    label="Address"
                    for="address"
                    :error="form.errors.address"
                    class="sm:col-span-2"
                >
                    <TextareaField
                        id="address"
                        v-model="form.address"
                        :invalid="!!form.errors.address"
                        placeholder="Street, city, province"
                    />
                </FormField>

                <FormField
                    label="Notes"
                    for="notes"
                    :error="form.errors.notes"
                    hint="Anything worth remembering, such as delivery terms."
                    class="sm:col-span-2"
                >
                    <TextareaField
                        id="notes"
                        v-model="form.notes"
                        :invalid="!!form.errors.notes"
                    />
                </FormField>
            </div>
        </section>

        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Status</h3>

            <div class="mt-4">
                <ToggleSwitch
                    v-model="form.is_active"
                    label="Supplier is active"
                    description="An inactive supplier cannot be picked on a new purchase. Its past purchases stay."
                />
            </div>

            <p v-if="form.errors.is_active" class="mt-2 text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </section>
    </div>
</template>
