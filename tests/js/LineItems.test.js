import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// The component reads the currency off the Inertia page, which only exists
// inside a running app. The amounts matter here, not where the sign is set.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { system: { currency_symbol: '\u20b1' } } }),
}))

const LineItems = (await import('../../resources/js/Components/LineItems.vue')).default

/**
 * The lines of a document.
 *
 * The combo box hands back the option's own value, where a native select
 * used to hand back a string. Everything here compares the two, so it is
 * worth proving they still meet.
 */

const products = [
    { value: 1, label: 'TSHIRT-BLK — T-Shirt (Black)', sku: 'TSHIRT-BLK', cost: '300.00', price: '500.00', on_hand: 10 },
    { value: 2, label: 'CAP-NVY — Cap (Navy)', sku: 'CAP-NVY', cost: '200.00', price: '300.00', on_hand: 4 },
    { value: 3, label: 'JACKET-L — Jacket (L)', sku: 'JACKET-L', cost: '600.00', price: '1000.00', on_hand: 0 },
]

function build(lines) {
    return mount(LineItems, {
        attachTo: document.body,
        props: { modelValue: lines, products, 'onUpdate:modelValue': () => {} },
    })
}

const blank = () => [{ product_id: '', quantity: 1, unit_cost: '0.00' }]
const lastEmit = (wrapper) => {
    const all = wrapper.emitted('update:modelValue')

    return all[all.length - 1][0]
}

async function pick(wrapper, value) {
    const combo = wrapper.findComponent({ name: 'ComboBox' })
    combo.vm.$emit('update:modelValue', value)
    await wrapper.vm.$nextTick()
}

describe('picking a product', () => {
    it('brings the product list price in', async () => {
        const wrapper = build(blank())

        await pick(wrapper, 1)

        expect(lastEmit(wrapper)[0]).toMatchObject({
            product_id: 1,
            unit_cost: '300.00',
        })
        wrapper.unmount()
    })

    it('swaps the cost when the line changes product', async () => {
        const wrapper = build([{ product_id: 1, quantity: 2, unit_cost: '300.00' }])

        await pick(wrapper, 2)

        // The old cost was the component's own, so it is replaced.
        expect(lastEmit(wrapper)[0]).toMatchObject({
            product_id: 2,
            unit_cost: '200.00',
        })
        wrapper.unmount()
    })

    it('keeps a cost the user typed by hand', async () => {
        const wrapper = build([{ product_id: 1, quantity: 2, unit_cost: '345.00' }])

        await pick(wrapper, 2)

        // 345 is nobody's list price, so it was typed and must survive.
        expect(lastEmit(wrapper)[0]).toMatchObject({
            product_id: 2,
            unit_cost: '345.00',
        })
        wrapper.unmount()
    })
})

describe('totals', () => {
    it('adds up without the drift of adding decimals', () => {
        const wrapper = build([
            { product_id: 1, quantity: 3, unit_cost: '0.10' },
            { product_id: 2, quantity: 3, unit_cost: '0.20' },
        ])

        // 0.1 + 0.1 + 0.1 + 0.2 + 0.2 + 0.2 is 0.8999999 in plain floats.
        expect(wrapper.text()).toContain('0.90')
        wrapper.unmount()
    })
})

/* ------------------------------------------------------------ Selling ---- */

function sellingForm(lines) {
    return mount(LineItems, {
        attachTo: document.body,
        props: {
            modelValue: lines,
            products,
            priceField: 'unit_price',
            productPriceField: 'price',
            showStock: true,
            warnBelowCost: true,
            'onUpdate:modelValue': () => {},
        },
    })
}

async function pickOn(wrapper, value) {
    wrapper.findComponent({ name: 'ComboBox' }).vm.$emit('update:modelValue', value)
    await wrapper.vm.$nextTick()
}

describe('the same component, selling', () => {
    it('fills the selling price, not the cost', async () => {
        const wrapper = sellingForm([{ product_id: '', quantity: 1, unit_price: '0.00' }])

        await pickOn(wrapper, 1)

        expect(lastEmit(wrapper)[0]).toMatchObject({
            product_id: 1,
            unit_price: '500.00',
        })
        wrapper.unmount()
    })

    it('swaps the selling price when the product changes', async () => {
        const wrapper = sellingForm([{ product_id: 1, quantity: 1, unit_price: '500.00' }])

        await pickOn(wrapper, 2)

        expect(lastEmit(wrapper)[0]).toMatchObject({ unit_price: '300.00' })
        wrapper.unmount()
    })

    it('keeps a price the user haggled', async () => {
        const wrapper = sellingForm([{ product_id: 1, quantity: 1, unit_price: '450.00' }])

        await pickOn(wrapper, 2)

        // 450 is nobody's list price, so it was typed and must survive.
        expect(lastEmit(wrapper)[0]).toMatchObject({ unit_price: '450.00' })
        wrapper.unmount()
    })

    it('says what is left after the sale', () => {
        const wrapper = sellingForm([{ product_id: 1, quantity: 4, unit_price: '500.00' }])

        expect(wrapper.text()).toContain('6 left after this sale')
        wrapper.unmount()
    })

    it('says so when the sale asks for more than there is', () => {
        const wrapper = sellingForm([{ product_id: 2, quantity: 6, unit_price: '300.00' }])

        // Four on hand, six asked for.
        expect(wrapper.text()).toContain('Short by 2')
        wrapper.unmount()
    })

    it('counts the same product across two lines', () => {
        const wrapper = sellingForm([
            { product_id: 2, quantity: 3, unit_price: '300.00' },
            { product_id: 2, quantity: 3, unit_price: '300.00' },
        ])

        // Neither line asks too much alone; together they do.
        expect(wrapper.text()).toContain('Short by 2')
        wrapper.unmount()
    })

    it('warns when the price is under what the goods cost', () => {
        const wrapper = sellingForm([{ product_id: 1, quantity: 1, unit_price: '250.00' }])

        expect(wrapper.text()).toContain('Below cost')
        wrapper.unmount()
    })

    it('says nothing about cost when the cost was never sent', () => {
        const wrapper = mount(LineItems, {
            attachTo: document.body,
            props: {
                modelValue: [{ product_id: 1, quantity: 1, unit_price: '1.00' }],
                // What a member of staff receives: a price, and no cost.
                products: [{ value: 1, label: 'A', price: '500.00', on_hand: 10 }],
                priceField: 'unit_price',
                productPriceField: 'price',
                warnBelowCost: true,
                'onUpdate:modelValue': () => {},
            },
        })

        expect(wrapper.text()).not.toContain('Below cost')
        wrapper.unmount()
    })
})

describe('parts', () => {
    const measured = [
        { value: 1, label: 'WIRE — THHN Wire', sku: 'WIRE', price: '18.35', on_hand: 10, unit: 'm', decimals: true },
        { value: 2, label: 'HAMMER — Claw Hammer', sku: 'HAMMER', price: '450.00', on_hand: 5, unit: 'pcs', decimals: false },
    ]

    function buildMeasured(lines) {
        return mount(LineItems, {
            attachTo: document.body,
            props: {
                modelValue: lines,
                products: measured,
                priceField: 'unit_price',
                productPriceField: 'price',
                showStock: true,
                'onUpdate:modelValue': () => {},
            },
        })
    }

    it('prices 2.5 m to the centavo the way the server does', () => {
        const wrapper = buildMeasured([{ product_id: 1, quantity: '2.5', unit_price: '18.35' }])

        // 2.5 x 18.35 = 45.875, which is 45.88.
        expect(wrapper.text()).toContain('45.88')
        expect(wrapper.text()).toContain('7.5 left after this sale')
        wrapper.unmount()
    })

    it('steps by a thousandth only on a unit that allows parts', () => {
        const wrapper = buildMeasured([
            { product_id: 1, quantity: 1, unit_price: '18.35' },
            { product_id: 2, quantity: 1, unit_price: '450.00' },
        ])

        const steps = wrapper.findAll('input[type="number"]')
            .filter((input) => input.attributes('step') !== '0.01')
            .map((input) => input.attributes('step'))

        expect(steps).toEqual(['0.001', '1'])
        wrapper.unmount()
    })
})

/**
 * Phase 15: a line may be typed in another unit. A box of 100 is priced
 * per box and weighed against the shelf as 100 pieces.
 */
describe('other units', () => {
    const screws = [{
        value: 7,
        label: 'SCREW-6 — Wood screw #6',
        sku: 'SCREW-6',
        price: '5.00',
        cost: '3.00',
        unit: 'pcs',
        decimals: false,
        on_hand: 250,
        units: [
            { value: 1, label: 'pcs', factor: 1, price: '5.00', cost: '3.00', decimals: false },
            { value: 2, label: 'box', factor: 100, price: '450.00', cost: '300.00', decimals: false },
        ],
    }]

    function buildBoxed(lines) {
        return mount(LineItems, {
            attachTo: document.body,
            props: {
                modelValue: lines,
                products: screws,
                withUnits: true,
                priceField: 'unit_price',
                productPriceField: 'price',
                showStock: true,
                warnBelowCost: true,
                'onUpdate:modelValue': () => {},
            },
        })
    }

    it('starts a product in its own unit at its own price', async () => {
        const wrapper = buildBoxed([{ product_id: '', unit_id: '', quantity: 1, unit_price: '0.00' }])

        await pick(wrapper, 7)

        expect(lastEmit(wrapper)[0]).toMatchObject({ product_id: 7, unit_id: 1, unit_price: '5.00' })
        wrapper.unmount()
    })

    it('brings the box price in when the line switches to a box', async () => {
        const wrapper = buildBoxed([{ product_id: 7, unit_id: 1, quantity: 1, unit_price: '5.00' }])

        const unitPicker = wrapper.findAllComponents({ name: 'ComboBox' })[1]
        unitPicker.vm.$emit('update:modelValue', 2)
        await wrapper.vm.$nextTick()

        expect(lastEmit(wrapper)[0]).toMatchObject({ unit_id: 2, unit_price: '450.00' })
        wrapper.unmount()
    })

    it('weighs boxes against the shelf in pieces', () => {
        const wrapper = buildBoxed([
            { product_id: 7, unit_id: 2, quantity: 2, unit_price: '450.00' },
            { product_id: 7, unit_id: 1, quantity: 60, unit_price: '5.00' },
        ])

        expect(wrapper.text()).toContain('= 200 pcs')
        // 250 less 200 less 60.
        expect(wrapper.text()).toContain('Short by 10')
        // 2 x 450 + 60 x 5.
        expect(wrapper.text()).toContain('1,200.00')
        wrapper.unmount()
    })

    it('weighs a box price against the cost of a box, not of a piece', () => {
        const fair = buildBoxed([{ product_id: 7, unit_id: 2, quantity: 1, unit_price: '450.00' }])
        expect(fair.text()).not.toContain('Below cost')
        fair.unmount()

        const cheap = buildBoxed([{ product_id: 7, unit_id: 2, quantity: 1, unit_price: '250.00' }])
        expect(cheap.text()).toContain('Below cost')
        cheap.unmount()
    })
})
