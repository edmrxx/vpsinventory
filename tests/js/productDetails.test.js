import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ComboBox from '../../resources/js/Components/ComboBox.vue'
import { matchesProduct, scannedProduct } from '../../resources/js/lib/scan'
import { usualSupplier } from '../../resources/js/lib/usualSupplier'

/**
 * Phase 30: a product found by its brand, model or barcode, a scan that
 * picks exactly the product it names, and the usual supplier a purchase
 * request fills in.
 */

const tiles = [
    { id: 1, sku: 'PNT-LTX-4L', name: 'Permacoat Latex', brand: 'Boysen', model: '4 L', barcode: '4800016123456' },
    { id: 2, sku: 'PNT-ENM-1L', name: 'Quick Dry Enamel', brand: 'Davies', model: '1 L', barcode: '123' },
    { id: 3, sku: 'WIRE-2MM', name: 'THHN Wire', brand: null, model: null, barcode: null },
]

describe('searching at the till', () => {
    it('finds a product by its name, SKU, brand, model or barcode', () => {
        const hits = (term) => tiles.filter((p) => matchesProduct(p, term)).map((p) => p.id)

        expect(hits('latex')).toEqual([1])
        expect(hits('wire-2')).toEqual([3])
        expect(hits('DAVIES')).toEqual([2])
        expect(hits('4 l')).toEqual([1])
        expect(hits('4800016')).toEqual([1])
        expect(hits('  ')).toEqual([1, 2, 3])
    })

    it('a scan names exactly one product, never one whose code merely contains it', () => {
        // "123" is inside the first barcode too; only the second is labelled 123.
        expect(scannedProduct(tiles, '123')?.id).toBe(2)
        expect(scannedProduct(tiles, ' 4800016123456 ')?.id).toBe(1)
        expect(scannedProduct(tiles, '4800016')).toBeNull()
    })

    it('falls back to the SKU when somebody types the code by hand', () => {
        expect(scannedProduct(tiles, 'wire-2mm')?.id).toBe(3)
        expect(scannedProduct(tiles, '')).toBeNull()
    })

    it('reads a barcode before a SKU that happens to be the same', () => {
        const clash = [
            { id: 7, sku: 'ABC1', barcode: null },
            { id: 8, sku: 'OTHER', barcode: 'ABC1' },
        ]

        expect(scannedProduct(clash, 'abc1')?.id).toBe(8)
    })
})

describe('the combo box on every form', () => {
    const options = [
        { value: 1, label: 'PNT-LTX-4L — Permacoat Latex', keywords: 'Boysen 4 L 4800016123456', barcode: '4800016123456' },
        { value: 2, label: 'PNT-ENM-1L — Quick Dry Enamel', keywords: 'Davies 1 L 123', barcode: '123' },
    ]

    function build() {
        return mount(ComboBox, { attachTo: document.body, props: { options, modelValue: '' } })
    }

    const labels = () => Array.from(document.querySelectorAll('[role="option"]')).map((el) => el.textContent.trim())

    it('searches the brand, model and barcode without showing them', async () => {
        const wrapper = build()

        await wrapper.get('input').trigger('focus')
        await wrapper.get('input').setValue('boysen')

        expect(labels()).toEqual(['PNT-LTX-4L — Permacoat Latex'])
        wrapper.unmount()
    })

    it('a scan and Enter picks the product with exactly that barcode', async () => {
        const wrapper = build()

        await wrapper.get('input').trigger('focus')
        // "123" also sits inside the Latex's barcode, which is listed first.
        await wrapper.get('input').setValue('123')
        await wrapper.get('input').trigger('keydown', { key: 'Enter' })

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([2])
        wrapper.unmount()
    })
})

describe('the usual supplier on a purchase request', () => {
    const products = [
        { value: 1, preferred_supplier_id: null },
        { value: 2, preferred_supplier_id: 20 },
        { value: 3, preferred_supplier_id: 30 },
        { value: 4, preferred_supplier_id: 99 },
    ]
    const suppliers = [{ value: 20 }, { value: 30 }]

    it('comes from the first line whose product has one', () => {
        expect(usualSupplier([{ product_id: '' }, { product_id: 1 }, { product_id: 3 }, { product_id: 2 }], products, suppliers)).toBe(30)
    })

    it('passes over a supplier the form no longer offers', () => {
        expect(usualSupplier([{ product_id: 4 }, { product_id: '2' }], products, suppliers)).toBe(20)
    })

    it('is nothing when no product names one', () => {
        expect(usualSupplier([{ product_id: 1 }], products, suppliers)).toBeNull()
        expect(usualSupplier([], products, suppliers)).toBeNull()
    })
})
