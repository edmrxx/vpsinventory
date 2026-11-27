<script setup>
import { computed, reactive, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { ArrowRight, FileSpreadsheet, Printer } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ChartPanel from '../../Components/ChartPanel.vue'
import BreakdownPanel from '../../Components/BreakdownPanel.vue'
import FilterSelect from '../../Components/FilterSelect.vue'
import PrintLetterhead from '../../Components/PrintLetterhead.vue'
import PrintFooter from '../../Components/PrintFooter.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { count, useMoney } from '../../lib/money'
import { trendChartOptions } from '../../lib/charts'

defineOptions({ layout: AppLayout })

const props = defineProps({
    range: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ staff: [], methods: [] }) },
    export: { type: Object, default: () => ({ available: false, query: {} }) },
    trend: { type: Object, default: null },
    sales: { type: Object, default: null },
    purchases: { type: Object, default: null },
    collections: { type: Object, default: null },
    /** Money paid to suppliers (phase 24.5), for who may read supplier payments. */
    payouts: { type: Object, default: null },
    inventory: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()
const page = usePage()

usePrintLayout()

/**
 * The same chart the dashboard draws, over the period being reported.
 *
 * Its months add up to the summary beside it, because both come from
 * `InventoryMetrics` with the same filters. A period inside one month is
 * plotted day by day, so the chart has a shape rather than a single point.
 */
const trendOptions = computed(() => trendChartOptions(props.trend, money))

/*
 * What the report is asked about. The form edits a copy; nothing changes
 * until Show is pressed, and the spreadsheet and the printed page always
 * follow what the screen shows — the applied filters, never the half-typed
 * ones.
 */
const state = reactive({
    from: props.range.from,
    to: props.range.to,
    staff_id: props.filters.staff_id ?? '',
    payment_method_id: props.filters.payment_method_id ?? '',
})

watch(
    () => [props.range, props.filters],
    ([range, filters]) => {
        state.from = range.from
        state.to = range.to
        state.staff_id = filters.staff_id ?? ''
        state.payment_method_id = filters.payment_method_id ?? ''
    }
)

function apply() {
    const query = { from: state.from, to: state.to }
    if (state.staff_id !== '' && state.staff_id !== null) query.staff_id = state.staff_id
    if (state.payment_method_id !== '' && state.payment_method_id !== null) query.payment_method_id = state.payment_method_id

    router.get('/reports', query, {
        preserveState: true,
        preserveScroll: true,
    })
}

/** The spreadsheet of exactly what is on screen. A plain link: it downloads a file. */
const spreadsheetHref = computed(() => `/reports/export?${new URLSearchParams(props.export.query ?? {}).toString()}`)

const narrowedByMethod = computed(() => props.filters.payment_method_id !== null && props.filters.payment_method_id !== undefined)

/** The printed page names who printed it and when, like the statement. */
const printedBy = computed(() => page.props.auth?.user?.name ?? '')
const printedAt = computed(() =>
    new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
)

function printReport() {
    window.print()
}
</script>

<template>
    <div>
        <PageHeader
            class="print:hidden"
            heading="Reports"
            :subheading="`Every figure here comes from the posted documents. ${range.label}.`"
        >
            <template #actions>
                <a
                    v-if="props.export.available"
                    :href="spreadsheetHref"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-field border border-line-strong bg-surface px-4 text-sm font-semibold text-ink transition-all duration-150 hover:bg-surface-sunken active:scale-[0.98]"
                >
                    <FileSpreadsheet :size="15" />
                    Spreadsheet
                </a>
                <PrimaryButton variant="secondary" @click="printReport">
                    <Printer :size="15" />
                    Print / PDF
                </PrimaryButton>
            </template>
        </PageHeader>

        <!-- Only on paper: what this report covers, and who printed it -->
        <div class="mb-6 hidden print:block">
            <PrintLetterhead title="Sales and purchase report">
                <p class="text-xs text-ink-muted">{{ range.label }}</p>
                <p class="text-xs text-ink-muted">Cashier: {{ filters.staff_label }} · Payment method: {{ filters.payment_label }}</p>
                <p class="text-2xs text-ink-subtle">Printed {{ printedAt }}<template v-if="printedBy"> by {{ printedBy }}</template></p>
            </PrintLetterhead>
            <p class="mt-2 text-2xs text-ink-subtle">Every figure comes from the posted documents; amounts include VAT.</p>
        </div>

        <!-- What the report is asked about -->
        <div class="card mb-4 p-4 print:hidden">
            <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">From</span>
                    <input
                        v-model="state.from"
                        type="date"
                        :max="state.to"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase">To</span>
                    <input
                        v-model="state.to"
                        type="date"
                        :min="state.from"
                        class="h-9 w-full rounded-field border border-line bg-surface-muted px-2 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                    />
                </label>
                <FilterSelect
                    v-model="state.staff_id"
                    label="Cashier"
                    any-label="Everyone"
                    :options="options.staff ?? []"
                />
                <FilterSelect
                    v-model="state.payment_method_id"
                    label="Payment method"
                    any-label="Any method"
                    :options="options.methods ?? []"
                />
                <button
                    type="button"
                    class="h-9 rounded-field bg-brand-600 px-4 text-sm font-semibold text-ink-inverse shadow-brand transition hover:brightness-90"
                    @click="apply"
                >
                    Show
                </button>
            </div>

            <p v-if="narrowedByMethod" class="mt-3 text-xs text-ink-muted">
                Sales paid at least in part by {{ filters.payment_label }}, and their returns. A sale paid two ways counts
                whole. Purchases record no payment method, so they are left out.
            </p>
            <p v-if="!props.export.available" class="mt-3 text-xs text-warning-700">
                The spreadsheet is not available: the server's PHP has no zip extension.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2 print:grid-cols-2">
            <!-- Sales -->
            <section v-if="sales" class="card overflow-hidden print:break-inside-avoid">
                <div class="border-b border-line px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-ink">Sales summary</h3>
                </div>

                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">Sold ({{ sales.documents }} documents)</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(sales.subtotal) }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">Discounts given</dt>
                        <dd class="tabular-nums text-sm text-ink">−{{ money(sales.discount) }}</dd>
                    </div>
                    <!-- Bringing the goods to the site (phase 28.1): part of net sales. -->
                    <div v-if="Number(sales.mobilization) > 0" class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">Mobilization charged</dt>
                        <dd class="tabular-nums text-sm text-ink">+{{ money(sales.mobilization) }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">
                            Returned ({{ sales.return_documents }} documents)
                        </dt>
                        <dd class="tabular-nums text-sm text-ink">−{{ money(sales.returns) }}</dd>
                    </div>
                    <div class="flex items-center justify-between bg-surface-sunken px-5 py-3">
                        <dt class="text-xs font-semibold text-ink">Net sales</dt>
                        <dd class="tabular-nums text-lg font-bold text-ink">{{ money(sales.net) }}</dd>
                    </div>

                    <!-- Only for the people allowed to see a margin -->
                    <template v-if="can.viewCost">
                        <div class="flex items-center justify-between px-5 py-2.5">
                            <dt class="text-xs text-ink-muted">Cost of goods sold</dt>
                            <dd class="tabular-nums text-sm text-ink">{{ money(sales.cost) }}</dd>
                        </div>
                        <div class="flex items-center justify-between bg-surface-sunken px-5 py-3">
                            <dt class="text-xs font-semibold text-ink">Gross profit</dt>
                            <dd
                                class="tabular-nums text-lg font-bold"
                                :class="Number(sales.gross_profit) < 0 ? 'text-danger-700' : 'text-success-700'"
                            >
                                {{ money(sales.gross_profit) }}
                            </dd>
                        </div>
                    </template>
                </dl>
            </section>

            <!-- Money received, by method: the till's payments and payments
                 against invoices, on the day the money came in -->
            <section v-if="collections" class="card overflow-hidden print:break-inside-avoid">
                <div class="border-b border-line px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-ink">Money received</h3>
                </div>

                <dl class="divide-y divide-line">
                    <p v-if="!collections.methods.length" class="px-5 py-6 text-center text-xs text-ink-subtle">
                        No money came in this period.
                    </p>
                    <div
                        v-for="method in collections.methods"
                        :key="method.name"
                        class="flex items-center justify-between px-5 py-2.5"
                    >
                        <dt class="text-xs text-ink-muted">{{ method.name }}</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(method.amount) }}</dd>
                    </div>
                    <div class="flex items-center justify-between bg-surface-sunken px-5 py-3">
                        <dt class="text-xs font-semibold text-ink">Total received</dt>
                        <dd class="tabular-nums text-lg font-bold text-ink">{{ money(collections.total) }}</dd>
                    </div>
                    <div v-if="Number(collections.account) > 0" class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">of which paid against invoices</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(collections.account) }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Money paid to suppliers, by method: on delivery and later
                 (phase 24.5) — the other side of Money received -->
            <section v-if="payouts" class="card overflow-hidden print:break-inside-avoid" data-block="payouts">
                <div class="border-b border-line px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-ink">Money paid to suppliers</h3>
                </div>

                <dl class="divide-y divide-line">
                    <p v-if="!payouts.methods.length" class="px-5 py-6 text-center text-xs text-ink-subtle">
                        Nothing was paid to suppliers this period.
                    </p>
                    <div
                        v-for="method in payouts.methods"
                        :key="method.name"
                        class="flex items-center justify-between px-5 py-2.5"
                    >
                        <dt class="text-xs text-ink-muted">{{ method.name }}</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(method.total) }}</dd>
                    </div>
                    <div class="flex items-center justify-between bg-surface-sunken px-5 py-3">
                        <dt class="text-xs font-semibold text-ink">Total paid</dt>
                        <dd class="tabular-nums text-lg font-bold text-ink">{{ money(payouts.totals.paid) }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">on delivery · later</dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(payouts.totals.on_delivery) }} · {{ money(payouts.totals.later) }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">Still owed to suppliers, as of today</dt>
                        <dd class="tabular-nums text-sm" :class="Number(payouts.totals.overdue) > 0 ? 'font-semibold text-warning-700' : 'text-ink'">
                            {{ money(payouts.totals.owed) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- Purchases -->
            <section v-if="purchases" class="card overflow-hidden print:break-inside-avoid">
                <div class="border-b border-line px-5 py-3.5">
                    <h3 class="text-sm font-semibold text-ink">Purchase summary</h3>
                </div>

                <dl class="divide-y divide-line">
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">
                            Bought ({{ purchases.documents }} documents)
                        </dt>
                        <dd class="tabular-nums text-sm text-ink">{{ money(purchases.total) }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5">
                        <dt class="text-xs text-ink-muted">
                            Sent back ({{ purchases.return_documents }} documents)
                        </dt>
                        <dd class="tabular-nums text-sm text-ink">−{{ money(purchases.returns) }}</dd>
                    </div>
                    <div class="flex items-center justify-between bg-surface-sunken px-5 py-3">
                        <dt class="text-xs font-semibold text-ink">Net purchases</dt>
                        <dd class="tabular-nums text-lg font-bold text-ink">{{ money(purchases.net) }}</dd>
                    </div>
                </dl>

                <!-- Valuation is "right now", not a period, so it points at
                     the screen that owns it rather than repeating it here -->
                <div v-if="inventory" class="border-t border-line bg-surface-muted px-5 py-3.5">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-xs text-ink-muted">Stock on hand, today</span>
                        <Link href="/stock" class="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline">
                            Open
                            <ArrowRight :size="12" />
                        </Link>
                    </div>
                    <p class="mt-1 tabular-nums text-xl font-bold text-ink">
                        {{ money(inventory.value) }}
                    </p>
                    <p class="mt-0.5 text-2xs text-ink-subtle">
                        {{ count(inventory.units) }} units · {{ inventory.low_stock }} running low
                    </p>
                </div>
            </section>
        </div>

        <!-- Sales against purchases, over the period above -->
        <!-- Left off paper: an SVG drawn at the screen's width would be cut
             at the page's, and the figures above already say it -->
        <ChartPanel
            v-if="trend && trend.series.length"
            class="mt-4 print:hidden"
            :heading="trend.series.map((series) => series.name).join(' vs ')"
            :subheading="range.label"
            :series="trend.series"
            :options="trendOptions"
        />

        <!-- The breakdowns. Ten rows each, with the rest gathered into one
             line so the column still adds up to the summary above. -->
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <BreakdownPanel
                v-if="sales"
                heading="Sales by product"
                :breakdown="sales.by_product"
                measure="quantity"
                with-sku
                empty-text="Nothing was sold in this period."
            />

            <BreakdownPanel
                v-if="sales"
                heading="Sales by customer"
                :breakdown="sales.by_customer"
                measure="documents"
                empty-text="Nothing was sold in this period."
            />

            <BreakdownPanel
                v-if="purchases"
                heading="Purchases by supplier"
                :breakdown="purchases.by_supplier"
                measure="documents"
                empty-text="Nothing was bought in this period."
            />

            <BreakdownPanel
                v-if="purchases"
                heading="Purchases by product"
                :breakdown="purchases.by_product"
                measure="quantity"
                with-sku
                empty-text="Nothing was bought in this period."
            />
        </div>

        <PrintFooter class="hidden print:block" />
    </div>
</template>
