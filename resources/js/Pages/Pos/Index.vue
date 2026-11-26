<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import {
    ChevronLeft,
    ChevronRight,
    CirclePause,
    ClipboardList,
    Eye,
    Hourglass,
    LogOut,
    MapPin,
    Minus,
    Package,
    Plus,
    Printer,
    Receipt,
    Search,
    ShoppingCart,
    Trash2,
} from '@lucide/vue'
import ActionBadge from '../../Components/ActionBadge.vue'
import AppearanceSwitch from '../../Components/AppearanceSwitch.vue'
import ComboBox from '../../Components/ComboBox.vue'
import DocumentModal from '../../Components/DocumentModal.vue'
import DayPicker from '../../Components/DayPicker.vue'
import Modal from '../../Components/Modal.vue'
import PaymentEntry from '../../Components/PaymentEntry.vue'
import PrimaryButton from '../../Components/PrimaryButton.vue'
import SlipPrinter from '../../Components/SlipPrinter.vue'
import TextField from '../../Components/TextField.vue'
import { confirmAction, confirmDelete, showChange, showValidationErrors, toastError, toastSuccess } from '../../lib/swal'
import { count, lineCentavos, quantityStep, sumQuantities, toMilli, useMoney, useVat, vatSplit } from '../../lib/money'
import { centavos, creditHintFor, pesos, settleSale } from '../../lib/payments'
import { rebuildHold } from '../../lib/holds'
import { printOrderSlip, printSlip } from '../../lib/slip'
import { matchesProduct, scannedProduct } from '../../lib/scan'

/**
 * The till on the shared tablet.
 *
 * No sidebar and no page chrome: at a counter there is one job, and
 * everything on screen should belong to it. Every target is sized for a
 * thumb rather than a cursor.
 *
 * It sells through `POST /sales`, the same route the ordinary sales form
 * uses, so the FIFO batches, the ledger and the audit trail are the ones
 * already tested. This screen only decides what goes in the basket.
 */
const props = defineProps({
    products: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    /** The walk-in customer: where every sale starts. */
    customer: { type: Object, default: () => ({}) },
    /** Every active customer, Walk-in first, for the picker. */
    customers: { type: Array, default: () => [] },
    /** Sales on hold, newest first. */
    holds: { type: Array, default: () => [] },
    today: { type: String, required: true },
    idempotencyKey: { type: String, default: '' },
    paymentMethods: { type: Array, default: () => [] },
    cashier: { type: Object, default: () => ({}) },
    sales: { type: Object, default: () => ({ rows: [], count: 0, total: '0.00', on_account: '0.00', by_method: [], shown: 0, of: 0 }) },
    day: { type: Object, default: () => ({}) },
    tradedOn: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    /**
     * Where the till sells from (phase 31.4) — a store — and the stores it
     * may switch to. The products' figures are that place's.
     */
    locationId: { type: [Number, String, null], default: null },
    locations: { type: Array, default: () => [] },
    /**
     * Orders the kiosk sent to the cashier (CR-2), waiting at this store,
     * oldest first. `openPending` opens one to take its money; `pendingKey`
     * makes a second tap on Pending payment send it once.
     */
    pending: { type: Array, default: () => [] },
    openPending: { type: [Number, null], default: null },
    pendingKey: { type: String, default: '' },
})

const { money } = useMoney()

// The till posts through the same `SalePoster`, so a sale rung up here
// carries the VAT rate like any other. The counter shows the split.
const { rate: vatRate } = useVat()
const page = usePage()

const company = computed(() => page.props.company ?? {})

const now = ref(
    new Date().toLocaleString('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
)

/* --------------------------------------------------------------- Browsing */

const category = ref(null)
const search = ref('')

/*
 * The category tabs sit on one line and scroll sideways, so the products
 * start at the same height however many categories the shop has. A swipe
 * scrolls them on a tablet; a mouse wheel scrolls them too, since nobody
 * thinks to hold Shift. A faded edge says there is more past it, and a
 * picked tab is brought fully into view.
 */
const categoryStrip = ref(null)
const moreLeft = ref(false)
const moreRight = ref(false)

function measureStrip() {
    const strip = categoryStrip.value
    if (!strip) return

    moreLeft.value = strip.scrollLeft > 1
    moreRight.value = strip.scrollLeft + strip.clientWidth < strip.scrollWidth - 1
}

function wheelStrip(event) {
    const strip = categoryStrip.value
    if (!strip || strip.scrollWidth <= strip.clientWidth) return
    if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) return

    event.preventDefault()
    strip.scrollLeft += event.deltaY
}

function pickCategory(value, event) {
    category.value = value
    event?.currentTarget?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' })
}

const stripMask = computed(() => {
    const left = moreLeft.value ? 'transparent 0, #000 2.5rem' : '#000 0'
    const right = moreRight.value ? '#000 calc(100% - 2.5rem), transparent 100%' : '#000 100%'
    const mask = `linear-gradient(to right, ${left}, ${right})`

    return { maskImage: mask, WebkitMaskImage: mask }
})

onMounted(() => {
    measureStrip()
    window.addEventListener('resize', measureStrip)
})

onBeforeUnmount(() => window.removeEventListener('resize', measureStrip))

/*
 * Where the till sells from (phase 31.4).
 *
 * The server decides: it sells only from an active store, whatever is
 * asked. The tablet remembers the last store picked, as a convenience —
 * a browser that forgets it simply starts on the default again.
 */
const TILL_PLACE = 'goe.till.location'

function remembered() {
    try {
        return window.localStorage.getItem(TILL_PLACE)
    } catch {
        return null
    }
}

function remember(id) {
    try {
        window.localStorage.setItem(TILL_PLACE, String(id))
    } catch {
        // Private window or blocked storage: the till still works.
    }
}

const tillLocation = ref(props.locationId)
const tillPlace = computed(
    () => props.locations.find((row) => String(row.value) === String(props.locationId))?.label ?? ''
)

watch(
    () => props.locationId,
    (id) => {
        tillLocation.value = id
        if (id) remember(id)
    }
)

watch(tillLocation, (id) => {
    if (!id || String(id) === String(props.locationId)) return

    router.reload({
        only: ['products', 'locationId'],
        data: { location: id },
        preserveState: true,
    })
})

onMounted(() => {
    const stored = remembered()
    const asked = new URLSearchParams(window.location.search).has('location')

    if (!asked && stored && String(stored) !== String(props.locationId) && props.locations.some((row) => String(row.value) === stored)) {
        tillLocation.value = Number(stored)
    } else if (props.locationId) {
        remember(props.locationId)
    }
})

/**
 * Do the tiles carry pictures?
 *
 * All of them show a picture or none of them do. Mixing the two would
 * leave the grid staggered, and a counter tablet wants one steady shape to
 * aim at. Until somebody uploads a photo the tiles stay exactly as they
 * were.
 */
const tilesHavePhotos = computed(() => props.products.some((product) => product.photo_url))

const shown = computed(() => {
    const term = search.value.trim().toLowerCase()

    return props.products.filter((product) => {
        if (category.value && product.category_id !== category.value) return false

        return matchesProduct(product, term)
    })
})

/* ------------------------------------------------------------- The basket */

/**
 * One line per product and unit: 1 box and 5 loose pieces of the same
 * screws are two lines.
 *
 * `factor` is how many of the product's own unit one of the line's unit
 * holds, and `price` is the price of one of it. `on_hand` stays in the
 * product's own unit.
 *
 * @type {import('vue').Ref<Array<{key:string,id:number,name:string,sku:string,unit:string,units:Array,unit_id:number,unit_code:string,factor:number,price:string,on_hand:number,decimals:boolean,quantity:number}>>}
 */
const basket = ref([])

const discount = ref('')

/* ----------------------------------------------------------- The customer */

/**
 * Who the sale is for. Walk-in unless the cashier picks a regular, and back
 * to Walk-in after every sale, hold or clear — the next person in the queue
 * is somebody else.
 *
 * A customer with credit can have the sale put on account in the payment
 * dialog; anybody else pays in full at the counter.
 */
const customerId = ref(props.customer.id ?? null)

const customerOptions = computed(() =>
    props.customers.length
        ? props.customers
        : props.customer.id
          ? [{ value: props.customer.id, label: props.customer.name }]
          : []
)

const customerName = computed(
    () =>
        customerOptions.value.find((option) => String(option.value) === String(customerId.value))?.label ??
        props.customer.name ??
        'Walk-in'
)

/** The picked customer's credit, or null when they have none. */
const account = computed(
    () => customerOptions.value.find((option) => String(option.value) === String(customerId.value))?.credit ?? null
)

/** Why "On account" is not offered, for a named customer with no credit. Walk-in pays at the counter. */
const creditHint = computed(() =>
    String(customerId.value) === String(props.customer.id)
        ? ''
        : creditHintFor(customerOptions.value.find((option) => String(option.value) === String(customerId.value)))
)

function nextCustomer() {
    customerId.value = props.customer.id ?? null
}

/** The product's own unit, which a tap on its tile adds. */
function ownUnit(product) {
    return product.units?.[0] ?? { value: '', label: product.unit, factor: 1, price: product.price, decimals: product.decimals }
}

function keyOf(productId, unitId) {
    return `${productId}:${unitId}`
}

function lineFor(product, unit) {
    return basket.value.find((line) => line.key === keyOf(product.id, unit.value))
}

/** Anything left at all. 0.5 m of cable can still be sold. */
function inStock(product) {
    return toMilli(product.on_hand) > 0
}

/** A line in the product's own unit, in thousandths: 1 box of 100 is 100,000. */
function baseMilli(line, quantity = line.quantity) {
    return Math.round((toMilli(quantity) * toMilli(line.factor)) / 1000)
}

/**
 * How much of the product's own unit is still free for this line, after
 * every other line of the same product has taken its share.
 */
function freeFor(line) {
    const others = basket.value
        .filter((row) => row.id === line.id && row.key !== line.key)
        .reduce((sum, row) => sum + baseMilli(row), 0)

    return toMilli(line.on_hand) - others
}

/** Would one more of this line's unit still fit on the shelf? */
function roomForOne(line) {
    return baseMilli(line, (toMilli(line.quantity) + 1000) / 1000) <= freeFor(line)
}

/**
 * Put one on the counter, in the product's own unit.
 *
 * Refused past what is on the shelf. The ledger would refuse it anyway,
 * but finding out at the end of an order is no way to serve somebody.
 * Quantities are added in thousandths, so 2.5 m and one more is 3.5 m.
 */
function add(product) {
    if (!inStock(product)) return

    const unit = ownUnit(product)
    const line = lineFor(product, unit)

    if (!line) {
        const fresh = {
            key: keyOf(product.id, unit.value),
            ...product,
            unit_id: unit.value,
            unit_code: unit.label,
            factor: 1,
            price: unit.price,
            decimals: !!unit.decimals,
            quantity: 0,
        }

        const free = freeFor(fresh)

        if (free <= 0) {
            toastError(`Only ${count(product.on_hand)} of ${product.name} left.`)

            return
        }

        // The last half metre on the reel is sold as half a metre.
        fresh.quantity = Math.min(1000, free) / 1000
        basket.value.push(fresh)

        return
    }

    more(line)
}

/**
 * Enter in the search box: what a barcode scanner presses after the code.
 *
 * The product whose barcode or SKU is exactly what was typed goes into the
 * basket, whatever category tab is open, and the box empties for the next
 * scan. Typed by hand, a search that leaves one tile adds that one. Nothing
 * else is guessed at.
 */
function scan() {
    const typed = search.value.trim()

    if (typed === '') return

    const product = scannedProduct(props.products, typed) ?? (shown.value.length === 1 ? shown.value[0] : null)

    if (!product) {
        if (shown.value.length === 0) toastError(`No product has the code ${typed}.`)

        return
    }

    search.value = ''

    if (!inStock(product)) {
        toastError(`${product.name} is out of stock.`)

        return
    }

    add(product)
}

/** One more of whatever unit this line is in. */
function more(line) {
    if (!roomForOne(line)) {
        toastError(`Only ${count(line.on_hand)} ${line.unit ?? ''} of ${line.name} left.`)

        return
    }

    line.quantity = (toMilli(line.quantity) + 1000) / 1000
}

/**
 * Sell this line by the box instead of by the piece, or back.
 *
 * The line starts again at one of the new unit, at that unit's price. A
 * unit already on the counter for this product is not offered: that line
 * is the one to change.
 */
function switchUnit(line, unit) {
    if (String(unit.value) === String(line.unit_id)) return

    const moved = { ...line, factor: unit.factor, quantity: 1 }

    if (baseMilli(moved) > freeFor(line)) {
        toastError(`Not enough ${line.name} left for one ${unit.label}.`)

        return
    }

    line.key = keyOf(line.id, unit.value)
    line.unit_id = unit.value
    line.unit_code = unit.label
    line.factor = unit.factor
    line.price = unit.price
    line.decimals = !!unit.decimals
    line.quantity = 1
}

/** Units this line may switch to: not one another line of the product already uses. */
function unitTaken(line, unit) {
    return basket.value.some(
        (row) => row.id === line.id && row.key !== line.key && row.key === keyOf(line.id, unit.value)
    )
}

function less(line) {
    const left = toMilli(line.quantity) - 1000

    if (left <= 0) {
        remove(line)

        return
    }

    line.quantity = left / 1000
}

/**
 * A quantity typed by hand: 2.5 m, for a unit sold by measure.
 *
 * Kept to three decimals and to what is on the shelf. A box left empty is
 * left alone until it loses focus, so the person can clear it and type.
 */
function setQuantity(line, value, { final = false } = {}) {
    if (value === '' || value === null) {
        if (final) remove(line)

        return
    }

    const wanted = toMilli(value)

    if (wanted <= 0) {
        if (final) remove(line)

        return
    }

    const free = freeFor(line)

    if (baseMilli(line, wanted / 1000) > free) {
        toastError(`Only ${count(line.on_hand)} ${line.unit ?? ''} of ${line.name} left.`)
        // As many of this line's unit as still fit, to the thousandth.
        line.quantity = Math.floor((free * 1000) / toMilli(line.factor)) / 1000

        return
    }

    line.quantity = wanted / 1000
}

function remove(line) {
    basket.value = basket.value.filter((row) => row.key !== line.key)
}

async function clear() {
    if (basket.value.length === 0) return

    const agreed = await confirmAction({
        icon: 'warning',
        title: 'Clear the counter?',
        text: 'Everything on it goes back.',
        confirmText: 'Yes, clear it',
    })

    if (agreed) {
        basket.value = []
        discount.value = ''
        nextCustomer()
    }
}

/* ------------------------------------------------------------- The totals */

// Each line to the centavo, in whole centavos, as the server prices it.
const subtotal = computed(() =>
    basket.value.reduce((carried, line) => carried + lineCentavos(line.price, line.quantity), 0) / 100
)

/** A discount can never take more than the order is worth. */
const takenOff = computed(() => Math.min(Math.max(Number(discount.value) || 0, 0), subtotal.value))

const total = computed(() => subtotal.value - takenOff.value)

// Taken off the total, after the discount, as the server does it.
const vat = computed(() => vatSplit(total.value, vatRate.value))

const units = computed(() => sumQuantities(basket.value.map((line) => line.quantity)))

/* -------------------------------------------------------------- Finishing */

const form = useForm({})
const posting = ref(false)

/* ---------------------------------------------------------------- Payment */

/** The payment dialog, which "Complete sale" opens. */
const paying = ref(false)

/** How the customer is paying: one row per method. See PaymentEntry. */
const payments = ref([])

/** What the server said was wrong, under the box that caused it. */
const paymentErrors = ref({})

/** How much goes on the customer's account. Empty is none. */
const onAccount = ref('')

/** In how many days it falls due, agreed on this sale (phase 18.1). Empty is the usual. */
const termsDays = ref('')

/**
 * The order the kiosk sent that the cashier is taking the money for (CR-2),
 * or null for the sale on the counter. It is paid exactly as it was sent:
 * its own lines, prices, discount, customer and store — the counter is not
 * touched.
 */
const payingPending = ref(null)

/** What the payment dialog asks for: the order's total, or the counter's. */
const dueTotal = computed(() => (payingPending.value ? Number(payingPending.value.total_amount) : total.value))

/** Whose credit the payment dialog offers. */
const payAccount = computed(() => (payingPending.value ? payingPending.value.credit ?? null : account.value))

const payCreditHint = computed(() => {
    if (!payingPending.value) return creditHint.value
    if (String(payingPending.value.customer_id) === String(props.customer.id)) return ''

    return creditHintFor(customerOptions.value.find((option) => String(option.value) === String(payingPending.value.customer_id)))
})

const paySubtitle = computed(() =>
    payingPending.value
        ? `${payingPending.value.reference} · ${payingPending.value.customer}`
        : `${count(units.value)} ${units.value === 1 ? 'item' : 'items'} · ${customerName.value}`
)

const settled = computed(() =>
    settleSale(centavos(dueTotal.value), onAccount.value, payments.value, props.paymentMethods, payAccount.value, {
        days: termsDays.value,
        mayExtend: !!props.can.extendTerms,
    })
)

// What the server said goes once the cashier changes the payment: an error
// left under a box already put right would only confuse.
const isPaymentError = (key) => key.startsWith('payments') || key === 'on_account' || key === 'terms_days'

watch(
    [payments, onAccount, termsDays],
    () => {
        if (Object.keys(paymentErrors.value).some(isPaymentError)) {
            paymentErrors.value = Object.fromEntries(
                Object.entries(paymentErrors.value).filter(([key]) => !isPaymentError(key))
            )
        }
    },
    { deep: true }
)

/**
 * Take the payment.
 *
 * Opens on cash for exactly what is owed, which is what most customers do;
 * the cashier types what was handed over, or taps a note, and the change
 * shows at once. Tapping GCash as well splits it.
 */
function complete() {
    if (basket.value.length === 0 || posting.value) return

    if (!customerId.value) {
        toastError('Pick who this sale is for.')

        return
    }

    payingPending.value = null
    openPayment()
}

/** The payment dialog, on cash for exactly what is due. */
function openPayment() {
    const cash = props.paymentMethods.find((method) => method.cash) ?? props.paymentMethods[0]

    payments.value = cash
        ? [{ payment_method_id: cash.value, amount: pesos(centavos(dueTotal.value)), reference: '' }]
        : []
    onAccount.value = ''
    termsDays.value = ''
    paymentErrors.value = {}
    paying.value = true
}

/** Take the money for an order the kiosk sent (CR-2). */
function payPending(order) {
    if (posting.value || !order?.can?.pay) return

    payingPending.value = order
    openPayment()
}

function stopPaying() {
    if (posting.value) return

    paying.value = false
    payingPending.value = null
}

/** Post the sale with its payments. The stock leaves the shelf now. */
function post() {
    if (posting.value || !settled.value.ready) return

    posting.value = true

    // Said once the sale is in, not before: it is what the cashier counts
    // out of the drawer. The server stores the same figure.
    const change = settled.value.change
    const order = payingPending.value

    router.post(
        '/sales',
        {
            // An order the kiosk sent is paid exactly as it was sent (CR-2).
            ...(order
                ? {
                      customer_id: order.customer_id,
                      location_id: order.location_id,
                      discount: order.discount,
                      pending_payment_id: order.id,
                      lines: order.lines.map((line) => ({
                          product_id: line.product_id,
                          unit_id: line.unit_id || null,
                          quantity: line.quantity,
                          unit_price: line.unit_price,
                      })),
                  }
                : {
                      customer_id: customerId.value,
                      location_id: props.locationId,
                      discount: takenOff.value,
                      lines: basket.value.map((line) => ({
                          product_id: line.id,
                          unit_id: line.unit_id || null,
                          quantity: line.quantity,
                          unit_price: line.price,
                      })),
                  }),
            sale_date: props.today,
            idempotency_key: props.idempotencyKey,
            from: 'pos',
            payments: payments.value.map((row) => ({
                payment_method_id: row.payment_method_id,
                amount: row.amount,
                reference: row.reference || null,
            })),
            on_account: onAccount.value || 0,
            // Only read when something goes on account. Empty is the usual.
            terms_days: onAccount.value ? termsDays.value : '',
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Paying a sent order leaves whatever is on the counter alone.
                if (!order) {
                    basket.value = []
                    discount.value = ''
                    nextCustomer()
                }

                payments.value = []
                onAccount.value = ''
                termsDays.value = ''
                paying.value = false
                payingPending.value = null

                // Its slip stays one tap away until the next sale replaces it.
                lastSale.value = page.props.flash?.sale ?? null

                if (change > 0) {
                    // Not awaited: the till is free the moment the sale is
                    // in. Printed once the alert has gone, so it is not on
                    // the paper.
                    showChange({
                        change: money(change / 100),
                        text: page.props.flash?.success ?? 'The sale was posted.',
                        printable: !!lastSale.value,
                    }).then((answer) => {
                        if (answer?.isDenied) printLastSlip()
                    })
                } else {
                    toastSuccess(page.props.flash?.success ?? 'Sale posted.')
                }
            },
            onError: (errors) => {
                paymentErrors.value = errors

                // A payment error sits under its box in the dialog. Anything
                // else — the stock ran out in between — is said out loud.
                const other = Object.entries(errors).find(([key]) => !isPaymentError(key))

                if (other) toastError(other[1])
            },
            onFinish: () => (posting.value = false),
        }
    )
}

function signOut() {
    form.post('/logout')
}

/* --------------------------------------------------------------- The slip */

/** The sale just rung up, as `{ id, reference }`, for its slip. */
const lastSale = ref(null)

const printingSlip = ref(false)

async function printLastSlip() {
    if (!lastSale.value || printingSlip.value) return

    printingSlip.value = true

    try {
        if (!(await printSlip(lastSale.value.id))) {
            toastError('The slip could not be read. Try again from the Today tab.')
        }
    } finally {
        printingSlip.value = false
    }
}

/* ------------------------------------------------------- Hold and recall */

/**
 * Park the counter, to finish later.
 *
 * Nothing leaves the shelf and nothing is set aside: a hold is the basket,
 * kept on the server so any till can bring it back. When it is recalled the
 * shelf is read again, and the sale is posted like any other.
 */
const holding = ref(false)
const holdNote = ref('')
const holdError = ref('')
const holdBusy = ref(false)

function startHold() {
    if (basket.value.length === 0 || posting.value) return

    holdNote.value = ''
    holdError.value = ''
    holding.value = true
}

function holdCounter({ note = holdNote.value, then = null } = {}) {
    if (basket.value.length === 0 || holdBusy.value) return

    holdBusy.value = true

    // When a recall follows, it takes over the busy flag: letting go of it
    // here would light every Recall button again while the claim is in
    // flight, and a second tap would cancel a recall the server has
    // already done — losing that sale.
    let handedOn = false

    router.post(
        '/pos/holds',
        {
            customer_id: customerId.value,
            note: note || null,
            discount: takenOff.value,
            lines: basket.value.map((line) => ({
                product_id: line.id,
                unit_id: line.unit_id || null,
                quantity: line.quantity,
                unit_price: line.price,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                basket.value = []
                discount.value = ''
                nextCustomer()
                holding.value = false

                if (then) {
                    handedOn = true
                    then()
                } else {
                    toastSuccess(page.props.flash?.success ?? 'The sale is on hold.')
                }
            },
            onError: (errors) => {
                holdError.value = errors.note ?? Object.values(errors)[0] ?? 'The sale was not held.'

                if (!holding.value) toastError(holdError.value)
            },
            onFinish: () => {
                if (!handedOn) holdBusy.value = false
            },
        }
    )
}

/** What a hold is called in the list: its note, else who it is for. */
function holdLabel(hold) {
    return hold.note || hold.customer || 'Walk-in'
}

/**
 * Bring a held sale back to the counter.
 *
 * Something already on the counter is held first, after asking, so a
 * recall never throws away what the cashier was in the middle of.
 */
async function recall(hold) {
    if (holdBusy.value || posting.value) return

    if (basket.value.length > 0) {
        const agreed = await confirmAction({
            icon: 'question',
            title: 'Hold the current sale first?',
            text: `What is on the counter goes under Held, and ${holdLabel(hold)} comes back.`,
            confirmText: 'Yes, swap them',
        })

        if (!agreed) return

        holdCounter({ note: '', then: () => claim(hold) })

        return
    }

    claim(hold)
}

/**
 * Claim the hold on the server, then fill the counter.
 *
 * The server deletes the row first, so two tills cannot both bring back
 * the same order. What comes back is laid against the shelf as it is now.
 */
function claim(hold) {
    holdBusy.value = true

    router.post(
        `/pos/holds/${hold.id}/recall`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response) => {
                const recalled = response.props.flash?.recalled

                if (!recalled) return

                const rebuilt = rebuildHold(
                    recalled,
                    response.props.products ?? props.products,
                    response.props.customers ?? props.customers,
                    props.customer.id ?? null,
                    money
                )

                basket.value = rebuilt.lines
                discount.value = rebuilt.discount
                customerId.value = rebuilt.customerId
                panel.value = 'counter'

                if (rebuilt.changes.length) {
                    showValidationErrors(rebuilt.changes, {
                        title: 'Some things changed while it was on hold',
                        icon: 'warning',
                    })
                } else {
                    toastSuccess(`${holdLabel(hold)} is back on the counter.`)
                }
            },
            onError: (errors) => toastError(errors.hold ?? Object.values(errors)[0] ?? 'The sale could not be recalled.'),
            onFinish: () => (holdBusy.value = false),
        }
    )
}

async function discard(hold) {
    if (holdBusy.value || posting.value) return

    const agreed = await confirmDelete({
        title: 'Discard this held sale?',
        text: `"${holdLabel(hold)}" will be thrown away. Nothing was taken off the shelf, so no stock moves.`,
        confirmText: 'Yes, discard it',
    })

    if (!agreed) return

    router.delete(`/pos/holds/${hold.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toastSuccess(page.props.flash?.success ?? 'The held sale was discarded.'),
        onError: (errors) => toastError(errors.hold ?? Object.values(errors)[0] ?? 'The held sale was not discarded.'),
    })
}

/* ------------------------------------------------------ Pending payment */

/**
 * The kiosk takes the order, the cashier takes the money (CR-2).
 *
 * "Pending payment" sends the counter to the cashier instead of taking the
 * money here: the goods are held at this store, the customer gets a slip
 * marked NOT PAID, and the order waits under Pending until the cashier
 * takes the money or it is cancelled. Nothing leaves the shelf until then.
 */
const sending = ref(false)

function sendPending() {
    if (basket.value.length === 0 || sending.value || posting.value) return

    if (!customerId.value) {
        toastError('Pick who this order is for.')

        return
    }

    sending.value = true

    router.post(
        '/pending-payments',
        {
            customer_id: customerId.value,
            location_id: props.locationId,
            discount: takenOff.value,
            idempotency_key: props.pendingKey,
            lines: basket.value.map((line) => ({
                product_id: line.id,
                unit_id: line.unit_id || null,
                quantity: line.quantity,
                unit_price: line.price,
            })),
        },
        {
            preserveScroll: true,
            onSuccess: async () => {
                const order = page.props.flash?.pending ?? null

                basket.value = []
                discount.value = ''
                nextCustomer()

                if (!order) {
                    toastSuccess(page.props.flash?.success ?? page.props.flash?.info ?? 'Sent to the cashier.')

                    return
                }

                const print = await confirmAction({
                    icon: 'success',
                    title: `Sent to the cashier: ${order.reference}`,
                    text: `${money(order.total_amount)} for ${order.customer}. The goods are held until it is paid. Print the slip for the customer to take to the cashier?`,
                    confirmText: 'Print slip',
                    cancelText: 'Not now',
                })

                if (print) printOrderSlip(order)
            },
            onError: (errors) => toastError(errors.lines ?? errors.discount ?? Object.values(errors)[0] ?? 'The order was not sent.'),
            onFinish: () => (sending.value = false),
        }
    )
}

/** Cancelling one: always with a reason, in a small dialog. */
const cancellingPending = ref(null)
const cancelReason = ref('')
const cancelError = ref('')
const cancelBusy = ref(false)

function startCancelPending(order) {
    cancellingPending.value = order
    cancelReason.value = ''
    cancelError.value = ''
}

function cancelPending() {
    const order = cancellingPending.value

    if (!order || cancelBusy.value) return

    cancelBusy.value = true

    router.post(
        `/pending-payments/${order.id}/cancel`,
        { reason: cancelReason.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                cancellingPending.value = null
                toastSuccess(page.props.flash?.success ?? 'The order was cancelled.')
            },
            onError: (errors) => (cancelError.value = errors.reason ?? Object.values(errors)[0] ?? 'The order was not cancelled.'),
            onFinish: () => (cancelBusy.value = false),
        }
    )
}

/** Is there a Pending tab at all for this person? */
const showsPending = computed(() => !!(props.can.seePending || props.can.sendPending))

/**
 * The cashier's list is read again every half minute while nothing is in
 * flight, so an order sent from the kiosk shows up without a refresh.
 */
let pendingTimer = null

onMounted(() => {
    if (showsPending.value) {
        pendingTimer = setInterval(() => {
            if (paying.value || posting.value || sending.value || cancelBusy.value || document.hidden) return

            router.reload({ only: ['pending'], preserveScroll: true, preserveState: true })
        }, 30000)
    }

    // Opened from the Pending payments screen to take one's money.
    if (props.openPending) {
        const order = props.pending.find((row) => String(row.id) === String(props.openPending))

        panel.value = 'pending'

        if (order?.can?.pay) {
            payPending(order)
        } else {
            toastError('That order is no longer waiting at this store.')
        }
    }
})

onBeforeUnmount(() => clearInterval(pendingTimer))

/* -------------------------------------------------------- What sold today */

/** 'counter' while ringing up, 'held' for parked sales, 'pending' for orders sent to the cashier, 'today' to look back over the shift. */
const panel = ref('counter')

/** The sale open in the dialog, as its own address, or null. */
const viewing = ref(null)

/**
 * Look at another day.
 *
 * Only the day's sales are fetched again. The basket, the category tab
 * and whatever is half-typed in the search all stay where they are — a
 * cashier ringing somebody up while glancing at yesterday should not lose
 * what they have already tapped.
 */
function showDay(date) {
    if (!date) return

    router.reload({
        only: ['sales', 'day', 'tradedOn'],
        data: { date },
    })
}

/** The calendar. */
const picking = ref(false)

/**
 * Turning the calendar's pages fetches that month's marks.
 *
 * The day being looked at does not change — only which days are shown as
 * having traded — so `day` and `sales` are left alone.
 */
function showMonth(firstOfMonth) {
    router.reload({
        only: ['tradedOn'],
        data: { date: firstOfMonth > props.day.max ? props.day.max : firstOfMonth },
    })
}

function step(days) {
    const date = new Date(`${props.day.date}T00:00:00`)

    date.setDate(date.getDate() + days)

    const next = date.toISOString().slice(0, 10)

    // There is nothing to show from tomorrow.
    if (next > props.day.max) return

    showDay(next)
}
</script>

<template>
    <div class="flex h-dvh flex-col overflow-hidden bg-surface-sunken">
        <!-- Who, where, when -->
        <header class="flex shrink-0 items-center justify-between gap-4 bg-navy-900 px-5 py-3.5">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-ink-inverse">
                    <Receipt :size="18" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-base font-bold text-ink-inverse">{{ company.name }}</p>
                    <p class="truncate text-xs text-white/55">{{ cashier.name }}</p>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <!-- Where it sells from. Fixed while the basket holds anything:
                     its stock was weighed at this place. -->
                <div v-if="locations.length > 1" class="w-44" :title="basket.length ? 'Finish or clear the sale to change where it sells from.' : 'Selling from'">
                    <ComboBox
                        id="pos-location"
                        v-model="tillLocation"
                        :options="locations"
                        :disabled="basket.length > 0 || posting"
                        empty-text="Pick a store"
                    />
                </div>
                <p v-else-if="tillPlace" class="hidden items-center gap-1.5 text-sm text-white/70 sm:inline-flex">
                    <MapPin :size="14" />
                    {{ tillPlace }}
                </p>
                <p class="hidden text-sm text-white/70 sm:block">{{ now }}</p>
                <!-- The light or dark screen: the till has no top bar. -->
                <AppearanceSwitch compact />
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-field px-3 text-sm font-medium text-white/80 transition hover:bg-white/10 hover:text-ink-inverse"
                    @click="signOut"
                >
                    <LogOut :size="16" />
                    Sign out
                </button>
            </div>
        </header>

        <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
            <!-- What is for sale -->
            <!-- min-w-0: the category strip scrolls inside it rather than widening it -->
            <section class="flex min-h-0 min-w-0 flex-1 flex-col">
                <div class="flex shrink-0 flex-col gap-2 px-4 pt-3 pb-1 sm:flex-row sm:items-start sm:gap-3">
                    <div
                        ref="categoryStrip"
                        class="scrollbar-bar flex min-w-0 flex-1 items-center gap-2 overflow-x-auto overscroll-x-contain pb-2"
                        :style="stripMask"
                        role="tablist"
                        aria-label="Categories"
                        @scroll.passive="measureStrip"
                        @wheel="wheelStrip"
                    >
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="category === null"
                            class="h-11 shrink-0 whitespace-nowrap rounded-field px-4 text-sm font-semibold transition"
                            :class="
                                category === null
                                    ? 'bg-brand-600 text-ink-inverse'
                                    : 'bg-surface text-ink-muted hover:text-ink'
                            "
                            @click="pickCategory(null, $event)"
                        >
                            All
                        </button>
                        <button
                            v-for="option in categories"
                            :key="option.value"
                            type="button"
                            role="tab"
                            :aria-selected="category === option.value"
                            class="h-11 shrink-0 whitespace-nowrap rounded-field px-4 text-sm font-semibold transition"
                            :class="
                                category === option.value
                                    ? 'bg-brand-600 text-ink-inverse'
                                    : 'bg-surface text-ink-muted hover:text-ink'
                            "
                            @click="pickCategory(option.value, $event)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <label class="flex h-11 w-full shrink-0 items-center gap-2 rounded-field bg-surface px-3 sm:w-64">
                        <Search :size="17" class="shrink-0 text-ink-subtle" />
                        <input
                            v-model="search"
                            type="search"
                            enterkeyhint="done"
                            @keydown.enter.prevent="scan"
                            class="w-full bg-transparent text-sm text-ink placeholder:text-ink-subtle focus:outline-none"
                            placeholder="Search or scan a barcode…"
                        />
                    </label>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4">
                    <p v-if="shown.length === 0" class="py-16 text-center text-sm text-ink-subtle">
                        Nothing matches that.
                    </p>

                    <div
                        v-else
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5"
                    >
                        <button
                            v-for="product in shown"
                            :key="product.id"
                            type="button"
                            class="flex min-h-28 flex-col overflow-hidden rounded-panel bg-surface text-left shadow-sm transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-45"
                            :disabled="!inStock(product)"
                            @click="add(product)"
                        >
                            <!-- A picture is quicker to find than a name
                                 when somebody is tapping at a counter. -->
                            <span
                                v-if="tilesHavePhotos"
                                class="grid aspect-[4/3] w-full shrink-0 place-items-center overflow-hidden bg-surface-sunken"
                            >
                                <img
                                    v-if="product.photo_url"
                                    :src="product.photo_url"
                                    :alt="product.name"
                                    loading="lazy"
                                    class="size-full object-cover"
                                />
                                <Package v-else :size="28" class="text-ink-subtle" />
                            </span>

                            <span class="flex flex-1 flex-col justify-between p-3.5">
                            <span>
                                <p class="line-clamp-2 text-sm font-semibold text-ink">
                                    {{ product.name }}
                                </p>
                                <!-- Two paints of the same name are told apart here. -->
                                <p
                                    v-if="product.brand || product.model"
                                    class="mt-0.5 truncate text-2xs text-ink-subtle"
                                >
                                    {{ [product.brand, product.model].filter(Boolean).join(' · ') }}
                                </p>
                            </span>
                            <div class="mt-2 flex items-end justify-between gap-2">
                                <p class="text-lg font-bold tabular-nums text-ink">
                                    {{ money(product.price) }}
                                </p>
                                <p
                                    class="text-2xs tabular-nums"
                                    :class="!inStock(product) ? 'font-semibold text-danger-700' : 'text-ink-subtle'"
                                >
                                    {{ !inStock(product) ? 'Out' : `${count(product.on_hand)} left` }}
                                </p>
                            </div>
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- The counter -->
            <aside class="flex w-full shrink-0 flex-col border-t border-line bg-surface lg:w-[26rem] lg:border-t-0 lg:border-l">
                <div class="flex shrink-0 items-center gap-1 border-b border-line px-3 py-2.5">
                    <button
                        type="button"
                        class="flex h-11 flex-auto items-center justify-center gap-1.5 rounded-field px-2 text-sm font-semibold whitespace-nowrap transition"
                        :class="panel === 'counter' ? 'bg-surface-sunken text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="panel = 'counter'"
                    >
                        <ShoppingCart :size="17" />
                        Current sale
                        <span
                            v-if="basket.length"
                            class="rounded-full bg-brand-600 px-1.5 text-2xs font-bold text-ink-inverse"
                        >
                            {{ basket.length }}
                        </span>
                    </button>

                    <button
                        v-if="can.takePayment"
                        type="button"
                        class="flex h-11 flex-auto items-center justify-center gap-1.5 rounded-field px-2 text-sm font-semibold whitespace-nowrap transition"
                        :class="panel === 'held' ? 'bg-surface-sunken text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="panel = 'held'"
                    >
                        <CirclePause :size="17" />
                        Held
                        <span
                            v-if="holds.length"
                            class="rounded-full bg-warning-500 px-1.5 text-2xs font-bold text-ink-inverse"
                        >
                            {{ holds.length }}
                        </span>
                    </button>

                    <!-- Orders the kiosk sent to the cashier (CR-2). -->
                    <button
                        v-if="showsPending"
                        type="button"
                        class="flex h-11 flex-auto items-center justify-center gap-1.5 rounded-field px-2 text-sm font-semibold whitespace-nowrap transition"
                        :class="panel === 'pending' ? 'bg-surface-sunken text-ink' : 'text-ink-muted hover:text-ink'"
                        data-pending-tab
                        @click="panel = 'pending'"
                    >
                        <Hourglass :size="17" />
                        Pending
                        <span
                            v-if="pending.length"
                            class="rounded-full bg-brand-600 px-1.5 text-2xs font-bold text-ink-inverse"
                        >
                            {{ pending.length }}
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex h-11 flex-auto items-center justify-center gap-1.5 rounded-field px-2 text-sm font-semibold whitespace-nowrap transition"
                        :class="panel === 'today' ? 'bg-surface-sunken text-ink' : 'text-ink-muted hover:text-ink'"
                        @click="panel = 'today'"
                    >
                        <ClipboardList :size="17" />
                        Today
                        <span class="text-2xs font-bold text-ink-subtle">{{ sales.count }}</span>
                    </button>

                    <button
                        v-if="panel === 'counter'"
                        type="button"
                        class="grid size-11 shrink-0 place-items-center rounded-field text-ink-subtle transition hover:bg-danger-50 hover:text-danger-700 disabled:opacity-40"
                        :disabled="basket.length === 0"
                        aria-label="Clear the counter"
                        @click="clear"
                    >
                        <Trash2 :size="18" />
                    </button>
                </div>

                <!-- Who it is for. Walk-in unless somebody is picked; the
                     sale is still paid in full here either way. -->
                <div v-if="panel === 'counter'" class="shrink-0 border-b border-line px-5 py-3">
                    <label for="pos-customer" class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-subtle uppercase">
                        Customer
                    </label>
                    <ComboBox
                        id="pos-customer"
                        v-model="customerId"
                        :options="customerOptions"
                        :disabled="posting"
                        empty-text="Pick a customer"
                    />
                </div>

                <div v-if="panel === 'counter'" class="min-h-0 flex-1 overflow-y-auto px-5">
                    <div v-if="basket.length === 0" class="py-10 text-center">
                        <p class="text-sm text-ink-subtle">Tap a product to start.</p>

                        <!-- The slip of the sale just rung up, while the
                             customer is still at the counter. -->
                        <div
                            v-if="lastSale"
                            class="mx-auto mt-6 flex max-w-xs items-center justify-between gap-3 rounded-field bg-surface-sunken px-4 py-3 text-left"
                        >
                            <div class="min-w-0">
                                <p class="text-2xs text-ink-subtle">Last sale</p>
                                <p class="truncate font-mono text-sm font-semibold text-ink">{{ lastSale.reference }}</p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-10 shrink-0 items-center gap-2 rounded-field bg-surface px-3 text-sm font-semibold text-brand-700 shadow-sm transition hover:bg-brand-50 disabled:opacity-50"
                                :disabled="printingSlip"
                                @click="printLastSlip"
                            >
                                <Printer :size="16" />
                                Print slip
                            </button>
                        </div>
                    </div>

                    <div v-else class="divide-y divide-line">
                        <div v-for="line in basket" :key="line.key" class="flex items-center gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink">{{ line.name }}</p>
                                <p class="text-xs tabular-nums text-ink-muted">
                                    {{ money(line.price) }}<template v-if="line.unit_code"> / {{ line.unit_code }}</template>
                                    <template v-if="toMilli(line.factor) !== 1000">
                                        · {{ count(baseMilli(line) / 1000) }} {{ line.unit }}
                                    </template>
                                </p>
                                <!-- Sold by the box as well: one tap switches. -->
                                <div v-if="(line.units ?? []).length > 1" class="mt-1.5 flex flex-wrap gap-1">
                                    <button
                                        v-for="unit in line.units"
                                        :key="unit.value"
                                        type="button"
                                        class="h-7 rounded-full px-2.5 text-2xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-35"
                                        :class="String(unit.value) === String(line.unit_id)
                                            ? 'bg-brand-600 text-ink-inverse'
                                            : 'bg-surface-sunken text-ink-muted hover:text-ink'"
                                        :disabled="unitTaken(line, unit)"
                                        :aria-pressed="String(unit.value) === String(line.unit_id)"
                                        :title="unitTaken(line, unit) ? `Already on the counter by the ${unit.label}` : `Sell by the ${unit.label}`"
                                        @click="switchUnit(line, unit)"
                                    >
                                        {{ unit.label }}
                                    </button>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center rounded-field ring-1 ring-line">
                                <button
                                    type="button"
                                    class="grid size-10 place-items-center text-ink-muted transition hover:text-ink"
                                    aria-label="One fewer"
                                    @click="less(line)"
                                >
                                    <Minus :size="16" />
                                </button>
                                <!-- Sold by measure: the amount is typed, 2.5 m. -->
                                <input
                                    v-if="line.decimals"
                                    :value="line.quantity"
                                    type="number"
                                    inputmode="decimal"
                                    :min="quantityStep(true)"
                                    :step="quantityStep(true)"
                                    :aria-label="`Quantity of ${line.name}${line.unit_code ? ` in ${line.unit_code}` : ''}`"
                                    class="h-10 w-16 border-0 bg-transparent px-1 text-center text-sm font-semibold tabular-nums text-ink [appearance:textfield] focus:ring-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                    @input="setQuantity(line, $event.target.value)"
                                    @blur="setQuantity(line, $event.target.value, { final: true })"
                                />
                                <span v-else class="w-8 text-center text-sm font-semibold tabular-nums text-ink">
                                    {{ count(line.quantity) }}
                                </span>
                                <button
                                    type="button"
                                    class="grid size-10 place-items-center text-ink-muted transition hover:text-ink disabled:opacity-35"
                                    :disabled="!roomForOne(line)"
                                    aria-label="One more"
                                    @click="more(line)"
                                >
                                    <Plus :size="16" />
                                </button>
                            </div>

                            <button
                                type="button"
                                class="grid size-10 shrink-0 place-items-center rounded-field text-ink-subtle transition hover:bg-danger-50 hover:text-danger-700"
                                aria-label="Take it off"
                                @click="remove(line)"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sales on hold, newest first. Shared by every till: the
                     customer comes back to the counter, not to a person. -->
                <div v-if="panel === 'held'" class="min-h-0 flex-1 overflow-y-auto px-5">
                    <p v-if="holds.length === 0" class="py-14 text-center text-sm text-ink-subtle">
                        No sales on hold.
                    </p>

                    <div v-else class="divide-y divide-line">
                        <div v-for="hold in holds" :key="hold.id" class="flex items-center gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-ink">{{ holdLabel(hold) }}</p>
                                <p class="text-2xs text-ink-subtle">
                                    <template v-if="hold.note && hold.customer">{{ hold.customer }} · </template>
                                    {{ count(hold.items) }} {{ hold.items === 1 ? 'item' : 'items' }}
                                    · {{ hold.at }}<template v-if="hold.held_by"> · {{ hold.held_by }}</template>
                                </p>
                            </div>

                            <p class="shrink-0 text-sm font-semibold tabular-nums text-ink">{{ money(hold.total) }}</p>

                            <button
                                type="button"
                                class="inline-flex h-10 shrink-0 items-center rounded-field bg-brand-600 px-3 text-sm font-semibold text-ink-inverse transition hover:brightness-90 disabled:opacity-50"
                                :disabled="holdBusy || posting"
                                @click="recall(hold)"
                            >
                                Recall
                            </button>

                            <button
                                type="button"
                                class="grid size-10 shrink-0 place-items-center rounded-field text-ink-subtle transition hover:bg-danger-50 hover:text-danger-700 disabled:opacity-40"
                                :disabled="holdBusy || posting"
                                :aria-label="`Discard ${holdLabel(hold)}`"
                                @click="discard(hold)"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>
                </div>

                <p v-if="panel === 'held'" class="shrink-0 border-t border-line px-5 py-3 text-xs text-ink-subtle">
                    A held sale keeps nothing aside. The shelf and today's prices are
                    checked again when it is recalled.
                </p>

                <!-- Orders the kiosk sent to the cashier, oldest first (CR-2). -->
                <div v-if="panel === 'pending'" class="min-h-0 flex-1 overflow-y-auto px-5" data-pending-panel>
                    <p v-if="pending.length === 0" class="py-14 text-center text-sm text-ink-subtle">
                        Nothing is waiting to be paid at {{ tillPlace }}.
                    </p>

                    <div v-else class="divide-y divide-line">
                        <div v-for="order in pending" :key="order.id" class="py-3" :data-pending="order.reference">
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-ink">
                                        <span class="font-mono">{{ order.reference }}</span> · {{ order.customer }}
                                    </p>
                                    <p class="text-2xs text-ink-subtle">
                                        {{ order.lines.length }} {{ order.lines.length === 1 ? 'line' : 'lines' }}
                                        · sent {{ order.sent_at }}<template v-if="order.taken_by"> by {{ order.taken_by }}</template>
                                    </p>
                                    <p class="mt-0.5 truncate text-2xs text-ink-muted">
                                        {{ order.lines.map((line) => `${count(line.quantity)} ${line.unit_code ?? ''} ${line.name}`).join(', ') }}
                                    </p>
                                </div>

                                <p class="shrink-0 text-sm font-bold tabular-nums text-ink">{{ money(order.total_amount) }}</p>
                            </div>

                            <div class="mt-2 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="grid size-10 shrink-0 place-items-center rounded-field text-ink-subtle transition hover:bg-surface-sunken hover:text-ink"
                                    :aria-label="`Print the slip for ${order.reference}`"
                                    @click="printOrderSlip(order)"
                                >
                                    <Printer :size="16" />
                                </button>
                                <button
                                    v-if="order.can.cancel"
                                    type="button"
                                    class="grid size-10 shrink-0 place-items-center rounded-field text-ink-subtle transition hover:bg-danger-50 hover:text-danger-700 disabled:opacity-40"
                                    :disabled="posting || cancelBusy"
                                    :aria-label="`Cancel ${order.reference}`"
                                    @click="startCancelPending(order)"
                                >
                                    <Trash2 :size="16" />
                                </button>
                                <button
                                    v-if="order.can.pay"
                                    type="button"
                                    class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-field bg-success-500 px-3 text-sm font-semibold text-ink-inverse transition hover:brightness-90 disabled:opacity-50"
                                    :disabled="posting"
                                    @click="payPending(order)"
                                >
                                    <Receipt :size="16" />
                                    Take payment
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-if="panel === 'pending'" class="shrink-0 border-t border-line px-5 py-3 text-xs text-ink-subtle">
                    Each order holds its goods until it is paid or cancelled, or until closing.
                    It is paid exactly as it was sent.
                </p>

                <!-- Which day. One tap a day either way, because that is
                     what gets asked for; the date itself opens the
                     tablet's own picker for anything further back. -->
                <div
                    v-if="panel === 'today'"
                    class="flex shrink-0 items-center gap-1 border-b border-line px-3 py-2"
                >
                    <button
                        type="button"
                        class="grid size-10 shrink-0 place-items-center rounded-field text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
                        aria-label="The day before"
                        @click="step(-1)"
                    >
                        <ChevronLeft :size="18" />
                    </button>

                    <!-- A real button opening a real calendar. This used
                         to be a transparent date input laid over the
                         label, which on a tablet often did nothing at
                         all when tapped. -->
                    <button
                        type="button"
                        class="flex-1 rounded-field py-2 text-sm font-semibold text-ink transition hover:bg-surface-sunken"
                        @click="picking = true"
                    >
                        {{ day.label }}
                    </button>

                    <button
                        type="button"
                        class="grid size-10 shrink-0 place-items-center rounded-field text-ink-muted transition hover:bg-surface-sunken hover:text-ink disabled:opacity-30"
                        :disabled="day.is_today"
                        aria-label="The day after"
                        @click="step(1)"
                    >
                        <ChevronRight :size="18" />
                    </button>

                    <button
                        v-if="!day.is_today"
                        type="button"
                        class="h-10 shrink-0 rounded-field px-3 text-xs font-semibold text-brand-700 transition hover:bg-brand-50"
                        @click="showDay(day.max)"
                    >
                        Today
                    </button>
                </div>

                <!-- What sold that day, newest first. No cost and no margin:
                     this screen faces a customer across a counter. -->
                <div v-if="panel === 'today'" class="min-h-0 flex-1 overflow-y-auto px-5">
                    <p v-if="sales.rows.length === 0" class="py-14 text-center text-sm text-ink-subtle">
                        Nothing went through on this day.
                    </p>

                    <div v-else class="divide-y divide-line">
                        <button
                            v-for="sale in sales.rows"
                            :key="sale.id"
                            type="button"
                            class="flex w-full items-center gap-3 py-3 text-left transition hover:bg-surface-sunken"
                            @click="viewing = `/sales/${sale.id}`"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2">
                                    <span class="truncate font-mono text-xs font-semibold text-ink">
                                        {{ sale.reference }}
                                    </span>
                                    <ActionBadge
                                        v-if="sale.status !== 'posted'"
                                        :label="sale.status_label"
                                        :tone="sale.status_tone"
                                    />
                                </p>
                                <p class="text-2xs text-ink-subtle">
                                    {{ sale.at }} · {{ count(sale.items) }}
                                    {{ sale.items === 1 ? 'item' : 'items' }}
                                </p>
                            </div>

                            <p
                                class="shrink-0 text-sm font-semibold tabular-nums"
                                :class="sale.status === 'posted' ? 'text-ink' : 'text-ink-subtle line-through'"
                            >
                                {{ money(sale.total) }}
                            </p>

                            <Eye :size="16" class="shrink-0 text-ink-subtle" />
                        </button>
                    </div>
                </div>

                <!-- The takings, which is the figure anybody at a till wants -->
                <div v-if="panel === 'today'" class="shrink-0 border-t border-line px-5 py-4">
                    <div class="flex items-baseline justify-between">
                        <p class="text-sm text-ink-muted">
                            {{ count(sales.count) }} {{ sales.count === 1 ? 'sale' : 'sales' }}
                        </p>
                        <p class="text-xl font-bold tabular-nums text-ink">{{ money(sales.total) }}</p>
                    </div>
                    <!-- How it came in: what the drawer and the GCash app
                         should each show. -->
                    <dl v-if="(sales.by_method ?? []).length" class="mt-2 space-y-1">
                        <div
                            v-for="method in sales.by_method"
                            :key="method.name"
                            class="flex items-baseline justify-between text-xs"
                        >
                            <dt class="text-ink-muted">{{ method.name }}</dt>
                            <dd class="tabular-nums font-semibold text-ink">{{ money(method.amount) }}</dd>
                        </div>
                    </dl>
                    <div
                        v-if="Number(sales.on_account) > 0"
                        class="mt-1 flex items-baseline justify-between text-xs"
                    >
                        <span class="text-ink-muted">On account (not taken)</span>
                        <span class="tabular-nums font-semibold text-ink">{{ money(sales.on_account) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-ink-subtle">
                        Voided sales are not counted.
                        <!-- The total above covers the whole day, not the
                             rows on screen, so it stays right. -->
                        <span v-if="sales.of > sales.shown">
                            Showing the newest {{ count(sales.shown) }} of {{ count(sales.of) }}.
                        </span>
                    </p>
                </div>

                <!-- What it comes to -->
                <div v-if="panel === 'counter'" class="shrink-0 border-t border-line px-5 py-4">
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-baseline justify-between">
                            <dt class="text-ink-muted">Subtotal</dt>
                            <dd class="tabular-nums text-ink">{{ money(subtotal) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-ink-muted">Discount</dt>
                            <dd class="flex items-center gap-1">
                                <input
                                    v-model="discount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="h-10 w-28 rounded-field bg-surface-sunken px-2.5 text-right text-sm tabular-nums text-ink focus:outline-none focus:ring-2 focus:ring-brand-500/40"
                                    placeholder="0.00"
                                />
                            </dd>
                        </div>
                        <template v-if="vatRate > 0">
                            <div class="flex items-baseline justify-between">
                                <dt class="text-ink-muted">Net of VAT</dt>
                                <dd class="tabular-nums text-ink">{{ money(vat.net) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <dt class="text-ink-muted">VAT ({{ vatRate }}%)</dt>
                                <dd class="tabular-nums text-ink">{{ money(vat.vat) }}</dd>
                            </div>
                        </template>
                    </dl>

                    <div class="mt-3 flex items-baseline justify-between border-t border-line pt-3">
                        <p class="text-base font-bold text-ink">Total</p>
                        <p class="text-2xl font-bold tabular-nums text-ink">{{ money(total) }}</p>
                    </div>

                    <p class="mt-1 text-right text-xs text-ink-subtle">
                        {{ count(units) }} {{ units === 1 ? 'item' : 'items' }}
                    </p>

                    <!-- Two rows, so every label sits on one line beside its icon:
                         Hold and Pending payment side by side, Take payment —
                         the one most tapped — full width under the thumb. -->
                    <div class="mt-3 space-y-2" data-till-actions>
                        <div v-if="can.takePayment || can.sendPending" class="flex gap-2">
                            <!-- Park it: the customer went back for one more thing. -->
                            <button
                                v-if="can.takePayment"
                                type="button"
                                class="flex h-12 flex-1 items-center justify-center gap-2 rounded-field bg-surface-sunken px-3 text-base font-semibold whitespace-nowrap text-ink transition hover:bg-warning-50 hover:text-warning-700 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="basket.length === 0 || posting || holdBusy"
                                @click="startHold"
                            >
                                <CirclePause :size="19" class="shrink-0" />
                                Hold
                            </button>

                            <!-- CR-2: send it to the cashier instead of taking the money here. -->
                            <button
                                v-if="can.sendPending"
                                type="button"
                                class="flex flex-[1.6] items-center justify-center gap-2 rounded-field border-2 border-brand-600 bg-surface px-3 text-base font-bold whitespace-nowrap text-brand-700 transition hover:bg-brand-50 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
                                :class="can.takePayment ? 'h-12' : 'h-14'"
                                :disabled="basket.length === 0 || posting || sending"
                                data-send-pending
                                @click="sendPending"
                            >
                                <Hourglass :size="19" class="shrink-0" />
                                {{ sending ? 'Sending…' : 'Pending payment' }}
                            </button>
                        </div>

                        <button
                            v-if="can.takePayment"
                            type="button"
                            class="flex h-14 w-full items-center justify-center gap-2.5 rounded-field bg-success-500 text-lg font-bold whitespace-nowrap text-ink-inverse transition hover:brightness-90 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="basket.length === 0 || posting || sending"
                            @click="complete"
                        >
                            <Receipt :size="21" class="shrink-0" />
                            {{ posting ? 'Posting…' : 'Take payment' }}
                        </button>
                    </div>
                </div>
            </aside>
        </div>

        <!-- Taking the money -->
        <Modal
            :open="paying"
            title="Take payment"
            :subtitle="paySubtitle"
            :busy="posting"
            :close-on-backdrop="false"
            size="md"
            @close="stopPaying"
        >
            <div class="mb-4 flex items-baseline justify-between rounded-field bg-surface-sunken px-4 py-3">
                <span class="text-sm font-semibold text-ink-muted">Amount due</span>
                <span class="text-3xl font-bold tabular-nums text-ink">{{ money(dueTotal) }}</span>
            </div>

            <!-- An order the kiosk sent: what is being paid for, as sent. -->
            <ul v-if="payingPending" class="mb-4 divide-y divide-line rounded-field border border-line text-sm" data-paying-pending>
                <li v-for="line in payingPending.lines" :key="line.id" class="flex justify-between gap-3 px-3 py-2">
                    <span class="min-w-0 truncate text-ink">{{ count(line.quantity) }} {{ line.unit_code }} · {{ line.name }}</span>
                    <span class="shrink-0 tabular-nums text-ink-muted">{{ money(line.line_total) }}</span>
                </li>
                <li v-if="Number(payingPending.discount) > 0" class="flex justify-between gap-3 px-3 py-2">
                    <span class="text-ink-muted">Discount</span>
                    <span class="tabular-nums text-ink-muted">−{{ money(payingPending.discount) }}</span>
                </li>
            </ul>
            <p v-if="paymentErrors.pending_payment_id" class="mb-3 text-xs font-medium text-danger-700">
                {{ paymentErrors.pending_payment_id }}
            </p>

            <PaymentEntry
                v-model="payments"
                v-model:on-account="onAccount"
                v-model:terms-days="termsDays"
                :account="payAccount"
                :may-extend-terms="!!can.extendTerms"
                :sale-date="today"
                :credit-hint="payCreditHint"
                :methods="paymentMethods"
                :total="dueTotal"
                :errors="paymentErrors"
                :disabled="posting"
                touch
            />

            <template #footer>
                <PrimaryButton variant="secondary" :disabled="posting" @click="stopPaying">
                    Back
                </PrimaryButton>
                <PrimaryButton :loading="posting" :disabled="!settled.ready" @click="post">
                    Complete sale
                </PrimaryButton>
            </template>
        </Modal>

        <!-- Parking the counter -->
        <Modal
            :open="holding"
            title="Hold this sale"
            :subtitle="`${count(units)} ${units === 1 ? 'item' : 'items'} · ${customerName} · ${money(total)}`"
            :busy="holdBusy"
            size="sm"
            @close="!holdBusy && (holding = false)"
        >
            <p class="text-sm text-ink-muted">
                It goes under <span class="font-semibold text-ink">Held</span>, so you can serve the next
                customer. Nothing leaves the shelf until it is recalled and paid.
            </p>

            <label for="hold-note" class="mt-4 mb-1.5 block text-sm font-medium text-ink">
                Note <span class="font-normal text-ink-subtle">(optional)</span>
            </label>
            <TextField
                id="hold-note"
                v-model="holdNote"
                maxlength="80"
                :invalid="!!holdError"
                placeholder="Mang Ben, getting his truck"
                @keydown.enter.prevent="holdCounter()"
            />
            <p v-if="holdError" class="mt-1.5 text-xs font-medium text-danger-700">{{ holdError }}</p>

            <template #footer>
                <PrimaryButton variant="secondary" :disabled="holdBusy" @click="holding = false">
                    Back
                </PrimaryButton>
                <PrimaryButton :loading="holdBusy" @click="holdCounter()">
                    Hold sale
                </PrimaryButton>
            </template>
        </Modal>

        <!-- Cancelling an order sent to the cashier (CR-2) -->
        <Modal
            :open="cancellingPending !== null"
            title="Cancel this order"
            :subtitle="cancellingPending ? `${cancellingPending.reference} · ${cancellingPending.customer} · ${money(cancellingPending.total_amount)}` : ''"
            :busy="cancelBusy"
            size="sm"
            @close="!cancelBusy && (cancellingPending = null)"
        >
            <p class="text-sm text-ink-muted">
                Its goods are free to sell again at once. Say why, for the record.
            </p>

            <label for="pending-cancel-reason" class="mt-4 mb-1.5 block text-sm font-medium text-ink">Reason</label>
            <TextField
                id="pending-cancel-reason"
                v-model="cancelReason"
                maxlength="255"
                :invalid="!!cancelError"
                placeholder="The customer left without paying"
                @keydown.enter.prevent="cancelPending()"
            />
            <p v-if="cancelError" class="mt-1.5 text-xs font-medium text-danger-700">{{ cancelError }}</p>

            <template #footer>
                <PrimaryButton variant="secondary" :disabled="cancelBusy" @click="cancellingPending = null">
                    Back
                </PrimaryButton>
                <PrimaryButton variant="danger" :loading="cancelBusy" @click="cancelPending()">
                    Cancel order
                </PrimaryButton>
            </template>
        </Modal>

        <DocumentModal
            :open="viewing !== null"
            :document-url="viewing"
            hide-cost
            @close="viewing = null"
        />

        <DayPicker
            :open="picking"
            :model-value="day.date"
            :max="day.max"
            :traded="tradedOn"
            @update:model-value="showDay"
            @month="showMonth"
            @close="picking = false"
        />

        <!-- A sale slip, while one is printing. Off screen otherwise. -->
        <SlipPrinter />
    </div>
</template>
