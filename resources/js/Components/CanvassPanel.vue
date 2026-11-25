<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Award, FileText, Plus, RotateCcw, Scale, Trash2 } from '@lucide/vue'
import PrimaryButton from './PrimaryButton.vue'
import { useMoney } from '../lib/money'
import { confirmAction } from '../lib/swal'
import { awardTotal, defaultChoices, needsReason, removeQuote, reopenAward } from '../lib/canvass'

/**
 * The canvass on an approved purchase request (phase 24.2): the Abstract of
 * Quotation on screen, and the award.
 *
 * Suppliers across, items down; the lowest price on each item is marked.
 * Somebody who may award switches the table into choosing: the lowest is
 * chosen already, a dearer choice asks for a reason, and the server weighs
 * it again (`QuoteAbstract::weighAward`) before anything is saved.
 */
const props = defineProps({
    request: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
})

const { money } = useMoney()

const canvass = computed(() => props.request.canvass)
const suppliers = computed(() => canvass.value?.suppliers ?? [])
const lines = computed(() => canvass.value?.lines ?? [])
const awarded = computed(() => Boolean(canvass.value?.awarded_at))
const unquoted = computed(() => lines.value.filter((line) => !line.quoted))

/** Who won what, for the line under the table. */
const winners = computed(() => {
    const byName = {}

    for (const line of lines.value) {
        if (!line.award) continue
        byName[line.award.supplier] ??= { items: 0, total: 0 }
        byName[line.award.supplier].items++
        byName[line.award.supplier].total += Number(line.award.line_total)
    }

    return Object.entries(byName).map(([name, won]) => ({ name, ...won }))
})

/* Choosing */
const choosing = ref(false)
const form = useForm({ choices: {}, reasons: {} })

function startAward() {
    form.clearErrors()
    form.choices = defaultChoices(canvass.value)
    form.reasons = {}
    choosing.value = true
}

function submitAward() {
    form.post(`/purchase-requests/${props.request.id}/award`, {
        preserveScroll: true,
        onSuccess: () => (choosing.value = false),
    })
}

const chosenTotal = computed(() => awardTotal(canvass.value, form.choices))

/* Ordering the award (phase 24.3): one order per winning supplier. */
const ordering = ref(false)

async function orderFromAward() {
    const list = winners.value.map((w) => `${w.name}: ${w.items} item(s), ${money(w.total)}`).join(' · ')
    const sure = await confirmAction({
        title: winners.value.length === 1 ? 'Place 1 purchase order?' : `Place ${winners.value.length} purchase orders?`,
        text: `One for each supplier, at the prices it quoted — ${list}.`,
        confirmText: 'Yes, place them',
    })

    if (!sure) return

    router.post(`/purchase-requests/${props.request.id}/award/order`, {}, {
        preserveScroll: true,
        onStart: () => (ordering.value = true),
        onFinish: () => (ordering.value = false),
    })
}

function cellClass(line, cell) {
    if (!cell) return 'text-ink-subtle'
    if (choosing.value && Number(form.choices[line.line_id]) === Number(cell.quote_line_id)) {
        return 'bg-brand-50 ring-2 ring-brand-500 ring-inset'
    }
    if (!choosing.value && line.award && Number(line.award.quote_line_id) === Number(cell.quote_line_id)) {
        return 'bg-brand-50 ring-2 ring-brand-500 ring-inset'
    }
    if (cell.lowest) return 'bg-success-50'

    return ''
}
</script>

<template>
    <section v-if="canvass" class="card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-3.5">
            <div class="flex items-start gap-2.5">
                <Scale :size="18" class="mt-0.5 text-brand-700" />
                <div>
                    <h3 class="text-sm font-semibold text-ink">Canvass — Abstract of Quotation</h3>
                    <p class="text-xs text-ink-subtle">
                        <template v-if="awarded">
                            Awarded on {{ canvass.awarded_at }}<template v-if="canvass.awarded_by"> by {{ canvass.awarded_by }}</template>.
                        </template>
                        <template v-else-if="canvass.quoted">
                            {{ suppliers.length }} {{ suppliers.length === 1 ? 'supplier has' : 'suppliers have' }} quoted. The lowest price on each item is marked green.
                        </template>
                        <template v-else>
                            No supplier has quoted yet. Print the RFQ, then type in each supplier's prices.
                        </template>
                    </p>
                </div>
            </div>

            <div v-if="!choosing" class="flex flex-wrap items-center gap-2">
                <PrimaryButton v-if="can.quote" variant="secondary" :href="`/purchase-requests/${request.id}/quotes/create`">
                    <Plus :size="15" />
                    Add supplier quote
                </PrimaryButton>
                <PrimaryButton v-if="can.award && canvass.quoted" @click="startAward">
                    <Award :size="15" />
                    Choose suppliers
                </PrimaryButton>
                <PrimaryButton v-if="can.reopenAward" variant="secondary" @click="reopenAward(request)">
                    <RotateCcw :size="15" />
                    Reopen award
                </PrimaryButton>
            </div>
        </div>

        <div v-if="canvass.quoted" class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-muted text-left align-bottom text-2xs font-semibold tracking-wider text-ink-muted uppercase">
                        <th class="px-4 py-2.5 font-semibold">Item</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Qty</th>
                        <th v-for="supplier in suppliers" :key="supplier.quote_id" class="min-w-40 px-3 py-2.5 text-right font-semibold">
                            <div class="flex items-start justify-end gap-1.5">
                                <span class="normal-case tracking-normal text-ink">{{ supplier.name }}</span>
                                <button
                                    v-if="can.quote && !choosing"
                                    type="button"
                                    class="text-ink-subtle transition-colors hover:text-danger-700"
                                    :title="`Remove ${supplier.name}'s quote`"
                                    :aria-label="`Remove ${supplier.name}'s quote`"
                                    @click="removeQuote(request, supplier)"
                                >
                                    <Trash2 :size="13" />
                                </button>
                            </div>
                            <p class="mt-0.5 font-normal normal-case tracking-normal text-ink-subtle">
                                <template v-if="supplier.delivery_days !== null">{{ supplier.delivery_days }} day(s)</template>
                                <template v-if="supplier.delivery_days !== null && supplier.payment_terms"> · </template>
                                <template v-if="supplier.payment_terms">{{ supplier.payment_terms }}</template>
                            </p>
                            <p v-if="supplier.notes" class="font-normal normal-case tracking-normal text-ink-subtle italic">{{ supplier.notes }}</p>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line">
                    <template v-for="line in lines" :key="line.line_id">
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <p class="font-medium text-ink">{{ line.name }}</p>
                                <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                                <p v-if="!line.quoted" class="mt-0.5 text-2xs font-medium text-warning-700">Nobody quoted — left out of the award.</p>
                            </td>
                            <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap text-ink">{{ line.quantity_label }}</td>
                            <td
                                v-for="(cell, column) in line.cells"
                                :key="column"
                                class="px-3 py-3 text-right align-top transition-colors"
                                :class="cellClass(line, cell)"
                            >
                                <template v-if="cell">
                                    <label :class="choosing ? 'cursor-pointer' : ''" class="block">
                                        <span class="flex items-center justify-end gap-2">
                                            <input
                                                v-if="choosing"
                                                v-model="form.choices[line.line_id]"
                                                type="radio"
                                                :name="`choice-${line.line_id}`"
                                                :value="cell.quote_line_id"
                                                class="size-4 accent-brand-600"
                                            />
                                            <span class="tabular-nums font-semibold text-ink">{{ money(cell.unit_price) }}</span>
                                        </span>
                                        <span class="block tabular-nums text-2xs text-ink-muted">{{ money(cell.line_total) }}</span>
                                        <span v-if="cell.lowest" class="mt-0.5 inline-block rounded-full bg-success-100 px-1.5 text-2xs font-semibold text-success-700">Lowest</span>
                                        <span
                                            v-if="!choosing && line.award && Number(line.award.quote_line_id) === Number(cell.quote_line_id)"
                                            class="mt-0.5 ml-1 inline-block rounded-full bg-brand-600 px-1.5 text-2xs font-semibold text-ink-inverse"
                                        >Awarded</span>
                                    </label>
                                </template>
                                <span v-else>—</span>
                            </td>
                        </tr>

                        <!-- Why not the lowest: asked while choosing, shown once awarded -->
                        <tr v-if="choosing && needsReason(line, form.choices[line.line_id])" class="bg-warning-50/50">
                            <td :colspan="2 + suppliers.length" class="px-4 py-2.5">
                                <label class="flex flex-wrap items-center gap-2 text-xs text-ink">
                                    <span class="font-medium">Not the lowest — why this supplier for {{ line.name }}?</span>
                                    <input
                                        v-model="form.reasons[line.line_id]"
                                        type="text"
                                        maxlength="255"
                                        class="h-8 min-w-60 flex-1 rounded-field border border-line bg-surface px-2 text-sm text-ink focus:border-brand-300"
                                        placeholder="For example: delivers in 2 days, the cheaper one in 10"
                                    />
                                </label>
                                <p v-if="form.errors[`reasons.${line.line_id}`]" class="mt-1 text-xs font-medium text-danger-700">{{ form.errors[`reasons.${line.line_id}`] }}</p>
                            </td>
                        </tr>
                        <tr v-if="choosing && form.errors[`choices.${line.line_id}`]">
                            <td :colspan="2 + suppliers.length" class="px-4 py-1.5 text-xs font-medium text-danger-700">{{ form.errors[`choices.${line.line_id}`] }}</td>
                        </tr>
                        <tr v-if="!choosing && line.award?.reason">
                            <td :colspan="2 + suppliers.length" class="px-4 pb-2.5 text-2xs text-ink-muted">
                                Not the lowest — awarded to {{ line.award.supplier }} because: {{ line.award.reason }}
                            </td>
                        </tr>
                    </template>
                </tbody>

                <tfoot>
                    <tr class="border-t border-line bg-surface-muted">
                        <td class="px-4 py-3 text-xs font-semibold text-ink" colspan="2">
                            Total
                            <span class="block font-normal text-ink-subtle">Lowest on every item: {{ money(canvass.lowest_total) }}</span>
                        </td>
                        <td v-for="supplier in suppliers" :key="supplier.quote_id" class="px-3 py-3 text-right">
                            <span class="tabular-nums text-sm font-bold text-ink">{{ money(supplier.total) }}</span>
                            <span class="block text-2xs text-ink-subtle">{{ supplier.items_quoted }} of {{ lines.length }} item(s) · lowest on {{ supplier.lowest_count }}</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Choosing: the total so far, and the two buttons -->
        <div v-if="choosing" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3.5">
            <p class="text-sm text-ink">
                Award total <span class="font-bold tabular-nums">{{ money(chosenTotal) }}</span>
                <span v-if="unquoted.length" class="block text-2xs text-warning-700">{{ unquoted.length }} item(s) nobody quoted stay out of the award.</span>
                <span v-if="form.errors.choices" class="block text-xs font-medium text-danger-700">{{ form.errors.choices }}</span>
            </p>
            <div class="flex items-center gap-2">
                <PrimaryButton variant="secondary" :disabled="form.processing" @click="choosing = false">Cancel</PrimaryButton>
                <PrimaryButton :loading="form.processing" @click="submitAward">
                    <Award :size="15" />
                    Approve award
                </PrimaryButton>
            </div>
        </div>

        <!-- Awarded: who won what -->
        <div v-else-if="awarded" class="border-t border-line px-5 py-3.5 text-sm">
            <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">Awarded</p>
            <ul class="mt-1.5 space-y-1">
                <li v-for="winner in winners" :key="winner.name" class="flex justify-between gap-3">
                    <span class="text-ink">{{ winner.name }} <span class="text-ink-subtle">— {{ winner.items }} item(s)</span></span>
                    <span class="tabular-nums font-semibold text-ink">{{ money(winner.total) }}</span>
                </li>
            </ul>
            <p class="mt-2 flex justify-between border-t border-line pt-2 text-sm font-bold text-ink">
                <span>Award total</span><span class="tabular-nums">{{ money(canvass.awarded_total) }}</span>
            </p>
            <div v-if="!request.orders?.length" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-2xs text-ink-subtle">Next, the purchase orders are made from this award: one for each supplier, at these prices.</p>
                <PrimaryButton v-if="can.orderFromAward" :loading="ordering" @click="orderFromAward">
                    <FileText :size="15" />
                    Create orders from award
                </PrimaryButton>
            </div>
            <p v-else class="mt-2 text-2xs text-ink-subtle">
                Ordered on
                <template v-for="(order, index) in request.orders" :key="order.id">
                    <span v-if="index > 0">, </span>
                    <Link v-if="can.viewOrders" :href="`/purchase-orders/${order.id}`" class="font-mono font-semibold text-brand-700 hover:underline">{{ order.reference }}</Link>
                    <span v-else class="font-mono font-semibold">{{ order.reference }}</span>
                </template>.
            </p>
        </div>

        <p v-else-if="!canvass.quoted && can.quote" class="px-5 py-8 text-center text-sm text-ink-subtle">
            Hand each supplier the Request for Quotation, then press <span class="font-medium text-ink">Add supplier quote</span> for every reply.
        </p>
    </section>
</template>
