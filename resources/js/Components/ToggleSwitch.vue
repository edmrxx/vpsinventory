<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    description: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <div class="flex items-start justify-between gap-4">
        <div v-if="label" class="min-w-0">
            <p class="text-sm font-medium text-ink">{{ label }}</p>
            <p v-if="description" class="mt-0.5 text-xs text-ink-subtle">
                {{ description }}
            </p>
        </div>

        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="label || 'Toggle'"
            :disabled="disabled"
            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-60"
            :class="modelValue ? 'bg-brand-600' : 'bg-line-strong'"
            @click="$emit('update:modelValue', !modelValue)"
        >
            <span
                class="inline-block size-4 rounded-full bg-surface shadow-sm transition-transform duration-200"
                :class="modelValue ? 'translate-x-6' : 'translate-x-1'"
            />
        </button>
    </div>
</template>
