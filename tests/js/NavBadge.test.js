import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

/**
 * The sidebar's red count (24 November 2026): Pending Payments shows how
 * many orders wait to be paid, read from the shared `badges`.
 */
const page = { component: 'Dashboard', props: { badges: {} } }

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: defineComponent({
        props: { href: String },
        setup(props, { slots, attrs }) {
            return () => h('a', { ...attrs, href: props.href }, slots.default?.())
        },
    }),
}))

const NavLink = (await import('../../resources/js/Components/NavLink.vue')).default
const item = { label: 'Pending Payments', href: '/pending-payments', component: 'PendingPayments', icon: 'span', badge: 'pending_payments' }

describe('the sidebar badge', () => {
    it('shows the count in red while orders wait', () => {
        page.props.badges = { pending_payments: 4 }

        const badge = mount(NavLink, { props: { item } }).get('[data-nav-badge]')

        expect(badge.text()).toBe('4')
        expect(badge.classes()).toContain('bg-danger-500')
    })

    it('shows nothing at zero, or for an item with no badge', () => {
        page.props.badges = { pending_payments: 0 }
        expect(mount(NavLink, { props: { item } }).find('[data-nav-badge]').exists()).toBe(false)

        page.props.badges = { pending_payments: 4 }
        expect(mount(NavLink, { props: { item: { ...item, badge: undefined } } }).find('[data-nav-badge]').exists()).toBe(false)
    })

    it('stays on the icon when the menu is folded, and caps at 99+', () => {
        page.props.badges = { pending_payments: 120 }

        expect(mount(NavLink, { props: { item, iconsOnly: true } }).get('[data-nav-badge]').text()).toBe('99+')
    })
})
