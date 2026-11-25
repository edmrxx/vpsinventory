<script setup>
import ComboBox from './ComboBox.vue'
import FormField from './FormField.vue'
import ImageSettingField from './ImageSettingField.vue'
import TextField from './TextField.vue'
import ToggleSwitch from './ToggleSwitch.vue'

/**
 * One settings input, chosen by the type the server declares.
 *
 * A new field type needs a branch here and nothing else: the schema in
 * config/settings.php carries the label, the hint and the options.
 */
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean, null], default: '' },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const update = (value) => emit('update:modelValue', value)
</script>

<template>
    <!-- A toggle carries its own label, so it skips the FormField wrapper. -->
    <div v-if="field.type === 'toggle'" class="rounded-field bg-surface-muted p-4">
        <ToggleSwitch
            :model-value="!!modelValue"
            :label="field.label"
            :description="field.hint"
            :disabled="disabled"
            @update:model-value="update"
        />
        <p v-if="error" class="mt-2 text-xs font-medium text-danger-700">{{ error }}</p>
    </div>

    <FormField
        v-else
        :label="field.label"
        :for="field.key"
        :error="error"
        :hint="field.hint"
        :required="field.required"
    >
        <!-- Picture: saved on upload, not by the Save button. -->
        <ImageSettingField
            v-if="field.type === 'image'"
            :id="field.key"
            :field="field"
            :disabled="disabled"
        />

        <!-- Colour -->
        <div v-else-if="field.type === 'color'" class="flex items-center gap-3">
            <input
                :id="field.key"
                type="color"
                :value="modelValue"
                :disabled="disabled"
                class="h-11 w-16 shrink-0 cursor-pointer rounded-field border border-line-strong bg-surface p-1 disabled:cursor-not-allowed"
                @input="update($event.target.value)"
            />
            <TextField
                :model-value="modelValue"
                :invalid="!!error"
                :disabled="disabled"
                placeholder="#5b3df5"
                class="font-mono"
                @update:model-value="update"
            />
        </div>

        <!-- Choice -->
        <ComboBox
            v-else-if="field.type === 'select'"
            :id="field.key"
            :model-value="modelValue"
            :options="field.options ?? []"
            :disabled="disabled"
            @update:model-value="update"
        />

        <!-- Long text -->
        <textarea
            v-else-if="field.type === 'textarea'"
            :id="field.key"
            :value="modelValue"
            :disabled="disabled"
            rows="3"
            class="w-full rounded-field border bg-surface px-3.5 py-2.5 text-sm text-ink shadow-xs-soft transition-colors placeholder:text-ink-subtle focus:border-brand-400 disabled:cursor-not-allowed disabled:bg-surface-sunken"
            :class="error ? 'border-danger-500/60' : 'border-line-strong'"
            @input="update($event.target.value)"
        />

        <!-- Everything else is a one-line box. -->
        <TextField
            v-else
            :id="field.key"
            :model-value="modelValue ?? ''"
            :type="field.type === 'number' ? 'number' : field.type"
            :invalid="!!error"
            :disabled="disabled"
            @update:model-value="update"
        />
    </FormField>
</template>
