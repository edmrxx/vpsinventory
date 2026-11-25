<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { TriangleAlert, WandSparkles } from '@lucide/vue'
import AppLayout from '../../Layouts/AppLayout.vue'
import PageHeader from '../../Components/PageHeader.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import FormField from '../../Components/FormField.vue'
import SelectField from '../../Components/SelectField.vue'
import TextField from '../../Components/TextField.vue'
import TextareaField from '../../Components/TextareaField.vue'
import { confirmAction, showValidationErrors } from '../../lib/swal'
import { useMoney } from '../../lib/money'
import { centavos, pesos } from '../../lib/payments'
import { fillOldestFirst, weighAllocations } from '../../lib/allocations'

/**
 * Money the shop pays a supplier against its bills (phase 24).
 *
 * Pick the supplier, type what is paid — and any supplier credit to use
 * with it — and the form spreads the two together over the bills soonest
 * due first. Any row can be changed by hand; once one is, the form stops
 * spreading until "Spread oldest first" is pressed again. The server weighs
 * it all again and decides (`BillAllocation`).
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    supplierId: { type: [Number, null], default: null },
    account: { type: Object, default: null },
    bills: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
})

const { money } = useMoney()

const form = useForm({
    supplier_id: props.supplierId ?? '',
    payment_date: props.today,
    payment_method_id: props.paymentMethods[0]?.value ?? '',
    method_reference: '',
    amount: '',
    // Supplier credit, from goods sent back, put towards the same bills.
    credit_used: '',
    notes: '',
    idempotency_key: props.idempotencyKey,
})

/** What each bill takes, by purchase id, in pesos as typed. */
const rows = ref({})

/** Spread automatically until somebody types into a row. */
const spreading = ref(true)

const method = computed(() =>
    props.paymentMethods.find((m) => String(m.value) === String(form.payment_method_id)) ?? null
)

const owedCents = computed(() => props.bills.reduce((sum, bill) => sum + centavos(bill.balance_due), 0))
const overdueCents = computed(() =>
    props.bills.filter((b) => b.overdue).reduce((sum, bill) => sum + centavos(bill.balance_due), 0)
)

const creditCents = computed(() => (props.account ? centavos(props.account.credit) : 0))

/** Money and credit together: what the bills must add up to. */
const paying = computed(() => pesos(Math.max(centavos(form.amount), 0) + Math.max(centavos(form.credit_used), 0)))

const weighed = computed(() => weighAllocations(paying.value, rows.value, props.bills))

/** A method is needed only when money goes out. */
const paysMoney = computed(() => centavos(form.amount) > 0)

function spread() {
    rows.value = fillOldestFirst(paying.value, props.bills)
    spreading.value = true
}

function useAllCredit() {
    form.credit_used = pesos(Math.min(creditCents.value, owedCents.value))
}

function typed(id, value) {
    rows.value = { ...rows.value, [id]: value }
    spreading.value = false
}

function payInFull() {
    form.amount = pesos(Math.max(owedCents.value - Math.max(centavos(form.credit_used), 0), 0))
    spread()
}

watch(() => [form.amount, form.credit_used], () => {
    if (spreading.value) spread()
})

// A different supplier: fetch only its bills, and start again.
watch(
    () => form.supplier_id,
    (id) => {
        if (String(id) === String(props.supplierId ?? '')) return

        router.reload({
            only: ['supplierId', 'account', 'bills'],
            data: { supplier: id || undefined },
            preserveState: true,
            onSuccess: () => {
                form.amount = ''
                form.credit_used = ''
                rows.value = {}
                spreading.value = true
            },
        })
    }
)

watch(
    () => props.idempotencyKey,
    (key) => {
        if (key) form.idempotency_key = key
    }
)

const headerFields = ['supplier_id', 'payment_date', 'payment_method_id', 'method_reference', 'amount', 'credit_used', 'notes']

/** Server errors on a row arrive as allocations.N.amount; N is its place in what was sent. */
const sentIds = ref([])

function rowError(id) {
    const index = sentIds.value.indexOf(id)

    if (index !== -1) {
        const server = form.errors[`allocations.${index}.amount`] ?? form.errors[`allocations.${index}.purchase_id`]

        if (server) return server
    }

    return weighed.value.errors[id] ?? null
}

async function submit() {
    const allocations = props.bills
        .filter((bill) => centavos(rows.value[bill.id]) !== 0)
        .map((bill) => ({ purchase_id: bill.id, amount: rows.value[bill.id] }))

    const agreed = await confirmAction({
        icon: 'question',
        title: 'Record this payment?',
        text: `${money(paying.value || 0)} comes off ${allocations.length} bill${allocations.length === 1 ? '' : 's'} at once. A recorded payment cannot be edited afterwards, only voided.`,
        confirmText: 'Yes, record it',
    })

    if (!agreed) return

    sentIds.value = allocations.map((a) => a.purchase_id)

    form
        .transform((data) => ({
            ...data,
            // No money out, no method: credit alone needs none.
            payment_method_id: centavos(data.amount) > 0 ? data.payment_method_id : '',
            method_reference: centavos(data.amount) > 0 ? data.method_reference : '',
            allocations,
        }))
        .post('/supplier-payments', {
            onError: (errors) => {
                const loose = Object.entries(errors)
                    .filter(([key]) => !headerFields.includes(key) && !key.startsWith('allocations.'))
                    .map(([, message]) => message)

                if (loose.length) showValidationErrors(loose)
            },
        })
}
</script>

<template>
    <form @submit.prevent="submit">
        <PageHeader
            heading="Pay a supplier"
            subheading="Money the shop pays against what it owes a supplier. It comes off the bills at once."
            back-href="/supplier-payments"
            back-label="Back to supplier payments"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/supplier-payments">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing" :disabled="!weighed.ready">
                    Record payment
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Supplier" for="supplier_id" required :error="form.errors.supplier_id"
                        hint="Only the suppliers the shop owes are listed.">
                        <SelectField
                            id="supplier_id"
                            v-model="form.supplier_id"
                            :options="suppliers"
                            :invalid="!!form.errors.supplier_id"
                            placeholder="Pick the supplier being paid"
                        />
                    </FormField>

                    <FormField label="Date paid" for="payment_date" required :error="form.errors.payment_date"
                        hint="Not in the future, and not before the bills it pays.">
                        <TextField
                            id="payment_date"
                            v-model="form.payment_date"
                            type="date"
                            :max="today"
                            :invalid="!!form.errors.payment_date"
                        />
                    </FormField>

                    <FormField label="Amount paid" for="amount" :required="!creditCents" :error="form.errors.amount"
                        hint="The money that goes out. Leave it empty to pay with supplier credit alone.">
                        <TextField
                            id="amount"
                            v-model="form.amount"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            :invalid="!!form.errors.amount"
                        />
                    </FormField>

                    <FormField v-if="creditCents > 0" label="Supplier credit to use" for="credit_used" :error="form.errors.credit_used"
                        :hint="`From goods sent back. This supplier has ${money(creditCents / 100)} to use.`">
                        <div class="flex gap-2">
                            <TextField
                                id="credit_used"
                                v-model="form.credit_used"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                :invalid="!!form.errors.credit_used"
                            />
                            <PrimaryButton variant="secondary" class="shrink-0 whitespace-nowrap" @click="useAllCredit">Use it</PrimaryButton>
                        </div>
                    </FormField>

                    <FormField v-if="paysMoney" label="Paid by" for="payment_method_id" required :error="form.errors.payment_method_id">
                        <SelectField
                            id="payment_method_id"
                            v-model="form.payment_method_id"
                            :options="paymentMethods"
                            :invalid="!!form.errors.payment_method_id"
                            placeholder="Pick how it was paid"
                        />
                    </FormField>

                    <FormField
                        v-if="paysMoney"
                        label="Reference number"
                        for="method_reference"
                        :required="method?.reference ?? false"
                        :error="form.errors.method_reference"
                        :hint="method?.reference ? `The ${method.label} reference of the transfer.` : 'Optional, for example a cheque number.'"
                    >
                        <TextField
                            id="method_reference"
                            v-model="form.method_reference"
                            maxlength="100"
                            :invalid="!!form.errors.method_reference"
                        />
                    </FormField>

                    <div v-if="account" class="grid grid-cols-2 gap-3 self-end">
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Owed now</p>
                            <p class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(account.owed) }}</p>
                        </div>
                        <div class="rounded-field p-3" :class="overdueCents > 0 ? 'bg-warning-50' : 'bg-surface-sunken'">
                            <p class="text-2xs" :class="overdueCents > 0 ? 'text-warning-700' : 'text-ink-muted'">Past due</p>
                            <p class="mt-0.5 tabular-nums text-sm font-semibold"
                                :class="overdueCents > 0 ? 'text-warning-700' : 'text-ink'">
                                {{ money(overdueCents / 100) }}
                            </p>
                        </div>
                    </div>
                </div>

                <p class="mt-5 text-xs text-ink-subtle">
                    Reference number: <span class="font-medium text-ink-muted">assigned when recorded</span>.
                </p>
            </section>

            <!-- The bills it pays -->
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Bills it pays</h3>
                        <p class="text-xs text-ink-subtle">Soonest due first. No bill can take more than it owes.</p>
                    </div>
                    <div v-if="bills.length" class="flex flex-wrap gap-2">
                        <PrimaryButton variant="secondary" @click="payInFull">
                            Pay everything ({{ money(owedCents / 100) }})
                        </PrimaryButton>
                        <PrimaryButton variant="secondary" :disabled="centavos(paying) <= 0" @click="spread">
                            <WandSparkles :size="14" />
                            Spread oldest first
                        </PrimaryButton>
                    </div>
                </div>

                <p v-if="!form.supplier_id" class="px-5 py-8 text-center text-sm text-ink-subtle sm:px-6">
                    Pick a supplier to see what the shop owes it.
                </p>
                <p v-else-if="!bills.length" class="px-5 py-8 text-center text-sm text-ink-subtle sm:px-6">
                    Nothing is owed to this supplier.
                </p>

                <div v-else>
                    <div
                        class="hidden border-b border-line bg-surface-muted px-5 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_7rem_7rem_8rem_9rem] sm:gap-3 sm:px-6"
                    >
                        <span>Bill</span>
                        <span>Due</span>
                        <span class="text-right">On account</span>
                        <span class="text-right">Still owed</span>
                        <span class="text-right">Pay now</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="bill in bills"
                            :key="bill.id"
                            class="grid gap-2 px-5 py-3 sm:grid-cols-[1fr_7rem_7rem_8rem_9rem] sm:items-center sm:gap-3 sm:px-6"
                        >
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-ink">{{ bill.reference }}</p>
                                <p class="text-2xs text-ink-subtle">
                                    Delivered {{ bill.purchase_date }}<template v-if="bill.supplier_reference"> · invoice {{ bill.supplier_reference }}</template>
                                </p>
                            </div>
                            <p class="text-sm" :class="bill.overdue ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                <TriangleAlert v-if="bill.overdue" :size="12" class="mr-0.5 inline -translate-y-px" />
                                {{ bill.due_date ?? '—' }}
                            </p>
                            <p class="tabular-nums text-sm text-ink-muted sm:text-right">{{ money(bill.on_account) }}</p>
                            <p class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(bill.balance_due) }}</p>
                            <div>
                                <input
                                    :value="rows[bill.id] ?? ''"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    :aria-label="`Pay now on ${bill.reference}`"
                                    :aria-invalid="rowError(bill.id) ? 'true' : undefined"
                                    class="h-9 w-full rounded-field border bg-surface px-2.5 text-right text-sm font-semibold tabular-nums text-ink focus:border-brand-400 focus:outline-none"
                                    :class="rowError(bill.id) ? 'border-danger-500/60' : 'border-line-strong'"
                                    @input="typed(bill.id, $event.target.value)"
                                    @focus="$event.target.select()"
                                />
                                <p v-if="rowError(bill.id)" class="mt-1 text-2xs font-medium text-danger-700">
                                    {{ rowError(bill.id) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Where the payment stands -->
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-sm sm:px-6"
                        :class="weighed.ready ? 'bg-success-50' : (weighed.left || weighed.over) ? 'bg-warning-50' : 'bg-surface-sunken'"
                    >
                        <span class="text-ink-muted">
                            Against the bills: <span class="font-semibold tabular-nums text-ink">{{ money(weighed.placed / 100) }}</span>
                            of {{ money(centavos(paying) / 100) }}
                        </span>
                        <span v-if="weighed.ready" class="font-semibold text-success-700">Every peso is placed.</span>
                        <span v-else-if="weighed.left" class="font-semibold text-warning-700">
                            {{ money(weighed.left / 100) }} is not against any bill yet.
                        </span>
                        <span v-else-if="weighed.over" class="font-semibold text-warning-700">
                            The bills come to {{ money(weighed.over / 100) }} more than the payment.
                        </span>
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="border-t border-line px-5 py-3 text-xs font-medium text-danger-700 sm:px-6">
                    {{ form.errors.allocations }}
                </p>
            </section>

            <section class="card p-5 sm:p-6">
                <FormField label="Notes" for="notes" :error="form.errors.notes">
                    <TextareaField id="notes" v-model="form.notes" :rows="3" maxlength="1000" />
                </FormField>
            </section>
        </div>
    </form>
</template>
