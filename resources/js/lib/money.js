import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Amounts, written the way the settings say.
 *
 * The symbol is a setting, so a business running in another currency changes
 * one box instead of every screen.
 *
 * Laravel sends a decimal column as a string, so every value is turned into
 * a number here. Never do arithmetic on the raw prop.
 */
export function useMoney() {
    const page = usePage()

    const symbol = computed(() => page.props.system?.currency_symbol ?? '')

    /** "1234.5" -> "₱1,234.50" */
    function money(value, { withSymbol = true } = {}) {
        const amount = Number(value ?? 0)

        if (Number.isNaN(amount)) return withSymbol ? `${symbol.value}0.00` : '0.00'

        const formatted = amount.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })

        return withSymbol ? `${symbol.value}${formatted}` : formatted
    }

    return { money, symbol }
}

/**
 * The VAT inside a VAT-inclusive amount.
 *
 * Every price typed into this system already has the VAT in it, so the tax
 * is taken out rather than added on. Worked in centavos, and the VAT is
 * whatever is left after the net, so the two halves always add back to the
 * amount they came from.
 *
 * The same arithmetic as `App\Support\Vat` on the server, which is what the
 * stored document is built from. This one only draws what is on the screen
 * while somebody types.
 */
export function vatSplit(gross, rate) {
    const amount = Math.round(Number(gross ?? 0) * 100)
    const basisPoints = Math.round(Number(rate ?? 0) * 100)

    if (!basisPoints || Number.isNaN(amount)) {
        return { net: Number(gross ?? 0) || 0, vat: 0 }
    }

    // Whole numbers only. Dividing in floats puts this one centavo away
    // from the server on an amount whose net lands on exactly half a
    // centavo: ₱42.14 came out 37.62 here and 37.63 there. The rate is
    // carried as basis points and the halving is done inside the fraction,
    // which is what `App\Support\Vat` does with the same figures.
    const sign = amount < 0 ? -1 : 1
    const divisor = 10000 + basisPoints
    const net = sign * Math.floor((2 * Math.abs(amount) * 10000 + divisor) / (2 * divisor))

    return { net: net / 100, vat: (amount - net) / 100 }
}

/**
 * The two VAT rates, which answer two different questions.
 *
 * Selling: do *we* charge VAT? That follows the Tax Settings switch.
 * Buying: did the supplier charge VAT? That follows the supplier, and the
 * rate still has to be known even with the switch off.
 *
 * Neither is used to draw a document already posted. A purchase carries
 * the rate it was invoiced at, and the server sends it.
 */
export function useVat() {
    const page = usePage()

    /**
     * What the business charges on what it sells.
     *
     * Zero when the business is not VAT-registered. Sales, sales returns
     * and the till draw with this.
     */
    const rate = computed(() => {
        const tax = page.props.tax ?? {}

        return tax.vat_registered ? Number(tax.vat_rate ?? 0) : 0
    })

    /**
     * The rate as configured, whatever the switch says.
     *
     * A VAT-registered supplier charges VAT whether or not we are
     * registered — you simply cannot claim it back. So the buying side
     * needs this figure even with the switch off, and asks the supplier
     * whether it applies.
     */
    const configuredRate = computed(() => Number(page.props.tax?.vat_rate ?? 0))

    return { rate, configuredRate, vatSplit }
}

/** Decimals a quantity may carry. The same as `App\Support\Quantity::PLACES`. */
export const QUANTITY_PLACES = 3

/**
 * A quantity for reading: 1234.5 -> "1,234.5", 10 -> "10".
 *
 * A quantity may carry up to three decimals (2.5 m, 0.125 kg). A whole one
 * is written without them, so 10 never reads as "10.000".
 */
export function count(value) {
    return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: QUANTITY_PLACES })
}

const SCALE = 10 ** QUANTITY_PLACES

/**
 * A quantity in whole thousandths: "2.5" -> 2500.
 *
 * Quantities are added and compared in thousandths, the way money is added
 * in centavos, because 0.1 + 0.2 in floats is 0.30000000000000004.
 */
export function toMilli(value) {
    const number = Number(value ?? 0)

    return Number.isFinite(number) ? Math.round(number * SCALE) : 0
}

/** Several quantities added up exactly: [0.1, 0.2] -> 0.3. */
export function sumQuantities(values) {
    return values.reduce((sum, value) => sum + toMilli(value), 0) / SCALE
}

/** The step and the floor of a quantity box: 0.001 where the unit allows parts, 1 where it does not. */
export function quantityStep(decimals) {
    return decimals ? String(1 / SCALE) : '1'
}

/**
 * Quantity times a price, to the centavo, rounded half up.
 *
 * 2.5 m at 18.35 is 45.875, which is 45.88. The same sum as
 * `Quantity::lineTotal()` on the server, in the same whole numbers, so the
 * line on the form is the line on the document.
 */
export function lineTotal(price, quantity) {
    return lineCentavos(price, quantity) / 100
}

/** `lineTotal()` in whole centavos, for adding several lines up exactly. */
export function lineCentavos(price, quantity) {
    const product = Math.round(Number(price ?? 0) * 100) * toMilli(quantity)

    if (!Number.isFinite(product)) return 0

    const sign = product < 0 ? -1 : 1

    return sign * Math.floor((2 * Math.abs(product) + SCALE) / (2 * SCALE))
}

/**
 * What a selling document comes to, in pesos (phase 28.1): the goods, less
 * the discount — never more than the goods — plus the mobilization charge,
 * which is never discounted. The same sum as `SaleTotals::of()` on the
 * server, in whole centavos, so the form shows the total the server stores.
 */
export function documentTotal(subtotal, discount, mobilization = 0) {
    const goods = Math.round(Number(subtotal ?? 0) * 100)
    const off = Math.min(Math.max(Math.round(Number(discount ?? 0) * 100) || 0, 0), Math.max(goods, 0))
    const charge = Math.max(Math.round(Number(mobilization ?? 0) * 100) || 0, 0)

    return (goods - off + charge) / 100
}
