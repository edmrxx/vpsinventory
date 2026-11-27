<script setup>
import { Link } from '@inertiajs/vue3'

/**
 * Owed | Paid — the two sides of Payables (phase 24.5, CR-5): what the shop
 * still owes its suppliers, and what it paid them in a period.
 */
defineProps({
    current: { type: String, required: true },
})

const TABS = [
    { key: 'owed', label: 'Owed', href: '/payables' },
    { key: 'paid', label: 'Paid', href: '/payables/paid' },
]
</script>

<template>
    <nav class="mb-4 inline-flex gap-1 rounded-field bg-surface-sunken p-1" aria-label="Payables">
        <Link
            v-for="tab in TABS"
            :key="tab.key"
            :href="tab.href"
            class="rounded-[calc(var(--radius-field)-0.25rem)] px-4 py-1.5 text-sm font-medium transition-colors"
            :class="current === tab.key ? 'bg-surface text-ink shadow-xs-soft' : 'text-ink-muted hover:text-ink'"
            :aria-current="current === tab.key ? 'page' : undefined"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
