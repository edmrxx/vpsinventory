import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}))

const { count, lineTotal, quantityStep, sumQuantities, toMilli } = await import('../../resources/js/lib/money')

/**
 * Quantities with decimals, drawn while somebody types.
 *
 * `lineTotal()` has to agree with `Quantity::lineTotal()` on the server to
 * the centavo, because the person reads this figure on the form and then the
 * stored one on the document. `tests/Unit/QuantityTest.php` holds the server
 * to the same cases.
 */
describe('lineTotal', () => {
    it('prices a part to the centavo, half up, as the server does', () => {
        expect(lineTotal('18.35', '2.5')).toBe(45.88)
        expect(lineTotal(12.35, 1.5)).toBe(18.53)
        expect(lineTotal('500.00', 2)).toBe(1000)
        expect(lineTotal('1.00', '0.001')).toBe(0)
        expect(lineTotal('1.00', '0.005')).toBe(0.01)
        expect(lineTotal('18.35', '-2.5')).toBe(-45.88)
    })

    it('treats an empty box as nothing', () => {
        expect(lineTotal('18.35', '')).toBe(0)
        expect(lineTotal(undefined, 2)).toBe(0)
    })
})

describe('quantities', () => {
    it('adds tenths exactly', () => {
        expect(sumQuantities([0.1, 0.1, 0.1])).toBe(0.3)
        expect(sumQuantities(['1.25', '1.251'])).toBe(2.501)
        expect(toMilli('2.5')).toBe(2500)
    })

    it('writes a whole quantity without decimals and a part with them', () => {
        expect(count(10)).toBe((10).toLocaleString())
        expect(count('2.500')).toBe((2.5).toLocaleString())
        expect(count(0.125)).toBe((0.125).toLocaleString())
    })

    it('steps by a thousandth only where the unit allows parts', () => {
        expect(quantityStep(true)).toBe('0.001')
        expect(quantityStep(false)).toBe('1')
        expect(quantityStep(undefined)).toBe('1')
    })
})
