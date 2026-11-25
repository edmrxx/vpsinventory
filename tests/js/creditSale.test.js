import { describe, expect, it } from 'vitest'
import { settleSale } from '../../resources/js/lib/payments'

/**
 * The till's half of a credit sale, mirroring
 * `PaymentSettlement::settleSale()`. ₱1,850 owed, ₱5,000 of credit left.
 */
const cash = { value: 1, label: 'Cash', cash: true, reference: false }
const gcash = { value: 2, label: 'GCash', cash: false, reference: true }
const methods = [cash, gcash]
const account = { limit: '5000.00', balance: '0.00', available: '5000.00', terms_days: 30 }
const total = 185000

const pay = (id, amount, reference = '') => ({ payment_method_id: id, amount, reference })

describe('settleSale', () => {
    it('is the ordinary settlement when nothing goes on account', () => {
        expect(settleSale(total, '', [pay(1, '2000.00')], methods, account)).toMatchObject({ change: 15000, ready: true })
    })

    it('treats an account box at zero as nothing on account', () => {
        expect(settleSale(total, '0.00', [pay(1, '2000.00')], methods, account)).toMatchObject({ change: 15000, ready: true })
    })

    it('takes a whole sale on account with nothing paid', () => {
        expect(settleSale(total, '1850.00', [], methods, account)).toMatchObject({ due: 0, ready: true })
    })

    it('lets the cash box stay at zero on a sale wholly on account', () => {
        expect(settleSale(total, '1850.00', [pay(1, '0.00')], methods, account).ready).toBe(true)
    })

    it('refuses money taken on a sale wholly on account', () => {
        const settled = settleSale(total, '1850.00', [pay(2, '500.00', 'X')], methods, account)

        expect(settled.ready).toBe(false)
        expect(settled.errors.on_account).toContain('whole sale is on account')
    })

    it('splits between the account and cash', () => {
        expect(settleSale(total, '850.00', [pay(1, '1000.00')], methods, account)).toMatchObject({
            change: 0,
            due: 0,
            ready: true,
        })
    })

    it('splits between the account and GCash', () => {
        expect(settleSale(total, '850.00', [pay(2, '1000.00', 'X')], methods, account).ready).toBe(true)
    })

    it('gives no change on a sale partly on account', () => {
        const settled = settleSale(total, '850.00', [pay(1, '1500.00')], methods, account)

        expect(settled.ready).toBe(false)
        expect(settled.change).toBe(0)
        expect(settled.errors['payments.0.amount']).toContain('put less on account')
    })

    it('still asks for the rest when the payment falls short', () => {
        const settled = settleSale(total, '850.00', [pay(1, '500.00')], methods, account)

        expect(settled.ready).toBe(false)
        expect(settled.errors['payments.0.amount']).toContain('more is needed')
    })

    it('refuses more on account than the sale', () => {
        const settled = settleSale(total, '1850.01', [], methods, account)

        expect(settled.ready).toBe(false)
        expect(settled.errors.on_account).toContain('more than the sale')
    })

    it('refuses more than the credit left', () => {
        const settled = settleSale(total, '1850.00', [], methods, { ...account, available: '1000.00' })

        expect(settled.ready).toBe(false)
        expect(settled.errors.on_account).toContain('1,000.00')
    })

    it('allows exactly the credit left', () => {
        expect(settleSale(total, '1850.00', [], methods, { ...account, available: '1850.00' }).ready).toBe(true)
    })

    it('refuses a customer with no credit', () => {
        const settled = settleSale(total, '1850.00', [], methods, null)

        expect(settled.ready).toBe(false)
        expect(settled.errors.on_account).toContain('no credit')
    })
})
