import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: 'Rizal St., Cebu City', tin: '123-456-789-000', logo: null },
    printing: {},
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const PurchaseOrderPrint = (await import('../../resources/js/Components/PurchaseOrderPrint.vue')).default

/**
 * Phase 24.4 (Q7): an order that is not approved prints only as a draft —
 * a mark across the page and a blank "Approved by" line — so it cannot
 * pass for an order sent to the supplier.
 */
function order(overrides = {}) {
    return {
        reference: 'PO-2026-0007',
        order_date: '14 Nov 2026',
        expected_date: null,
        late: false,
        status: 'open',
        draft: false,
        supplier: { name: 'Alpha Electrical', address: null, tin: null },
        request: { reference: 'PRQ-2026-0004', approved_by: 'Request Approver' },
        ordered_by: 'Ana Buyer',
        approved_by: 'Fernan Go',
        vat_rate: '0.00',
        total_amount: '880.00',
        notes: null,
        lines: [
            { id: 1, name: 'THHN Wire 2.0 mm', sku: 'WIRE-20', quantity_label: '20 m', unit_cost: '44.00', unit_code: 'm', line_total: '880.00' },
        ],
        ...overrides,
    }
}

const signatures = (wrapper) => wrapper.findAll('footer p.font-semibold').map((p) => p.text())

describe('the printed purchase order', () => {
    it('prints clean once approved, signed by the order\'s own approver', () => {
        const wrapper = mount(PurchaseOrderPrint, { props: { order: order() } })

        expect(wrapper.find('[data-test="draft-mark"]').exists()).toBe(false)
        expect(wrapper.text()).not.toMatch(/draft/i)
        expect(signatures(wrapper)).toEqual(['Ana Buyer', 'Fernan Go'])
    })

    it('carries the draft mark and a blank approver while it waits', () => {
        const wrapper = mount(PurchaseOrderPrint, {
            props: { order: order({ status: 'pending_approval', draft: true, approved_by: null }) },
        })

        expect(wrapper.find('[data-test="draft-mark"]').text()).toMatch(/draft — not approved/i)
        expect(wrapper.text()).toContain('Do not send this order to the supplier')
        // The request's approver never stands in for the order's.
        expect(wrapper.text()).not.toContain('Request Approver')
        expect(signatures(wrapper)[1].trim()).toBe('')
    })

    it('stays a draft when rejected, and says so', () => {
        const wrapper = mount(PurchaseOrderPrint, {
            props: { order: order({ status: 'rejected', draft: true, approved_by: null }) },
        })

        expect(wrapper.find('[data-test="draft-mark"]').exists()).toBe(true)
        expect(wrapper.text()).toMatch(/rejected/i)
    })
})

/**
 * Phase 31.3: an order names where the supplier is to deliver. An order
 * from before names none and reprints exactly as it did.
 */
describe('where the order is delivered', () => {
    it('prints the place and its address when the order names one', () => {
        const wrapper = mount(PurchaseOrderPrint, {
            props: { order: order({ deliver_to: { id: 2, name: 'Mandaue Warehouse', address: 'A.S. Fortuna St., Mandaue' } }) },
        })

        const box = wrapper.get('[data-test="deliver-to"]')
        expect(box.text()).toContain('Deliver to')
        expect(box.text()).toContain('Mandaue Warehouse')
        expect(box.text()).toContain('A.S. Fortuna St., Mandaue')
    })

    it('leaves the box off an order that names no place', () => {
        const wrapper = mount(PurchaseOrderPrint, { props: { order: order({ deliver_to: null }) } })

        expect(wrapper.find('[data-test="deliver-to"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Deliver to')
    })
})
