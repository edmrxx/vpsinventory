<script setup>
import { computed } from 'vue'

/**
 * The shape of a figure over the period, drawn small.
 *
 * Plain SVG, not a chart library: a card holds eight of these and loading
 * ApexCharts eight times over would cost more than the whole dashboard.
 * There are no axes and no tooltip on purpose — the number above it is the
 * fact, and this only says which way it has been moving.
 */
const props = defineProps({
    /** @type {number[]} */
    values: { type: Array, required: true },
    colour: { type: String, default: 'currentColor' },
    height: { type: Number, default: 28 },
})

const width = 100

/** The path, scaled into the box. A flat run is drawn down the middle. */
const line = computed(() => {
    const values = props.values

    if (values.length < 2) return ''

    const lowest = Math.min(...values)
    const highest = Math.max(...values)
    const span = highest - lowest

    const step = width / (values.length - 1)
    const top = 2
    const usable = props.height - 4

    return values
        .map((value, index) => {
            const y = span === 0 ? top + usable / 2 : top + usable - ((value - lowest) / span) * usable

            return `${index === 0 ? 'M' : 'L'}${(index * step).toFixed(2)},${y.toFixed(2)}`
        })
        .join(' ')
})
</script>

<template>
    <svg
        v-if="line"
        :viewBox="`0 0 ${width} ${height}`"
        :height="height"
        class="w-full"
        preserveAspectRatio="none"
        aria-hidden="true"
    >
        <path
            :d="line"
            fill="none"
            :stroke="colour"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
