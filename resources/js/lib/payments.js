/**
 * How a sale is paid, drawn while the cashier types.
 *
 * The same sums as `App\Support\PaymentSettlement` on the server, in whole
 * centavos like it, so the change on the screen is the change the sale is
 * stored with. The server works it all out again and decides; this only
 * lets the till say "Change ₱150.00" before anybody presses anything.
 *
 * - Cash may be more than is owed; the rest is change.
 * - Nothing else may: nobody gives change from GCash.
 * - One cash payment per sale.
 * - A method that asks for a reference number gets one.
 */

/** Pesos to whole centavos: "18.35" -> 1835. Empty is nothing. */
export function centavos(value) {
    const number = Number(value)

    return Number.isFinite(number) ? Math.round(number * 100) : 0
}

/** Whole centavos back to a figure a money box shows: 1835 -> "18.35". */
export function pesos(cents) {
    return (Math.max(cents, 0) / 100).toFixed(2)
}

/**
 * Settle a sale.
 *
 * @param {number} total  What the sale comes to, in centavos.
 * @param {Array<{payment_method_id:number|string, amount:number|string, reference?:string}>} payments
 * @param {Array<{value:number, label:string, cash:boolean, reference:boolean}>} methods
 * @param {{ givesChange?: boolean }} [options]  No change on a sale partly on account.
 * @returns {{ change:number, due:number, paid:number, cashOwed:number, errors:Record<string,string>, ready:boolean }}
 *          Every figure in centavos. `due` is what is still to pay; `paid`
 *          what the payments put towards the sale.
 */
export function settle(total, payments, methods, { givesChange = true } = {}) {
    const result = { change: 0, due: 0, paid: 0, cashOwed: 0, errors: {}, ready: false }

    if (total <= 0) return { ...result, ready: true }

    const rows = payments ?? []

    if (rows.length === 0) {
        return { ...result, due: total, errors: { payments: 'Record how this sale was paid.' } }
    }

    const byId = new Map((methods ?? []).map((method) => [String(method.value), method]))
    const errors = {}
    let cashIndex = null
    let cashHanded = 0
    let others = 0

    rows.forEach((row, index) => {
        const method = byId.get(String(row.payment_method_id))
        const amount = centavos(row.amount)

        if (!method) {
            errors[`payments.${index}.payment_method_id`] = 'Pick a payment method that is still active.'

            return
        }

        if (amount <= 0) errors[`payments.${index}.amount`] = 'Type the amount paid.'

        if (method.reference && !String(row.reference ?? '').trim()) {
            errors[`payments.${index}.reference`] = `Type the ${method.label} reference number.`
        }

        if (method.cash) {
            if (cashIndex !== null) {
                errors[`payments.${index}.payment_method_id`] =
                    'Only one cash payment per sale. Put the cash together on one line.'

                return
            }

            cashIndex = index
            cashHanded = amount
        } else {
            others += Math.max(amount, 0)
        }
    })

    const owedInCash = Math.max(total - others, 0)
    const paid = Math.min(others, total) + Math.min(cashHanded, owedInCash)
    const shape = {
        ...result,
        paid,
        due: Math.max(total - paid, 0),
        cashOwed: cashIndex === null ? 0 : owedInCash,
        change: cashIndex === null ? 0 : Math.max(cashHanded - owedInCash, 0),
    }

    if (others > total) {
        errors.payments = `Payments other than cash come to ${money(others)}, more than the sale's ${money(total)}. Change is given only from cash.`
    } else if (cashIndex !== null && owedInCash === 0) {
        errors[`payments.${cashIndex}.amount`] =
            'The other payments already cover the sale, so nothing is left to pay in cash.'
    } else if (cashIndex !== null && cashHanded < owedInCash && !errors[`payments.${cashIndex}.amount`]) {
        errors[`payments.${cashIndex}.amount`] =
            `${money(owedInCash - cashHanded)} more is needed: ${money(owedInCash)} is left to pay in cash.`
    } else if (!givesChange && cashIndex !== null && cashHanded > owedInCash) {
        shape.change = 0
        errors[`payments.${cashIndex}.amount`] =
            `Only ${money(owedInCash)} is left to pay in cash. To take more, put less on account.`
    } else if (cashIndex === null && others < total) {
        errors.payments = `Still ${money(total - others)} to pay.`
    }

    return { ...shape, errors, ready: Object.keys(errors).length === 0 }
}

/**
 * Settle a sale that may be partly or wholly on the customer's account.
 *
 * The same rules as `PaymentSettlement::settleSale()`. Credit is always asked
 * for in so many pesos, never inferred from a payment that falls short, and
 * what is left is paid the ordinary way, without change. The credit figure is
 * as of when the page opened; the server weighs it again and decides.
 *
 * @param {number} total  In centavos.
 * @param {number|string} onAccount  In pesos, as typed. Empty is none.
 * @param {Array} payments
 * @param {Array} methods
 * @param {{ available:string, terms_days?:number }|null} account  The customer's credit, or null.
 * @param {{ days:number|string|null, mayExtend:boolean }|null} [terms]  The days agreed on
 *   this sale (phase 18.1), and whether this user may give more than the usual.
 * @returns {ReturnType<typeof settle>}
 */
export function settleSale(total, onAccount, payments, methods, account, terms = null) {
    const credit = centavos(onAccount)

    if (credit <= 0) return settle(total, payments, methods)

    const blocked = (message, field = 'on_account') => ({
        change: 0,
        due: Math.max(total - credit, 0),
        paid: 0,
        cashOwed: 0,
        errors: { [field]: message },
        ready: false,
    })

    if (credit > total) return blocked(`On account cannot be more than the sale's ${money(total)}.`)
    if (!account) return blocked('This customer has no credit, so this sale cannot go on account.')

    const available = centavos(account.available)

    if (credit > available) return blocked(`Only ${money(available)} of credit is left.`)

    const termsError = terms ? termsRefusal(account, terms.days, terms.mayExtend) : null

    if (termsError) return blocked(termsError, 'terms_days')

    const left = total - credit

    if (left === 0) {
        if ((payments ?? []).some((row) => centavos(row.amount) > 0)) {
            return blocked('The whole sale is on account, so nothing is paid now. Put less on account, or take the payment off.')
        }

        return settle(0, payments, methods)
    }

    return settle(left, payments, methods, { givesChange: false })
}

/** The longest terms a sale may be given, as `CustomerCredit::MAX_TERMS_DAYS`. */
export const MAX_TERMS_DAYS = 365

/**
 * The days agreed on one sale on account (phase 18.1): what was typed, or
 * the customer's usual terms when the box is empty.
 *
 * @param {{ terms_days?:number }|null} account
 * @param {number|string|null} days  As typed.
 * @returns {number}
 */
export function agreedTerms(account, days) {
    return days === '' || days === null || days === undefined ? Number(account?.terms_days ?? 0) : Number(days)
}

/**
 * Why these days cannot be given, or null. The same rule as
 * `CustomerCredit::termsRefusal()`: fewer days than usual is anybody's to
 * give, more needs the right to set credit.
 *
 * @param {{ terms_days?:number }|null} account
 * @param {number|string|null} days  As typed. Empty is the usual.
 * @param {boolean} mayExtend
 * @returns {string|null}
 */
export function termsRefusal(account, days, mayExtend) {
    const agreed = agreedTerms(account, days)

    if (!Number.isInteger(agreed)) return 'Type the terms as a whole number of days.'
    if (agreed < 0) return 'Terms cannot be below zero.'
    if (agreed > MAX_TERMS_DAYS) return `Terms are at most ${MAX_TERMS_DAYS} days.`

    const usual = Number(account?.terms_days ?? 0)

    if (agreed > usual && !mayExtend) {
        return `This customer's usual terms are ${daysLabel(usual)}. Only somebody who may set credit can give more.`
    }

    return null
}

/**
 * When a sale on this date falls due after so many days: "24 Dec 2026".
 * Worked out on the calendar date alone, so no time zone can move it.
 *
 * @param {string} saleDate  Y-m-d.
 * @param {number} days
 * @returns {string|null}  Null when the date cannot be read.
 */
export function dueDateLabel(saleDate, days) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(saleDate ?? '')

    if (!match || !Number.isInteger(days)) return null

    const due = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + days))

    // Spelt out here, as PHP's "d M Y" writes it: a browser's own short
    // month may read "Sept".
    return `${String(due.getUTCDate()).padStart(2, '0')} ${MONTHS[due.getUTCMonth()]} ${due.getUTCFullYear()}`
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/**
 * Why "On account" is not offered for the customer picked, or '' when it
 * is, or when nobody asks (Walk-in pays at the counter).
 *
 * @param {{ label:string, walk_in?:boolean, credit?:object|null }|undefined} customer
 * @returns {string}
 */
export function creditHintFor(customer) {
    if (!customer || customer.walk_in || customer.credit) return ''

    return `${customer.label} has no credit limit, so this sale is paid in full now. To sell on account, set a credit limit under Customers.`
}

/** "1 day", "30 days". */
export function daysLabel(days) {
    return `${days} ${Number(days) === 1 ? 'day' : 'days'}`
}

/**
 * Notes a customer is likely to hand over for what is owed: ₱1,850 is
 * ₱1,900 or ₱2,000. One tap instead of typing. Never the exact figure,
 * which has its own button.
 *
 * @param {number} owed  In centavos.
 * @returns {number[]}  In centavos, smallest first, at most three.
 */
export function likelyNotes(owed) {
    if (owed <= 0) return []

    const suggestions = new Set()

    // The next note of each size strictly above what is owed, so ₱2,000
    // owed still offers ₱2,100, ₱2,500 and ₱3,000.
    for (const step of [10000, 50000, 100000]) {
        suggestions.add(Math.floor(owed / step) * step + step)
    }

    return [...suggestions].sort((a, b) => a - b).slice(0, 3)
}

function money(cents) {
    return (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
