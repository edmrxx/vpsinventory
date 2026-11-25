import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { system: { currency_symbol: '₱' } } }),
}))

const ReturnLines = (await import('../../resources/js/Components/ReturnLines.vue')).default

/**
 * The return line editor sits on a refund form, so the cap matters: the box
 * must not accept more than is still returnable, whatever is typed.
 */

const lines = [
    { id: 1, sku: 'TSHIRT-BLK', name: 'T-Shirt (Black)', quantity: 5, returned_quantity: 2, returnable: 3, unit_price: '500.00' },
    { id: 2, sku: 'CAP-NVY', name: 'Cap (Navy)', quantity: 4, returned_quantity: 4, returnable: 0, unit_price: '300.00' },
]

function build(values = [{ sale_line_id: 1, quantity: 0 }, { sale_line_id: 2, quantity: 0 }]) {
    return mount(ReturnLines, {
        attachTo: document.body,
        props: { modelValue: values, lines, 'onUpdate:modelValue': () => {} },
    })
}

const lastEmit = (wrapper) => {
    const all = wrapper.emitted('update:modelValue')

    return all[all.length - 1][0]
}

describe('the cap', () => {
    it('refuses more than is still returnable', async () => {
        const wrapper = build()

        await wrapper.get('input[type="number"]').setValue('99')

        // Three are left, so three is all that can be asked for.
        expect(lastEmit(wrapper)[0].quantity).toBe(3)
        wrapper.unmount()
    })

    it('accepts a quantity inside the cap', async () => {
        const wrapper = build()

        await wrapper.get('input[type="number"]').setValue('2')

        expect(lastEmit(wrapper)[0].quantity).toBe(2)
        wrapper.unmount()
    })

    it('never goes below nothing', async () => {
        const wrapper = build()

        await wrapper.get('input[type="number"]').setValue('-4')

        expect(lastEmit(wrapper)[0].quantity).toBe(0)
        wrapper.unmount()
    })

    it('rounds a typed fraction down to whole units', async () => {
        const wrapper = build()

        await wrapper.get('input[type="number"]').setValue('2.9')

        expect(lastEmit(wrapper)[0].quantity).toBe(2)
        wrapper.unmount()
    })
})

describe('the All button', () => {
    it('asks for everything that is left', async () => {
        const wrapper = build()

        await wrapper.get('button').trigger('click')

        expect(lastEmit(wrapper)[0].quantity).toBe(3)
        wrapper.unmount()
    })
})

describe('a line with nothing left', () => {
    it('offers no box at all', () => {
        const wrapper = build()

        // One line still has three to give; the other has none.
        expect(wrapper.findAll('input[type="number"]')).toHaveLength(1)
        expect(wrapper.text()).toContain('Nothing left to return')
        wrapper.unmount()
    })
})

describe('what it adds up to', () => {
    it('counts the units and their value', async () => {
        const wrapper = build([{ sale_line_id: 1, quantity: 2 }, { sale_line_id: 2, quantity: 0 }])

        expect(wrapper.text()).toContain('2 units coming back')
        expect(wrapper.text()).toContain('1,000.00')
        wrapper.unmount()
    })
})

describe('parts', () => {
    const measured = [
        { id: 3, sku: 'WIRE-THHN-12', name: 'THHN Wire #12', quantity: 2.5, returned_quantity: 0, returnable: 2.5, unit: 'm', decimals: true, unit_price: '18.35' },
        { id: 4, sku: 'HAMMER-16', name: 'Claw Hammer', quantity: 3, returned_quantity: 0, returnable: 3, unit: 'pcs', decimals: false, unit_price: '450.00' },
    ]

    function buildMeasured() {
        return mount(ReturnLines, {
            attachTo: document.body,
            props: {
                modelValue: [{ sale_line_id: 3, quantity: 0 }, { sale_line_id: 4, quantity: 0 }],
                lines: measured,
                'onUpdate:modelValue': () => {},
            },
        })
    }

    it('keeps a part where the unit is sold by measure, and caps it', async () => {
        const wrapper = buildMeasured()
        const [wire] = wrapper.findAll('input[type="number"]')

        await wire.setValue('0.75')
        expect(lastEmit(wrapper)[0].quantity).toBe(0.75)
        expect(wire.attributes('step')).toBe('0.001')

        await wire.setValue('2.6')
        expect(lastEmit(wrapper)[0].quantity).toBe(2.5)
        wrapper.unmount()
    })

    it('cuts a part to whole units where the unit is counted whole', async () => {
        const wrapper = buildMeasured()
        const hammer = wrapper.findAll('input[type="number"]')[1]

        await hammer.setValue('2.5')

        expect(lastEmit(wrapper)[1].quantity).toBe(2)
        expect(hammer.attributes('step')).toBe('1')
        wrapper.unmount()
    })
})
