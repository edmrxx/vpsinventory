<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import { listHref, markReturn, recall } from '../lib/listMemory'

const props = defineProps({
    heading: { type: String, required: true },
    subheading: { type: String, default: '' },
    /** Where "back" goes. Shows the breadcrumb when set. */
    backHref: { type: String, default: null },
    /** "Back to customers": the crumb reads "Customers". */
    backLabel: { type: String, default: 'Back' },
    /**
     * The trail above the heading, when one crumb is not enough:
     * `[{ label: 'Customers', href: '/customers' }, { label: 'RJ Builders', href: '/customers/3' }]`.
     * The heading is always the last part, and the last crumb is where the
     * phone's back arrow goes.
     */
    crumbs: { type: Array, default: null },
})

/** "Back to purchase requests" -> "Purchase requests". */
function crumbLabel(label) {
    const words = String(label ?? '').replace(/^back to\s+(the\s+)?/i, '').trim()

    return words ? words.charAt(0).toUpperCase() + words.slice(1) : 'Back'
}

/**
 * Every crumb that leads to a list goes back to it as it was left — the
 * sort, filters, search and page — and the list scrolls back and marks the
 * row (see `lib/listMemory.js`). Read when the page is drawn.
 */
const trail = computed(() => {
    const crumbs = props.crumbs ?? (props.backHref ? [{ label: crumbLabel(props.backLabel), href: props.backHref }] : [])

    return crumbs.map((crumb) => ({ ...crumb, to: listHref(crumb.href) }))
})

const back = computed(() => trail.value[trail.value.length - 1] ?? null)

function followed(crumb) {
    if (recall(crumb.href)) markReturn(crumb.href)
}
</script>

<template>
    <div class="mb-6">
        <!-- Where this page sits. On a phone, one arrow back to the last crumb. -->
        <nav v-if="trail.length" aria-label="Breadcrumb" class="mb-3 text-xs font-medium text-ink-muted" data-breadcrumb>
            <Link
                v-if="back"
                :href="back.to"
                class="inline-flex items-center gap-1 transition-colors hover:text-ink sm:hidden"
                @click="followed(back)"
            >
                <ChevronLeft :size="14" aria-hidden="true" />
                {{ back.label }}
            </Link>

            <ol class="hidden min-w-0 flex-wrap items-center gap-1 sm:flex">
                <li v-for="crumb in trail" :key="crumb.href" class="inline-flex items-center gap-1">
                    <Link
                        :href="crumb.to"
                        class="rounded transition-colors hover:text-ink hover:underline underline-offset-2"
                        @click="followed(crumb)"
                    >
                        {{ crumb.label }}
                    </Link>
                    <ChevronRight :size="13" class="text-ink-subtle" aria-hidden="true" />
                </li>
                <li class="min-w-0 truncate text-ink" aria-current="page">{{ heading }}</li>
            </ol>
        </nav>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="truncate text-xl font-semibold tracking-tight text-ink">
                    {{ heading }}
                </h2>
                <p v-if="subheading" class="mt-1 text-sm text-ink-muted">
                    {{ subheading }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
