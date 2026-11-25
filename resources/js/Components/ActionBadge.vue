<script setup>
import { computed } from 'vue'

/**
 * The coloured word in the Action column of the audit trail.
 *
 * The tone comes from the server, out of App\Enums\AuditAction, so a new
 * action needs no change here.
 */
const props = defineProps({
    label: { type: String, required: true },
    tone: { type: String, default: 'neutral' },
})

const tones = {
    success: 'bg-success-50 text-success-700 ring-success-500/25',
    danger: 'bg-danger-50 text-danger-700 ring-danger-500/25',
    warning: 'bg-warning-50 text-warning-700 ring-warning-500/25',
    info: 'bg-info-50 text-info-700 ring-info-500/25',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
    neutral: 'bg-surface-sunken text-ink-muted ring-line-strong',
}

const classes = computed(() => tones[props.tone] ?? tones.neutral)
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-1 text-2xs font-semibold tracking-wide uppercase ring-1 ring-inset"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
