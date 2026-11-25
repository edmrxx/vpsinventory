<script setup>
import { Lightbulb, TrendingDown, TrendingUp, Minus } from '@lucide/vue'

/**
 * What the figures are saying, in sentences.
 *
 * Every line is built on the server from a number that was already worked
 * out, and says that number aloud. Nothing is written in advance and
 * nothing is rounded into a claim it cannot support — a dashboard that
 * tells an owner something untrue once is a dashboard they stop reading.
 */
defineProps({
    lines: { type: Array, default: () => [] },
})

const tones = {
    good: { icon: TrendingUp, class: 'bg-success-50 text-success-700' },
    warning: { icon: TrendingDown, class: 'bg-warning-50 text-warning-700' },
    neutral: { icon: Minus, class: 'bg-surface-sunken text-ink-muted' },
}

function style(tone) {
    return tones[tone] ?? tones.neutral
}
</script>

<template>
    <section class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-line px-5 py-3.5">
            <Lightbulb :size="15" class="text-brand-700" />
            <h3 class="text-sm font-semibold text-ink">What's happening</h3>
        </div>

        <p v-if="lines.length === 0" class="px-5 py-8 text-sm text-ink-subtle">
            There is not enough trading in this period to say anything useful yet.
        </p>

        <ul v-else class="divide-y divide-line">
            <li v-for="(line, index) in lines" :key="index" class="flex items-start gap-3 px-5 py-3">
                <span
                    class="mt-px grid size-6 shrink-0 place-items-center rounded-full"
                    :class="style(line.tone).class"
                >
                    <component :is="style(line.tone).icon" :size="13" />
                </span>
                <p class="text-sm leading-relaxed text-ink">{{ line.text }}</p>
            </li>
        </ul>
    </section>
</template>
