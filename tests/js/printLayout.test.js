import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'

const pageProps = {
    company: {
        name: 'GO Enterprises',
        legal_name: 'GO Enterprises Trading',
        address: 'Rizal St., Cebu City',
        tin: '123-456-789-000',
        phone: '0917 000 0000',
        logo: '/storage/settings/logo.png',
    },
    printing: {},
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const { pageRule, usePrintLayout, PRINT_LAYOUT_ID } = await import('../../resources/js/composables/usePrintLayout.js')
const PrintLetterhead = (await import('../../resources/js/Components/PrintLetterhead.vue')).default
const PrintFooter = (await import('../../resources/js/Components/PrintFooter.vue')).default

/**
 * Settings → Print Layouts (phase 35). Every default must print the page
 * exactly as it printed before the settings existed.
 */
describe('the page rule', () => {
    it('leaves the printer alone by default', () => {
        expect(pageRule({})).toBe('')
        expect(pageRule({ paper_size: 'auto', margins: 'auto' })).toBe('')
    })

    it('sets the paper and the margins chosen', () => {
        expect(pageRule({ paper_size: 'a4', margins: 'normal' })).toBe('@media print { @page { size: A4; margin: 12mm; } }')
        expect(pageRule({ paper_size: 'long', margins: 'auto' })).toBe('@media print { @page { size: 8.5in 13in; } }')
        expect(pageRule({ paper_size: 'legal', margins: 'auto' })).toBe('@media print { @page { size: legal; } }')
        expect(pageRule({ paper_size: 'auto', margins: 'wide' })).toBe('@media print { @page { margin: 18mm; } }')
    })

    it('ignores a value it does not know', () => {
        expect(pageRule({ paper_size: 'A0', margins: 'huge' })).toBe('')
    })

    it('is in the page only while a document page is open', () => {
        pageProps.printing = { paper_size: 'a4', margins: 'narrow' }
        const Page = defineComponent({ setup: () => { usePrintLayout(); return () => h('div') } })

        const wrapper = mount(Page)
        expect(document.getElementById(PRINT_LAYOUT_ID)?.textContent).toContain('size: A4; margin: 8mm;')

        // Gone with the page, so the sale slip never prints on A4.
        wrapper.unmount()
        expect(document.getElementById(PRINT_LAYOUT_ID)).toBeNull()
        pageProps.printing = {}
    })
})

describe('the letterhead', () => {
    it('prints the logo and the company details by default', () => {
        pageProps.printing = {}
        const wrapper = mount(PrintLetterhead, { props: { title: 'Quotation' }, slots: { default: 'QT-2026-0001' } })

        expect(wrapper.find('img').attributes('src')).toBe('/storage/settings/logo.png')
        expect(wrapper.text()).toContain('Rizal St., Cebu City')
        expect(wrapper.text()).toContain('TIN 123-456-789-000')
        expect(wrapper.text()).toContain('Quotation')
        expect(wrapper.text()).toContain('QT-2026-0001')
    })

    it('leaves out the logo and the details when switched off, but never the name', () => {
        pageProps.printing = { show_logo: false, show_company_details: false }
        const wrapper = mount(PrintLetterhead, { props: { title: 'Purchase order' } })

        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.text()).toContain('GO Enterprises')
        expect(wrapper.text()).not.toContain('Rizal St.')
        expect(wrapper.text()).not.toContain('TIN')
        pageProps.printing = {}
    })
})

describe('the footer', () => {
    it('renders nothing until somebody types terms or a closing line', () => {
        pageProps.printing = { footer_note: '' }
        expect(mount(PrintFooter, { props: { terms: '' } }).html()).not.toContain('Terms')
        expect(mount(PrintFooter).text()).toBe('')
    })

    it('prints the terms and the closing line', () => {
        pageProps.printing = { footer_note: 'www.goenterprises.ph' }
        const wrapper = mount(PrintFooter, { props: { terms: 'Delivery within 3 days.' } })

        expect(wrapper.text()).toContain('Terms')
        expect(wrapper.text()).toContain('Delivery within 3 days.')
        expect(wrapper.text()).toContain('www.goenterprises.ph')
        pageProps.printing = {}
    })
})
