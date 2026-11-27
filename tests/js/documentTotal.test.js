import { describe, expect, it } from 'vitest'
import { documentTotal } from '../../resources/js/lib/money.js'

/**
 * Phase 28.1 (CR-4): the total a quotation, order or sale form shows is the
 * server's `SaleTotals` sum — goods less the discount, plus mobilization.
 */
describe('what a selling document comes to', () => {
    it('adds the mobilization after the discount', () => {
        expect(documentTotal(2500, 100, 500)).toBe(2900)
        expect(documentTotal('2500.00', '100.00', '500.00')).toBe(2900)
    })

    it('never lets the discount reach the charge', () => {
        expect(documentTotal(2500, 99999, 500)).toBe(500)
        expect(documentTotal(0, 100, 500)).toBe(500)
    })

    it('treats an empty or negative box as nothing, as the server does', () => {
        expect(documentTotal(2500, '', '')).toBe(2500)
        expect(documentTotal(2500, 0, -50)).toBe(2500)
        expect(documentTotal(2500, 100)).toBe(2400)
    })

    it('adds centavos exactly', () => {
        expect(documentTotal(0.1, 0, 0.2)).toBe(0.3)
        expect(documentTotal(1820.55, 20.05, 333.33)).toBe(2133.83)
    })
})
