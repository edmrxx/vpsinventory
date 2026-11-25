<script setup>
import ComboBox from './ComboBox.vue'

/**
 * A pick list on a form.
 *
 * Kept as its own name so the forms read the way they always did, but it is
 * a ComboBox underneath: every list in the app can be typed into.
 */
defineOptions({ inheritAttrs: false })

defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    options: { type: Array, default: () => [] },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Shown as the first, unselected choice. Leave null to require a pick. */
    placeholder: { type: String, default: null },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <ComboBox
        v-bind="$attrs"
        :model-value="modelValue"
        :options="options"
        :invalid="invalid"
        :disabled="disabled"
        :placeholder="placeholder"
        size="md"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>
