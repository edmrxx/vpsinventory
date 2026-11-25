import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: 'Rizal St., Cebu City', tin: '123-456-789-000', logo: null },
    slip: { width: '80', footer: 'Salamat po!' },
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const SaleSlip = (await import('../../resources/js/Components/SaleSlip.vue')).default

/**
 * The customer's copy of a sale.
 *
 * The payload it is drawn from carries the cost for anybody allowed to see
 * a margin — which the cashier at the till usually is. The slip must never
 * print it.
 */

function sale(overrides = {}) {
    return {
        id: 5,
        reference: 'SI-2026-0042',
        customer: 'Aling Nena',
        posted_at: '09 Nov 2026, 2:15 PM',
        posted_by: 'Ana Cashier',
        status: 'posted',
        subtotal: '1640.00',
        discount: '0.00',
        total_amount: '1640.00',
        vat_rate: '0.00',
        net_amount: '1640.00',
        vat_amount: '0.00',
        change: '360.00',
        payments: [
            { id: 1, method: 'Cash', amount: '640.00', tendered: '1000.00', reference: null },
            { id: 2, method: 'GCash', amount: '1000.00', tendered: null, reference: '1234 5678' },
        ],
        lines: [
            {
                id: 9,
                name: 'Angle Bar 1-1/2"',
                quantity: 2,
                converted: false,
                unit_quantity: 2,
                unit_code: 'length',
                unit_price: '820.00',
                line_total: '1640.00',
                // What the goods cost: in the payload, never on the paper.
                unit_cost: '613.77',
                line_cost: '1227.54',
            },
        ],
        total_cost: '1227.54',
        gross_profit: '412.46',
        ...overrides,
    }
}

describe('SaleSlip', () => {
    it('prints the sale, how it was paid and the change', () => {
        const text = mount(SaleSlip, { props: { sale: sale() } }).text()

        expect(text).toContain('GO Enterprises')
        expect(text).toContain('TIN 123-456-789-000')
        expect(text).toContain('SI-2026-0042')
        expect(text).toContain('Aling Nena')
        expect(text).toContain('Ana Cashier')
        expect(text).toContain('2 length × ₱820.00')
        expect(text).toContain('₱1,640.00')
        expect(text).toContain('GCash')
        expect(text).toContain('1234 5678')
        expect(text).toContain('Received₱1,000.00')
        expect(text).toContain('Change₱360.00')
        expect(text).toContain('Salamat po!')
    })

    it('never prints a cost or a margin', () => {
        const html = mount(SaleSlip, { props: { sale: sale() } }).html()

        for (const figure of ['613.77', '1,227.54', '1227.54', '412.46']) {
            expect(html).not.toContain(figure)
        }
        expect(html.toLowerCase()).not.toContain('cost')
        expect(html.toLowerCase()).not.toContain('profit')
    })

    it('always says it is not an official receipt', () => {
        pageProps.slip.footer = ''

        const text = mount(SaleSlip, { props: { sale: sale() } }).text()

        expect(text).toContain('This is not an official receipt.')

        pageProps.slip.footer = 'Salamat po!'
    })

    it('shows the box a line was sold in, not the pieces it became', () => {
        const text = mount(SaleSlip, {
            props: {
                sale: sale({
                    lines: [{ id: 1, name: 'Tekscrew', quantity: 200, converted: true, unit_quantity: 2, unit_code: 'box', unit_price: '450.00', line_total: '900.00' }],
                }),
            },
        }).text()

        expect(text).toContain('2 box × ₱450.00')
        expect(text).not.toContain('200')
    })

    it('stamps a voided sale', () => {
        expect(mount(SaleSlip, { props: { sale: sale({ status: 'voided' }) } }).text()).toContain('VOIDED')
    })

    it('splits the VAT only when it is on, and the discount only when there is one', () => {
        const plain = mount(SaleSlip, { props: { sale: sale() } }).text()

        expect(plain).not.toContain('VAT')
        expect(plain).not.toContain('Discount')

        const text = mount(SaleSlip, {
            props: { sale: sale({ vat_rate: '12.00', net_amount: '1464.29', vat_amount: '175.71', discount: '40.00', subtotal: '1680.00' }) },
        }).text()

        expect(text).toContain('VAT (12%)')
        expect(text).toContain('₱175.71')
        expect(text).toContain('Discount-₱40.00')
    })

    it('narrows to a 58 mm roll', () => {
        pageProps.slip.width = '58'

        const slip = mount(SaleSlip, { props: { sale: sale() } })

        expect(slip.classes()).toContain('narrow')
        expect(slip.attributes('style')).toContain('58mm')

        pageProps.slip.width = '80'
    })
})
