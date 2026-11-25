import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'

const post = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ render: () => null }),
    Link: defineComponent({
        props: { href: { type: String, required: true } },
        setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    usePage: () => ({ props: {} }),
    router: { on: () => () => {} },
    useForm: (data) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post,
            reset() {},
        }),
}))

const SignInField = (await import('../../resources/js/Components/SignInField.vue')).default
const Login = (await import('../../resources/js/Pages/Auth/Login.vue')).default

/**
 * The sign-in screen redrawn to the client's mockup (18 November 2026). The
 * look changed; what the form sends and the way to the kiosk did not.
 */
describe('the sign-in field', () => {
    it('shows and hides the password with the eye, and says which', async () => {
        const field = mount(SignInField, { props: { id: 'password', label: 'Password', type: 'password' } })
        const input = field.get('input')
        const eye = field.get('button')

        expect(input.attributes('type')).toBe('password')
        expect(eye.attributes('aria-label')).toBe('Show password')

        await eye.trigger('click')
        expect(input.attributes('type')).toBe('text')
        expect(eye.attributes('aria-label')).toBe('Hide password')
    })

    it('has no eye on a plain field', () => {
        const field = mount(SignInField, { props: { id: 'username', label: 'Username' } })
        expect(field.find('button').exists()).toBe(false)
    })
})

describe('the login card', () => {
    const card = () => mount(Login, { props: { status: null } })

    it('keeps the field names and the remember-me box the server reads', () => {
        const page = card()
        expect(page.get('#username').attributes('name')).toBe('username')
        expect(page.get('#username').attributes('autofocus')).toBeDefined()
        expect(page.get('#password').attributes('name')).toBe('password')
        expect(page.get('#remember').attributes('name')).toBe('remember')
        expect(page.get('label[for="remember"]').text()).toBe('Remember me')
    })

    it('keeps the way to the kiosk, which the mockup leaves out', () => {
        const kiosk = card().get('[data-test="kiosk-entry"] a')
        expect(kiosk.attributes('href')).toBe('/kiosk')
        expect(kiosk.text()).toBe('Kiosk sign in')
    })

    it('points Forgot password? nowhere, since there is no reset', () => {
        const link = card().findAll('a').find((a) => a.text() === 'Forgot password?')
        expect(link.attributes('href')).toBe('#')
    })

    it('posts to the same login route', async () => {
        const page = card()
        await page.get('form').trigger('submit')
        expect(post).toHaveBeenCalledWith('/login', expect.any(Object))
    })
})
