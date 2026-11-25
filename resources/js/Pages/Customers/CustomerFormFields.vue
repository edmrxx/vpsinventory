<script setup>
import FormField from '../../Components/FormField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

/**
 * The fields shared by the new-customer and edit-customer forms.
 */
defineProps({
    form: { type: Object, required: true },
    /** "create" | "edit" */
    mode: { type: String, default: 'create' },
    /** The walk-in row must stay active, so its toggle is locked. */
    isWalkIn: { type: Boolean, default: false },
    /** May this user set a credit limit and terms? */
    canCredit: { type: Boolean, default: false },
})
</script>

<template>
    <div class="space-y-6">
        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Customer</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                The code is what a sale names, so keep it short and stable.
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
                        placeholder="CUST-001"
                    />
                </FormField>

                <FormField label="Name" for="name" required :error="form.errors.name">
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="Dela Cruz Enterprises"
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
                        placeholder="buyer@example.com"
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
                    hint="Anything worth remembering about this customer."
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

        <section v-if="canCredit && !isWalkIn" class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Credit</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">
                How much this customer may owe at once. Zero means no credit.
            </p>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <FormField label="Credit limit" for="credit_limit" :error="form.errors.credit_limit">
                    <TextField
                        id="credit_limit"
                        v-model="form.credit_limit"
                        type="number"
                        min="0"
                        step="0.01"
                        :invalid="!!form.errors.credit_limit"
                    />
                </FormField>

                <FormField
                    label="Usual payment terms (days)"
                    for="payment_terms_days"
                    :error="form.errors.payment_terms_days"
                    hint="Each sale on account starts here. The cashier may give fewer days on a sale; more needs the right to set credit."
                >
                    <TextField
                        id="payment_terms_days"
                        v-model="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="365"
                        step="1"
                        :invalid="!!form.errors.payment_terms_days"
                    />
                </FormField>
            </div>
        </section>

        <section class="card p-5 sm:p-6">
            <h3 class="text-sm font-semibold text-ink">Status</h3>

            <div class="mt-4">
                <ToggleSwitch
                    v-model="form.is_active"
                    label="Customer is active"
                    :disabled="isWalkIn"
                    :description="
                        isWalkIn
                            ? 'The walk-in customer is used by counter sales and must stay active.'
                            : 'An inactive customer cannot be picked on a new sale. Its past sales stay.'
                    "
                />
            </div>

            <p v-if="form.errors.is_active" class="mt-2 text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </section>
    </div>
</template>
