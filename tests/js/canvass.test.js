import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', logo: null },
    printing: {},
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }), router: { post: vi.fn(), delete: vi.fn() } }))

const { awardTotal, defaultChoices, needsReason } = await import('../../resources/js/lib/canvass.js')
const AbstractPrint = (await import('../../resources/js/Components/AbstractPrint.vue')).default

/**
 * Phase 24.2: the award form starts on the lowest price, asks for a reason
 * only when a dearer one is chosen, and the printed Abstract marks the
 * lowest and the award.
 */
const canvass = {
    quoted: true,
    awarded_at: null,
    awarded_by: null,
    lowest_total: '1660.00',
    awarded_total: null,
    suppliers: [
        { quote_id: 1, supplier_id: 7, name: 'Alpha Electrical', delivery_days: 3, payment_terms: 'COD', total: '1760.00', items_quoted: 2 },
        { quote_id: 2, supplier_id: 8, name: 'Bravo Hardware', delivery_days: 1, payment_terms: null, total: '1700.00', items_quoted: 2 },
    ],
    lines: [
        {
            line_id: 11, name: 'THHN Wire', sku: 'WIRE', quantity_label: '10 m', quoted: true, award: null,
            cells: [
                { quote_line_id: 101, unit_price: '48.00', line_total: '480.00', lowest: true },
                { quote_line_id: 201, unit_price: '52.00', line_total: '520.00', lowest: false },
            ],
        },
        {
            line_id: 12, name: 'Breaker 20A', sku: 'BRK', quantity_label: '4 pcs', quoted: true, award: null,
            cells: [
                { quote_line_id: 102, unit_price: '320.00', line_total: '1280.00', lowest: false },
                { quote_line_id: 202, unit_price: '295.00', line_total: '1180.00', lowest: true },
            ],
        },
        { line_id: 13, name: 'Tape', sku: 'TAPE', quantity_label: '20 pcs', quoted: false, award: null, cells: [null, null] },
    ],
}

describe('choosing the suppliers', () => {
    it('starts on the lowest price of every quoted item', () => {
        expect(defaultChoices(canvass)).toEqual({ 11: 101, 12: 202 })
    })

    it('asks for a reason only when a dearer price is chosen', () => {
        expect(needsReason(canvass.lines[1], 202)).toBe(false)
        expect(needsReason(canvass.lines[1], 102)).toBe(true)
    })

    it('adds the chosen prices up', () => {
        expect(awardTotal(canvass, { 11: 101, 12: 202 })).toBe(1660)
        expect(awardTotal(canvass, { 11: 101, 12: 102 })).toBe(1760)
    })
})

describe('the printed abstract', () => {
    const request = { reference: 'PRQ-2026-0009', department: 'Electrical', requested_by: 'Ana Encoder', reason: 'Lahug job', needed_by: null }

    it('marks the lowest price and says when nothing is awarded yet', () => {
        const text = mount(AbstractPrint, { props: { request: { ...request, canvass } } }).text()

        expect(text).toContain('Abstract of quotation')
        expect(text).toContain('Not yet awarded')
        expect(text).toContain('★ ₱48.00')
        expect(text).toContain('★ ₱295.00')
        expect(text).not.toContain('★ ₱52.00')
        expect(text).toContain('COD')
    })

    it('names the winner and why it was not the lowest', () => {
        const awarded = {
            ...canvass,
            awarded_at: '14 Nov 2026, 9:00 AM',
            awarded_by: 'Fernan Go',
            awarded_total: '1760.00',
            lines: [
                { ...canvass.lines[0], award: { quote_line_id: 101, supplier: 'Alpha Electrical', line_total: '480.00', reason: null } },
                { ...canvass.lines[1], award: { quote_line_id: 102, supplier: 'Alpha Electrical', line_total: '1280.00', reason: 'One supplier for the job' } },
                canvass.lines[2],
            ],
        }

        const text = mount(AbstractPrint, { props: { request: { ...request, canvass: awarded } } }).text()

        expect(text).not.toContain('Not yet awarded')
        expect(text).toContain('Not the lowest — awarded to Alpha Electrical: One supplier for the job')
        expect(text).toContain('Fernan Go')
        expect(text).toContain('₱1,760.00')
    })
})
