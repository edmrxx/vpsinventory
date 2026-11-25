<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Ban, Check, PackageX, Undo2, X } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import LossPhotos from '../../Components/LossPhotos.vue'
import { count, useMoney } from '../../lib/money'
import { approveLoss, rejectLoss, withdrawLoss } from '../../lib/losses'

defineOptions({ layout: AppLayout })

const props = defineProps({
    report: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const written = computed(() => props.report.status === 'approved')

const details = computed(() => [
    { label: 'Location', value: props.report.location ?? '—' },
    { label: 'Reason', value: props.report.reason_label },
    { label: 'Reported', value: props.report.reported_at ?? '—' },
    { label: 'Reported by', value: props.report.reported_by ?? '—' },
    ...(props.report.decided_at
        ? [{ label: written.value ? 'Approved' : 'Rejected', value: `${props.report.decided_at}${props.report.decided_by ? ` by ${props.report.decided_by}` : ''}` }]
        : []),
])
</script>

<template>
    <div>
        <PageHeader
            :heading="report.reference"
            :subheading="`${report.reason_label} · ${report.location} · ${report.report_date}`"
            back-href="/loss-reports"
            back-label="Back to loss reports"
        >
            <template #actions>
                <PrimaryButton v-if="can.cancel && !can.approve" variant="secondary" @click="withdrawLoss(report)">
                    <Undo2 :size="15" />
                    Withdraw
                </PrimaryButton>
                <PrimaryButton v-if="can.reject" variant="secondary" @click="rejectLoss(report)">
                    <X :size="15" />
                    Reject
                </PrimaryButton>
                <PrimaryButton v-if="can.approve" variant="danger" @click="approveLoss(report)">
                    <Check :size="15" />
                    Approve write-off
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="mb-4 space-y-2">
            <p v-if="report.status === 'pending'" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-warning-700">
                <PackageX :size="16" class="mt-px shrink-0" />
                <span>
                    Held aside at {{ report.location }}: these goods are still on the books, but nobody can sell,
                    move or reserve them until an approver writes them off or rejects the report.
                </span>
            </p>
            <p v-if="written" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-danger-700">
                <PackageX :size="16" class="mt-px shrink-0" />
                <span>
                    Written off as
                    <Link v-if="report.adjustment" :href="`/adjustments/${report.adjustment.id}`" class="font-mono font-semibold underline">{{ report.adjustment.reference }}</Link><template v-if="report.adjustment?.status === 'voided'"> (since voided)</template><template v-if="report.decision_note">: {{ report.decision_note }}</template>.
                </span>
            </p>
            <p v-if="report.status === 'rejected'" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-ink-muted">
                <X :size="16" class="mt-px shrink-0" />
                <span>Rejected: {{ report.decision_note }}. Nothing was written off; the goods are free again.</span>
            </p>
            <p v-if="report.status === 'cancelled'" class="card flex items-start gap-2.5 p-4 text-xs font-medium text-ink-muted">
                <Ban :size="16" class="mt-px shrink-0" />
                <span>
                    Withdrawn on {{ report.cancelled_at }}<template v-if="report.cancelled_by"> by {{ report.cancelled_by }}</template>:
                    {{ report.cancel_reason }}. Nothing was written off.
                </span>
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="card p-5 sm:p-6">
                <ActionBadge :label="report.status_label" :tone="report.status_tone" />
                <dl class="mt-4 space-y-3">
                    <div
                        v-for="detail in details"
                        :key="detail.label"
                        class="flex items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0"
                    >
                        <dt class="text-xs text-ink-muted">{{ detail.label }}</dt>
                        <dd class="min-w-0 text-right text-sm break-words text-ink">{{ detail.value }}</dd>
                    </div>
                </dl>
                <div v-if="written" class="mt-6 rounded-field bg-surface-sunken p-4">
                    <p class="text-xs text-ink-muted">Written off, at cost</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums text-ink">{{ money(report.total_cost) }}</p>
                </div>
            </section>

            <div class="space-y-4 lg:col-span-2">
                <section class="card overflow-hidden">
                    <div
                        class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_7rem_8rem_8rem] md:gap-3"
                    >
                        <span>Product</span>
                        <span class="text-right">Qty</span>
                        <span class="text-right">Unit cost</span>
                        <span class="text-right">Value</span>
                    </div>
                    <div class="divide-y divide-line">
                        <div
                            v-for="line in report.lines"
                            :key="line.id"
                            class="grid gap-1.5 p-4 md:grid-cols-[1fr_7rem_8rem_8rem] md:items-center md:gap-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                            </div>
                            <span class="tabular-nums text-sm text-ink md:text-right">{{ count(line.quantity) }} {{ line.unit }}</span>
                            <span class="tabular-nums text-sm text-ink-muted md:text-right">{{ written ? money(line.unit_cost) : '—' }}</span>
                            <span class="tabular-nums text-sm font-semibold text-ink md:text-right">{{ written ? money(line.total_cost) : '—' }}</span>
                        </div>
                    </div>
                </section>

                <section class="card p-5 sm:p-6">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Photos</h3>
                    <LossPhotos :photos="report.photos" />
                </section>

                <section v-if="report.notes" class="card p-5 sm:p-6">
                    <h3 class="text-sm font-semibold text-ink">Notes</h3>
                    <p class="mt-2 text-sm whitespace-pre-line text-ink-muted">{{ report.notes }}</p>
                </section>
            </div>
        </div>
    </div>
</template>
