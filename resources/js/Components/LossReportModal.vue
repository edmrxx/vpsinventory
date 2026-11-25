<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Check, ExternalLink, Undo2, X } from '@lucide/vue'
import RecordModal from './RecordModal.vue'
import PrimaryButton from './PrimaryButton.vue'
import ActionBadge from './ActionBadge.vue'
import LossPhotos from './LossPhotos.vue'
import { count, useMoney } from '../lib/money'
import { approveLoss, rejectLoss, withdrawLoss } from '../lib/losses'

/**
 * A loss report, read and answered without leaving the list (phase 33).
 * The figures come from the controller's `detail()`, the same payload the
 * full page renders.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The report's own address, e.g. "/loss-reports/5". */
    reportUrl: { type: String, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()
const done = { onDone: () => emit('close') }

const url = computed(() => (props.reportUrl ? `${props.reportUrl}/preview` : null))
</script>

<template>
    <RecordModal :open="open" :url="url" size="lg" @close="$emit('close')">
        <template #default="{ data }">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <h3 class="font-mono text-base font-bold text-ink">{{ data.report.reference }}</h3>
                        <ActionBadge :label="data.report.status_label" :tone="data.report.status_tone" />
                    </div>
                    <Link :href="reportUrl" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-700 hover:underline">
                        <ExternalLink :size="14" />
                        Open full page
                    </Link>
                </div>

                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div
                        v-for="detail in [
                            { label: 'Location', value: data.report.location },
                            { label: 'Reason', value: data.report.reason_label },
                            { label: 'Reported', value: data.report.reported_at },
                            { label: 'Reported by', value: data.report.reported_by },
                            { label: 'Written off as', value: data.report.adjustment?.reference ?? '—' },
                            { label: 'Value', value: data.report.status === 'approved' ? money(data.report.total_cost) : '—' },
                        ]"
                        :key="detail.label"
                        class="flex items-baseline justify-between gap-3 border-b border-line pb-2"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value ?? '—' }}</dd>
                    </div>
                </dl>

                <p v-if="data.report.status === 'rejected'" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    Rejected: {{ data.report.decision_note }}
                </p>
                <p v-if="data.report.status === 'cancelled'" class="rounded-field bg-surface-sunken px-4 py-3 text-xs text-ink-muted">
                    Withdrawn: {{ data.report.cancel_reason }}
                </p>

                <section class="overflow-hidden rounded-field border border-line">
                    <div class="divide-y divide-line">
                        <div v-for="line in data.report.lines" :key="line.id" class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink">{{ count(line.quantity) }} {{ line.unit }}</span>
                        </div>
                    </div>
                </section>

                <LossPhotos :photos="data.report.photos" />

                <div v-if="data.can?.cancel || data.can?.approve" class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                    <PrimaryButton v-if="data.can.cancel && !data.can.approve" variant="secondary" @click="withdrawLoss(data.report, done)">
                        <Undo2 :size="15" />
                        Withdraw
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.reject" variant="secondary" @click="rejectLoss(data.report, done)">
                        <X :size="15" />
                        Reject
                    </PrimaryButton>
                    <PrimaryButton v-if="data.can.approve" variant="danger" @click="approveLoss(data.report, done)">
                        <Check :size="15" />
                        Approve write-off
                    </PrimaryButton>
                </div>
            </div>
        </template>
    </RecordModal>
</template>
