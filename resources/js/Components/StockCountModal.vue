<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Ban, Check, ExternalLink, Undo2 } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import { count as qty, useMoney } from '../lib/money'
import { approveCount, cancelCount, sendBackCount } from '../lib/stockCounts'

/**
 * A count sheet, read and answered without leaving the list (phase 32).
 *
 * The figures come from the controller's `detail()`, the same payload the
 * full page renders. A summary: the lines over or short, not the whole
 * sheet — counting is done on the page.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The count's own address, e.g. "/stock-counts/5". */
    countUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()
const done = { onDone: () => emit('close') }

/** The lines worth reading in a dialog: those that differ. */
function differing(data) {
    const open = ['counting', 'submitted'].includes(data.count.status)

    return data.count.lines
        .map((line) => ({ ...line, diff: open ? line.difference : line.variance, value: open ? line.estimate : line.variance_value }))
        .filter((line) => line.diff !== null && line.diff !== undefined && Number(line.diff) !== 0)
}

const signed = (n) => (Number(n) > 0 ? '+' : '') + qty(n)
const tone = (n) => (Number(n) > 0 ? 'text-success-700' : 'text-danger-700')

const url = computed(() => (props.countUrl ? `${props.countUrl}/preview` : null))
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.count.reference }}</h3>
                        <ActionBadge :label="data.count.status_label" :tone="data.count.status_tone" />
                    </div>
                    <Link :href="countUrl" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline">
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Location', value: data.count.location },
                            { label: 'Counting', value: data.count.scope },
                            { label: 'Cutoff', value: data.count.cutoff_at },
                            { label: 'Started by', value: data.count.started_by },
                            { label: 'Counted', value: `${qty(data.summary.counted)} of ${qty(data.summary.lines)}` },
                            { label: 'Approved', value: data.count.approved_at ? `${data.count.approved_at}${data.count.approved_by ? ` by ${data.count.approved_by}` : ''}` : '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.count.cancelled_at" class="rounded-field bg-danger-50 px-4 py-3 text-xs text-danger-700">
                    Cancelled on {{ data.count.cancelled_at }}<span v-if="data.count.cancelled_by"> by {{ data.count.cancelled_by }}</span>.
                    Reason: {{ data.count.cancel_reason }}
                </p>
                <p v-if="data.count.status === 'counting' && data.count.send_back_reason" class="rounded-field bg-warning-50 px-4 py-3 text-xs text-warning-700">
                    Sent back: {{ data.count.send_back_reason }}
                </p>

                <p v-if="data.count.blind" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    A blind count: the book figures and the differences show to the approver once it is handed in.
                </p>

                <template v-else>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Over</p>
                            <p class="text-base font-bold tabular-nums text-success-700">{{ qty(data.summary.over) }}</p>
                            <p class="text-2xs text-ink-subtle">{{ data.summary.estimated ? 'about ' : '' }}{{ money(data.summary.gain_value) }}</p>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Short</p>
                            <p class="text-base font-bold tabular-nums text-danger-700">{{ qty(data.summary.short) }}</p>
                            <p class="text-2xs text-ink-subtle">{{ data.summary.estimated ? 'about ' : '' }}{{ money(data.summary.loss_value) }}</p>
                        </div>
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Matched</p>
                            <p class="text-base font-bold tabular-nums text-ink">{{ qty(data.summary.matched) }}</p>
                        </div>
                    </div>

                    <section v-if="differing(data).length" class="overflow-hidden rounded-field border border-line">
                        <div class="hidden border-b border-line bg-surface-muted px-4 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_6rem_6rem_7rem] sm:gap-3">
                            <span>Product</span>
                            <span class="text-right">Counted</span>
                            <span class="text-right">Difference</span>
                            <span class="text-right">Value</span>
                        </div>
                        <div class="max-h-72 divide-y divide-line overflow-y-auto">
                            <div v-for="line in differing(data)" :key="line.id"
                                class="grid gap-1 px-4 py-2.5 sm:grid-cols-[1fr_6rem_6rem_7rem] sm:items-center sm:gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                    <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }} · books {{ qty(line.system_quantity) }} {{ line.unit }}</p>
                                </div>
                                <span class="tabular-nums text-sm text-ink sm:text-right">{{ qty(line.counted_quantity) }} {{ line.unit }}</span>
                                <span class="tabular-nums text-sm font-semibold sm:text-right" :class="tone(line.diff)">{{ signed(line.diff) }}</span>
                                <span class="tabular-nums text-sm sm:text-right" :class="tone(line.value)">{{ money(line.value) }}</span>
                            </div>
                        </div>
                    </section>
                    <p v-else class="text-xs text-ink-muted">Nothing counted differs from the books.</p>
                </template>

                <div v-if="data.can?.cancel || data.can?.approve || data.can?.send_back" class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <PrimaryButton v-if="data.can.cancel" variant="danger" @click="cancelCount(data.count, done)">
                        <Ban :size="15" />
                        Cancel
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.send_back" variant="secondary" @click="sendBackCount(data.count, done)">
                        <Undo2 :size="15" />
                        Send back
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.approve" @click="approveCount(data.count, data.summary, money, done)">
                        <Check :size="15" />
                        Approve and post
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
