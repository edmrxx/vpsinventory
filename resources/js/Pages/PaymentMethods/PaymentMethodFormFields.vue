<script setup>
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

defineProps({
    form: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    /** Editing the cash method: it stays cash, and stays on. */
    isCash: { type: Boolean, default: false },
})
</script>

<template>
    <section class="card p-5 sm:p-6">
        <h3 class="text-sm font-semibold text-ink">Payment method</h3>
        <p class="mt-0.5 text-xs text-ink-subtle">
            A way a customer may pay at the till and on the sale form.
        </p>

        <div class="mt-5 space-y-5">
            <FormField label="Name" for="name" required :error="form.errors.name" hint="What the cashier taps, such as GCash.">
                <TextField
                    id="name"
                    v-model="form.name"
                    :invalid="!!form.errors.name"
                    placeholder="GCash"
                />
            </FormField>

            <FormField
                label="Kind"
                for="kind"
                required
                :error="form.errors.kind"
                :hint="isCash ? 'This is the cash method. Change is given from it, so it stays cash.' : 'Only cash gives change. There is one cash method.'"
            >
                <SelectField
                    id="kind"
                    v-model="form.kind"
                    :options="kinds"
                    :invalid="!!form.errors.kind"
                    :disabled="isCash"
                    placeholder="Pick a kind"
                />
            </FormField>

            <ToggleSwitch
                v-model="form.requires_reference"
                label="Needs a reference number"
                description="Turn on for GCash, Maya or a bank transfer, so the transaction number is typed at the till and can be checked against the app or the bank later."
            />

            <p v-if="form.errors.requires_reference" class="text-xs font-medium text-danger-700">
                {{ form.errors.requires_reference }}
            </p>

            <ToggleSwitch
                v-if="!isCash"
                v-model="form.is_active"
                label="Payment method is active"
                description="An inactive method cannot be picked on a new sale. Sales already paid with it stay as they are."
            />

            <p v-if="form.errors.is_active" class="text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </div>
    </section>
</template>
