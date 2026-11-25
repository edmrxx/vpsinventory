<script setup>
import FormField from '../../Components/FormField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import ToggleSwitch from '../../Components/ToggleSwitch.vue'

defineProps({
    form: { type: Object, required: true },
})
</script>

<template>
    <section class="card p-5 sm:p-6">
        <h3 class="text-sm font-semibold text-ink">Category</h3>
        <p class="mt-0.5 text-xs text-ink-subtle">
            A product is filed under one category.
        </p>

        <div class="mt-5 space-y-5">
            <FormField label="Name" for="name" required :error="form.errors.name">
                <TextField
                    id="name"
                    v-model="form.name"
                    :invalid="!!form.errors.name"
                    placeholder="Apparel"
                />
            </FormField>

            <FormField
                label="Description"
                for="description"
                :error="form.errors.description"
                hint="Optional. A line to say what belongs here."
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
                label="Category is active"
                description="An inactive category cannot be picked on a new product. Its products stay where they are."
            />

            <p v-if="form.errors.is_active" class="text-xs font-medium text-danger-700">
                {{ form.errors.is_active }}
            </p>
        </div>
    </section>
</template>
