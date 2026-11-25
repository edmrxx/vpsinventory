<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowRight, CheckCircle2, ChevronDown, Info, TriangleAlert, XCircle } from '@lucide/vue'
import { count, useMoney } from '../lib/money'

/**
 * What somebody has to do something about.
 *
 * Ordered worst first, and every item carries somewhere to go. A list of
 * problems with nowhere to click is a list of worries.
 *
 * Nothing in it is written in advance: each line is a count or a figure
 * from the ledger. When there is nothing wrong it says so, rather than
 * showing an empty box that reads like a fault.
 */
defineProps({
    items: { type: Array, default: () => [] },
})

const { money } = useMoney()

const open = ref(null)

function toggle(key) {
    open.value = open.value === key ? null : key
}

const severities = {
    danger: {
        icon: XCircle,
        dot: 'bg-danger-50 text-danger-700',
        edge: 'border-l-danger-500',
    },
    warning: {
        icon: TriangleAlert,
        dot: 'bg-warning-50 text-warning-700',
        edge: 'border-l-warning-500',
    },
    info: {
        icon: Info,
        dot: 'bg-info-50 text-info-700',
        edge: 'border-l-info-500',
    },
}

function style(severity) {
    return severities[severity] ?? severities.info
}

/** A row carries different columns depending on what it is about. */
function columns(key, row) {
    if (key === 'low_stock') {
        return [
            `${count(row.on_hand)} left, reorder at ${count(row.reorder_level)}`,
            `Order about ${count(row.suggested)}`,
            row.last_bought_on ? `Last bought ${row.last_bought_on} at ${money(row.last_cost)}` : 'Never bought in',
        ]
    }

    if (key === 'dead_stock') {
        return [
            `${count(row.on_hand)} on the shelf`,
            // Value at cost: only sent to somebody who may read cost.
            row.tied_up !== undefined ? `${money(row.tied_up)} tied up` : '',
            row.last_sold_on ? `Last sold ${row.last_sold_on}` : 'Never sold',
        ]
    }

    if (key === 'high_returns') {
        return [`${count(row.returned)} of ${count(row.sold)} came back`, `${row.rate}%`, '']
    }

    return [row.reason ?? '', money(row.value), row.on ?? '']
}
</script>

<template>
    <section class="card overflow-hidden">
        <div class="border-b border-line px-5 py-3.5">
            <h3 class="text-sm font-semibold text-ink">Needs your attention</h3>
            <p class="mt-0.5 text-xs text-ink-subtle">Worst first</p>
        </div>

        <!-- Nothing wrong is an answer, and deserves words of its own -->
        <div v-if="items.length === 0" class="flex items-center gap-3 px-5 py-8">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-success-50 text-success-700">
                <CheckCircle2 :size="20" />
            </span>
            <div>
                <p class="text-sm font-medium text-ink">Nothing needs doing.</p>
                <p class="mt-0.5 text-xs text-ink-subtle">
                    No stock is out or running low, and nothing looks wrong.
                </p>
            </div>
        </div>

        <div v-else class="divide-y divide-line">
            <div
                v-for="item in items"
                :key="item.key"
                class="border-l-2"
                :class="style(item.severity).edge"
            >
                <div class="flex items-start gap-3 px-5 py-3.5">
                    <span
                        class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full"
                        :class="style(item.severity).dot"
                    >
                        <component :is="style(item.severity).icon" :size="15" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">{{ item.title }}</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-ink-subtle">{{ item.detail }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <Link
                                v-if="item.action"
                                :href="item.action.href"
                                class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
                            >
                                {{ item.action.label }}
                                <ArrowRight :size="13" />
                            </Link>

                            <button
                                v-if="item.rows && item.rows.length"
                                type="button"
                                class="inline-flex items-center gap-1 text-xs font-medium text-ink-muted hover:text-ink"
                                @click="toggle(item.key)"
                            >
                                {{ open === item.key ? 'Hide' : 'Which ones' }}
                                <ChevronDown
                                    :size="13"
                                    class="transition-transform"
                                    :class="open === item.key ? 'rotate-180' : ''"
                                />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- The rows behind the count -->
                <div v-if="open === item.key && item.rows" class="bg-surface-sunken px-5 pb-3">
                    <div
                        v-for="row in item.rows"
                        :key="row.id ?? row.sku ?? row.reference"
                        class="grid gap-x-3 border-t border-line py-2 sm:grid-cols-[1fr_auto]"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-ink">
                                {{ row.name ?? row.reference }}
                            </p>
                            <p v-if="row.sku" class="font-mono text-2xs text-ink-subtle">{{ row.sku }}</p>
                        </div>
                        <div class="text-2xs text-ink-muted sm:text-right">
                            <p v-for="(line, index) in columns(item.key, row)" :key="index">
                                {{ line }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
