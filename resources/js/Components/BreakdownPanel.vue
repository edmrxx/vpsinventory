<script setup>
import { computed, ref } from 'vue'
import { count, useMoney } from '../lib/money'

/**
 * One breakdown on the Reports screen.
 *
 * Four of these used to be four copies of the same markup. They are one
 * component now, so a change to the shape of a report row happens once.
 *
 * **The column always adds up.** Only the first few rows are shown, but the
 * ones left out are gathered into a single "Other" line worked out from the
 * whole of the period, not from the rows that happen to have been sent. A
 * truncated table that quietly stops agreeing with the net figure above it
 * is worse than a long page: somebody adds up the column and the report
 * looks wrong.
 */
const props = defineProps({
    heading: { type: String, required: true },
    /** { rows: [...], total: { count, amount, quantity|documents } } */
    breakdown: { type: Object, default: null },
    /** 'quantity' on a product list, 'documents' on a party list. */
    measure: { type: String, default: 'quantity' },
    /** Show the SKU under the name. */
    withSku: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Nothing to show for this period.' },
    /** How many rows before the rest are gathered up. */
    limit: { type: Number, default: 10 },
})

const { money } = useMoney()

const expanded = ref(false)

const rows = computed(() => props.breakdown?.rows ?? [])

const visible = computed(() => (expanded.value ? rows.value : rows.value.slice(0, props.limit)))

/**
 * Everything not on screen, as one line.
 *
 * Worked out by taking the visible rows away from the period's own total,
 * so it stays right however many rows are shown and however many the
 * server sent.
 */
const other = computed(() => {
    const total = props.breakdown?.total

    if (!total) return null

    const shown = visible.value.length
    const remaining = total.count - shown

    if (remaining <= 0) return null

    const sum = (key) =>
        visible.value.reduce((carried, row) => carried + Number(row[key] ?? 0), 0)

    return {
        count: remaining,
        amount: Number(total.amount) - sum('amount'),
        measure: Number(total[props.measure] ?? 0) - sum(props.measure),
    }
})

/** How many rows the server actually has for us to open up. */
const hidden = computed(() => Math.max(0, rows.value.length - props.limit))
</script>

<template>
    <section class="card overflow-hidden">
        <div class="flex items-baseline justify-between gap-3 border-b border-line px-5 py-3.5">
            <h3 class="text-sm font-semibold text-ink">{{ heading }}</h3>
            <span v-if="breakdown?.total?.count" class="text-xs text-ink-muted">
                {{ count(breakdown.total.count) }} in total
            </span>
        </div>

        <p v-if="rows.length === 0" class="px-5 py-6 text-sm text-ink-subtle">
            {{ emptyText }}
        </p>

        <div v-else class="divide-y divide-line">
            <div
                v-for="(row, index) in visible"
                :key="row.sku ?? row.name ?? index"
                class="grid grid-cols-[1fr_4rem_7rem] items-center gap-3 px-5 py-2.5"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm text-ink">{{ row.name }}</p>
                    <p v-if="withSku" class="font-mono text-2xs text-ink-subtle">{{ row.sku }}</p>
                </div>
                <span class="tabular-nums text-right text-sm text-ink-muted">
                    {{ count(row[measure]) }}
                </span>
                <span class="tabular-nums text-right text-sm font-semibold text-ink">
                    {{ money(row.amount) }}
                </span>
            </div>

            <!-- What is not on screen, so the column still adds up -->
            <div
                v-if="other"
                class="grid grid-cols-[1fr_4rem_7rem] items-center gap-3 bg-surface-sunken px-5 py-2.5"
            >
                <p class="truncate text-sm text-ink-muted">
                    Other ({{ count(other.count) }}
                    {{ other.count === 1 ? 'more' : 'more' }})
                </p>
                <span class="tabular-nums text-right text-sm text-ink-muted">
                    {{ count(other.measure) }}
                </span>
                <span class="tabular-nums text-right text-sm font-semibold text-ink-muted">
                    {{ money(other.amount) }}
                </span>
            </div>
        </div>

        <div v-if="hidden > 0" class="border-t border-line px-5 py-2.5 print:hidden">
            <button
                type="button"
                class="text-xs font-medium text-brand-700 hover:underline"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show all ${count(rows.length)}` }}
            </button>
        </div>
    </section>
</template>
