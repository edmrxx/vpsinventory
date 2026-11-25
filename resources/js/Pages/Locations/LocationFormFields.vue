<script setup>
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

defineProps({
    form: { type: Object, required: true },
    /** [{ value: 'store', label: 'Store' }, { value: 'warehouse', … }] */
    types: { type: Array, default: () => [] },
})
</script>

<template>
    <section class="card p-5 sm:p-6">
        <h3 class="text-sm font-semibold text-ink">Location</h3>
        <p class="mt-0.5 text-xs text-ink-subtle">
            A place that holds stock. Goods will be received, sold and moved between
            locations in the next phases.
        </p>

        <div class="mt-5 space-y-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <FormField label="Name" for="name" required :error="form.errors.name">
                    <TextField
                        id="name"
                        v-model="form.name"
                        :invalid="!!form.errors.name"
                        placeholder="Main Store"
                    />
                </FormField>

                <FormField label="Type" for="type" required :error="form.errors.type">
                    <SelectField
                        id="type"
                        v-model="form.type"
                        :options="types"
                        :invalid="!!form.errors.type"
                        placeholder="Pick Store or Warehouse"
                    />
                </FormField>
            </div>

            <FormField
                label="Address"
                for="address"
                :error="form.errors.address"
                hint="Optional. Where it is, if it is not at the shop."
            >
                <TextField
                    id="address"
                    v-model="form.address"
                    :invalid="!!form.errors.address"
                    placeholder="Lot 5, National Highway"
                />
            </FormField>

            <FormField
                label="Description"
                for="description"
                :error="form.errors.description"
                hint="Optional. A line to say what is kept here."
            >
                <TextareaField
                    id="description"
                    v-model="form.description"
                    :rows="2"
                    :invalid="!!form.errors.description"
                />
            </FormField>

            <ToggleSwitch
                v-model="form.is_active"
                label="Location is active"
                description="An inactive location cannot be picked on a new document. Its history keeps it."
            />

            <p v-if="form.errors.is_active" class="text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </div>
    </section>
</template>
