<script setup>
import { computed, onMounted, shallowRef } from 'vue'
import Skeleton from './Skeleton.vue'
import { chartTheme, darkChart, useApexCharts } from '../lib/charts'
import { isDark } from '../lib/appearance'

/**
 * A chart in a card, with ApexCharts loaded only when it is needed.
 *
 * A skeleton stands in twice: while the figures are on their way from the
 * server, and while the chart library itself downloads.
 */
const props = defineProps({
    heading: { type: String, required: true },
    subheading: { type: String, default: '' },
    /** The merged ApexCharts options, or null while the data is loading. */
    options: { type: Object, default: null },
    series: { type: Array, default: null },
    height: { type: Number, default: 300 },
})

const ApexChart = shallowRef(null)

onMounted(async () => {
    ApexChart.value = await useApexCharts()
})

/*
 * Redrawn for the dark screen while it is dark. The chart is keyed on the
 * screen too, so a switch draws it afresh rather than leaving light labels
 * that ApexCharts did not think to update.
 */
const drawn = computed(() => (isDark.value ? darkChart(props.options) : props.options))

defineExpose({ chartTheme })
</script>

<template>
    <section class="card p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-ink">{{ heading }}</h3>
                <p v-if="subheading" class="mt-0.5 text-xs text-ink-subtle">
                    {{ subheading }}
                </p>
            </div>
            <slot name="actions" />
        </div>

        <div class="mt-4">
            <component
                :is="ApexChart"
                v-if="ApexChart && options && series"
                :key="isDark ? 'dark' : 'light'"
                :options="drawn"
                :series="series"
                :height="height"
            />

            <div v-else class="space-y-3" aria-busy="true">
                <!-- An inline height, because Tailwind cannot see a class
                     built at runtime. -->
                <div
                    class="shimmer rounded-card"
                    :style="{ height: `${height - 40}px` }"
                    aria-hidden="true"
                />
                <div class="flex gap-2">
                    <Skeleton width="w-16" height="h-3" />
                    <Skeleton width="w-16" height="h-3" />
                    <Skeleton width="w-16" height="h-3" />
                </div>
            </div>
        </div>
    </section>
</template>
