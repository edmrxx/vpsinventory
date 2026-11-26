import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

const pageProps = { auth: { user: { appearance: null } }, appearance: 'light' }
const put = vi.fn()

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }), router: { put: (...args) => put(...args) } }))

const { applyAppearance, isDark, resolveTheme, syncAppearance } = await import('../../resources/js/lib/appearance.js')
const { darkChart, darkChartColours, barChartOptions, trendChartOptions } = await import('../../resources/js/lib/charts.js')
const AppearanceSwitch = (await import('../../resources/js/Components/AppearanceSwitch.vue')).default

/**
 * Phase 35.1 (CR-3): each person picks a light or a dark screen.
 */
const root = document.documentElement

afterEach(() => {
    delete root.dataset.appearance
    delete root.dataset.theme
    put.mockReset()
    pageProps.auth.user.appearance = null
    pageProps.appearance = 'light'
})

describe('what a choice comes to', () => {
    it('is dark only for Dark, or for the device when it is dark', () => {
        expect(resolveTheme('light', true)).toBe('light')
        expect(resolveTheme('dark', false)).toBe('dark')
        expect(resolveTheme('system', true)).toBe('dark')
        expect(resolveTheme('system', false)).toBe('light')
        expect(resolveTheme(null, true)).toBe('light')
    })

    it('is put on <html> after a visit, and taken away for a guest so the sign-in page is light', () => {
        syncAppearance({ appearance: 'dark' })
        expect(root.dataset.appearance).toBe('dark')
        expect(root.dataset.theme).toBe('dark')
        expect(isDark.value).toBe(true)

        // Signed out: an Inertia visit to the sign-in page, which has no appearance.
        syncAppearance({ appearance: null })
        expect(root.dataset.appearance).toBeUndefined()
        expect(root.dataset.theme).toBeUndefined()
        expect(isDark.value).toBe(false)
    })

    it('ignores a value it does not know', () => {
        applyAppearance('purple')
        expect(root.dataset.theme).toBeUndefined()
    })
})

describe('the switch in the top bar', () => {
    it('offers Light, Dark and Same as my device, and marks the one in use', () => {
        pageProps.appearance = 'dark'
        const wrapper = mount(AppearanceSwitch)

        const options = wrapper.findAll('[role="radio"]')
        expect(options.map((option) => option.text())).toEqual(['Light', 'Dark', 'Device'])
        expect(wrapper.get('[data-appearance-option="system"]').attributes('title')).toBe('Same as my device')
        expect(wrapper.get('[data-appearance-option="dark"]').attributes('aria-checked')).toBe('true')
    })

    it('changes the screen at once and saves without reloading the page’s figures', async () => {
        const wrapper = mount(AppearanceSwitch)

        await wrapper.get('[data-appearance-option="dark"]').trigger('click')

        expect(root.dataset.theme).toBe('dark')
        expect(put).toHaveBeenCalledTimes(1)
        const [url, data, options] = put.mock.calls[0]
        expect(url).toBe('/appearance')
        expect(data).toEqual({ appearance: 'dark' })
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true, only: ['auth', 'appearance'] })
    })

    it('puts the screen back when the save is refused', async () => {
        const wrapper = mount(AppearanceSwitch)

        await wrapper.get('[data-appearance-option="dark"]').trigger('click')
        put.mock.calls[0][2].onError()

        expect(root.dataset.theme).toBe('light')
    })

    it('stands in the top bar where the search box was, not in the menu under the name', () => {
        const topbar = readFileSync(resolve(__dirname, '../../resources/js/Components/AppTopbar.vue'), 'utf8')

        expect(topbar).not.toMatch(/type="search"/)
        expect(topbar.match(/<AppearanceSwitch\b/g)).toHaveLength(1)
        expect(topbar.indexOf('<AppearanceSwitch')).toBeLessThan(topbar.indexOf('role="menu"'))
    })

    it('names each choice for a screen reader, the word being hidden on a phone', () => {
        const wrapper = mount(AppearanceSwitch)

        expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Appearance')
        expect(wrapper.findAll('[role="radio"]').map((option) => option.attributes('aria-label'))).toEqual(['Light', 'Dark', 'Same as my device'])
        expect(wrapper.get('[data-appearance-option="light"] span').classes()).toEqual(['hidden', 'sm:block'])
    })

    it('comes as three icons for the till, which has no top bar', async () => {
        const wrapper = mount(AppearanceSwitch, { props: { compact: true } })

        const options = wrapper.findAll('[role="radio"]')
        expect(options.map((option) => option.attributes('aria-label'))).toEqual(['Light', 'Dark', 'Same as my device'])
        expect(wrapper.text()).toBe('')

        await wrapper.get('[data-appearance-option="dark"]').trigger('click')
        expect(put).toHaveBeenCalledTimes(1)
    })

    it('sits in the till header', () => {
        const pos = readFileSync(resolve(__dirname, '../../resources/js/Pages/Pos/Index.vue'), 'utf8')

        expect(pos).toMatch(/<AppearanceSwitch compact \/>/)
    })

    it('does nothing when the choice is already the one in use', async () => {
        pageProps.auth.user.appearance = 'light'
        const wrapper = mount(AppearanceSwitch)

        await wrapper.get('[data-appearance-option="light"]').trigger('click')

        expect(put).not.toHaveBeenCalled()
    })
})

describe('the charts on the dark screen', () => {
    const money = (value) => `₱${value}`

    it('recolour the axes, the grid, the tooltip and the legend, and keep the series colours', () => {
        const light = trendChartOptions({ categories: ['Jan', 'Feb'] }, money)
        const dark = darkChart(light)

        expect(dark.chart.background).toBe('transparent')
        expect(dark.theme.mode).toBe('dark')
        expect(dark.tooltip.theme).toBe('dark')
        expect(dark.tooltip.y.formatter(5)).toBe('₱5')
        expect(dark.grid.borderColor).toBe(darkChartColours.grid)
        expect(dark.xaxis.labels.style.colors).toBe(darkChartColours.axis)
        expect(dark.yaxis.labels.style.colors).toBe(darkChartColours.axis)
        expect(dark.yaxis.labels.formatter(1500)).toBe(light.yaxis.labels.formatter(1500))
        expect(dark.legend.labels.colors).toBe(darkChartColours.text)
        expect(dark.colors).toEqual(light.colors)

        // The light options are left as they were.
        expect(light.xaxis.labels.style.colors).not.toBe(darkChartColours.axis)
    })

    it('recolour the values written after the bars and the phone layout too', () => {
        const dark = darkChart(barChartOptions({ labels: ['A'], series: [{ name: 'x', data: [3] }] }, money, { labelWidth: 200, showValues: true }))

        expect(dark.dataLabels.style.colors).toEqual([darkChartColours.text])
        expect(dark.responsive[0].options.yaxis.labels.style.colors).toBe(darkChartColours.axis)
        expect(dark.responsive[0].options.xaxis.tickAmount).toBe(3)
    })

    it('leave a chart still loading as nothing', () => {
        expect(darkChart(null)).toBeNull()
    })
})

/*
 * The guard. The dark screen works because every colour is a token that
 * changes with it. A fixed colour would stay light — a white box, black
 * text, a grey from Tailwind's own palette — or a token used for the wrong
 * job would turn the wrong way: `ink` (text) as a backdrop, a `-700` text
 * shade as a hover. This fails on any of them, outside the screens that keep
 * a fixed identity of their own: the sign-in pages, the navy guest pages and
 * the slips, which are paper.
 */
describe('every screen can turn dark', () => {
    const FIXED_IDENTITY = new Set([
        'resources/js/Layouts/SignInLayout.vue',
        'resources/js/Components/SignInField.vue',
        'resources/js/Pages/Auth/Login.vue',
        'resources/js/Pages/Auth/Kiosk.vue',
        'resources/js/Layouts/GuestLayout.vue',
        'resources/js/Components/SaleSlip.vue',
        'resources/js/Components/OrderSlip.vue',
    ])

    const RULES = [
        // White or black that is not only for paper (`print:`) and not a see-through tint on a coloured box.
        { name: 'a fixed white or black', pattern: /(?<![\w:-]|print:)(?:[a-z-]+:)*(?:bg|text|border)-(?:white|black)(?![\w/-])/g, skip: /print:(?:[a-z-]+:)*(?:bg|text|border)-(?:white|black)/ },
        { name: "a grey from Tailwind's own palette", pattern: /\b(?:bg|text|border|ring|divide|fill|stroke|from|to|via)-(?:slate|gray|zinc|neutral|stone)-\d+/g },
        { name: 'ink (a text colour) used as a background', pattern: /\bbg-ink(?:\/\d+)?(?![\w-])/g },
        { name: 'surface (a background) used as text', pattern: /\btext-(?:surface|canvas)\b/g },
        { name: 'a -700 text shade used as a hover background', pattern: /\bhover:bg-(?:brand|success|warning|danger|info)-(?:700|800|900)\b/g },
    ]

    function files(dir) {
        return readdirSync(dir).flatMap((name) => {
            const path = join(dir, name)

            return statSync(path).isDirectory() ? files(path) : /\.(vue|js)$/.test(name) ? [path] : []
        })
    }

    const base = resolve(__dirname, '../..')

    it('uses no colour that stays light, and no token the wrong way round', () => {
        const found = []

        for (const path of files(join(base, 'resources/js'))) {
            const name = relative(base, path)

            if (FIXED_IDENTITY.has(name)) continue

            readFileSync(path, 'utf8')
                .split('\n')
                .forEach((line, index) => {
                    for (const rule of RULES) {
                        for (const match of line.matchAll(rule.pattern)) {
                            if (rule.skip && rule.skip.test(match[0])) continue

                            found.push(`${name}:${index + 1} — ${rule.name}: ${match[0]}`)
                        }
                    }
                })
        }

        expect(found).toEqual([])
    })

    it('keeps the dark tokens inside `@media screen`, so paper always prints light', () => {
        const css = readFileSync(join(base, 'resources/css/app.css'), 'utf8')
        const screen = css.indexOf('@media screen {')
        const first = css.indexOf(':root[data-theme="dark"]')

        expect(screen).toBeGreaterThan(-1)
        expect(first).toBeGreaterThan(screen)
    })
})
