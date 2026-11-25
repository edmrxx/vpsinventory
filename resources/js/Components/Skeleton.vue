<script setup>
/**
 * One grey placeholder block with a moving highlight.
 * Use it for any loading state so every skeleton looks the same.
 */
defineProps({
    /** Tailwind width class, e.g. "w-24" or "w-full". */
    width: { type: String, default: 'w-full' },
    /** Tailwind height class. */
    height: { type: String, default: 'h-4' },
    /** "line" | "block" | "circle" */
    shape: { type: String, default: 'line' },
})

const radius = {
    line: 'rounded-md',
    block: 'rounded-card',
    circle: 'rounded-full',
}
</script>

<template>
    <div
        class="shimmer"
        :class="[width, height, radius[shape]]"
        role="presentation"
        aria-hidden="true"
    />
</template>
