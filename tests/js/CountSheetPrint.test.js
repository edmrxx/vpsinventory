import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const pageProps = {
    company: { name: 'GO Enterprises', address: 'Rizal St., Cebu City', tin: '123-456-789-000', logo: null },
    printing: {},
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const CountSheetPrint = (await import('../../resources/js/Components/CountSheetPrint.vue')).default

/**
 * Phase 32: the count sheet on paper. A counter writes what is on the shelf,
 * so the paper never carries what the books expect — not even when the
 * approver, whose payload holds the figures, is the one printing it.
 */
function count(overrides = {}) {
    return {
        reference: 'CNT-2026-0003',
        location: 'Main Store',
        scope: 'All products',
        cutoff_at: '23 Nov 2026, 7:30 AM',
        lines: [
            {
                id: 1,
                sku: 'PNT-1G',
                name: 'Boysen Paint 1 gal',
                unit: 'can',
                system_quantity: 7391,
                counted_quantity: 6427,
                difference: -964,
                changed_since: -58,
            },
        ],
        ...overrides,
    }
}

describe('the printed count sheet', () => {
    it('lists the products with a blank counted column and the signature lines', () => {
        const text = mount(CountSheetPrint, { props: { count: count() } }).text()

        expect(text).toContain('Count sheet')
        expect(text).toContain('CNT-2026-0003')
        expect(text).toContain('Main Store')
        expect(text).toContain('23 Nov 2026, 7:30 AM')
        expect(text).toContain('PNT-1G')
        expect(text).toContain('Boysen Paint 1 gal')
        expect(text).toContain('Counted by')
        expect(text).toContain('Checked by')
        // Set-aside goods are on the books until written off (phase 33).
        expect(text).toContain('goods set aside for a loss report too')
    })

    it('never prints a book figure, a counted figure or a difference', () => {
        const text = mount(CountSheetPrint, { props: { count: count() } }).text()

        for (const figure of ['7391', '7,391', '6427', '6,427', '964', '58']) {
            expect(text).not.toContain(figure)
        }
    })
})
