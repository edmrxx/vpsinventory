import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: '', tin: '', logo: null },
    slip: { width: '80', footer: '' },
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const PaymentEntry = (await import('../../resources/js/Components/PaymentEntry.vue')).default
const SaleSlip = (await import('../../resources/js/Components/SaleSlip.vue')).default

const methods = [
    { value: 1, label: 'Cash', kind: 'cash', cash: true, reference: false },
    { value: 2, label: 'GCash', kind: 'ewallet', cash: false, reference: true },
]

const account = { limit: '5000.00', balance: '0.00', available: '5000.00', terms_days: 30 }

/** A parent that keeps both v-models, as the till and the sale form do. */
function mountEntry({ withAccount = account, total = 1850 } = {}) {
    const rows = ref([{ payment_method_id: 1, amount: pesosOf(total), reference: '' }])
    const onAccount = ref('')

    const wrapper = mount(PaymentEntry, {
        props: {
            methods,
            total,
            account: withAccount,
            modelValue: rows.value,
            onAccount: onAccount.value,
            'onUpdate:modelValue': (value) => {
                rows.value = value
                wrapper.setProps({ modelValue: value })
            },
            'onUpdate:onAccount': (value) => {
                onAccount.value = value
                wrapper.setProps({ onAccount: value })
            },
        },
    })

    return { wrapper, rows, onAccount }
}

const pesosOf = (amount) => amount.toFixed(2)

const accountChip = (wrapper) => wrapper.findAll('button').find((button) => button.text() === 'On account')

describe('On account at the payment box', () => {
    it('says what is past due, and still lets the sale go on account', async () => {
        const { wrapper, onAccount } = mountEntry({ withAccount: { ...account, overdue: '750.00' } })

        await accountChip(wrapper).trigger('click')
        await nextTick()

        expect(onAccount.value).toBe('1850.00')
        expect(wrapper.text()).toContain('₱750.00 of what this customer owes is past due.')
    })

    it('says nothing about past due when nothing is', async () => {
        const { wrapper } = mountEntry({ withAccount: { ...account, overdue: '0.00' } })

        await accountChip(wrapper).trigger('click')
        await nextTick()

        expect(wrapper.text()).not.toContain('past due')
    })

    it('is not offered to a customer with no credit', () => {
        const { wrapper } = mountEntry({ withAccount: null })

        expect(accountChip(wrapper)).toBeUndefined()
    })

    it('puts the whole sale on account with one tap and leaves nothing to take', async () => {
        const { wrapper, onAccount } = mountEntry()

        await accountChip(wrapper).trigger('click')
        await nextTick()

        expect(onAccount.value).toBe('1850.00')
        expect(wrapper.text()).not.toContain('Cash received')
        expect(wrapper.text()).toContain('of credit left')
    })

    it('takes only what the credit will allow, and the cash follows the rest', async () => {
        const { wrapper, rows, onAccount } = mountEntry({ withAccount: { ...account, available: '1000.00' } })

        await accountChip(wrapper).trigger('click')
        await nextTick()
        await nextTick()

        expect(onAccount.value).toBe('1000.00')
        expect(rows.value[0].amount).toBe('850.00')
    })

    it('offers no bigger notes on a sale still owing', async () => {
        const { wrapper } = mountEntry({ withAccount: { ...account, available: '1000.00' } })

        await accountChip(wrapper).trigger('click')
        await nextTick()
        await nextTick()

        expect(wrapper.text()).toContain('Exact')
        expect(wrapper.text()).not.toContain('₱900.00')
    })

    it('comes off again with a second tap, and the cash goes back to the whole sale', async () => {
        const { wrapper, rows, onAccount } = mountEntry({ withAccount: { ...account, available: '1000.00' } })

        await accountChip(wrapper).trigger('click')
        await nextTick()
        await nextTick()
        await accountChip(wrapper).trigger('click')
        await nextTick()
        await nextTick()

        expect(onAccount.value).toBe('')
        expect(rows.value[0].amount).toBe('1850.00')
    })
})

describe('The slip of a sale on account', () => {
    const sale = {
        id: 5,
        reference: 'SI-2026-0042',
        customer: 'Dela Cruz',
        posted_at: '10 Nov 2026, 2:15 PM',
        posted_by: 'Ana',
        status: 'posted',
        subtotal: '1850.00',
        discount: '0.00',
        total_amount: '1850.00',
        vat_rate: '0.00',
        net_amount: '1850.00',
        vat_amount: '0.00',
        change: '0.00',
        payments: [],
        on_account: '1850.00',
        balance_due: '1850.00',
        due_date: '10 Dec 2026',
        lines: [],
    }

    it('says what went on account, when it is due, and is still not a receipt', () => {
        const text = mount(SaleSlip, { props: { sale } }).text()

        expect(text).toContain('On account')
        expect(text).toContain('10 Dec 2026')
        expect(text).toContain('This is not an official receipt.')
    })

    it('says nothing of an account on a sale paid in full', () => {
        const text = mount(SaleSlip, { props: { sale: { ...sale, on_account: '0.00', due_date: null } } }).text()

        expect(text).not.toContain('On account')
    })
})
