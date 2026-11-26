<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The company mark.
 *
 * It shows the logo named in Settings -> Company Information. With no logo
 * set, it falls back to a badge of initials, so the app never shows a broken
 * picture. Used on the sign-in screen and in the sidebar, so the mark is the
 * same everywhere.
 */
const props = defineProps({
    /** The box the mark sits in, e.g. "size-9". */
    sizeClass: { type: String, default: 'size-9' },
    /** How the initials badge looks when there is no logo. */
    fallbackClass: {
        type: String,
        default: 'rounded-full ring-1 ring-gold-400/45 text-gold-300',
    },
})

const page = usePage()
const company = computed(() => page.props.company ?? {})

const logo = computed(() => {
    const path = (company.value.logo ?? '').trim()

    return path === '' ? null : path
})

/** Up to three initials, for the fallback badge. */
const initials = computed(() =>
    (company.value.name ?? 'MAF')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 3)
        .map((word) => word[0])
        .join('')
        .toUpperCase()
)
</script>

<template>
    <img
        v-if="logo"
        :src="logo"
        :alt="company.name"
        data-logo-chip
        class="shrink-0 object-contain"
        :class="sizeClass"
    />

    <span
        v-else
        class="grid shrink-0 place-items-center text-2xs font-bold"
        :class="[sizeClass, fallbackClass]"
        :aria-label="company.name"
    >
        {{ initials }}
    </span>
</template>
