<script setup>
defineProps({
    label: { type: String, required: true },
    /** The id of the input inside, so the label points at it. */
    for: { type: String, default: null },
    error: { type: String, default: null },
    hint: { type: String, default: null },
    required: { type: Boolean, default: false },
})
</script>

<template>
    <div>
        <label :for="$props.for" class="block text-sm font-medium text-ink">
            {{ label }}
            <span v-if="required" class="text-danger-700" aria-hidden="true">*</span>
        </label>

        <div class="mt-1.5">
            <slot />
        </div>

        <p v-if="error" class="mt-1.5 text-xs font-medium text-danger-700">
            {{ error }}
        </p>
        <p v-else-if="hint" class="mt-1.5 text-xs text-ink-subtle">
            {{ hint }}
        </p>
    </div>
</template>
