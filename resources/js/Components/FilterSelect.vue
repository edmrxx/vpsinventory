<script setup>
import ComboBox from './ComboBox.vue'

/**
 * One labelled pick list in the filter bar above a list screen.
 *
 * Small and quiet, so the filters sit back from the table itself. A
 * ComboBox underneath, like every other list in the app.
 */
defineOptions({ inheritAttrs: false })

defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    /** { value, label } rows. */
    options: { type: Array, default: () => [] },
    /** The choice that means "no filter". */
    anyLabel: { type: String, default: 'All' },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <div class="block">
        <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">
            {{ label }}
        </span>
        <ComboBox
            v-bind="$attrs"
            :model-value="modelValue"
            :options="options"
            :placeholder="anyLabel"
            size="sm"
            @update:model-value="$emit('update:modelValue', $event)"
        />
    </div>
</template>
