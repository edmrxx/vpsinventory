<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { LoaderCircle } from '@lucide/vue'
import { listHref, markReturn, recall } from '../lib/listMemory'

const props = defineProps({
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    /** "primary" | "secondary" | "danger" */
    variant: { type: String, default: 'primary' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
})

const styles = {
    primary:
        'bg-brand-600 text-ink-inverse shadow-brand hover:brightness-90 disabled:hover:brightness-100',
    secondary:
        'border border-line-strong bg-surface text-ink hover:bg-surface-sunken',
    danger: 'bg-danger-500 text-ink-inverse hover:brightness-95',
}

/**
 * A button that leads back to a list — Cancel on a New form — returns to it
 * as it was left (see `lib/listMemory.js`). Any other link is unchanged.
 */
const to = computed(() => listHref(props.href))

function followed() {
    if (props.href && recall(props.href)) markReturn(props.href)
}
</script>

<template>
    <component
        :is="href && !disabled && !loading ? Link : 'button'"
        :href="href && !disabled && !loading ? to : undefined"
        :type="href ? undefined : type"
        :disabled="href ? undefined : disabled || loading"
        class="inline-flex h-10 items-center justify-center gap-2 rounded-field px-4 text-sm font-semibold transition-all duration-150 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-70"
        :class="styles[variant]"
        @click="href ? followed() : undefined"
    >
        <LoaderCircle v-if="loading" :size="15" class="animate-spin" aria-hidden="true" />
        <slot />
    </component>
</template>
