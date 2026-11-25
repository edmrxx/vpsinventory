<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { count, useMoney } from '../lib/money'

/**
 * The slip for an order the kiosk sent to the cashier (CR-2).
 *
 * The customer carries it to the cashier, who takes the money for exactly
 * what it lists. Drawn from the order's own payload, the one the till's
 * Pending tab and the Pending payments screen read.
 *
 * **It says NOT PAID** across the top, and **"This is not an official
 * receipt."** at the foot — both written here, never in a setting, so they
 * cannot be cleared. No money has changed hands when it prints.
 *
 * **It never prints a cost or a margin**: the payload carries none, and no
 * field below reads one. `OrderSlip.test.js` guards all three.
 *
 * Black on white, in fixed sizes, like the sale slip.
 */
const props = defineProps({
    /** The `pending` of a pending payment's detail payload. */
    order: { type: Object, required: true },
})

const page = usePage()
const { money } = useMoney()

const company = computed(() => page.props.company ?? {})
const slip = computed(() => page.props.slip ?? {})

const narrow = computed(() => String(slip.value.width) === '58')

const discounted = computed(() => Number(props.order.discount ?? 0) > 0)
</script>

<template>
    <article class="slip" :class="{ narrow }" :style="{ width: narrow ? '58mm' : '80mm' }">
        <header class="center">
            <img v-if="company.logo" :src="company.logo" alt="" class="logo" />
            <p class="strong big">{{ company.name }}</p>
            <p v-if="company.address" class="wrap">{{ company.address }}</p>
        </header>

        <hr />

        <p class="center strong big" data-not-paid>*** NOT PAID ***</p>
        <p class="center strong">PAY AT THE CASHIER</p>

        <dl class="facts">
            <div><dt>Order</dt><dd class="strong">{{ order.reference }}</dd></div>
            <div><dt>Sent</dt><dd>{{ order.sent_at }}</dd></div>
            <div v-if="order.taken_by"><dt>Taken by</dt><dd>{{ order.taken_by }}</dd></div>
            <div><dt>Customer</dt><dd>{{ order.customer }}</dd></div>
            <div v-if="order.location"><dt>At</dt><dd>{{ order.location }}</dd></div>
        </dl>

        <hr />

        <ul class="lines">
            <li v-for="line in order.lines" :key="line.id">
                <p class="wrap">{{ line.name }}</p>
                <p class="row">
                    <span>{{ count(line.quantity) }} {{ line.unit_code ?? '' }} × {{ money(line.unit_price) }}</span>
                    <span>{{ money(line.line_total) }}</span>
                </p>
            </li>
        </ul>

        <hr />

        <dl class="totals">
            <div v-if="discounted"><dt>Subtotal</dt><dd>{{ money(order.subtotal) }}</dd></div>
            <div v-if="discounted"><dt>Discount</dt><dd>-{{ money(order.discount) }}</dd></div>
            <div class="strong big"><dt>TO PAY</dt><dd>{{ money(order.total_amount) }}</dd></div>
        </dl>

        <hr />

        <footer class="center">
            <p>Held for you until closing on {{ order.hold_until }}.</p>
            <p class="strong">This is not an official receipt.</p>
        </footer>
    </article>
</template>

<style scoped>
.slip {
    box-sizing: border-box;
    max-width: 100%;
    padding: 2mm 3mm;
    background: #fff;
    color: #000;
    font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
    font-size: 11px;
    line-height: 1.35;
}

.slip.narrow {
    font-size: 9.5px;
    padding: 1.5mm 2mm;
}

.slip p,
.slip dl,
.slip ul {
    margin: 0;
    padding: 0;
}

.slip ul {
    list-style: none;
}

.center {
    text-align: center;
}

.strong {
    font-weight: 700;
}

.big {
    font-size: 1.25em;
}

.wrap {
    overflow-wrap: anywhere;
    white-space: pre-line;
}

.logo {
    display: block;
    max-height: 14mm;
    max-width: 40mm;
    margin: 0 auto 1.5mm;
    object-fit: contain;
    filter: grayscale(1);
}

hr {
    margin: 2mm 0;
    border: 0;
    border-top: 1px dashed #000;
}

.facts > div,
.totals > div,
.row {
    display: flex;
    justify-content: space-between;
    gap: 2mm;
}

.facts dd,
.totals dd,
.row span:last-child {
    text-align: right;
    white-space: nowrap;
}

.facts dd {
    white-space: normal;
    overflow-wrap: anywhere;
}

.lines li + li {
    margin-top: 1.2mm;
}

footer p + p {
    margin-top: 1mm;
}
</style>
