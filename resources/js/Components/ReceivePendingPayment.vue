<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import PaymentEntry from './PaymentEntry.vue'
import PrimaryButton from './PrimaryButton.vue'
import { centavos, pesos, settleSale } from '../lib/payments'
import { count, useMoney } from '../lib/money'
import { confirmAction, showChange, toastError } from '../lib/swal'
import { printSlip } from '../lib/slip'

/**
 * Receive payment for an order the kiosk sent, from the back office (CR-2,
 * the user's GO! of 24 November 2026).
 *
 * The cashier who really takes the money may sit at a desktop, not at the
 * kiosk. This is the till's payment box over Sales → Pending Payments: it
 * posts the sale through `POST /sales` exactly as the order was sent —
 * lines, prices, discount, customer and store — with `pending_payment_id`,
 * so the same checks decide: still waiting, paid once, nothing re-priced.
 * Needs only Record a sale. Afterwards it lands on the new sale and offers
 * its slip.
 */
const props = defineProps({
    /** The `pending` of the detail payload, or null while closed. */
    order: { type: Object, default: null },
    /** The `receive` of the detail payload: methods, today, extend_terms. */
    box: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const { money } = useMoney()

const payments = ref([])
const onAccount = ref('')
const termsDays = ref('')
const errors = ref({})
const posting = ref(false)
const key = ref('')

const methods = computed(() => props.box?.methods ?? [])
const total = computed(() => Number(props.order?.total_amount ?? 0))

/** A fresh key each time it opens: one tap, one sale. */
function newKey() {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`.replace(/[^0-9a-f-]/g, '')
}

watch(
    () => props.order,
    (order) => {
        if (!order) return

        const cash = methods.value.find((method) => method.cash) ?? methods.value[0]

        payments.value = cash ? [{ payment_method_id: cash.value, amount: pesos(centavos(total.value)), reference: '' }] : []
        onAccount.value = ''
        termsDays.value = ''
        errors.value = {}
        key.value = newKey()
    },
    { immediate: true }
)

const settled = computed(() =>
    settleSale(centavos(total.value), onAccount.value, payments.value, methods.value, props.order?.credit ?? null, {
        days: termsDays.value,
        mayExtend: !!props.box?.extend_terms,
    })
)

function close() {
    if (!posting.value) emit('close')
}

function receive() {
    const order = props.order

    if (!order || posting.value || !settled.value.ready) return

    posting.value = true

    const change = settled.value.change

    router.post(
        '/sales',
        {
            customer_id: order.customer_id,
            location_id: order.location_id,
            discount: order.discount,
            pending_payment_id: order.id,
            sale_date: props.box?.today,
            idempotency_key: key.value,
            lines: order.lines.map((line) => ({
                product_id: line.product_id,
                unit_id: line.unit_id || null,
                quantity: line.quantity,
                unit_price: line.unit_price,
            })),
            payments: payments.value.map((row) => ({
                payment_method_id: row.payment_method_id,
                amount: row.amount,
                reference: row.reference || null,
            })),
            on_account: onAccount.value || 0,
            terms_days: onAccount.value ? termsDays.value : '',
        },
        {
            onSuccess: async (page) => {
                emit('close')

                // Now on the new sale's own page, which holds the slip.
                const sale = page.props.sale ?? null
                const text = page.props.flash?.success ?? 'The payment was received.'

                if (change > 0) {
                    const answer = await showChange({ change: money(change / 100), text, printable: !!sale })

                    if (answer?.isDenied && sale) printSlip(sale)

                    return
                }

                const print = await confirmAction({
                    icon: 'success',
                    title: 'Payment received',
                    text: `${text} Print the slip for the customer?`,
                    confirmText: 'Print slip',
                    cancelText: 'Not now',
                })

                if (print && sale) printSlip(sale)
            },
            onError: (bag) => {
                errors.value = bag

                const loose = bag.pending_payment_id ?? bag.lines ?? bag.customer_id ?? bag.sale_date

                if (loose) toastError(loose)
            },
            onFinish: () => (posting.value = false),
        }
    )
}
</script>

<template>
    <Modal
        :open="!!order"
        title="Receive payment"
        :subtitle="order ? `${order.reference} · ${order.customer}` : ''"
        :busy="posting"
        :close-on-backdrop="false"
        size="md"
        @close="close"
    >
        <template v-if="order">
            <div class="mb-4 flex items-baseline justify-between rounded-field bg-surface-sunken px-4 py-3">
                <span class="text-sm font-semibold text-ink-muted">Amount due</span>
                <span class="text-3xl font-bold tabular-nums text-ink">{{ money(total) }}</span>
            </div>

            <!-- What is being paid for, exactly as the kiosk sent it. -->
            <ul class="mb-4 divide-y divide-line rounded-field border border-line text-sm" data-receiving>
                <li v-for="line in order.lines" :key="line.id" class="flex justify-between gap-3 px-3 py-2">
                    <span class="min-w-0 truncate text-ink">{{ count(line.quantity) }} {{ line.unit_code }} · {{ line.name }}</span>
                    <span class="shrink-0 tabular-nums text-ink-muted">{{ money(line.line_total) }}</span>
                </li>
                <li v-if="Number(order.discount) > 0" class="flex justify-between gap-3 px-3 py-2">
                    <span class="text-ink-muted">Discount</span>
                    <span class="tabular-nums text-ink-muted">−{{ money(order.discount) }}</span>
                </li>
            </ul>

            <p v-if="errors.pending_payment_id" class="mb-3 text-xs font-medium text-danger-700">
                {{ errors.pending_payment_id }}
            </p>

            <PaymentEntry
                v-model="payments"
                v-model:on-account="onAccount"
                v-model:terms-days="termsDays"
                :account="order.credit ?? null"
                :may-extend-terms="!!box?.extend_terms"
                :sale-date="box?.today ?? ''"
                :methods="methods"
                :total="total"
                :errors="errors"
                :disabled="posting"
            />
        </template>

        <template #footer>
            <PrimaryButton variant="secondary" :disabled="posting" @click="close">Back</PrimaryButton>
            <PrimaryButton :loading="posting" :disabled="!settled.ready" @click="receive">
                Receive payment
            </PrimaryButton>
        </template>
    </Modal>
</template>
