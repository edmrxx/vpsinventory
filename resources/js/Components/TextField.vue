<script setup>
defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    invalid: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <input
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :aria-invalid="invalid ? 'true' : undefined"
        class="h-11 w-full rounded-field border bg-surface px-3.5 text-sm text-ink shadow-xs-soft transition-colors placeholder:text-ink-subtle focus:border-brand-400 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-ink-muted"
        :class="invalid ? 'border-danger-500/60' : 'border-line-strong'"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
