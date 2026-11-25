import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { system: { currency_symbol: '₱' } } }),
}))

const { centavos, likelyNotes, pesos, settle } = await import('../../resources/js/lib/payments')
const PaymentEntry = (await import('../../resources/js/Components/PaymentEntry.vue')).default

/**
 * How a sale is paid, drawn while the cashier types.
 *
 * These are the cases `tests/Feature/Sales/PaymentTest.php` holds the
 * server to, so the change on the screen is the change the sale is stored
 * with. A sale of ₱1,850 throughout.
 */

const methods = [
    { value: 1, label: 'Cash', kind: 'cash', cash: true, reference: false },
    { value: 2, label: 'GCash', kind: 'ewallet', cash: false, reference: true },
    { value: 3, label: 'Bank transfer', kind: 'bank', cash: false, reference: false },
]

const cash = (amount) => ({ payment_method_id: 1, amount })
const gcash = (amount, reference = '1234') => ({ payment_method_id: 2, amount, reference })

describe('settle', () => {
    it('gives change from cash', () => {
        const paid = settle(185000, [cash('2000')], methods)

        expect(paid.ready).toBe(true)
        expect(paid.change).toBe(15000)
        expect(paid.due).toBe(0)
    })

    it('says how much more is needed when the cash is short', () => {
        const paid = settle(185000, [cash('1800')], methods)

        expect(paid.ready).toBe(false)
        expect(paid.due).toBe(5000)
        expect(paid.errors['payments.0.amount']).toContain('50.00 more is needed')
    })

    it('takes the change from the cash in a split payment', () => {
        const paid = settle(185000, [gcash('1000'), cash('1000')], methods)

        expect(paid.ready).toBe(true)
        expect(paid.cashOwed).toBe(85000)
        expect(paid.change).toBe(15000)
    })

    it('never gives change from GCash', () => {
        const paid = settle(185000, [gcash('2000')], methods)

        expect(paid.ready).toBe(false)
        expect(paid.errors.payments).toContain('Change is given only from cash')
    })

    it('asks for the GCash reference number', () => {
        const paid = settle(185000, [gcash('1850', '  ')], methods)

        expect(paid.errors['payments.0.reference']).toBe('Type the GCash reference number.')
    })

    it('does not ask a method that needs none', () => {
        const paid = settle(185000, [{ payment_method_id: 3, amount: '1850' }], methods)

        expect(paid.ready).toBe(true)
    })

    it('refuses two cash lines', () => {
        const paid = settle(185000, [cash('1000'), cash('850')], methods)

        expect(paid.errors['payments.1.payment_method_id']).toContain('Only one cash payment')
    })

    it('needs a payment, unless the sale came to nothing', () => {
        expect(settle(185000, [], methods).errors.payments).toBe('Record how this sale was paid.')
        expect(settle(0, [], methods).ready).toBe(true)
    })

    it('adds in whole centavos', () => {
        expect(centavos('0.1') + centavos('0.2')).toBe(30)
        expect(pesos(185000)).toBe('1850.00')
        expect(settle(30, [{ payment_method_id: 3, amount: '0.1' }, cash('0.2')], methods).change).toBe(0)
    })
})

describe('likelyNotes', () => {
    it('offers the notes a customer is likely to hand over', () => {
        expect(likelyNotes(185000)).toEqual([190000, 200000])
        expect(likelyNotes(4550)).toEqual([10000, 50000, 100000])
    })

    it('never offers the exact figure, which has its own button', () => {
        expect(likelyNotes(200000)).toEqual([210000, 250000, 300000])
    })
})

describe('PaymentEntry', () => {
    // The parent holds the rows, as the till and the sale form do.
    function build(rows, total = 1850) {
        const wrapper = mount(PaymentEntry, {
            props: {
                modelValue: rows,
                methods,
                total,
                'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
            },
        })

        return wrapper
    }

    const rows = (wrapper) => wrapper.props('modelValue')
    const tap = (wrapper, label) => wrapper.findAll('button').find((b) => b.text().trim() === label).trigger('click')

    it('shows the change as soon as the cash is typed', async () => {
        const wrapper = build([cash('1850.00')])

        await wrapper.get('input[type="number"]').setValue('2000')

        expect(wrapper.text()).toContain('Change')
        expect(wrapper.text()).toContain('150.00')
    })

    it('lets the cash make up whatever GCash does not cover', async () => {
        const wrapper = build([cash('1850.00')])

        await tap(wrapper, 'GCash')

        // GCash starts empty for the cashier to type.
        expect(rows(wrapper)[1]).toEqual({ payment_method_id: 2, amount: '', reference: '' })

        await wrapper.findAll('input[type="number"]')[1].setValue('1000')

        expect(rows(wrapper)[0].amount).toBe('850.00')
    })

    it('stops following once the cashier types the cash handed over', async () => {
        const wrapper = build([cash('1850.00')])

        await wrapper.get('input[type="number"]').setValue('2000')
        await wrapper.setProps({ total: 1900 })

        expect(rows(wrapper)[0].amount).toBe('2000')
        expect(wrapper.text()).toContain('100.00')
    })

    it('fills the cash with a note in one tap', async () => {
        const wrapper = build([cash('1850.00')])

        await tap(wrapper, '₱2,000.00')

        expect(rows(wrapper)[0].amount).toBe('2000.00')
    })

    it('asks for a reference only where it applies', () => {
        expect(build([cash('1850')]).text()).not.toContain('Reference')
        expect(build([gcash('1850')]).text()).toContain('Reference no.')
    })

    it('follows the total while nobody has typed', async () => {
        const wrapper = build([cash('1850.00')])

        await wrapper.setProps({ total: 2000 })

        expect(rows(wrapper)[0].amount).toBe('2000.00')
    })

    it('keeps one way to pay at least', async () => {
        const wrapper = build([cash('1850.00')])

        await tap(wrapper, 'Cash')

        expect(rows(wrapper)).toHaveLength(1)
    })
})
