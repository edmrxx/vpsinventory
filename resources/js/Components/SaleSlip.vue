<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { count, useMoney } from '../lib/money'

/**
 * The slip handed to the customer.
 *
 * Drawn from the sale's own `detail()` payload, the one its page and its
 * dialog read, so the paper says what the screen says.
 *
 * **It never prints a cost or a margin**, whatever the account may see:
 * the payload carries them for somebody with `sales.view_cost`, and the
 * cashier at the till usually has it, but this is the customer's copy. No
 * field below reads `unit_cost`, `line_cost`, `total_cost` or
 * `gross_profit`, and `SaleSlip.test.js` fails if one starts to.
 *
 * **It is not an official receipt**, and says so on every slip. That line
 * is written here rather than taken from Settings, so it cannot be cleared:
 * an official receipt in the Philippines comes from a BIR-registered
 * system, which this is not.
 *
 * Black on white, in fixed sizes, whatever the theme on screen: a receipt
 * printer has one colour, and a dark-mode token would print grey.
 */
const props = defineProps({
    /** The `sale` of a sale's detail payload. */
    sale: { type: Object, required: true },
})

const page = usePage()
const { money } = useMoney()

const company = computed(() => page.props.company ?? {})
const slip = computed(() => page.props.slip ?? {})

const narrow = computed(() => String(slip.value.width) === '58')

const vatable = computed(() => Number(props.sale.vat_rate ?? 0) > 0)
const voided = computed(() => props.sale.status === 'voided')
const discounted = computed(() => Number(props.sale.discount ?? 0) > 0)
const change = computed(() => Number(props.sale.change ?? 0))

/** As rung up: 2 box, not the 200 pcs it became on the shelf. */
function asSold(line) {
    const quantity = line.converted ? line.unit_quantity : line.quantity

    return `${count(quantity)} ${line.unit_code ?? ''}`.trim()
}
</script>

<template>
    <article class="slip" :class="{ narrow }" :style="{ width: narrow ? '58mm' : '80mm' }">
        <header class="center">
            <img v-if="company.logo" :src="company.logo" alt="" class="logo" />
            <p class="strong big">{{ company.name }}</p>
            <p v-if="company.legal_name && company.legal_name !== company.name">{{ company.legal_name }}</p>
            <p v-if="company.address" class="wrap">{{ company.address }}</p>
            <p v-if="company.tin">TIN {{ company.tin }}</p>
            <p v-if="company.phone">{{ company.phone }}</p>
        </header>

        <hr />

        <p class="center strong">TRANSACTION SLIP</p>
        <p v-if="voided" class="center strong voided">*** VOIDED ***</p>

        <dl class="facts">
            <div><dt>No.</dt><dd class="strong">{{ sale.reference }}</dd></div>
            <div><dt>Date</dt><dd>{{ sale.posted_at ?? sale.sale_date }}</dd></div>
            <div v-if="sale.posted_by"><dt>Cashier</dt><dd>{{ sale.posted_by }}</dd></div>
            <div><dt>Customer</dt><dd>{{ sale.customer }}</dd></div>
        </dl>

        <hr />

        <ul class="lines">
            <li v-for="line in sale.lines" :key="line.id">
                <p class="wrap">{{ line.name }}</p>
                <p class="row">
                    <span>{{ asSold(line) }} × {{ money(line.unit_price) }}</span>
                    <span>{{ money(line.line_total) }}</span>
                </p>
            </li>
        </ul>

        <hr />

        <dl class="totals">
            <div v-if="discounted"><dt>Subtotal</dt><dd>{{ money(sale.subtotal) }}</dd></div>
            <div v-if="discounted"><dt>Discount</dt><dd>-{{ money(sale.discount) }}</dd></div>
            <div class="strong big"><dt>TOTAL</dt><dd>{{ money(sale.total_amount) }}</dd></div>
            <template v-if="vatable">
                <div><dt>VATable sales</dt><dd>{{ money(sale.net_amount) }}</dd></div>
                <div><dt>VAT ({{ Number(sale.vat_rate) }}%)</dt><dd>{{ money(sale.vat_amount) }}</dd></div>
            </template>
        </dl>

        <template v-if="(sale.payments ?? []).length">
            <hr />

            <dl class="totals">
                <template v-for="payment in sale.payments" :key="payment.id">
                    <div><dt>{{ payment.method }}</dt><dd>{{ money(payment.amount) }}</dd></div>
                    <div v-if="payment.tendered !== null && payment.tendered !== undefined" class="sub">
                        <dt>Received</dt><dd>{{ money(payment.tendered) }}</dd>
                    </div>
                    <div v-if="payment.reference" class="sub">
                        <dt>Ref</dt><dd class="wrap">{{ payment.reference }}</dd>
                    </div>
                </template>
                <div v-if="change > 0" class="strong"><dt>Change</dt><dd>{{ money(sale.change) }}</dd></div>
            </dl>
        </template>

        <template v-if="Number(sale.on_account) > 0">
            <hr />

            <dl class="totals">
                <div class="strong"><dt>On account</dt><dd>{{ money(sale.on_account) }}</dd></div>
                <div v-if="sale.terms_days !== null && sale.terms_days !== undefined" class="sub">
                    <dt>Terms</dt><dd>{{ sale.terms_days }} {{ sale.terms_days === 1 ? 'day' : 'days' }}</dd>
                </div>
                <div class="sub"><dt>Due</dt><dd>{{ sale.due_date }}</dd></div>
            </dl>
        </template>

        <hr />

        <footer class="center">
            <p v-if="slip.footer" class="wrap">{{ slip.footer }}</p>
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

.voided {
    margin-top: 1mm;
    font-size: 1.3em;
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

.sub {
    padding-left: 3mm;
    font-size: 0.92em;
}

footer p + p {
    margin-top: 1mm;
}
</style>
