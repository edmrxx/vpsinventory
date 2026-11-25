import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ComboBox from '../../resources/js/Components/ComboBox.vue'

/**
 * The combo box is the only thing in the app PHPUnit cannot reach: typing,
 * arrow keys and clicking away all happen in the browser. It also sits on
 * the money forms, so it is worth covering properly.
 */

const products = [
    { value: 1, label: 'TSHIRT-BLK — T-Shirt (Black)' },
    { value: 2, label: 'TSHIRT-WHT — T-Shirt (White)' },
    { value: 3, label: 'CAP-NVY — Cap (Navy)' },
    { value: 4, label: 'SOCKS-WHT — Socks' },
    { value: 5, label: 'JACKET-L — Jacket (L)' },
    { value: 6, label: 'POLO-M — Polo Shirt (M)' },
    { value: 7, label: 'BELT-BRN — Belt (Brown)' },
    { value: 8, label: 'SHOES-42 — Shoes (42)' },
]

function build(props = {}) {
    return mount(ComboBox, {
        attachTo: document.body,
        props: { options: products, placeholder: 'Pick a product', ...props },
    })
}

const input = (wrapper) => wrapper.get('input')
const optionsOf = () => document.querySelectorAll('[role="option"]')
const labels = () => Array.from(optionsOf()).map((el) => el.textContent.trim())

describe('opening and closing', () => {
    it('shows every option when it opens', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')

        // Eight products plus the row that clears the choice.
        expect(optionsOf()).toHaveLength(9)
        wrapper.unmount()
    })

    it('closes when the choice is made', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await optionsOf()[1].click()

        expect(optionsOf()).toHaveLength(0)
        wrapper.unmount()
    })
})

describe('typing', () => {
    it('narrows the list to what matches', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('tshirt')

        // The clearing row goes while a search is being typed, so only
        // real matches are offered.
        expect(labels()).toEqual([
            'TSHIRT-BLK — T-Shirt (Black)',
            'TSHIRT-WHT — T-Shirt (White)',
        ])
        wrapper.unmount()
    })

    it('ignores case, so sku or name both find it', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('CAP')

        expect(labels()).toContain('CAP-NVY — Cap (Navy)')

        await input(wrapper).setValue('navy')

        expect(labels()).toContain('CAP-NVY — Cap (Navy)')
        wrapper.unmount()
    })

    it('says so when nothing matches', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('zzzz')

        expect(optionsOf()).toHaveLength(0)
        expect(document.body.textContent).toContain('Nothing matches')
        wrapper.unmount()
    })

    it('never turns typed text into the value', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('made up thing')
        await input(wrapper).trigger('blur')

        // Nothing was picked, so nothing was chosen.
        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
        wrapper.unmount()
    })

    it('puts the real label back after typing and leaving', async () => {
        const wrapper = build({ modelValue: 3 })

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('socks')
        await input(wrapper).trigger('blur')

        expect(input(wrapper).element.value).toBe('CAP-NVY — Cap (Navy)')
        wrapper.unmount()
    })
})

describe('choosing', () => {
    it('gives back the option\'s own value', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).setValue('socks')
        await optionsOf()[0].click()

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([4])
        wrapper.unmount()
    })

    it('clears the choice through the first row', async () => {
        const wrapper = build({ modelValue: 2 })

        await input(wrapper).trigger('focus')
        await optionsOf()[0].click()

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([''])
        wrapper.unmount()
    })

    it('shows the selected label even when the value arrives as text', async () => {
        // The server sends filters back as numbers and a form as strings.
        const wrapper = build({ modelValue: '3' })

        expect(input(wrapper).element.value).toBe('CAP-NVY — Cap (Navy)')
        wrapper.unmount()
    })
})

describe('the keyboard', () => {
    it('walks the list with the arrow keys and picks with Enter', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).trigger('keydown', { key: 'ArrowDown' })
        await input(wrapper).trigger('keydown', { key: 'ArrowDown' })
        await input(wrapper).trigger('keydown', { key: 'Enter' })

        // Row 0 clears, row 1 is the first product, row 2 the second.
        expect(wrapper.emitted('update:modelValue')[0]).toEqual([2])
        wrapper.unmount()
    })

    it('stops Enter reaching the form behind it', async () => {
        const wrapper = build()
        const stop = vi.fn()

        await input(wrapper).trigger('focus')
        await input(wrapper).trigger('keydown', { key: 'Enter', stopPropagation: stop })

        // Otherwise Enter would post the purchase being typed.
        expect(stop).toHaveBeenCalled()
        wrapper.unmount()
    })

    it('stops Escape reaching the dialog behind it', async () => {
        const wrapper = build()
        const stop = vi.fn()

        await input(wrapper).trigger('focus')
        expect(optionsOf()).toHaveLength(9)

        await input(wrapper).trigger('keydown', { key: 'Escape', stopPropagation: stop })

        // The first Escape closes the list; the dialog stays open.
        expect(optionsOf()).toHaveLength(0)
        expect(stop).toHaveBeenCalled()
        wrapper.unmount()
    })

    it('wraps around at the ends', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        await input(wrapper).trigger('keydown', { key: 'ArrowUp' })
        await input(wrapper).trigger('keydown', { key: 'Enter' })

        // Up from the first row lands on the last option.
        expect(wrapper.emitted('update:modelValue')[0]).toEqual([8])
        wrapper.unmount()
    })
})

describe('the dialog behind it', () => {
    it('flags itself while open, so the dialog leaves Escape alone', async () => {
        const wrapper = build()

        expect(document.body.dataset.comboOpen).toBeUndefined()

        await input(wrapper).trigger('focus')
        expect(document.body.dataset.comboOpen).toBe('true')

        await input(wrapper).trigger('keydown', { key: 'Escape' })
        expect(document.body.dataset.comboOpen).toBeUndefined()

        wrapper.unmount()
    })

    it('clears that flag when it is taken off the page', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        wrapper.unmount()

        expect(document.body.dataset.comboOpen).toBeUndefined()
    })
})

describe('a long list', () => {
    it('draws only so many rows and says how many are left', async () => {
        const many = Array.from({ length: 400 }, (_, i) => ({
            value: i + 1,
            label: `SKU-${i + 1}`,
        }))

        const wrapper = mount(ComboBox, {
            attachTo: document.body,
            props: { options: many, placeholder: 'Pick one', limit: 50 },
        })

        await input(wrapper).trigger('focus')

        expect(optionsOf()).toHaveLength(50)
        expect(document.body.textContent).toContain('351 more')

        wrapper.unmount()
    })
})

describe('a short list', () => {
    const statuses = {
        options: [
            { value: 'active', label: 'Active only' },
            { value: 'inactive', label: 'Inactive only' },
        ],
        placeholder: 'Active and inactive',
    }

    it('can be typed into, short as it is', async () => {
        const wrapper = mount(ComboBox, { attachTo: document.body, props: statuses })

        await input(wrapper).trigger('focus')

        // Two options and the clearing row.
        expect(optionsOf()).toHaveLength(3)

        await input(wrapper).setValue('inact')

        // The search used to be switched off below eight options, which
        // meant it was off everywhere on real data. Now it narrows here too.
        expect(optionsOf()).toHaveLength(1)
        expect(optionsOf()[0].textContent).toContain('Inactive only')
        wrapper.unmount()
    })

    it('is a normal input, not a readonly one', async () => {
        const wrapper = mount(ComboBox, { attachTo: document.body, props: statuses })

        // A readonly input is not reliably focused by a label click in every
        // browser, which would leave the FormField label dead.
        expect(input(wrapper).attributes('readonly')).toBeUndefined()
        wrapper.unmount()
    })

    it('opens on a click, with no focus event at all', async () => {
        const wrapper = mount(ComboBox, { attachTo: document.body, props: statuses })

        await wrapper.get('[role="combobox"]').element.parentElement.click()
        await wrapper.vm.$nextTick()

        expect(optionsOf()).toHaveLength(3)
        wrapper.unmount()
    })
})

describe('two of them on one page', () => {
    it('keeps the dialog flag until the last one closes', async () => {
        const a = build()
        const b = build()

        await input(a).trigger('focus')
        await input(b).trigger('focus')

        expect(document.body.dataset.comboOpen).toBe('true')

        // Closing the first must not take the flag away from the second,
        // or the next Escape would shut the dialog behind them.
        await input(a).trigger('keydown', { key: 'Escape' })
        expect(document.body.dataset.comboOpen).toBe('true')

        await input(b).trigger('keydown', { key: 'Escape' })
        expect(document.body.dataset.comboOpen).toBeUndefined()

        a.unmount()
        b.unmount()
    })
})

describe('clicking the teleported list', () => {
    it('picks the option rather than closing on the outside click', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')

        const option = optionsOf()[2]

        // The real sequence a mouse makes. The list lives under <body>, so
        // the outside-click watcher has to recognise it as inside.
        option.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        option.click()
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([2])
        expect(optionsOf()).toHaveLength(0)
        wrapper.unmount()
    })

    it('closes when the click lands somewhere else entirely', async () => {
        const wrapper = build()

        await input(wrapper).trigger('focus')
        expect(optionsOf()).toHaveLength(9)

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await wrapper.vm.$nextTick()

        expect(optionsOf()).toHaveLength(0)
        wrapper.unmount()
    })
})
