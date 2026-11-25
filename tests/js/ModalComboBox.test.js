import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Modal from '../../resources/js/Components/Modal.vue'
import ComboBox from '../../resources/js/Components/ComboBox.vue'

/**
 * A combo box inside a dialog.
 *
 * The two listen for Escape separately: the dialog in the capture phase on
 * the document, the box on its own input. If they disagree, the first
 * Escape throws away a half-filled form.
 */

const options = Array.from({ length: 10 }, (_, i) => ({
    value: i + 1,
    label: `Product ${i + 1}`,
}))

function openDialog() {
    return mount(Modal, {
        attachTo: document.body,
        props: { open: true, title: 'New product' },
        slots: {
            default: () => h(ComboBox, { options, placeholder: 'Pick a product' }),
        },
    })
}

const listRows = () => document.querySelectorAll('[role="option"]')

describe('escape inside a dialog', () => {
    it('closes the list first and leaves the dialog alone', async () => {
        const wrapper = openDialog()
        const input = document.querySelector('[role="combobox"]')

        input.dispatchEvent(new Event('focus'))
        await wrapper.vm.$nextTick()
        expect(listRows().length).toBeGreaterThan(0)

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
        await wrapper.vm.$nextTick()

        expect(listRows()).toHaveLength(0)
        // The form behind it is still open, with whatever was typed in it.
        expect(wrapper.emitted('close')).toBeUndefined()

        wrapper.unmount()
    })

    it('closes the dialog on the second escape', async () => {
        const wrapper = openDialog()
        const input = document.querySelector('[role="combobox"]')

        input.dispatchEvent(new Event('focus'))
        await wrapper.vm.$nextTick()

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
        await wrapper.vm.$nextTick()

        // The list is shut, so the dialog takes the keyboard back.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('close')).toBeTruthy()

        wrapper.unmount()
    })
})
