<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowDownRight, ArrowUpRight, Info, Lock, Minus, Sparkles } from '@lucide/vue'
import Sparkline from './Sparkline.vue'
import { icon } from '../lib/icons'
import { useMoney } from '../lib/money'

/**
 * One hero card on the dashboard.
 *
 * Everything on it is decided by the server — the label, the figure, how to
 * format it, the comparison, the plain-English line and the tone. The card
 * only draws.
 *
 * A card with an `href` is a link: low stock opens the Stock screen already
 * filtered to the rows it is counting, so the number and the list behind it
 * can never disagree.
 *
 * `state` says whether there is a figure at all. A "restricted" card was
 * sent with no number, because this user may not read cost; a
 * "not_tracked" card is something the system does not record yet. Both say
 * so in words rather than showing a nought that would read as news.
 *
 * `compact` is the smaller card of the secondary row.
 */
const props = defineProps({
    stat: { type: Object, required: true },
    compact: { type: Boolean, default: false },
})

const blank = computed(() => props.stat.state === 'restricted' || props.stat.state === 'not_tracked')

const tones = {
    brand: 'bg-brand-50 text-brand-700',
    success: 'bg-success-50 text-success-700',
    info: 'bg-info-50 text-info-700',
    warning: 'bg-warning-50 text-warning-700',
    danger: 'bg-danger-50 text-danger-700',
    neutral: 'bg-surface-sunken text-ink-muted',
}

const toneClass = computed(() => tones[props.stat.tone] ?? tones.brand)

const number = new Intl.NumberFormat('en-PH')

const { money } = useMoney()

/** The server sends a plain decimal; the currency sign is a setting. */
function format(value) {
    if (value === null || value === undefined) return '—'
    if (props.stat.format === 'money') return money(value)
    if (props.stat.format === 'percent') return `${value}%`

    return number.format(value)
}

const shown = computed(() => format(props.stat.value))

const change = computed(() => props.stat.change)

/**
 * Is this change good news?
 *
 * Usually up is good. On a returns rate it is not, which is what `invert`
 * says — a card that painted more returns green would be worse than no
 * colour at all.
 */
const mood = computed(() => {
    const direction = change.value?.direction

    if (!direction || direction === 'flat') return 'flat'
    if (direction === 'new') return 'new'

    const rising = direction === 'up'

    return rising === !props.stat.invert ? 'good' : 'bad'
})

const changeIcon = computed(() => {
    const direction = change.value?.direction

    if (direction === 'up') return ArrowUpRight
    if (direction === 'down') return ArrowDownRight
    if (direction === 'new') return Sparkles

    return Minus
})

const changeClass = computed(
    () =>
        ({
            good: 'text-success-700',
            bad: 'text-danger-700',
            flat: 'text-ink-subtle',
            new: 'text-brand-700',
        })[mood.value]
)

const changeText = computed(() => {
    if (!change.value) return null
    if (change.value.direction === 'new') return 'new'
    if (change.value.percent === null) return 'no change'

    return `${Math.abs(change.value.percent)}%`
})

/** The line colour follows the mood, so the card reads at a glance. */
const sparkColour = computed(
    () =>
        ({
            good: 'oklch(0.68 0.14 162)',
            bad: 'oklch(0.62 0.21 25)',
            flat: 'oklch(0.66 0.013 286)',
            new: 'oklch(0.55 0.2 286)',
        })[mood.value]
)
</script>

<template>
    <component
        :is="stat.href ? Link : 'div'"
        :href="stat.href"
        class="card block transition-shadow duration-200 hover:shadow-raised"
        :class="[compact ? 'p-4' : 'p-5', stat.href ? 'cursor-pointer' : '']"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-1.5">
                <p class="truncate text-xs font-medium tracking-wide text-ink-muted">
                    {{ stat.label }}
                </p>

                <!-- Exactly how this figure is worked out. On hover and on
                     focus, so it is reachable from the keyboard too. -->
                <span v-if="stat.formula" class="group/info relative shrink-0">
                    <button
                        type="button"
                        class="grid size-4 place-items-center rounded-full text-ink-subtle transition hover:text-ink focus:text-ink focus:outline-none"
                        :aria-label="`How ${stat.label} is worked out`"
                        @click.prevent.stop
                    >
                        <Info :size="12" />
                    </button>

                    <span
                        class="pointer-events-none absolute top-6 left-1/2 z-50 w-60 -translate-x-1/2 rounded-field bg-scrim px-3 py-2 text-2xs leading-relaxed font-normal tracking-normal text-ink-inverse opacity-0 shadow-raised transition-opacity duration-150 group-hover/info:opacity-100 group-focus-within/info:opacity-100"
                        role="tooltip"
                    >
                        {{ stat.formula }}
                    </span>
                </span>
            </div>

            <span
                class="grid shrink-0 place-items-center rounded-field"
                :class="[toneClass, compact ? 'size-7' : 'size-9']"
            >
                <component :is="icon(stat.icon)" :size="compact ? 14 : 17" aria-hidden="true" />
            </span>
        </div>

        <!-- No figure: say why, in words. -->
        <p
            v-if="stat.state === 'restricted'"
            class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-semibold text-ink-muted"
            :class="compact ? 'mt-2' : 'mt-3'"
        >
            <Lock :size="12" aria-hidden="true" />
            Restricted
        </p>
        <p
            v-else-if="stat.state === 'not_tracked'"
            class="inline-flex items-center rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-semibold text-ink-muted"
            :class="compact ? 'mt-2' : 'mt-3'"
        >
            Not tracked yet
        </p>

        <p
            v-else
            class="font-semibold tracking-tight tabular-nums text-ink"
            :class="compact ? 'mt-2 text-lg' : 'mt-3 text-2xl'"
        >
            {{ shown }}
        </p>

        <!-- How it compares with the period before -->
        <div v-if="change && !blank" class="mt-2 flex items-center gap-1.5 text-xs">
            <span class="inline-flex items-center gap-0.5 font-semibold" :class="changeClass">
                <component :is="changeIcon" :size="13" />
                {{ changeText }}
            </span>
            <span v-if="stat.previous !== null" class="truncate text-ink-subtle">
                from {{ format(stat.previous) }}
            </span>
        </div>

        <Sparkline
            v-if="!compact && !blank && stat.sparkline && stat.sparkline.length > 1"
            :values="stat.sparkline"
            :colour="sparkColour"
            class="mt-3"
        />

        <p
            class="border-t border-line text-2xs leading-relaxed text-ink-subtle"
            :class="compact ? 'mt-2 pt-2' : 'mt-3 pt-2.5'"
        >
            {{ stat.hint }}
            <span v-if="stat.hint_note" class="font-medium">{{ stat.hint_note }}</span>
        </p>
    </component>
</template>
