<script setup>
import { computed, ref, watch } from 'vue'
import { Banknote, ClipboardList, CreditCard, Landmark, Smartphone, Wallet, X } from '@lucide/vue'
import { agreedTerms, centavos, daysLabel, dueDateLabel, likelyNotes, pesos, settleSale } from '../lib/payments'
import { useMoney } from '../lib/money'

/**
 * How the customer pays: cash, GCash, a bank transfer, or several at once.
 *
 * Used by the till and by the New sale form, so both take a payment the
 * same way. Tap a method to add it; tap it again to take it off. Cash is
 * what was handed over, and the change shows at once. Everything else is
 * what was paid that way, with its reference number.
 *
 * A customer with credit also gets "On account": how much of the sale is not
 * paid now, and in how many days it falls due — agreed on this sale, starting
 * on the customer's usual terms (phase 18.1). The rest is paid as usual,
 * without change.
 *
 * The figures come from `settleSale()` in `lib/payments.js`, the same sums
 * the server works out again when the sale posts.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    /** What the sale comes to, in pesos. */
    total: { type: Number, default: 0 },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    /** Thumb-sized, for the till. */
    touch: { type: Boolean, default: false },
    /** How much goes on account, in pesos as typed. Empty is none. */
    onAccount: { type: [String, Number], default: '' },
    /** The picked customer's credit, or null when they have none. */
    account: { type: Object, default: null },
    /** The days agreed for what goes on account, as typed. Empty is the customer's usual. */
    termsDays: { type: [String, Number], default: '' },
    /** May this user give more days than the customer's usual terms? */
    mayExtendTerms: { type: Boolean, default: false },
    /** The sale's date, Y-m-d, for the due date shown. Empty is today. */
    saleDate: { type: String, default: '' },
    /**
     * Said where "On account" would be when the customer picked has no
     * credit, so a missing button is never a puzzle. Empty says nothing.
     */
    creditHint: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'update:onAccount', 'update:termsDays'])

const { money } = useMoney()

const totalCents = computed(() => centavos(props.total))

const rows = computed(() => props.modelValue ?? [])

const methodOf = (id) => props.methods.find((method) => String(method.value) === String(id))

const terms = computed(() => ({ days: props.termsDays, mayExtend: props.mayExtendTerms }))

const settled = computed(() =>
    settleSale(totalCents.value, props.onAccount, rows.value, props.methods, props.account, terms.value)
)

/** Is this sale going on account at all? An empty string is no. */
const accountPicked = computed(() => props.onAccount !== '' && props.onAccount !== null)

/** What is left to pay now, in centavos, once the account has taken its part. */
const payableCents = computed(() => Math.max(totalCents.value - centavos(props.onAccount), 0))

const isCashRow = (row) => !!methodOf(row.payment_method_id)?.cash

/** What the payments other than cash come to, in centavos. */
const othersCents = computed(() =>
    rows.value.filter((row) => !isCashRow(row)).reduce((sum, row) => sum + Math.max(centavos(row.amount), 0), 0)
)

/**
 * Until the cashier types in it, the cash box shows what is owed in cash:
 * the sale less everything paid some other way. Type ₱1,000 in GCash and the
 * cash drops to ₱850 by itself; type what the customer handed over and it
 * stays put, and the change shows.
 */
const cashFollows = ref(true)

/** A sale paid one way only, not in cash, follows the total until typed in. */
const soleFollows = ref(true)

watch(
    [payableCents, othersCents, () => rows.value.length],
    () => {
        const cashIndex = rows.value.findIndex(isCashRow)

        if (cashIndex >= 0 && cashFollows.value) {
            const owed = Math.max(payableCents.value - othersCents.value, 0)

            if (centavos(rows.value[cashIndex].amount) !== owed) update(cashIndex, { amount: pesos(owed) })
        } else if (cashIndex < 0 && rows.value.length === 1 && soleFollows.value) {
            if (centavos(rows.value[0].amount) !== payableCents.value) update(0, { amount: pesos(payableCents.value) })
        }
    }
)

/**
 * Put the sale on account, for as much as is not already paid another way
 * and the credit will take; or take it off again. The cash follows either
 * way, so it never asks for more than is left.
 */
function toggleAccount() {
    if (props.disabled || !props.account) return

    cashFollows.value = true

    if (accountPicked.value) {
        emit('update:onAccount', '')
        emit('update:termsDays', '')

        return
    }

    // Every sale starts on the customer's usual terms, there to be changed.
    emit('update:termsDays', props.account.terms_days ?? 0)

    const notCovered = Math.max(totalCents.value - othersCents.value, 0)

    emit('update:onAccount', pesos(Math.min(notCovered, centavos(props.account.available))))
}

function update(index, changes) {
    emit(
        'update:modelValue',
        rows.value.map((row, at) => (at === index ? { ...row, ...changes } : row))
    )
}

function isPicked(method) {
    return rows.value.some((row) => String(row.payment_method_id) === String(method.value))
}

/**
 * Add a way to pay, at what is still owed; or take it off again.
 *
 * The last one left cannot be taken off: a sale is always paid somehow.
 */
function toggle(method) {
    if (props.disabled) return

    if (isPicked(method)) {
        if (rows.value.length === 1) return

        emit(
            'update:modelValue',
            rows.value.filter((row) => String(row.payment_method_id) !== String(method.value))
        )

        return
    }

    const cashWaiting = rows.value.some(isCashRow) && cashFollows.value

    if (method.cash) cashFollows.value = true

    emit('update:modelValue', [
        ...rows.value,
        {
            payment_method_id: method.value,
            // With cash still following, the new box starts empty for the
            // cashier to type, and the cash makes up the rest. Otherwise it
            // starts at whatever is still owed.
            amount: !method.cash && cashWaiting ? '' : pesos(method.cash ? Math.max(payableCents.value - othersCents.value, 0) : settled.value.due),
            reference: '',
        },
    ])
}

function typeAmount(index, value) {
    if (isCashRow(rows.value[index])) {
        cashFollows.value = false
    } else {
        soleFollows.value = false
    }

    update(index, { amount: value })
}

function remove(index) {
    if (rows.value.length === 1) return

    emit(
        'update:modelValue',
        rows.value.filter((_, at) => at !== index)
    )
}

/** What a cash line is still owed: the sale less the other payments. */
const cashOwed = computed(() => settled.value.cashOwed)

// Nobody gets change on a sale still owing, so a bigger note is no help.
const notes = computed(() => (accountPicked.value ? [] : likelyNotes(cashOwed.value)))

function hand(index, cents) {
    cashFollows.value = false
    update(index, { amount: pesos(cents) })
}

function iconFor(method) {
    switch (method?.kind) {
        case 'cash':
            return Banknote
        case 'ewallet':
            return Smartphone
        case 'bank':
            return Landmark
        case 'card':
            return CreditCard
        default:
            return Wallet
    }
}

const errorFor = (index, field) => props.errors[`payments.${index}.${field}`]

/** The server's word over the screen's, once it has spoken. */
const generalError = computed(() => props.errors.payments)

const accountError = computed(() => props.errors.on_account ?? settled.value.errors.on_account)

const termsError = computed(() => props.errors.terms_days ?? settled.value.errors.terms_days)

/** The days this sale is given: as typed, or the usual when the box is empty. */
const agreedDays = computed(() => agreedTerms(props.account, props.termsDays))

const usualDays = computed(() => Number(props.account?.terms_days ?? 0))

/** Today, Y-m-d, in the browser's own day. */
function today() {
    const now = new Date()

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const dueLabel = computed(() => (termsError.value ? null : dueDateLabel(props.saleDate || today(), agreedDays.value)))

/**
 * Why the sale cannot be completed yet, said quietly before anybody presses
 * anything — "Type the GCash reference number." — so a grey button is never
 * a puzzle. Left out while the server has its own word to say, and while
 * the box below already says what is still to pay.
 */
const notYet = computed(() => {
    if (settled.value.ready || settled.value.due > 0) return null
    if (Object.keys(props.errors).some((key) => key.startsWith('payments'))) return null

    // The account box says its own.
    return Object.entries(settled.value.errors).find(([key]) => key !== 'on_account' && key !== 'terms_days')?.[1] ?? null
})

const chip = computed(() => (props.touch ? 'h-11 px-4 text-sm' : 'h-9 px-3 text-xs'))
const box = computed(() => (props.touch ? 'h-12 text-base' : 'h-10 text-sm'))
</script>

<template>
    <div class="space-y-3">
        <!-- Which ways it is paid. More than one is a split payment. -->
        <div class="flex flex-wrap gap-2" role="group" aria-label="Paid by">
            <button
                v-for="method in methods"
                :key="method.value"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                :class="[
                    chip,
                    isPicked(method)
                        ? 'bg-brand-600 text-ink-inverse shadow-sm'
                        : 'bg-surface-sunken text-ink-muted hover:text-ink',
                ]"
                :aria-pressed="isPicked(method)"
                :disabled="disabled"
                @click="toggle(method)"
            >
                <component :is="iconFor(method)" :size="touch ? 16 : 14" />
                {{ method.label }}
            </button>

            <button
                v-if="account"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full font-semibold transition disabled:cursor-not-allowed disabled:opacity-50"
                :class="[
                    chip,
                    accountPicked ? 'bg-brand-600 text-ink-inverse shadow-sm' : 'bg-surface-sunken text-ink-muted hover:text-ink',
                ]"
                :aria-pressed="accountPicked"
                :disabled="disabled"
                @click="toggleAccount"
            >
                <ClipboardList :size="touch ? 16 : 14" />
                On account
            </button>
        </div>

        <p v-if="methods.length === 0" class="text-xs font-medium text-danger-700">
            No payment method is switched on. Add one under Payment Methods.
        </p>

        <!-- Why there is no "On account" for this customer. -->
        <p v-if="!account && creditHint" class="text-2xs text-ink-subtle" data-credit-hint>{{ creditHint }}</p>

        <!-- What is not paid now -->
        <div v-if="accountPicked && account" class="rounded-field border border-line p-3">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-ink">
                <ClipboardList :size="14" class="text-ink-subtle" />
                On account
            </p>

            <div class="mt-2 grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-2">
                <label class="block">
                    <span class="text-2xs font-medium text-ink-muted">Amount</span>
                    <input
                        :value="onAccount"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        :disabled="disabled"
                        :aria-invalid="accountError ? 'true' : undefined"
                        class="mt-1 w-full rounded-field border bg-surface px-3 text-right font-semibold tabular-nums text-ink focus:border-brand-400 focus:outline-none"
                        :class="[box, accountError ? 'border-danger-500/60' : 'border-line-strong']"
                        placeholder="0.00"
                        @input="emit('update:onAccount', $event.target.value)"
                        @focus="$event.target.select()"
                    />
                </label>

                <!-- The days agreed on this sale (phase 18.1). -->
                <label class="block">
                    <span class="text-2xs font-medium text-ink-muted">Terms (days)</span>
                    <input
                        :value="termsDays"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        :max="mayExtendTerms ? 365 : usualDays"
                        step="1"
                        :disabled="disabled"
                        :aria-invalid="termsError ? 'true' : undefined"
                        class="mt-1 w-full rounded-field border bg-surface px-3 text-right font-semibold tabular-nums text-ink focus:border-brand-400 focus:outline-none"
                        :class="[box, termsError ? 'border-danger-500/60' : 'border-line-strong']"
                        :placeholder="String(usualDays)"
                        data-terms-days
                        @input="emit('update:termsDays', $event.target.value)"
                        @focus="$event.target.select()"
                    />
                </label>
            </div>

            <p class="mt-1.5 text-2xs text-ink-subtle" data-terms-summary>
                {{ money(account.available) }} of credit left<template v-if="dueLabel">
                    · <span class="whitespace-nowrap">due {{ dueLabel }} ({{ daysLabel(agreedDays) }})</span></template><template
                    v-if="agreedDays !== usualDays"
                > · <span class="whitespace-nowrap">usual {{ daysLabel(usualDays) }}</span></template>
            </p>
            <p v-if="termsError" class="mt-1.5 text-xs font-medium text-danger-700">{{ termsError }}</p>
            <!-- Said, not refused: within the limit the sale may still go on account. -->
            <p v-if="Number(account.overdue ?? 0) > 0" class="mt-1.5 text-2xs font-semibold text-warning-700">
                {{ money(account.overdue) }} of what this customer owes is past due.
            </p>
            <p v-if="accountError" class="mt-1.5 text-xs font-medium text-danger-700">{{ accountError }}</p>
        </div>

        <!-- One box per way paid. Nothing to take when it is all on account. -->
        <div
            v-for="(row, index) in payableCents > 0 ? rows : []"
            :key="`${row.payment_method_id}-${index}`"
            class="rounded-field border border-line p-3"
        >
            <div class="flex items-center justify-between gap-2">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-ink">
                    <component :is="iconFor(methodOf(row.payment_method_id))" :size="14" class="text-ink-subtle" />
                    {{ methodOf(row.payment_method_id)?.label ?? 'Pick a method' }}
                </p>
                <button
                    v-if="rows.length > 1"
                    type="button"
                    class="grid size-7 place-items-center rounded-full text-ink-subtle transition hover:bg-danger-50 hover:text-danger-700"
                    :aria-label="`Take ${methodOf(row.payment_method_id)?.label ?? 'this payment'} off`"
                    :disabled="disabled"
                    @click="remove(index)"
                >
                    <X :size="14" />
                </button>
            </div>

            <div class="mt-2 grid gap-2" :class="methodOf(row.payment_method_id)?.cash ? '' : 'sm:grid-cols-2'">
                <label class="block">
                    <span class="text-2xs font-medium text-ink-muted">
                        {{ methodOf(row.payment_method_id)?.cash ? 'Cash received' : 'Amount' }}
                    </span>
                    <input
                        :value="row.amount"
                        type="number"
                        inputmode="decimal"
                        min="0"
                        step="0.01"
                        :disabled="disabled"
                        :aria-invalid="errorFor(index, 'amount') ? 'true' : undefined"
                        class="mt-1 w-full rounded-field border bg-surface px-3 text-right font-semibold tabular-nums text-ink focus:border-brand-400 focus:outline-none"
                        :class="[box, errorFor(index, 'amount') ? 'border-danger-500/60' : 'border-line-strong']"
                        placeholder="0.00"
                        @input="typeAmount(index, $event.target.value)"
                        @focus="$event.target.select()"
                    />
                </label>

                <label v-if="!methodOf(row.payment_method_id)?.cash" class="block">
                    <span class="text-2xs font-medium text-ink-muted">
                        Reference no.<template v-if="!methodOf(row.payment_method_id)?.reference"> (optional)</template>
                    </span>
                    <input
                        :value="row.reference"
                        type="text"
                        maxlength="100"
                        autocomplete="off"
                        :disabled="disabled"
                        :aria-invalid="errorFor(index, 'reference') ? 'true' : undefined"
                        class="mt-1 w-full rounded-field border bg-surface px-3 font-mono text-ink focus:border-brand-400 focus:outline-none"
                        :class="[box, errorFor(index, 'reference') ? 'border-danger-500/60' : 'border-line-strong']"
                        placeholder="e.g. 1234 5678 9012"
                        @input="update(index, { reference: $event.target.value })"
                    />
                </label>
            </div>

            <!-- The notes a customer is likely to hand over: one tap. -->
            <div
                v-if="methodOf(row.payment_method_id)?.cash && cashOwed > 0"
                class="mt-2 flex flex-wrap gap-1.5"
            >
                <button
                    type="button"
                    class="rounded-full bg-surface-sunken px-3 font-semibold text-ink-muted transition hover:text-ink"
                    :class="touch ? 'h-10 text-sm' : 'h-8 text-xs'"
                    :disabled="disabled"
                    @click="hand(index, cashOwed)"
                >
                    Exact
                </button>
                <button
                    v-for="note in notes"
                    :key="note"
                    type="button"
                    class="rounded-full bg-surface-sunken px-3 font-semibold tabular-nums text-ink-muted transition hover:text-ink"
                    :class="touch ? 'h-10 text-sm' : 'h-8 text-xs'"
                    :disabled="disabled"
                    @click="hand(index, note)"
                >
                    {{ money(note / 100) }}
                </button>
            </div>

            <p
                v-for="field in ['payment_method_id', 'amount', 'reference']"
                v-show="errorFor(index, field)"
                :key="field"
                class="mt-1.5 text-xs font-medium text-danger-700"
            >
                {{ errorFor(index, field) }}
            </p>
        </div>

        <p v-if="generalError" class="text-xs font-medium text-danger-700">{{ generalError }}</p>
        <p v-else-if="notYet" class="text-xs text-ink-muted">{{ notYet }}</p>

        <!-- The answer to the only question at a counter: change, or how much more. -->
        <div
            v-if="totalCents > 0 && rows.length"
            class="flex items-baseline justify-between rounded-field px-4 py-3"
            :class="settled.change > 0 ? 'bg-success-50' : settled.due > 0 ? 'bg-warning-50' : 'bg-surface-sunken'"
        >
            <template v-if="settled.change > 0">
                <span class="text-sm font-semibold text-success-700">Change</span>
                <span class="font-bold tabular-nums text-success-700" :class="touch ? 'text-2xl' : 'text-xl'">
                    {{ money(settled.change / 100) }}
                </span>
            </template>
            <template v-else-if="settled.due > 0">
                <span class="text-sm font-semibold text-warning-700">Still to pay</span>
                <span class="font-bold tabular-nums text-warning-700" :class="touch ? 'text-2xl' : 'text-xl'">
                    {{ money(settled.due / 100) }}
                </span>
            </template>
            <template v-else-if="accountPicked && centavos(onAccount) > 0">
                <span class="text-sm font-semibold text-ink-muted">On account</span>
                <span class="font-bold tabular-nums text-ink" :class="touch ? 'text-2xl' : 'text-xl'">
                    {{ money(centavos(onAccount) / 100) }}
                </span>
            </template>
            <template v-else>
                <span class="text-sm font-semibold text-ink-muted">Paid in full</span>
                <span class="text-sm font-semibold text-ink-muted">No change</span>
            </template>
        </div>
    </div>
</template>
