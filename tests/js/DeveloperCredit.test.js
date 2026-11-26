import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const DeveloperCredit = (await import('../../resources/js/Components/DeveloperCredit.vue')).default

const source = (path) => readFileSync(resolve(__dirname, '../../', path), 'utf8')

/**
 * The developer's credit line (23 November 2026): "Designed, Built and
 * Shipped by [logo] DeltaTech", the logo always left of the name and only the
 * name linked. It is written in the component, so nothing can clear it.
 */
describe('the credit line', () => {
    it('reads the agreed words and links only the name to the developer, in a new tab', () => {
        const credit = mount(DeveloperCredit)
        const link = credit.get('a')

        expect(credit.text().replace(/\s+/g, ' ')).toBe('Designed, Built and Shipped by DeltaTech')
        expect(credit.findAll('a')).toHaveLength(1)
        expect(link.text()).toBe('DeltaTech')
        expect(link.attributes('href')).toBe('https://deltatechph.com')
        expect(link.attributes('target')).toBe('_blank')
        expect(link.attributes('rel')).toContain('noopener')
    })

    it('puts the logo just left of the name', () => {
        const credit = mount(DeveloperCredit)
        const logo = credit.get('[data-credit-logo]').element
        const name = credit.get('a').element

        expect(logo.compareDocumentPosition(name) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
        expect(logo.closest('span')).toBe(name.closest('span'))
    })

    it.each(['navy', 'white'])('draws the %s logo, and the file is there', (logo) => {
        const credit = mount(DeveloperCredit, { props: { logo } })

        expect(credit.get('img').attributes('src')).toBe(`/images/deltatech/deltatech-${logo}.png`)
        expect(credit.get('source').attributes('srcset')).toBe(`/images/deltatech/deltatech-${logo}.webp`)
        expect(existsSync(resolve(__dirname, `../../public/images/deltatech/deltatech-${logo}.png`))).toBe(true)
        expect(existsSync(resolve(__dirname, `../../public/images/deltatech/deltatech-${logo}.webp`))).toBe(true)
    })
})

describe('where the credit line is shown', () => {
    it('sits on the sign-in pages, the app footer and the signed-out error page, each with the logo for its background', () => {
        expect(source('resources/js/Layouts/SignInLayout.vue')).toMatch(/<DeveloperCredit logo="navy"/)
        // The app footer follows the person's light or dark screen (phase 35.1).
        expect(source('resources/js/Layouts/AppLayout.vue')).toMatch(/<DeveloperCredit :logo="isDark \? 'white' : 'navy'"/)
        expect(source('resources/js/Layouts/GuestLayout.vue')).toMatch(/<DeveloperCredit logo="white"/)
    })

    it('never prints on a paper that goes to the client’s customers or suppliers, nor shows on the till', () => {
        for (const path of [
            'resources/js/Components/PrintFooter.vue',
            'resources/js/Components/PrintLetterhead.vue',
            'resources/js/Components/SaleSlip.vue',
            'resources/js/Pages/Pos/Index.vue',
        ]) {
            expect(source(path), path).not.toContain('DeveloperCredit')
        }
    })
})
