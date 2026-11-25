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
 * Money a customer brings in against what they owe.
 *
 * Pick the customer, type what they paid, and the form spreads it over
 * their invoices soonest due first. Any row can be changed by hand; once one
 * is, the form stops spreading until "Spread oldest first" is pressed again.
 * The server weighs it all again and decides (`PaymentAllocation`).
 */
defineOptions({ layout: AppLayout })

const props = defineProps({
    customers: { type: Array, default: () => [] },
    customerId: { type: [Number, null], default: null },
    account: { type: Object, default: null },
    invoices: { type: Array, default: () => [] },
    paymentMethods: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    idempotencyKey: { type: String, default: '' },
})

const { money } = useMoney()

const form = useForm({
    customer_id: props.customerId ?? '',
    payment_date: props.today,
    payment_method_id: props.paymentMethods[0]?.value ?? '',
    method_reference: '',
    amount: '',
    notes: '',
    idempotency_key: props.idempotencyKey,
})

/** What each invoice takes, by sale id, in pesos as typed. */
const rows = ref({})

/** Spread automatically until somebody types into a row. */
const spreading = ref(true)

const method = computed(() =>
    props.paymentMethods.find((m) => String(m.value) === String(form.payment_method_id)) ?? null
)

const owedCents = computed(() => props.invoices.reduce((sum, invoice) => sum + centavos(invoice.balance_due), 0))
const overdueCents = computed(() =>
    props.invoices.filter((i) => i.overdue).reduce((sum, invoice) => sum + centavos(invoice.balance_due), 0)
)

const weighed = computed(() => weighAllocations(form.amount, rows.value, props.invoices))

function spread() {
    rows.value = fillOldestFirst(form.amount, props.invoices)
    spreading.value = true
}

function typed(id, value) {
    rows.value = { ...rows.value, [id]: value }
    spreading.value = false
}

function payInFull() {
    form.amount = pesos(owedCents.value)
    spread()
}

watch(() => form.amount, () => {
    if (spreading.value) spread()
})

// A different customer: fetch only their invoices, and start again.
watch(
    () => form.customer_id,
    (id) => {
        if (String(id) === String(props.customerId ?? '')) return

        router.reload({
            only: ['customerId', 'account', 'invoices'],
            data: { customer: id || undefined },
            preserveState: true,
            onSuccess: () => {
                form.amount = ''
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

const headerFields = ['customer_id', 'payment_date', 'payment_method_id', 'method_reference', 'amount', 'notes']

/** Server errors on a row arrive as allocations.N.amount; N is its place in what was sent. */
const sentIds = ref([])

function rowError(id) {
    const index = sentIds.value.indexOf(id)

    if (index !== -1) {
        const server = form.errors[`allocations.${index}.amount`] ?? form.errors[`allocations.${index}.sale_id`]

        if (server) return server
    }

    return weighed.value.errors[id] ?? null
}

async function submit() {
    const allocations = props.invoices
        .filter((invoice) => centavos(rows.value[invoice.id]) !== 0)
        .map((invoice) => ({ sale_id: invoice.id, amount: rows.value[invoice.id] }))

    const agreed = await confirmAction({
        icon: 'question',
        title: 'Record this payment?',
        text: `${money(form.amount || 0)} comes off ${allocations.length} invoice${allocations.length === 1 ? '' : 's'} at once. A recorded payment cannot be edited afterwards, only voided.`,
        confirmText: 'Yes, record it',
    })

    if (!agreed) return

    sentIds.value = allocations.map((a) => a.sale_id)

    form
        .transform((data) => ({ ...data, allocations }))
        .post('/customer-payments', {
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
            heading="Record a customer payment"
            subheading="Money a customer pays against what they owe. It comes off their invoices at once."
            back-href="/customer-payments"
            back-label="Back to customer payments"
        >
            <template #actions>
                <PrimaryButton variant="secondary" href="/customer-payments">Cancel</PrimaryButton>
                <PrimaryButton type="submit" :loading="form.processing" :disabled="!weighed.ready">
                    Record payment
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="card p-5 sm:p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Customer" for="customer_id" required :error="form.errors.customer_id"
                        hint="Only the customers who owe something are listed.">
                        <SelectField
                            id="customer_id"
                            v-model="form.customer_id"
                            :options="customers"
                            :invalid="!!form.errors.customer_id"
                            placeholder="Pick the customer who paid"
                        />
                    </FormField>

                    <FormField label="Date paid" for="payment_date" required :error="form.errors.payment_date"
                        hint="Not in the future, and not before the invoices it pays.">
                        <TextField
                            id="payment_date"
                            v-model="form.payment_date"
                            type="date"
                            :max="today"
                            :invalid="!!form.errors.payment_date"
                        />
                    </FormField>

                    <FormField label="Paid by" for="payment_method_id" required :error="form.errors.payment_method_id">
                        <SelectField
                            id="payment_method_id"
                            v-model="form.payment_method_id"
                            :options="paymentMethods"
                            :invalid="!!form.errors.payment_method_id"
                            placeholder="Pick how they paid"
                        />
                    </FormField>

                    <FormField
                        label="Reference number"
                        for="method_reference"
                        :required="method?.reference ?? false"
                        :error="form.errors.method_reference"
                        :hint="method?.reference ? `The ${method.label} reference the customer gives.` : 'Optional, for example a cheque or deposit slip number.'"
                    >
                        <TextField
                            id="method_reference"
                            v-model="form.method_reference"
                            maxlength="100"
                            :invalid="!!form.errors.method_reference"
                        />
                    </FormField>

                    <FormField label="Amount paid" for="amount" required :error="form.errors.amount">
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

                    <div v-if="account" class="grid grid-cols-2 gap-3 self-end">
                        <div class="rounded-field bg-surface-sunken p-3">
                            <p class="text-2xs text-ink-muted">Owes now</p>
                            <p class="mt-0.5 tabular-nums text-sm font-semibold text-ink">{{ money(account.balance) }}</p>
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
                    This records money received. It is not an official receipt.
                </p>
            </section>

            <!-- The invoices it pays -->
            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Invoices it pays</h3>
                        <p class="text-xs text-ink-subtle">Soonest due first. No invoice can take more than it owes.</p>
                    </div>
                    <div v-if="invoices.length" class="flex flex-wrap gap-2">
                        <PrimaryButton variant="secondary" @click="payInFull">
                            Pay everything ({{ money(owedCents / 100) }})
                        </PrimaryButton>
                        <PrimaryButton variant="secondary" :disabled="!form.amount" @click="spread">
                            <WandSparkles :size="14" />
                            Spread oldest first
                        </PrimaryButton>
                    </div>
                </div>

                <p v-if="!form.customer_id" class="px-5 py-8 text-center text-sm text-ink-subtle sm:px-6">
                    Pick a customer to see what they owe.
                </p>
                <p v-else-if="!invoices.length" class="px-5 py-8 text-center text-sm text-ink-subtle sm:px-6">
                    This customer owes nothing.
                </p>

                <div v-else>
                    <div
                        class="hidden border-b border-line bg-surface-muted px-5 py-2 text-2xs font-semibold tracking-wider text-ink-muted uppercase sm:grid sm:grid-cols-[1fr_7rem_7rem_8rem_9rem] sm:gap-3 sm:px-6"
                    >
                        <span>Invoice</span>
                        <span>Due</span>
                        <span class="text-right">On account</span>
                        <span class="text-right">Still owed</span>
                        <span class="text-right">Pay now</span>
                    </div>

                    <div class="divide-y divide-line">
                        <div
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            class="grid gap-2 px-5 py-3 sm:grid-cols-[1fr_7rem_7rem_8rem_9rem] sm:items-center sm:gap-3 sm:px-6"
                        >
                            <div class="min-w-0">
                                <p class="font-mono text-xs font-semibold text-ink">{{ invoice.reference }}</p>
                                <p class="text-2xs text-ink-subtle">Sold {{ invoice.sale_date }}</p>
                            </div>
                            <p class="text-sm" :class="invoice.overdue ? 'font-semibold text-warning-700' : 'text-ink-muted'">
                                <TriangleAlert v-if="invoice.overdue" :size="12" class="mr-0.5 inline -translate-y-px" />
                                {{ invoice.due_date ?? '—' }}
                            </p>
                            <p class="tabular-nums text-sm text-ink-muted sm:text-right">{{ money(invoice.on_account) }}</p>
                            <p class="tabular-nums text-sm font-semibold text-ink sm:text-right">{{ money(invoice.balance_due) }}</p>
                            <div>
                                <input
                                    :value="rows[invoice.id] ?? ''"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    :aria-label="`Pay now on ${invoice.reference}`"
                                    :aria-invalid="rowError(invoice.id) ? 'true' : undefined"
                                    class="h-9 w-full rounded-field border bg-surface px-2.5 text-right text-sm font-semibold tabular-nums text-ink focus:border-brand-400 focus:outline-none"
                                    :class="rowError(invoice.id) ? 'border-danger-500/60' : 'border-line-strong'"
                                    @input="typed(invoice.id, $event.target.value)"
                                    @focus="$event.target.select()"
                                />
                                <p v-if="rowError(invoice.id)" class="mt-1 text-2xs font-medium text-danger-700">
                                    {{ rowError(invoice.id) }}
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
                            Against the invoices: <span class="font-semibold tabular-nums text-ink">{{ money(weighed.placed / 100) }}</span>
                            of {{ money(centavos(form.amount) / 100) }}
                        </span>
                        <span v-if="weighed.ready" class="font-semibold text-success-700">Every peso is placed.</span>
                        <span v-else-if="weighed.left" class="font-semibold text-warning-700">
                            {{ money(weighed.left / 100) }} is not against any invoice yet.
                        </span>
                        <span v-else-if="weighed.over" class="font-semibold text-warning-700">
                            The invoices come to {{ money(weighed.over / 100) }} more than the payment.
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
