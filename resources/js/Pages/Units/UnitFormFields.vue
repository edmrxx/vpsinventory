<script setup>
import FormField from '../../Components/FormField.vue'
import TextField from '../../Components/TextField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

defineProps({
    form: { type: Object, required: true },
})
</script>

<template>
    <section class="card p-5 sm:p-6">
        <h3 class="text-sm font-semibold text-ink">Unit</h3>
        <p class="mt-0.5 text-xs text-ink-subtle">
            The short code is what a document prints beside a quantity.
        </p>

        <div class="mt-5 space-y-5">
            <FormField
                label="Code"
                for="code"
                required
                :error="form.errors.code"
                hint="Letters, numbers, dots, slashes and dashes."
            >
                <TextField
                    id="code"
                    v-model="form.code"
                    :invalid="!!form.errors.code"
                    placeholder="pcs"
                />
            </FormField>

            <FormField label="Name" for="name" required :error="form.errors.name">
                <TextField
                    id="name"
                    v-model="form.name"
                    :invalid="!!form.errors.name"
                    placeholder="Pieces"
                />
            </FormField>

            <ToggleSwitch
                v-model="form.allows_decimals"
                label="Allows decimals"
                description="Turn on for a unit sold by measure, such as kg or m, so 2.5 can be typed. Leave off for pcs, bag or box: a product follows its unit."
            />

            <p v-if="form.errors.allows_decimals" class="text-xs font-medium text-danger-700">
                {{ form.errors.allows_decimals }}
            </p>

            <ToggleSwitch
                v-model="form.is_active"
                label="Unit is active"
                description="An inactive unit cannot be picked on a new product. Its products stay as they are."
            />

            <p v-if="form.errors.is_active" class="text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </div>
    </section>
</template>
