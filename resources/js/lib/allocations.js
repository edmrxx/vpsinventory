/**
 * Spreading a customer payment over the invoices it pays, drawn while
 * somebody types.
 *
 * The same rules as `App\Support\PaymentAllocation` on the server, in whole
 * centavos like it. The server weighs it all again and decides; this only
 * lets the screen say "₱500.00 is not against any invoice yet" before
 * anybody presses Save.
 *
 * - No invoice takes more than it still owes.
 * - The invoices add up to the payment exactly.
 */
import { centavos, pesos } from './payments'

/**
 * Fill the payment in over the invoices in the order given — the form gives
 * them soonest due first — each up to what it owes, until the money runs out.
 *
 * @param {number|string} amount  The payment, in pesos as typed.
 * @param {Array<{id:number, balance_due:string|number}>} invoices
 * @returns {Record<number, string>}  Amount per invoice id, in pesos; '' for none.
 */
export function fillOldestFirst(amount, invoices) {
    let left = Math.max(centavos(amount), 0)
    const filled = {}

    for (const invoice of invoices) {
        const take = Math.min(left, Math.max(centavos(invoice.balance_due), 0))

        filled[invoice.id] = take > 0 ? pesos(take) : ''
        left -= take
    }

    return filled
}

/**
 * Where the payment stands against the invoices.
 *
 * @param {number|string} amount  The payment, in pesos as typed.
 * @param {Record<number, string|number>} rows  Amount per invoice id, as typed.
 * @param {Array<{id:number, reference:string, balance_due:string|number}>} invoices
 * @returns {{ placed:number, left:number, over:number, errors:Record<number,string>, ready:boolean }}
 *          `placed`, `left` and `over` in centavos: what the invoices take,
 *          what of the payment is not placed yet, and how far they go past it.
 */
export function weighAllocations(amount, rows, invoices) {
    const payment = Math.max(centavos(amount), 0)
    const errors = {}
    let placed = 0

    for (const invoice of invoices) {
        const cents = centavos(rows[invoice.id])

        if (cents === 0) continue

        if (cents < 0) {
            errors[invoice.id] = 'An amount cannot be below nothing.'
            continue
        }

        const owed = centavos(invoice.balance_due)

        if (cents > owed) {
            errors[invoice.id] = `${invoice.reference} owes only ${pesos(owed)}.`
            continue
        }

        placed += cents
    }

    return {
        placed,
        left: Math.max(payment - placed, 0),
        over: Math.max(placed - payment, 0),
        errors,
        ready: payment > 0 && placed === payment && Object.keys(errors).length === 0,
    }
}
