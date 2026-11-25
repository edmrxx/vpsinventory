import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: 'Rizal St., Cebu City', tin: '123-456-789-000', logo: null },
    printing: {},
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const PurchaseRequestPrint = (await import('../../resources/js/Components/PurchaseRequestPrint.vue')).default

/**
 * Phase 24.1: a purchase request on paper, and the Request for Quotation a
 * supplier fills in. The RFQ must never show what we expect to pay.
 */
function request(overrides = {}) {
    return {
        reference: 'PRQ-2026-0007',
        request_date: '14 Nov 2026',
        needed_by: '20 Nov 2026',
        department: 'Electrical',
        supplier: null,
        reason: 'Wiring for the Lahug job',
        notes: null,
        status: 'approved',
        requested_by: 'Ana Encoder',
        decided_by: 'Fernan Go',
        estimated_total: '9876.50',
        order: null,
        lines: [
            { id: 1, name: 'THHN Wire 2.0 mm', sku: 'WIRE-20', quantity_label: '150 m', unit_cost: '65.84', unit_code: 'm', line_total: '9876.50' },
        ],
        ...overrides,
    }
}

describe('the request for quotation', () => {
    it('lists the items and quantities with empty price columns', () => {
        const text = mount(PurchaseRequestPrint, { props: { request: request(), mode: 'rfq' } }).text()

        expect(text).toContain('Request for quotation')
        expect(text).toContain('PRQ-2026-0007')
        expect(text).toContain('THHN Wire 2.0 mm')
        expect(text).toContain('150 m')
        expect(text).toContain('Unit price')
        expect(text).toContain('by 20 Nov 2026')
        expect(text).toContain('Quoted by (name and signature)')
    })

    it('never shows what we expect to pay', () => {
        const text = mount(PurchaseRequestPrint, { props: { request: request(), mode: 'rfq' } }).text()

        expect(text).not.toContain('65.84')
        expect(text).not.toContain('9,876.50')
        expect(text).not.toContain('Estimate')
    })
})

describe('the request itself', () => {
    it('carries the department, the estimate and both signatures', () => {
        const text = mount(PurchaseRequestPrint, { props: { request: request(), mode: 'request' } }).text()

        expect(text).toContain('Purchase request')
        expect(text).toContain('Electrical')
        expect(text).toContain('Wiring for the Lahug job')
        expect(text).toContain('₱9,876.50')
        expect(text).toContain('Ana Encoder')
        expect(text).toContain('Fernan Go')
        expect(text).not.toContain('Waiting for approval')
    })

    it('says so across the top while nobody has approved it', () => {
        const text = mount(PurchaseRequestPrint, {
            props: { request: request({ status: 'pending', decided_by: null }), mode: 'request' },
        }).text()

        expect(text).toContain('Waiting for approval')
    })

    it('never names a rejecter as the approver', () => {
        const wrapper = mount(PurchaseRequestPrint, {
            props: { request: request({ status: 'rejected', decided_by: 'Fernan Go' }), mode: 'request' },
        })

        expect(wrapper.text()).toContain('Rejected')
        expect(wrapper.text()).not.toContain('Fernan Go')
    })
})
