<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Ban, Check, ClipboardCheck, EyeOff, Printer, Save, Search, Send, Undo2 } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import CountSheetPrint from '../../Components/CountSheetPrint.vue'
import { usePrintLayout } from '../../composables/usePrintLayout'
import { confirmAction, toastError } from '../../lib/swal'
import { count as qty, quantityStep, toMilli, useMoney } from '../../lib/money'
import { approveCount, cancelCount, sendBackCount, submitCount } from '../../lib/stockCounts'

defineOptions({ layout: AppLayout })

/**
 * The count sheet (phase 32): where the counting is entered, and where the
 * approver weighs the differences.
 *
 * While open, a counter who may not approve is sent no book figure (a blind
 * count): the columns for it are simply absent. The printed sheet never
 * carries one either — it is for writing on.
 */
const props = defineProps({
    count: { type: Object, required: true },
    summary: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

usePrintLayout()

const { money } = useMoney()

const blind = computed(() => props.count.blind)
const open = computed(() => ['counting', 'submitted'].includes(props.count.status))

/* ------------------------------------------------------------ Entering */

/** What is typed, by line id, as text so an empty box stays empty. */
const entries = reactive({})
/** What was saved, the same way, to tell what changed. */
const saved = reactive({})

function load(lines) {
    for (const line of lines) {
        const value = line.counted_quantity === null || line.counted_quantity === undefined ? '' : String(line.counted_quantity)
        entries[line.id] = value
        saved[line.id] = value
    }
}

load(props.count.lines)

watch(() => props.count.lines, load)

const same = (a, b) => (a === '' && b === '') || (a !== '' && b !== '' && toMilli(a) === toMilli(b))

const changedIds = computed(() =>
    props.count.lines.filter((l) => !same(entries[l.id] ?? '', saved[l.id] ?? '')).map((l) => l.id)
)

const dirty = computed(() => changedIds.value.length > 0)

/** Errors from the last save, by line id. */
const lineErrors = ref({})
const saving = ref(false)

function save({ then } = {}) {
    const ids = changedIds.value
    if (!ids.length) {
        then?.()
        return
    }

    const counts = ids.map((id) => ({ id, counted_quantity: entries[id] === '' ? null : entries[id] }))

    saving.value = true
    lineErrors.value = {}

    router.put(`/stock-counts/${props.count.id}/counts`, { counts }, {
        preserveScroll: true,
        onSuccess: () => then?.(),
        onError: (errors) => {
            const byLine = {}
            const loose = []

            for (const [key, message] of Object.entries(errors)) {
                const match = key.match(/^counts\.(\d+)\./)
                if (match && counts[Number(match[1])]) byLine[counts[Number(match[1])].id] = message
                else loose.push(message)
            }

            lineErrors.value = byLine
            toastError(loose[0] ?? 'Some figures need fixing. They are marked in red.')
        },
        onFinish: () => (saving.value = false),
    })
}

/*
 * Leaving with figures typed but not saved asks first: a counter who has
 * typed three hundred figures and clicks the sidebar would lose them all.
 * Only a page visit is held up — saving and handing in go through.
 */
let leaving = false
let stopGuard = null

function warnOnUnload(event) {
    if (!dirty.value) return
    event.preventDefault()
    event.returnValue = ''
}

onMounted(() => {
    window.addEventListener('beforeunload', warnOnUnload)

    stopGuard = router.on('before', (event) => {
        const visit = event.detail.visit
        if (!dirty.value || leaving || visit.method !== 'get') return

        event.preventDefault()

        confirmAction({
            icon: 'warning',
            title: 'Leave without saving?',
            text: `${changedIds.value.length} counted figure(s) on this sheet are not saved yet and will be lost.`,
            confirmText: 'Leave anyway',
            cancelText: 'Stay and save',
        }).then((agreed) => {
            if (!agreed) return
            leaving = true
            router.visit(visit.url)
        })
    })
})

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', warnOnUnload)
    stopGuard?.()
})

function handIn() {
    // What is on screen is saved first, so nothing typed is lost.
    const summary = {
        counted: props.count.lines.filter((l) => (entries[l.id] ?? '') !== '').length,
        uncounted: props.count.lines.filter((l) => (entries[l.id] ?? '') === '').length,
    }

    save({ then: () => submitCount(props.count, summary) })
}

/* ------------------------------------------------------------ Reading */

const search = ref('')
const show = ref('all')

const filters = computed(() => [
    { value: 'all', label: 'All' },
    { value: 'uncounted', label: 'Not counted' },
    { value: 'counted', label: 'Counted' },
    ...(blind.value ? [] : [{ value: 'different', label: 'Differences' }]),
    ...(!blind.value && open.value ? [{ value: 'moved', label: 'Moved since cutoff' }] : []),
])

/** The difference as it stands on screen, typed but not saved included. */
function differenceOf(line) {
    if (blind.value) return null
    const typed = entries[line.id] ?? ''
    if (typed === '') return null
    return (toMilli(typed) - toMilli(line.system_quantity)) / 1000
}

const rows = computed(() => {
    const words = search.value.trim().toLowerCase()

    return props.count.lines.filter((line) => {
        if (words && !`${line.sku} ${line.name} ${line.category ?? ''}`.toLowerCase().includes(words)) return false

        const typed = (entries[line.id] ?? '') !== ''

        switch (show.value) {
            case 'uncounted':
                return !typed
            case 'counted':
                return typed
            case 'different': {
                const d = open.value ? differenceOf(line) : line.variance
                return d !== null && d !== undefined && Number(d) !== 0
            }
            case 'moved':
                return Number(line.changed_since ?? 0) !== 0
            default:
                return true
        }
    })
})

const countedOnScreen = computed(() => props.count.lines.filter((l) => (entries[l.id] ?? '') !== '').length)

function tone(value) {
    const n = Number(value ?? 0)
    if (n > 0) return 'text-success-700'
    if (n < 0) return 'text-danger-700'
    return 'text-ink-muted'
}

function signed(value) {
    const n = Number(value ?? 0)
    return (n > 0 ? '+' : '') + qty(n)
}

const details = computed(() => [
    { label: 'Location', value: props.count.location },
    { label: 'Counting', value: props.count.scope },
    { label: 'Cutoff', value: props.count.cutoff_at },
    { label: 'Started by', value: props.count.started_by ?? '—' },
    ...(props.count.submitted_at ? [{ label: 'Handed in', value: `${props.count.submitted_at}${props.count.submitted_by ? ` by ${props.count.submitted_by}` : ''}` }] : []),
    ...(props.count.approved_at ? [{ label: 'Approved', value: `${props.count.approved_at}${props.count.approved_by ? ` by ${props.count.approved_by}` : ''}` }] : []),
])

const posted = computed(() => [props.count.gain_adjustment, props.count.loss_adjustment].filter(Boolean))

function printSheet() {
    window.print()
}
</script>

<template>
    <div>
        <div class="print:hidden">
            <PageHeader
                :heading="count.reference"
                :subheading="`${count.location} · ${count.scope} · cutoff ${count.cutoff_at}`"
                back-href="/stock-counts"
                back-label="Back to stock counts"
            >
                <template #actions>
                    <PrimaryButton variant="secondary" @click="printSheet">
                        <Printer :size="15" />
                        Print sheet
                    </PrimaryButton>
                    <PrimaryButton v-if="can.cancel" variant="danger" @click="cancelCount(count)">
                        <Ban :size="15" />
                        Cancel
                    </PrimaryButton>
                    <PrimaryButton v-if="can.count" variant="secondary" :loading="saving" :disabled="!dirty" @click="save()">
                        <Save :size="15" />
                        Save counts
                    </PrimaryButton>
                    <PrimaryButton v-if="can.submit" :disabled="saving" @click="handIn">
                        <Send :size="15" />
                        Hand in
                    </PrimaryButton>
                    <PrimaryButton v-if="can.send_back" variant="secondary" @click="sendBackCount(count)">
                        <Undo2 :size="15" />
                        Send back
                    </PrimaryButton>
                    <PrimaryButton v-if="can.approve" @click="approveCount(count, summary, money)">
                        <Check :size="15" />
                        Approve and post
                    </PrimaryButton>
                </template>
            </PageHeader>

            <div class="mb-4 space-y-2">
                <p v-if="count.status === 'cancelled'" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700">
                    <Ban :size="16" class="mt-px shrink-0" />
                    <span>
                        Cancelled on {{ count.cancelled_at }}<template v-if="count.cancelled_by"> by {{ count.cancelled_by }}</template>.
                        Reason: {{ count.cancel_reason }}. Nothing was posted.
                    </span>
                </p>
                <p
                    v-if="count.status === 'counting' && count.send_back_reason"
                    class="card flex items-start gap-2.5 p-4 text-xs font-medium text-warning-700"
                >
                    <Undo2 :size="16" class="mt-px shrink-0" />
                    <span>
                        Sent back on {{ count.sent_back_at }}<template v-if="count.sent_back_by"> by {{ count.sent_back_by }}</template>:
                        {{ count.send_back_reason }}
                    </span>
                </p>
                <p v-if="count.status === 'approved'" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-success-700">
                    <ClipboardCheck :size="16" class="mt-px shrink-0" />
                    <span v-if="posted.length">
                        Approved and posted as
                        <template v-for="(adjustment, i) in posted" :key="adjustment.id">
                            <template v-if="i > 0"> and </template>
                            <Link :href="`/adjustments/${adjustment.id}`" class="font-mono font-semibold underline">{{ adjustment.reference }}</Link>
                            <template v-if="adjustment.status === 'voided'"> (since voided)</template>
                        </template>.
                    </span>
                    <span v-else>Approved. Everything counted matched the books, so nothing was posted.</span>
                </p>
                <p v-if="blind" class="card flex items-start gap-2.5 p-4 text-xs text-ink-muted">
                    <EyeOff :size="16" class="mt-px shrink-0" />
                    <span>
                        A blind count: the book figures are hidden while counting, so write what you find.
                        Leave a product empty if you have not counted it — empty is not zero. Type 0 only
                        when the shelf is empty. Count goods set aside for a loss report too: they stay on
                        the books until they are written off.
                    </span>
                </p>
            </div>

            <div class="mb-4 grid gap-4 lg:grid-cols-3">
                <section class="card p-5">
                    <div class="flex items-center gap-2">
                        <ActionBadge :label="count.status_label" :tone="count.status_tone" />
                    </div>
                    <dl class="mt-4 space-y-2.5">
                        <div
                            v-for="detail in details"
                            :key="detail.label"
                            class="flex items-start justify-between gap-3 border-b border-line pb-2.5 last:border-0 last:pb-0"
                        >
                            <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                            <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                        </div>
                    </dl>
                    <p v-if="count.notes" class="mt-4 text-xs whitespace-pre-line text-ink-muted">{{ count.notes }}</p>
                </section>

                <section class="card grid grid-cols-2 gap-3 p-5 lg:col-span-2 sm:grid-cols-4">
                    <div class="rounded-field bg-surface-sunken p-3">
                        <p class="text-2xs text-ink-muted">Counted</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">
                            {{ qty(countedOnScreen) }}
                            <span class="text-xs font-medium text-ink-muted">of {{ qty(summary.lines) }}</span>
                        </p>
                    </div>
                    <template v-if="!blind">
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Over</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-success-700">{{ qty(summary.over) }}</p>
                            <p class="text-2xs text-ink-subtle">{{ summary.estimated ? 'about ' : '' }}{{ money(summary.gain_value) }}</p>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Short</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-danger-700">{{ qty(summary.short) }}</p>
                            <p class="text-2xs text-ink-subtle">{{ summary.estimated ? 'about ' : '' }}{{ money(summary.loss_value) }}</p>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Matched</p>
                            <p class="mt-0.5 text-lg font-bold tabular-nums text-ink">{{ qty(summary.matched) }}</p>
                        </div>
                        <p v-if="summary.estimated" class="col-span-full text-2xs text-ink-subtle">
                            As last saved. Values are estimates at each product's cost; a shortfall posts at
                            the cost of the batches it comes out of. A line that moved since the cutoff is
                            worth a second look before approving.
                        </p>
                    </template>
                    <p v-else class="col-span-full self-center text-xs text-ink-subtle sm:col-span-3">
                        {{ qty(summary.lines - countedOnScreen) }} still to count. Save often; nothing posts until the count is approved.
                    </p>
                </section>
            </div>

            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center gap-3 border-b border-line p-3">
                    <label class="relative min-w-0 flex-1 sm:max-w-xs">
                        <Search :size="15" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-ink-subtle" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search SKU, name or category…"
                            class="h-9 w-full rounded-field border border-line bg-surface-muted pr-2 pl-8 text-sm text-ink focus:border-brand-300 focus:bg-surface"
                        />
                    </label>
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="option in filters"
                            :key="option.value"
                            type="button"
                            class="h-8 rounded-field px-3 text-xs font-medium transition-colors"
                            :class="show === option.value ? 'bg-brand-600 text-ink-inverse' : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
                            @click="show = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <span v-if="dirty" class="ml-auto text-xs font-medium text-warning-700">
                        {{ changedIds.length }} unsaved
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-muted text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Product</th>
                                <th v-if="!blind" class="px-3 py-2.5 text-right whitespace-nowrap">At cutoff</th>
                                <th v-if="!blind && open" class="hidden px-3 py-2.5 text-right whitespace-nowrap md:table-cell">Since cutoff</th>
                                <th class="px-3 py-2.5 text-right">Counted</th>
                                <th v-if="!blind" class="px-3 py-2.5 text-right">Difference</th>
                                <th v-if="!blind" class="hidden px-4 py-2.5 text-right sm:table-cell">Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="line in rows" :key="line.id" class="transition-colors hover:bg-surface-muted">
                                <td class="px-4 py-2">
                                    <p class="font-medium text-ink">{{ line.name }}</p>
                                    <p class="font-mono text-2xs text-ink-subtle">
                                        {{ line.sku }}<template v-if="line.category"> · {{ line.category }}</template>
                                    </p>
                                    <p v-if="Number(line.held_for_losses ?? 0) > 0" class="mt-0.5 text-2xs font-medium text-warning-700">
                                        {{ qty(line.held_for_losses) }} {{ line.unit }} held for a loss report — counted in, or it comes off twice
                                    </p>
                                </td>
                                <td v-if="!blind" class="px-3 py-2 text-right tabular-nums whitespace-nowrap text-ink-muted">
                                    {{ qty(line.system_quantity) }} {{ line.unit }}
                                </td>
                                <td
                                    v-if="!blind && open"
                                    class="hidden px-3 py-2 text-right tabular-nums whitespace-nowrap md:table-cell"
                                    :class="Number(line.changed_since) !== 0 ? 'font-semibold text-warning-700' : 'text-ink-subtle'"
                                >
                                    {{ Number(line.changed_since) !== 0 ? signed(line.changed_since) : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <template v-if="can.count">
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <input
                                                v-model="entries[line.id]"
                                                type="number"
                                                inputmode="decimal"
                                                min="0"
                                                :step="quantityStep(line.decimals)"
                                                :aria-label="`Counted ${line.name}`"
                                                placeholder="—"
                                                class="h-8 w-24 rounded-field border bg-surface px-2 text-right text-sm tabular-nums text-ink focus:border-brand-300"
                                                :class="lineErrors[line.id] ? 'border-danger-500' : 'border-line'"
                                            />
                                            <span class="w-8 text-left text-2xs text-ink-subtle">{{ line.unit }}</span>
                                        </div>
                                        <p v-if="lineErrors[line.id]" class="mt-1 text-2xs text-danger-700">{{ lineErrors[line.id] }}</p>
                                    </template>
                                    <span v-else class="tabular-nums whitespace-nowrap text-ink">
                                        <template v-if="line.counted_quantity !== null">{{ qty(line.counted_quantity) }} {{ line.unit }}</template>
                                        <span v-else class="text-ink-subtle">Not counted</span>
                                    </span>
                                </td>
                                <td v-if="!blind" class="px-3 py-2 text-right tabular-nums whitespace-nowrap font-semibold">
                                    <template v-if="open">
                                        <span v-if="differenceOf(line) !== null" :class="tone(differenceOf(line))">
                                            {{ differenceOf(line) === 0 ? '0' : signed(differenceOf(line)) }}
                                        </span>
                                        <span v-else class="font-normal text-ink-subtle">—</span>
                                    </template>
                                    <template v-else>
                                        <span v-if="line.variance !== null && line.variance !== undefined" :class="tone(line.variance)">
                                            {{ Number(line.variance) === 0 ? '0' : signed(line.variance) }}
                                        </span>
                                        <span v-else class="font-normal text-ink-subtle">—</span>
                                    </template>
                                </td>
                                <td v-if="!blind" class="hidden px-4 py-2 text-right tabular-nums whitespace-nowrap sm:table-cell">
                                    <template v-if="open">
                                        <span v-if="Number(line.estimate) !== 0" :class="tone(line.estimate)">{{ money(line.estimate) }}</span>
                                        <span v-else class="text-ink-subtle">—</span>
                                    </template>
                                    <template v-else>
                                        <span v-if="Number(line.variance_value ?? 0) !== 0" :class="tone(line.variance_value)">{{ money(line.variance_value) }}</span>
                                        <span v-else class="text-ink-subtle">—</span>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-ink-muted">Nothing matches.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div v-if="can.count" class="mt-4 flex justify-end gap-2">
                <PrimaryButton variant="secondary" :loading="saving" :disabled="!dirty" @click="save()">
                    <Save :size="15" />
                    Save counts
                </PrimaryButton>
                <PrimaryButton v-if="can.submit" :disabled="saving" @click="handIn">
                    <Send :size="15" />
                    Hand in
                </PrimaryButton>
            </div>
        </div>

        <!-- The paper sheet, for counting by hand. Never a book figure. -->
        <CountSheetPrint :count="count" class="hidden print:block" />
    </div>
</template>
