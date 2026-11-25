import { describe, expect, it, vi } from 'vitest'

// `useVat` reads the Inertia page; the split itself does not, and that is
// what matters here.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { tax: { vat_registered: true, vat_rate: 12 } } }),
}))

const { vatSplit } = await import('../../resources/js/lib/money')

/**
 * The VAT split drawn while somebody types.
 *
 * This has to agree with `App\Support\Vat` on the server, because the user
 * reads this figure on the form and then reads the stored one on the
 * document. A centavo between them would be a bug report.
 */
describe('vatSplit', () => {
    it('takes the VAT out of a VAT-inclusive amount', () => {
        expect(vatSplit(1440, 12)).toEqual({ net: 1285.71, vat: 154.29 })
    })

    it('leaves the amount alone when there is no rate', () => {
        expect(vatSplit(1440, 0)).toEqual({ net: 1440, vat: 0 })
        expect(vatSplit(1440, null)).toEqual({ net: 1440, vat: 0 })
    })

    it('rounds a net landing on half a centavo up, as the server does', () => {
        // Float division put these two one centavo apart from PHP.
        expect(vatSplit(42.14, 12)).toEqual({ net: 37.63, vat: 4.51 })
        expect(vatSplit(1000.02, 12)).toEqual({ net: 892.88, vat: 107.14 })
    })

    it('always adds back to the amount it came from', () => {
        for (const gross of [0.01, 7.77, 42.14, 99.99, 1000.02, 1234.56, 1000000.05]) {
            const { net, vat } = vatSplit(gross, 12)

            expect(Number((net + vat).toFixed(2))).toBe(gross)
        }
    })

    it('reads nothing as zero rather than NaN', () => {
        expect(vatSplit(null, 12)).toEqual({ net: 0, vat: 0 })
        expect(vatSplit('', 12)).toEqual({ net: 0, vat: 0 })
    })

    it('handles a rate that is not twelve', () => {
        expect(vatSplit(110, 10)).toEqual({ net: 100, vat: 10 })
    })
})
