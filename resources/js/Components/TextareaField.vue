<script setup>
/**
 * A multi-line box, styled to match TextField.
 */
defineProps({
    modelValue: { type: String, default: '' },
    rows: { type: Number, default: 3 },
    invalid: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <textarea
        :value="modelValue"
        :rows="rows"
        :placeholder="placeholder"
        :aria-invalid="invalid ? 'true' : undefined"
        class="w-full resize-y rounded-field border bg-surface px-3.5 py-2.5 text-sm text-ink shadow-xs-soft transition-colors placeholder:text-ink-subtle focus:border-brand-400 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-muted"
        :class="invalid ? 'border-danger-500/60' : 'border-line-strong'"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
