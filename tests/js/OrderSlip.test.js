import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: 'Cebu City', tin: '', logo: null },
    slip: { width: '80', footer: 'Salamat!' },
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const OrderSlip = (await import('../../resources/js/Components/OrderSlip.vue')).default

/**
 * CR-2: the slip for an order the kiosk sent to the cashier. No money has
 * changed hands when it prints, so it must say so, and it is never a
 * receipt — both written in the component, where no setting can clear them.
 */
const order = {
    id: 3,
    reference: 'PP-2026-0003',
    sent_at: '24 Nov 2026, 4:40 PM',
    taken_by: 'Juan Dela Cruz',
    customer: 'Walk-in Customer',
    location: 'Main Store',
    hold_until: '24 Nov 2026',
    subtotal: '1000.00',
    discount: '50.00',
    total_amount: '950.00',
    lines: [{ id: 1, name: 'Cement 40 kg', sku: 'CEM-40', quantity: 4, unit_code: 'bag', unit_price: '250.00', line_total: '1000.00' }],
}

describe('the order slip', () => {
    it('says NOT PAID, where to pay, and what to pay', () => {
        const text = mount(OrderSlip, { props: { order } }).text()

        expect(text).toContain('*** NOT PAID ***')
        expect(text).toContain('PAY AT THE CASHIER')
        expect(text).toContain('PP-2026-0003')
        expect(text).toContain('4 bag × ₱250.00')
        expect(text).toContain('TO PAY')
        expect(text).toContain('₱950.00')
        expect(text).toContain('Held for you until closing on 24 Nov 2026.')
    })

    it('always says it is not an official receipt, and that line is not a setting', () => {
        const text = mount(OrderSlip, { props: { order } }).text()
        const source = readFileSync(resolve(__dirname, '../../resources/js/Components/OrderSlip.vue'), 'utf8')

        expect(text).toContain('This is not an official receipt.')
        expect(source).toContain('This is not an official receipt.')
        expect(source).toContain('*** NOT PAID ***')
    })

    it('never prints a cost or a margin', () => {
        const source = readFileSync(resolve(__dirname, '../../resources/js/Components/OrderSlip.vue'), 'utf8')

        for (const field of ['unit_cost', 'line_cost', 'total_cost', 'gross_profit', 'profit']) {
            expect(source, field).not.toContain(field)
        }
    })
})
