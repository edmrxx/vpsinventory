import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

/**
 * The bell (24 November 2026): what waits on this person, the same counts
 * as the sidebar badges, each linking to the list narrowed to them.
 */
const page = { component: 'Dashboard', props: { auth: { user: { name: 'Admin', username: 'admin', initials: 'SA', roles: ['Admin'], permissions: [] } }, notifications: [] } }

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Link: defineComponent({
        props: { href: String },
        setup(props, { slots, attrs }) {
            return () => h('a', { ...attrs, href: props.href }, slots.default?.())
        },
    }),
    router: { post: vi.fn() },
}))

const AppTopbar = (await import('../../resources/js/Components/AppTopbar.vue')).default

const waiting = [
    { key: 'purchase_requests', count: 1, text: ['purchase request waiting for your approval', 'purchase requests waiting for your approval'], href: '/purchase-requests?status=pending' },
    { key: 'loss_reports', count: 3, text: ['loss report waiting for your approval', 'loss reports waiting for your approval'], href: '/loss-reports?status=pending' },
]

describe('the bell', () => {
    it('shows the total in red, and each waiting item with its words and link', async () => {
        page.props.notifications = waiting

        const bar = mount(AppTopbar, { attachTo: document.body })

        expect(bar.get('[data-bell-count]').text()).toBe('4')

        await bar.get('[data-bell]').trigger('click')
        const items = bar.findAll('[data-bell-item]')

        expect(items).toHaveLength(2)
        expect(items[0].attributes('href')).toBe('/purchase-requests?status=pending')
        expect(items[0].text()).toContain('Purchase Requests')
        expect(items[0].text()).toContain('1 purchase request waiting for your approval')
        expect(items[1].text()).toContain('3 loss reports waiting for your approval')

        bar.unmount()
    })

    it('shows no red mark and says so when nothing waits', async () => {
        page.props.notifications = []

        const bar = mount(AppTopbar, { attachTo: document.body })

        expect(bar.find('[data-bell-count]').exists()).toBe(false)

        await bar.get('[data-bell]').trigger('click')

        expect(bar.get('[data-bell-panel]').text()).toContain('Nothing is waiting for you.')

        bar.unmount()
    })
})
