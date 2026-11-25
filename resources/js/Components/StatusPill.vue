<script setup>
defineProps({
    active: { type: Boolean, required: true },
    activeLabel: { type: String, default: 'Active' },
    inactiveLabel: { type: String, default: 'Inactive' },
})
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-2xs font-semibold tracking-wide uppercase ring-1 ring-inset"
        :class="
            active
                ? 'bg-success-50 text-success-700 ring-success-500/25'
                : 'bg-surface-sunken text-ink-muted ring-line-strong'
        "
    >
        <span
            class="size-1.5 rounded-full"
            :class="active ? 'bg-success-500' : 'bg-ink-subtle'"
            aria-hidden="true"
        />
        {{ active ? activeLabel : inactiveLabel }}
    </span>
</template>
