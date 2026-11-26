<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Monitor, Moon, Sun } from '@lucide/vue'
import { applyAppearance } from '../lib/appearance'

/**
 * Light, Dark or Same as my device — in the top bar, where a search box that
 * searched nothing used to be (phase 35.1, CR-3; moved out of the menu under
 * the person's name on 26 November 2026). Each person chooses for themselves, and the choice is
 * kept on their account, so it follows them to any computer.
 *
 * The screen changes at once, before the server has answered. The save then
 * reloads only `auth` and `appearance`, so a half-filled form and the
 * dashboard's figures are left exactly as they were.
 *
 * `compact` draws three icons for the till's navy header, which has no top
 * bar — without it a kiosk-only account could not choose.
 */
const props = defineProps({
    compact: { type: Boolean, default: false },
})

const page = usePage()

const OPTIONS = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
    { value: 'system', label: 'Device', title: 'Same as my device', icon: Monitor },
]

/** The person's own choice, else the default they are on. */
const chosen = computed(() => page.props.auth?.user?.appearance ?? page.props.appearance ?? 'light')

const saving = ref(false)

function choose(value) {
    if (value === chosen.value || saving.value) return

    const before = page.props.appearance

    applyAppearance(value)
    saving.value = true

    router.put(
        '/appearance',
        { appearance: value },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['auth', 'appearance'],
            // A refused value puts the screen back. (A lost session or the
            // throttle never reaches here: app.js handles those for every
            // request, and the next visit puts the saved choice back.)
            onError: () => applyAppearance(before),
            onFinish: () => {
                saving.value = false
            },
        }
    )
}
</script>

<template>
    <!-- The till's header: navy on both screens, so it keeps its own white. -->
    <div
        v-if="props.compact"
        class="flex items-center gap-0.5 rounded-field bg-white/10 p-0.5"
        role="radiogroup"
        aria-label="Appearance"
    >
        <button
            v-for="option in OPTIONS"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="chosen === option.value"
            :aria-label="option.title ?? option.label"
            :title="option.title ?? option.label"
            class="grid size-8 place-items-center rounded-[calc(var(--radius-field)-0.125rem)] transition-colors"
            :class="chosen === option.value ? 'bg-white/20 text-ink-inverse' : 'text-white/60 hover:text-ink-inverse'"
            :data-appearance-option="option.value"
            @click="choose(option.value)"
        >
            <component :is="option.icon" :size="15" aria-hidden="true" />
        </button>
    </div>

    <!-- The top bar: the icon above the word; the icon alone on a phone. At
         44 px it fits the compact top bar (52 px) as well as the usual one. -->
    <div
        v-else
        data-appearance-switch
        class="grid grid-cols-3 gap-0.5 rounded-field bg-surface-sunken p-1"
        role="radiogroup"
        aria-label="Appearance"
    >
        <button
            v-for="option in OPTIONS"
            :key="option.value"
            type="button"
            role="radio"
            :aria-checked="chosen === option.value"
            :aria-label="option.title ?? option.label"
            :title="option.title ?? option.label"
            class="flex min-w-8 flex-col items-center justify-center gap-0.5 rounded-[calc(var(--radius-field)-0.25rem)] px-2 py-1 text-2xs leading-none font-medium transition-colors sm:min-w-14 sm:px-3"
            :class="
                chosen === option.value
                    ? 'bg-surface text-ink shadow-xs-soft'
                    : 'text-ink-muted hover:text-ink'
            "
            :data-appearance-option="option.value"
            @click="choose(option.value)"
        >
            <component :is="option.icon" :size="15" aria-hidden="true" />
            <span class="hidden sm:block" aria-hidden="true">{{ option.label }}</span>
        </button>
    </div>
</template>
