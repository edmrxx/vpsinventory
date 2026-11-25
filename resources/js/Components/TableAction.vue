<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Eye, Pencil, Trash2, TriangleAlert } from '@lucide/vue'

/**
 * Icon-only row button.
 *
 * The colour carries the meaning, so the four kinds always look the same:
 * view is blue, edit is green, delete is red, warning is orange.
 */
const props = defineProps({
    /** "view" | "edit" | "delete" | "warning" */
    variant: { type: String, default: 'view' },
    /** Render an Inertia link instead of a button. */
    href: { type: String, default: null },
    /** Overrides the default icon for the variant. */
    icon: { type: [Object, Function], default: null },
    /** Shown as the tooltip and read out by screen readers. */
    label: { type: String, default: null },
    disabled: { type: Boolean, default: false },
})

const presets = {
    view: {
        icon: Eye,
        label: 'View',
        class: 'text-info-700 bg-info-50 hover:bg-info-500/15 ring-info-500/20',
    },
    edit: {
        icon: Pencil,
        label: 'Edit',
        class: 'text-success-700 bg-success-50 hover:bg-success-500/15 ring-success-500/20',
    },
    delete: {
        icon: Trash2,
        label: 'Delete',
        class: 'text-danger-700 bg-danger-50 hover:bg-danger-500/15 ring-danger-500/20',
    },
    warning: {
        icon: TriangleAlert,
        label: 'Warning',
        class: 'text-warning-700 bg-warning-50 hover:bg-warning-500/15 ring-warning-500/20',
    },
}

const preset = computed(() => presets[props.variant] ?? presets.view)
const text = computed(() => props.label ?? preset.value.label)
const glyph = computed(() => props.icon ?? preset.value.icon)
</script>

<template>
    <component
        :is="href && !disabled ? Link : 'button'"
        :href="href && !disabled ? href : undefined"
        :type="href && !disabled ? undefined : 'button'"
        :disabled="href ? undefined : disabled"
        :aria-label="text"
        :title="text"
        class="grid size-8 place-items-center rounded-field ring-1 transition-all duration-150 ring-inset hover:scale-105 active:scale-95"
        :class="
            disabled
                ? 'cursor-not-allowed bg-surface-sunken text-ink-subtle ring-line opacity-60 hover:scale-100'
                : preset.class
        "
    >
        <component :is="glyph" :size="15" :stroke-width="2" aria-hidden="true" />
    </component>
</template>
