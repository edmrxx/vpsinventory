<script setup>
import { computed, ref } from 'vue'
import { Deferred, Link, router } from '@inertiajs/vue3'
import { ArrowRight, RefreshCw } from '@lucide/vue'
import AppLayout from '../Layouts/AppLayout.vue'
import ActionBadge from '../Components/ActionBadge.vue'
import ChartPanel from '../Components/ChartPanel.vue'
import PageHeader from '../Components/PageHeader.vue'
import Skeleton from '../Components/Skeleton.vue'
import StatCard from '../Components/StatCard.vue'
import StatCardSkeleton from '../Components/StatCardSkeleton.vue'
import DashboardFilters from '../Components/DashboardFilters.vue'
import EmptyPanel from '../Components/EmptyPanel.vue'
import AttentionPanel from '../Components/AttentionPanel.vue'
import ExceptionList from '../Components/ExceptionList.vue'
import InsightsPanel from '../Components/InsightsPanel.vue'
import { barChartOptions, donutChartOptions, trendChartOptions } from '../lib/charts'
import { count, useMoney } from '../lib/money'

defineOptions({ layout: AppLayout })

const props = defineProps({
    greeting: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    overview: { type: Object, default: null },
    exceptions: { type: Array, default: null },
    staffLocked: { type: Boolean, default: false },
    trend: { type: Object, default: null },
    returnsTrend: { type: Object, default: null },
    salesByCategory: { type: Object, default: null },
    stockByCategory: { type: Object, default: null },
    movements: { type: Object, default: null },
    ranking: { type: Array, default: null },
    attention: { type: Array, default: null },
    insights: { type: Array, default: null },
    slowest: { type: Array, default: null },
    recent: { type: Array, default: null },
    can: { type: Object, default: () => ({}) },
})

const { money, symbol } = useMoney()

/* ----------------------------------------------------------- Chart options */

/** Written once in lib/charts, because the Reports screen draws it too. */
const trendOptions = computed(() => trendChartOptions(props.trend, money))

/**
 * The chart's two lines, built from the period series.
 *
 * `periodSeries()` answers with plain arrays rather than ApexCharts' own
 * shape, because the sparklines on the cards read the same figures and
 * have no use for a chart library's wrapper.
 */
const trendSeries = computed(() => {
    if (!props.trend) return []

    const lines = []

    if (props.can.sales) lines.push({ name: 'Sales', data: props.trend.sales })
    if (props.can.purchases) lines.push({ name: 'Purchases', data: props.trend.purchases })
    // Profit is cost data. The server leaves it out of the payload for
    // anybody who may not read cost, so it is only drawn when it came.
    if (props.trend.profit) lines.push({ name: 'Gross profit', data: props.trend.profit })

    return lines
})

/* ----------------------------------------------------------- The toggles */

const grains = [
    { value: 'auto', label: 'Auto' },
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
]

/**
 * The grain is a server decision: it changes which buckets are counted.
 * Only the chart block is asked for again, so the cards stay where they are.
 */
function setGrain(grain) {
    router.get(
        '/dashboard',
        { ...props.filters, grain },
        { preserveState: true, preserveScroll: true, replace: true }
    )
}

// Money or units. Both are already on the row, so this costs no request.
const rankBy = [
    { value: 'amount', label: 'Revenue' },
    { value: 'quantity', label: 'Quantity' },
]

const ranked = ref('amount')

const rankedRows = computed(() =>
    [...(props.ranking ?? [])].sort((a, b) => Number(b[ranked.value]) - Number(a[ranked.value]))
)

/* ------------------------------------------------------- The other charts */

const donutOptions = computed(() =>
    donutChartOptions(props.salesByCategory, money)
)

const barOptions = computed(() =>
    barChartOptions(props.stockByCategory, money, { labelWidth: 220, showValues: true })
)

/**
 * One category per row, at a height a name can be read at. Twenty-five
 * categories in a fixed 280px drew their names on top of each other.
 */
const stockChartHeight = computed(() =>
    Math.max(280, (props.stockByCategory?.labels?.length ?? 0) * 30 + 70)
)

const stockBars = computed(() =>
    props.stockByCategory ? [{ name: 'On the shelf', data: props.stockByCategory.series }] : []
)

const returnsOptions = computed(() => trendChartOptions(props.returnsTrend, money))

const returnsSeries = computed(() => {
    if (!props.returnsTrend) return []

    return [
        { name: 'From customers', data: props.returnsTrend.sales },
        { name: 'To suppliers', data: props.returnsTrend.purchases },
    ]
})

/** Nothing came back is a real answer, and deserves its own words. */
const returnsHappened = computed(() => {
    if (!props.returnsTrend) return false

    return [...props.returnsTrend.sales, ...props.returnsTrend.purchases].some((v) => Number(v) > 0)
})

const movementOptions = computed(() =>
    barChartOptions(props.movements, (value) => count(value), { stacked: false })
)

const movementSeries = computed(() => {
    if (!props.movements) return []

    return [
        { name: 'In', data: props.movements.received },
        { name: 'Out', data: props.movements.issued },
    ]
})

/* ------------------------------------------------------------------ Actions */

/** Every block that holds a figure, so a refresh asks for all of them. */
const figureBlocks = [
    'overview',
    'exceptions',
    'attention',
    'insights',
    'trend',
    'returnsTrend',
    'salesByCategory',
    'stockByCategory',
    'movements',
    'ranking',
    'slowest',
    'recent',
]

const refreshing = ref(false)

/**
 * Throw the cached figures away and fetch them again.
 *
 * The page itself is not reloaded: the cache is cleared, then only the
 * blocks holding figures are asked for. The filter bar, the scroll
 * position and anything half-open stay where they are.
 */
function refresh() {
    if (refreshing.value) return

    refreshing.value = true

    router.post('/dashboard/refresh', {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () =>
            router.reload({
                only: figureBlocks,
                onFinish: () => (refreshing.value = false),
            }),
        onError: () => (refreshing.value = false),
    })
}
</script>

<template>
    <div>
        <PageHeader
            :heading="`Good day, ${greeting.name.split(' ')[0]}`"
            :subheading="greeting.today"
        >
            <template #actions>
                <!-- Refresh alone. Everything else that used to sit here —
                     new sale, new purchase, adjust stock, reports,
                     settings — is a screen the sidebar already opens, and
                     a second copy of the navigation only gives the eye
                     somewhere else to look. This is the one control that
                     belongs to the dashboard itself. -->
                <button
                    type="button"
                    class="inline-flex h-9 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink disabled:opacity-60"
                    title="Read the figures again"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCw :size="15" :class="refreshing ? 'animate-spin' : ''" />
                    {{ refreshing ? 'Reading…' : 'Refresh' }}
                </button>
            </template>
        </PageHeader>

        <DashboardFilters :filters="filters" :options="options" :staff-locked="staffLocked" />

        <!-- How the business is doing, over the chosen period -->
        <Deferred data="overview">
            <template #fallback>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCardSkeleton :count="8" />
                </div>
            </template>

            <div v-if="overview">
                <!-- The eight the owner reads every day -->
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        v-for="card in overview.cards"
                        :key="card.key"
                        :stat="card"
                    />
                </div>

                <!-- The rest, smaller -->
                <div
                    v-if="overview.secondary?.length"
                    class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                >
                    <StatCard
                        v-for="card in overview.secondary"
                        :key="card.key"
                        :stat="card"
                        compact
                    />
                </div>

                <p class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-subtle">
                    <span>
                        Every change above compares this period with the one before it,
                        <span class="font-medium text-ink-muted">{{ overview.compared_to }}</span
                        >, so the two are the same length.
                    </span>
                    <span v-if="overview.generated_at" class="text-ink-subtle">
                        · Read at {{ overview.generated_at }}
                    </span>
                </p>
            </div>
        </Deferred>

        <!-- What to look at first. Every row opens the record behind it. -->
        <Deferred data="exceptions">
            <template #fallback>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div v-for="n in 4" :key="n" class="card p-5">
                        <Skeleton class="h-5 w-40" />
                        <Skeleton v-for="m in 3" :key="m" class="mt-3 h-8 w-full" />
                    </div>
                </div>
            </template>

            <div v-if="exceptions?.length" class="mt-4 grid gap-4 md:grid-cols-2">
                <ExceptionList v-for="list in exceptions" :key="list.key" :list="list" />
            </div>
        </Deferred>

        <!-- What to do about it, and what it means -->
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <Deferred data="attention">
                <template #fallback>
                    <div class="card p-5">
                        <Skeleton class="h-5 w-44" />
                        <Skeleton v-for="n in 3" :key="n" class="mt-3 h-12 w-full" />
                    </div>
                </template>

                <AttentionPanel :items="attention ?? []" />
            </Deferred>

            <Deferred data="insights">
                <template #fallback>
                    <div class="card p-5">
                        <Skeleton class="h-5 w-36" />
                        <Skeleton v-for="n in 4" :key="n" class="mt-3 h-8 w-full" />
                    </div>
                </template>

                <InsightsPanel :lines="insights ?? []" />
            </Deferred>
        </div>

        <!-- Sales, purchases and what was made on them -->
        <Deferred data="trend">
            <template #fallback>
                <div class="card mt-4 p-5">
                    <Skeleton class="h-5 w-40" />
                    <Skeleton class="mt-6 h-72 w-full" />
                </div>
            </template>

            <ChartPanel
                v-if="trend"
                class="mt-4"
                heading="Sales vs Purchases"
                :subheading="filters.label"
                :options="trendOptions"
                :series="trendSeries"
                :height="320"
            >
                <template #actions>
                    <div class="flex rounded-field bg-surface-sunken p-0.5">
                        <button
                            v-for="option in grains"
                            :key="option.value"
                            type="button"
                            class="rounded-[calc(var(--radius-field)-2px)] px-2.5 py-1 text-xs font-medium transition"
                            :class="
                                filters.grain === option.value
                                    ? 'bg-surface text-ink shadow-sm'
                                    : 'text-ink-muted hover:text-ink'
                            "
                            @click="setGrain(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </template>
            </ChartPanel>
        </Deferred>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <!-- Where the money came from -->
            <Deferred data="salesByCategory">
                <template #fallback>
                    <div class="card p-5">
                        <Skeleton class="h-5 w-40" />
                        <Skeleton class="mt-6 h-64 w-full" />
                    </div>
                </template>

                <ChartPanel
                    v-if="salesByCategory && salesByCategory.series.length"
                    heading="Sales by category"
                    :subheading="`${money(salesByCategory.total)} over the period`"
                    :options="donutOptions"
                    :series="salesByCategory.series"
                    :height="280"
                />
                <EmptyPanel
                    v-else
                    heading="Sales by category"
                    text="Nothing was sold in this period."
                />
            </Deferred>

            <!-- What came back -->
            <Deferred data="returnsTrend">
                <template #fallback>
                    <div class="card p-5">
                        <Skeleton class="h-5 w-40" />
                        <Skeleton class="mt-6 h-64 w-full" />
                    </div>
                </template>

                <ChartPanel
                    v-if="returnsTrend && returnsHappened"
                    heading="Returns"
                    subheading="What customers sent back, and what you sent back"
                    :options="returnsOptions"
                    :series="returnsSeries"
                    :height="280"
                />
                <EmptyPanel
                    v-else
                    heading="Returns"
                    text="Nothing came back in this period. That is good news."
                />
            </Deferred>

            <!-- How the stock moved -->
            <Deferred data="movements">
                <template #fallback>
                    <div class="card p-5 lg:col-span-2">
                        <Skeleton class="h-5 w-40" />
                        <Skeleton class="mt-6 h-64 w-full" />
                    </div>
                </template>

                <ChartPanel
                    v-if="movements && movements.labels.length"
                    class="lg:col-span-2"
                    heading="Stock movement"
                    subheading="Units in and out, by reason"
                    :options="movementOptions"
                    :series="movementSeries"
                    :height="280"
                />
                <EmptyPanel
                    v-else
                    class="lg:col-span-2"
                    heading="Stock movement"
                    text="No stock moved in this period."
                />
            </Deferred>
        </div>

        <!-- Where the money is sitting: full width, so every category name
             has room and every bar carries its value. A value at cost, so the
             row is left out for anyone who may not see cost, rather than
             saying the shelf is empty. -->
        <div v-if="can.stock && can.cost" class="mt-4">
            <Deferred data="stockByCategory">
                <template #fallback>
                    <div class="card p-5">
                        <Skeleton class="h-5 w-40" />
                        <Skeleton class="mt-6 h-64 w-full" />
                    </div>
                </template>

                <ChartPanel
                    v-if="stockByCategory && stockByCategory.series.length"
                    heading="Inventory value by category"
                    :subheading="`${money(stockByCategory.total)} on the shelf today`"
                    :options="barOptions"
                    :series="stockBars"
                    :height="stockChartHeight"
                />
                <EmptyPanel
                    v-else
                    heading="Inventory value by category"
                    text="There is no stock on the shelf."
                />
            </Deferred>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-5">
            <!-- What sold most -->
            <div class="lg:col-span-3">
                <Deferred data="ranking">
                    <template #fallback>
                        <div class="card p-5">
                            <Skeleton class="h-5 w-32" />
                            <Skeleton v-for="n in 6" :key="n" class="mt-3 h-8 w-full" />
                        </div>
                    </template>

                    <section v-if="ranking" class="card overflow-hidden">
                        <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                            <div>
                                <h3 class="text-sm font-semibold text-ink">Top selling products</h3>
                                <p class="mt-0.5 text-xs text-ink-subtle">{{ filters.label }}</p>
                            </div>

                            <div class="flex rounded-field bg-surface-sunken p-0.5">
                                <button
                                    v-for="option in rankBy"
                                    :key="option.value"
                                    type="button"
                                    class="rounded-[calc(var(--radius-field)-2px)] px-2.5 py-1 text-xs font-medium transition"
                                    :class="
                                        ranked === option.value
                                            ? 'bg-surface text-ink shadow-sm'
                                            : 'text-ink-muted hover:text-ink'
                                    "
                                    @click="ranked = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <p v-if="ranking.length === 0" class="px-5 py-6 text-sm text-ink-subtle">
                            Nothing was sold in this period.
                        </p>

                        <div v-else class="divide-y divide-line">
                            <div
                                class="hidden border-b border-line bg-surface-muted px-5 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:gap-3"
                                :class="can.cost
                                    ? 'sm:grid-cols-[1.5rem_1fr_3.5rem_3.5rem_6rem_5rem_4rem]'
                                    : 'sm:grid-cols-[1.5rem_1fr_3.5rem_3.5rem_6rem]'"
                            >
                                <span>#</span>
                                <span>Product</span>
                                <span class="text-right">Sold</span>
                                <span class="text-right">Stock</span>
                                <span class="text-right">Revenue</span>
                                <template v-if="can.cost">
                                    <span class="text-right">Profit</span>
                                    <span class="text-right">Margin</span>
                                </template>
                            </div>

                            <div
                                v-for="(row, index) in rankedRows"
                                :key="row.sku"
                                class="grid items-center gap-1 px-5 py-2.5 sm:gap-3"
                                :class="can.cost
                                    ? 'sm:grid-cols-[1.5rem_1fr_3.5rem_3.5rem_6rem_5rem_4rem]'
                                    : 'sm:grid-cols-[1.5rem_1fr_3.5rem_3.5rem_6rem]'"
                            >
                                <span class="hidden text-xs font-semibold tabular-nums text-ink-subtle sm:block">
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-ink">{{ row.name }}</p>
                                    <p class="text-2xs text-ink-subtle">
                                        <span class="font-mono">{{ row.sku }}</span>
                                        <span v-if="row.category"> · {{ row.category }}</span>
                                    </p>
                                </div>
                                <span class="text-right text-sm tabular-nums text-ink-muted">
                                    {{ count(row.quantity) }}
                                </span>
                                <!-- What is left to sell, so a top seller
                                     about to run out is visible here. -->
                                <span
                                    class="text-right text-sm tabular-nums"
                                    :class="row.on_hand === 0 ? 'font-semibold text-danger-700' : 'text-ink-subtle'"
                                >
                                    {{ count(row.on_hand) }}
                                </span>
                                <span class="text-right text-sm font-semibold tabular-nums text-ink">
                                    {{ money(row.amount) }}
                                </span>
                                <template v-if="can.cost">
                                    <span class="text-right text-sm tabular-nums text-success-700">
                                        {{ money(row.profit) }}
                                    </span>
                                    <span class="text-right text-sm tabular-nums text-ink-muted">
                                        {{ row.margin }}%
                                    </span>
                                </template>
                            </div>
                        </div>
                    </section>
                </Deferred>
            </div>

            <!-- What is not selling -->
            <div class="lg:col-span-2">
                <Deferred data="slowest">
                    <template #fallback>
                        <div class="card p-5">
                            <Skeleton class="h-5 w-32" />
                            <Skeleton v-for="n in 4" :key="n" class="mt-3 h-8 w-full" />
                        </div>
                    </template>

                    <section v-if="slowest" class="card overflow-hidden">
                        <div class="border-b border-line px-5 py-3.5">
                            <h3 class="text-sm font-semibold text-ink">Slowest movers</h3>
                            <p class="mt-0.5 text-xs text-ink-subtle">
                                Holding stock, selling least
                            </p>
                        </div>

                        <p v-if="slowest.length === 0" class="px-5 py-6 text-sm text-ink-subtle">
                            Everything on the shelf is selling.
                        </p>

                        <div v-else class="divide-y divide-line">
                            <div
                                v-for="row in slowest"
                                :key="row.sku"
                                class="grid grid-cols-[1fr_3.5rem_3.5rem] items-center gap-3 px-5 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-ink">{{ row.name }}</p>
                                    <p class="font-mono text-2xs text-ink-subtle">{{ row.sku }}</p>
                                </div>
                                <span
                                    class="text-right text-sm tabular-nums"
                                    :class="row.quantity === 0 ? 'font-semibold text-danger-700' : 'text-ink-muted'"
                                >
                                    {{ count(row.quantity) }}
                                </span>
                                <span class="text-right text-sm font-semibold text-ink tabular-nums">
                                    {{ count(row.on_hand) }}
                                </span>
                            </div>
                            <p class="px-5 py-2 text-2xs text-ink-subtle">
                                Sold · on hand. A nought sold means it has not moved at all.
                            </p>
                        </div>
                    </section>
                </Deferred>
            </div>
        </div>

        <div class="mt-4">
            <!-- Who did what -->
            <div>
                <Deferred data="recent">
                    <template #fallback>
                        <div class="card p-5">
                            <Skeleton class="h-5 w-32" />
                            <Skeleton v-for="n in 5" :key="n" class="mt-4 h-6 w-full" />
                        </div>
                    </template>

                    <section class="card overflow-hidden">
                        <div class="flex items-baseline justify-between gap-3 border-b border-line px-5 py-3.5">
                            <h3 class="text-sm font-semibold text-ink">Recent activity</h3>
                            <Link
                                v-if="can.audit"
                                href="/audit"
                                class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline"
                            >
                                Audit trail
                                <ArrowRight :size="13" />
                            </Link>
                        </div>

                        <p v-if="!recent || recent.length === 0" class="px-5 py-6 text-sm text-ink-subtle">
                            Nothing has happened yet.
                        </p>

                        <ul v-else class="divide-y divide-line">
                            <li
                                v-for="row in recent"
                                :key="row.id"
                                class="flex items-start gap-3 px-5 py-3"
                            >
                                <span
                                    class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-surface-sunken text-2xs font-bold text-ink-muted"
                                >
                                    {{ row.initials }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-ink">{{ row.description }}</span>
                                    <span class="mt-0.5 flex items-center gap-2 text-2xs text-ink-subtle">
                                        <ActionBadge :label="row.action_label" :tone="row.action_tone" />
                                        {{ row.when }}
                                    </span>
                                </span>
                            </li>
                        </ul>
                    </section>
                </Deferred>
            </div>
        </div>
    </div>
</template>
