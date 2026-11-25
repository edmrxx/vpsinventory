<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowRight, ChevronRight } from '@lucide/vue'
import { icon } from '../lib/icons'

/**
 * One short "look at these first" list under the dashboard cards.
 *
 * The server sends the rows already worded and already linked, so every
 * row opens the record behind it and the list cannot disagree with the
 * screen it leads to. A list the system does not record yet says so in
 * words, under a "Not tracked yet" tag.
 */
const props = defineProps({
    list: { type: Object, required: true },
})

const tones = {
    warning: 'bg-warning-50 text-warning-700',
    danger: 'bg-danger-50 text-danger-700',
    neutral: 'bg-surface-sunken text-ink-muted',
}

const toneClass = computed(() => tones[props.list.tone] ?? tones.neutral)
</script>

<template>
    <section class="card flex flex-col overflow-hidden">
        <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="grid size-7 shrink-0 place-items-center rounded-field" :class="toneClass">
                    <component :is="icon(list.icon)" :size="14" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h3 class="truncate text-sm font-semibold text-ink">{{ list.title }}</h3>
                    <p class="truncate text-2xs text-ink-subtle">{{ list.subtitle }}</p>
                </div>
            </div>

            <Link
                v-if="list.href"
                :href="list.href"
                class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
            >
                View all
                <ArrowRight :size="13" />
            </Link>
            <span
                v-else-if="list.state === 'not_tracked'"
                class="shrink-0 rounded-full bg-surface-sunken px-2 py-0.5 text-2xs font-semibold text-ink-muted"
            >
                Not tracked yet
            </span>
        </div>

        <p v-if="!list.rows.length" class="px-5 py-5 text-sm leading-relaxed text-ink-subtle">
            {{ list.empty }}
        </p>

        <ul v-else class="divide-y divide-line">
            <li v-for="row in list.rows" :key="row.id">
                <!-- A row this account may not open is shown, not linked. -->
                <component
                    :is="row.href ? Link : 'div'"
                    :href="row.href || undefined"
                    class="group flex items-center gap-3 px-5 py-2.5 transition-colors"
                    :class="row.href ? 'hover:bg-surface-sunken' : ''"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm text-ink">{{ row.title }}</span>
                        <span class="block truncate text-2xs text-ink-subtle">
                            {{ row.meta }}<template v-if="row.when"> · {{ row.when }}</template>
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-sm font-semibold tabular-nums text-ink">{{ row.figure }}</span>
                        <span v-if="row.figure_note" class="block text-2xs text-ink-subtle">
                            {{ row.figure_note }}
                        </span>
                    </span>
                    <ChevronRight
                        v-if="row.href"
                        :size="14"
                        class="shrink-0 text-ink-subtle transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </component>
            </li>
        </ul>
    </section>
</template>
