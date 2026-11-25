import { describe, expect, it } from 'vitest'
import { fillOldestFirst, weighAllocations } from '../../resources/js/lib/allocations'

/**
 * Spreading a customer payment over invoices, as the form draws it.
 *
 * The server (`App\Support\PaymentAllocation`, `CustomerPaymentTest`) holds
 * the same rules: no invoice takes more than it owes, and the invoices add
 * up to the payment exactly.
 */

const invoices = [
    { id: 1, reference: 'SI-0001', balance_due: '1000.00' },
    { id: 2, reference: 'SI-0002', balance_due: '250.50' },
    { id: 3, reference: 'SI-0003', balance_due: '400.00' },
]

describe('fillOldestFirst', () => {
    it('pays the soonest due first, each up to what it owes', () => {
        expect(fillOldestFirst('1100', invoices)).toEqual({ 1: '1000.00', 2: '100.00', 3: '' })
    })

    it('pays everything when the money covers it, and leaves the rest unplaced', () => {
        expect(fillOldestFirst('2000', invoices)).toEqual({ 1: '1000.00', 2: '250.50', 3: '400.00' })
    })

    it('places nothing for an empty amount', () => {
        expect(fillOldestFirst('', invoices)).toEqual({ 1: '', 2: '', 3: '' })
    })

    it('adds up in centavos, not floats', () => {
        const filled = fillOldestFirst('1250.50', invoices)

        expect(filled).toEqual({ 1: '1000.00', 2: '250.50', 3: '' })
    })
})

describe('weighAllocations', () => {
    it('is ready when the invoices add up to the payment exactly', () => {
        const result = weighAllocations('1100', { 1: '1000', 2: '100' }, invoices)

        expect(result).toMatchObject({ placed: 110000, left: 0, over: 0, ready: true })
    })

    it('says how much of the payment is not placed yet', () => {
        const result = weighAllocations('1500', { 1: '1000' }, invoices)

        expect(result).toMatchObject({ placed: 100000, left: 50000, over: 0, ready: false })
    })

    it('says how far the invoices go past the payment', () => {
        const result = weighAllocations('500', { 1: '1000' }, invoices)

        expect(result).toMatchObject({ over: 50000, ready: false })
    })

    it('refuses more than an invoice owes, under that invoice', () => {
        const result = weighAllocations('300', { 2: '300' }, invoices)

        expect(result.errors[2]).toBe('SI-0002 owes only 250.50.')
        expect(result.ready).toBe(false)
    })

    it('is not ready with no payment', () => {
        expect(weighAllocations('', {}, invoices).ready).toBe(false)
    })
})
