import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { system: { currency_symbol: '₱' } } }),
}))

const { rebuildHold } = await import('../../resources/js/lib/holds')

/**
 * A recalled hold, laid against the shelf as it is now.
 *
 * Time has passed since it was parked, so the price is today's, a product
 * gone is left off, a quantity past the shelf is cut, and each of those is
 * said out loud.
 */

const pieces = { value: 1, label: 'pcs', factor: 1, price: '5.00', decimals: false }
const box = { value: 7, label: 'box', factor: 100, price: '450.00', decimals: false }

const products = [
    { id: 10, name: 'Tekscrew', sku: 'TEK', unit: 'pcs', price: '5.00', on_hand: 250, units: [pieces, box] },
    { id: 20, name: 'Tie Wire', sku: 'TW', unit: 'kg', price: '95.00', on_hand: 3.25, units: [{ value: 3, label: 'kg', factor: 1, price: '95.00', decimals: true }] },
]

const customers = [
    { value: 1, label: 'Walk-in Customer' },
    { value: 2, label: 'Aling Nena' },
]

function hold(lines, extra = {}) {
    return { customer_id: 2, discount: '0.00', lines, ...extra }
}

describe('rebuildHold', () => {
    it('puts the lines back as they were rung up', () => {
        const rebuilt = rebuildHold(
            hold([
                { product_id: 10, unit_id: 7, quantity: '2', unit_price: '450.00' },
                { product_id: 20, unit_id: 3, quantity: '2.5', unit_price: '95.00' },
            ]),
            products,
            customers,
            1
        )

        expect(rebuilt.changes).toEqual([])
        expect(rebuilt.customerId).toBe(2)
        expect(rebuilt.lines).toHaveLength(2)
        expect(rebuilt.lines[0]).toMatchObject({ key: '10:7', unit_id: 7, unit_code: 'box', factor: 100, price: '450.00', quantity: 2 })
        expect(rebuilt.lines[1]).toMatchObject({ key: '20:3', quantity: 2.5, decimals: true })
    })

    it('reads a missing unit as the product’s own', () => {
        const rebuilt = rebuildHold(hold([{ product_id: 10, unit_id: null, quantity: '3' }]), products, customers, 1)

        expect(rebuilt.lines[0]).toMatchObject({ key: '10:1', unit_code: 'pcs', quantity: 3 })
    })

    it('charges today’s price and says so', () => {
        const rebuilt = rebuildHold(
            hold([{ product_id: 10, unit_id: 1, quantity: '4', unit_price: '4.50' }]),
            products,
            customers,
            1
        )

        expect(rebuilt.lines[0].price).toBe('5.00')
        expect(rebuilt.changes).toEqual(['Tekscrew is now 5.00, not 4.50.'])
    })

    it('cuts a quantity to what is left, counting every line of the product', () => {
        // 2 boxes take 200 of the 250; only 50 loose pieces are left for the second line.
        const rebuilt = rebuildHold(
            hold([
                { product_id: 10, unit_id: 7, quantity: '2' },
                { product_id: 10, unit_id: 1, quantity: '80' },
            ]),
            products,
            customers,
            1
        )

        expect(rebuilt.lines.map((line) => line.quantity)).toEqual([2, 50])
        expect(rebuilt.changes[0]).toContain('Only 50 pcs of Tekscrew left')
    })

    it('never cuts a whole unit to a part', () => {
        // 250 pieces is 2.5 boxes, and a box is sold whole.
        const rebuilt = rebuildHold(hold([{ product_id: 10, unit_id: 7, quantity: '3' }]), products, customers, 1)

        expect(rebuilt.lines[0].quantity).toBe(2)
    })

    it('cuts a part unit to the thousandth', () => {
        const rebuilt = rebuildHold(hold([{ product_id: 20, unit_id: 3, quantity: '5' }]), products, customers, 1)

        expect(rebuilt.lines[0].quantity).toBe(3.25)
    })

    it('leaves off what has gone and says why', () => {
        const rebuilt = rebuildHold(
            hold([
                { product_id: 99, unit_id: null, quantity: '1' },
                { product_id: 10, unit_id: 42, quantity: '1' },
                { product_id: 20, unit_id: 3, quantity: '1' },
            ]),
            [products[0], { ...products[1], on_hand: 0 }],
            customers,
            1
        )

        expect(rebuilt.lines).toEqual([])
        expect(rebuilt.changes).toEqual([
            'A product on it is no longer for sale, so it was left off.',
            'Tekscrew is no longer sold by that unit, so it was left off.',
            'Tie Wire has sold out since, so it was left off.',
        ])
    })

    it('goes back to Walk-in when the customer is no longer active', () => {
        const rebuilt = rebuildHold(hold([{ product_id: 10, unit_id: 1, quantity: '1' }], { customer_id: 5 }), products, customers, 1)

        expect(rebuilt.customerId).toBe(1)
        expect(rebuilt.changes).toContain('The customer on it is no longer active, so the sale is back on Walk-in.')
    })

    it('keeps the discount, and an empty box for none', () => {
        expect(rebuildHold(hold([], { discount: '50.00' }), products, customers, 1).discount).toBe('50.00')
        expect(rebuildHold(hold([], { discount: '0.00' }), products, customers, 1).discount).toBe('')
    })
})
