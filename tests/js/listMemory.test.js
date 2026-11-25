import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'

/**
 * Every list remembers where you were (24 November 2026): the shared table
 * writes down its address, scroll and last row; every breadcrumb, "Back to"
 * link and Cancel button that leads to the bare list goes back to it.
 */
const navigateHandlers = []

vi.mock('@inertiajs/vue3', () => ({
    // A plain anchor, so the href can be read.
    Link: defineComponent({
        props: { href: String },
        setup(props, { slots, attrs }) {
            // jsdom cannot follow a link, so a click stays on the page.
            return () =>
                h('a', { ...attrs, href: props.href, onClickCapture: (event) => event.preventDefault() }, slots.default?.())
        },
    }),
    router: {
        get: vi.fn(),
        on: (event, handler) => {
            navigateHandlers.push(handler)

            return () => navigateHandlers.splice(navigateHandlers.indexOf(handler), 1)
        },
    },
    usePage: () => ({ props: {} }),
}))

const memory = await import('../../resources/js/lib/listMemory')
const PageHeader = (await import('../../resources/js/Components/PageHeader.vue')).default
const PrimaryButton = (await import('../../resources/js/Components/PrimaryButton.vue')).default
const DataTable = (await import('../../resources/js/Components/DataTable.vue')).default

function at(path) {
    window.history.replaceState({}, '', path)
}

beforeEach(() => {
    memory.forgetAll()
    at('/')
})

afterEach(() => memory.forgetAll())

describe('the memory', () => {
    it('gives back the list as it was left', () => {
        memory.remember('/customers', { url: '/customers?sort=balance&direction=desc&page=2' })

        expect(memory.listHref('/customers')).toBe('/customers?sort=balance&direction=desc&page=2')
    })

    it('leaves alone a list never visited, an address with its own query, and anything else', () => {
        memory.remember('/customers', { url: '/customers?sort=balance' })

        expect(memory.listHref('/suppliers')).toBe('/suppliers')
        expect(memory.listHref('/customers?status=active')).toBe('/customers?status=active')
        expect(memory.listHref('/customers/5')).toBe('/customers/5')
        expect(memory.listHref(null)).toBeNull()
    })

    it('says a return once, then forgets it', () => {
        memory.markReturn('/customers')

        expect(memory.takeReturn('/suppliers')).toBe(false)
        expect(memory.takeReturn('/customers')).toBe(true)
        expect(memory.takeReturn('/customers')).toBe(false)
    })

    it('falls back to the plain list when storage is blocked', () => {
        const spy = vi.spyOn(window, 'sessionStorage', 'get').mockImplementation(() => {
            throw new Error('blocked')
        })

        expect(() => memory.remember('/customers', { url: '/customers?page=3' })).not.toThrow()
        expect(memory.listHref('/customers')).toBe('/customers')
        expect(memory.takeReturn('/customers')).toBe(false)

        spy.mockRestore()
    })
})

describe('the breadcrumb', () => {
    it('reads the list and the page, and leads back to the list as it was left', () => {
        memory.remember('/customers', { url: '/customers?sort=balance&direction=desc' })

        const header = mount(PageHeader, {
            props: { heading: 'RJ Builders & Construction', backHref: '/customers', backLabel: 'Back to customers' },
        })
        const crumbs = header.get('ol')

        expect(crumbs.findAll('li').map((li) => li.text())).toEqual(['Customers', 'RJ Builders & Construction'])
        expect(crumbs.get('a').attributes('href')).toBe('/customers?sort=balance&direction=desc')
        expect(crumbs.find('[aria-current="page"]').text()).toBe('RJ Builders & Construction')
    })

    it('marks the visit as a return, so the list scrolls back and marks the row', async () => {
        memory.remember('/customers', { url: '/customers?page=2' })

        const header = mount(PageHeader, { props: { heading: 'X', backHref: '/customers', backLabel: 'Back to customers' } })

        await header.get('ol a').trigger('click')

        expect(memory.takeReturn('/customers')).toBe(true)
    })

    it('takes a trail of its own two levels down', () => {
        const header = mount(PageHeader, {
            props: {
                heading: 'Statement of account',
                backHref: '/customers/3',
                crumbs: [
                    { label: 'Customers', href: '/customers' },
                    { label: 'RJ Builders', href: '/customers/3' },
                ],
            },
        })

        expect(header.get('ol').findAll('li').map((li) => li.text())).toEqual(['Customers', 'RJ Builders', 'Statement of account'])
        // On a phone, one arrow back to the page above.
        expect(header.get('nav > a').attributes('href')).toBe('/customers/3')
    })

    it('words the crumb from the old back label', () => {
        const header = mount(PageHeader, {
            props: { heading: 'PRQ-2026-0001', backHref: '/purchase-requests', backLabel: 'Back to purchase requests' },
        })

        expect(header.get('ol a').text()).toBe('Purchase requests')
    })

    it('shows no trail on a page with nowhere to go back to', () => {
        expect(mount(PageHeader, { props: { heading: 'Customers' } }).find('[data-breadcrumb]').exists()).toBe(false)
    })
})

describe('Cancel', () => {
    it('goes back to the list as it was left', () => {
        memory.remember('/sales', { url: '/sales?status=posted&page=4' })

        const button = mount(PrimaryButton, { props: { href: '/sales', variant: 'secondary' }, slots: { default: 'Cancel' } })

        expect(button.get('a').attributes('href')).toBe('/sales?status=posted&page=4')
    })

    it('leaves any other link as it is', () => {
        memory.remember('/sales', { url: '/sales?page=4' })

        const button = mount(PrimaryButton, { props: { href: '/sales/create' }, slots: { default: 'New sale' } })

        expect(button.get('a').attributes('href')).toBe('/sales/create')
    })
})

describe('the table', () => {
    const data = {
        data: [
            { id: 1, name: 'Ana' },
            { id: 2, name: 'Ben' },
        ],
        links: [],
        from: 1,
        to: 2,
        total: 2,
    }
    const columns = [{ key: 'name', label: 'Name' }]

    it('writes down the address it is showing, and again after every search, sort or page', () => {
        at('/customers?sort=balance&direction=desc')
        mount(DataTable, { props: { data, columns, url: '/customers' } })

        expect(memory.recall('/customers').url).toBe('/customers?sort=balance&direction=desc')

        at('/customers?sort=balance&direction=desc&page=2')
        navigateHandlers.forEach((handler) => handler())

        expect(memory.recall('/customers').url).toBe('/customers?sort=balance&direction=desc&page=2')
    })

    it('keeps nothing when it is not the list on the page', () => {
        at('/customers/3')
        mount(DataTable, { props: { data, columns, url: '/sales' } })

        expect(memory.recall('/sales')).toBeNull()
    })

    it('marks the row last opened, and remembers it', async () => {
        at('/customers')
        const table = mount(DataTable, { props: { data, columns, url: '/customers' } })

        await table.findAll('tbody tr')[1].trigger('click')

        expect(table.findAll('tbody tr')[1].attributes('data-last-opened')).toBe('true')
        expect(table.findAll('tbody tr')[0].attributes('data-last-opened')).toBeUndefined()
        expect(memory.recall('/customers').row).toBe(2)
    })

    it('on a return, marks the row again and scrolls back down', async () => {
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
        const frame = vi.spyOn(window, 'requestAnimationFrame').mockImplementation((fn) => {
            fn()

            return 1
        })

        at('/customers')
        memory.remember('/customers', { url: '/customers', row: 2, scroll: 640 })
        memory.markReturn('/customers')

        const table = mount(DataTable, { props: { data, columns, url: '/customers' } })
        await nextTick()
        await nextTick()

        expect(table.findAll('tbody tr')[1].attributes('data-last-opened')).toBe('true')
        expect(scrollTo).toHaveBeenCalledWith({ top: 640 })

        scrollTo.mockRestore()
        frame.mockRestore()
    })

    it('opened fresh from the menu, marks nothing', () => {
        at('/customers')
        memory.remember('/customers', { url: '/customers', row: 2 })

        const table = mount(DataTable, { props: { data, columns, url: '/customers' } })

        expect(table.find('[data-last-opened]').exists()).toBe(false)
    })
})
