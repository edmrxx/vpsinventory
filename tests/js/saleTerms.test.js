import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import {
    agreedTerms,
    creditHintFor,
    dueDateLabel,
    settleSale,
    termsRefusal,
} from '../../resources/js/lib/payments'

const pageProps = {
    system: { currency_symbol: '₱' },
    company: { name: 'GO Enterprises', address: '', tin: '', logo: null },
    slip: { width: '80', footer: '' },
}

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }))

const PaymentEntry = (await import('../../resources/js/Components/PaymentEntry.vue')).default
const SaleSlip = (await import('../../resources/js/Components/SaleSlip.vue')).default

/**
 * Phase 18.1: the days a sale on account is given are agreed on that sale.
 * They start on the customer's usual terms; fewer is anybody's to give, more
 * needs the right to set credit — the same rule as
 * `CustomerCredit::termsRefusal()`.
 */
const methods = [{ value: 1, label: 'Cash', kind: 'cash', cash: true, reference: false }]
const account = { limit: '5000.00', balance: '0.00', available: '5000.00', terms_days: 30 }

describe('the rule for the days', () => {
    it('takes an empty box as the usual terms', () => {
        expect(agreedTerms(account, '')).toBe(30)
        expect(agreedTerms(account, null)).toBe(30)
        expect(agreedTerms(account, '15')).toBe(15)
        expect(agreedTerms(account, 0)).toBe(0)
    })

    it('lets anybody give fewer days, down to none', () => {
        expect(termsRefusal(account, '15', false)).toBeNull()
        expect(termsRefusal(account, '0', false)).toBeNull()
        expect(termsRefusal(account, '30', false)).toBeNull()
    })

    it('needs the right to set credit for more days than usual', () => {
        expect(termsRefusal(account, '45', false)).toBe(
            "This customer's usual terms are 30 days. Only somebody who may set credit can give more."
        )
        expect(termsRefusal(account, '45', true)).toBeNull()
    })

    it('takes whole days from zero to a year only', () => {
        expect(termsRefusal(account, '-1', true)).toBe('Terms cannot be below zero.')
        expect(termsRefusal(account, '366', true)).toBe('Terms are at most 365 days.')
        expect(termsRefusal(account, '2.5', true)).toBe('Type the terms as a whole number of days.')
    })

    it('holds the sale back while the days cannot be given', () => {
        const refused = settleSale(185000, '1850.00', [], methods, account, { days: '45', mayExtend: false })

        expect(refused.ready).toBe(false)
        expect(refused.errors.terms_days).toContain('usual terms are 30 days')
        expect(settleSale(185000, '1850.00', [], methods, account, { days: '15', mayExtend: false }).ready).toBe(true)
    })

    it('never weighs the days on a sale with nothing on account', () => {
        expect(settleSale(185000, '', [{ payment_method_id: 1, amount: '1850.00' }], methods, account, { days: '99', mayExtend: false }).ready).toBe(true)
    })

    it('writes the due date as the server does, on the calendar day alone', () => {
        expect(dueDateLabel('2026-11-24', 30)).toBe('24 Dec 2026')
        expect(dueDateLabel('2026-11-24', 0)).toBe('24 Nov 2026')
        expect(dueDateLabel('2026-08-31', 1)).toBe('01 Sep 2026')
        expect(dueDateLabel('', 30)).toBeNull()
    })
})

describe('why there is no On account', () => {
    it('names a customer with no credit limit', () => {
        expect(creditHintFor({ label: 'Aling Nena Hardware', walk_in: false, credit: null })).toBe(
            'Aling Nena Hardware has no credit limit, so this sale is paid in full now. To sell on account, set a credit limit under Customers.'
        )
    })

    it('says nothing for Walk-in, for a customer with credit, or for nobody', () => {
        expect(creditHintFor({ label: 'Walk-in Customer', walk_in: true, credit: null })).toBe('')
        expect(creditHintFor({ label: 'Dela Cruz', walk_in: false, credit: account })).toBe('')
        expect(creditHintFor(undefined)).toBe('')
    })
})

/** A parent that keeps all three v-models, as the till and the sale form do. */
function mountEntry({ mayExtendTerms = false, withAccount = account, creditHint = '' } = {}) {
    const rows = ref([{ payment_method_id: 1, amount: '1850.00', reference: '' }])
    const onAccount = ref('')
    const termsDays = ref('')

    const wrapper = mount(PaymentEntry, {
        props: {
            methods,
            total: 1850,
            account: withAccount,
            saleDate: '2026-11-24',
            mayExtendTerms,
            creditHint,
            modelValue: rows.value,
            onAccount: onAccount.value,
            termsDays: termsDays.value,
            'onUpdate:modelValue': (value) => {
                rows.value = value
                wrapper.setProps({ modelValue: value })
            },
            'onUpdate:onAccount': (value) => {
                onAccount.value = value
                wrapper.setProps({ onAccount: value })
            },
            'onUpdate:termsDays': (value) => {
                termsDays.value = value
                wrapper.setProps({ termsDays: value })
            },
        },
    })

    return { wrapper, onAccount, termsDays }
}

const accountChip = (wrapper) => wrapper.findAll('button').find((button) => button.text() === 'On account')

async function putOnAccount(wrapper) {
    await accountChip(wrapper).trigger('click')
    await nextTick()
    await nextTick()
}

describe('the Terms box', () => {
    it('starts on the customer’s usual terms and says when it falls due', async () => {
        const { wrapper, termsDays } = mountEntry()

        await putOnAccount(wrapper)

        expect(termsDays.value).toBe(30)
        expect(wrapper.get('[data-terms-days]').element.value).toBe('30')
        expect(wrapper.get('[data-terms-summary]').text().replace(/\s+/g, ' ')).toBe(
            '₱5,000.00 of credit left · due 24 Dec 2026 (30 days)'
        )
    })

    it('moves the due date with the days typed, and names the usual beside it', async () => {
        const { wrapper } = mountEntry()

        await putOnAccount(wrapper)
        await wrapper.get('[data-terms-days]').setValue('15')
        await nextTick()

        expect(wrapper.get('[data-terms-summary]').text().replace(/\s+/g, ' ')).toBe(
            '₱5,000.00 of credit left · due 09 Dec 2026 (15 days) · usual 30 days'
        )
    })

    it('refuses more days than usual for a cashier, under the box', async () => {
        const { wrapper } = mountEntry()

        await putOnAccount(wrapper)
        await wrapper.get('[data-terms-days]').setValue('45')
        await nextTick()

        expect(wrapper.text()).toContain("This customer's usual terms are 30 days. Only somebody who may set credit can give more.")
        expect(wrapper.get('[data-terms-days]').attributes('aria-invalid')).toBe('true')
    })

    it('lets somebody who may set credit give more', async () => {
        const { wrapper } = mountEntry({ mayExtendTerms: true })

        await putOnAccount(wrapper)
        await wrapper.get('[data-terms-days]').setValue('45')
        await nextTick()

        expect(wrapper.text()).not.toContain('Only somebody who may set credit')
        expect(wrapper.get('[data-terms-summary]').text()).toContain('due 08 Jan 2027 (45 days)')
    })

    it('empties the days when the sale comes off the account', async () => {
        const { wrapper, termsDays } = mountEntry()

        await putOnAccount(wrapper)
        await putOnAccount(wrapper)

        expect(termsDays.value).toBe('')
        expect(wrapper.find('[data-terms-days]').exists()).toBe(false)
    })

    it('says why On account is missing for a customer with no credit', () => {
        const hint = 'Aling Nena Hardware has no credit limit, so this sale is paid in full now. To sell on account, set a credit limit under Customers.'
        const { wrapper } = mountEntry({ withAccount: null, creditHint: hint })

        expect(accountChip(wrapper)).toBeUndefined()
        expect(wrapper.get('[data-credit-hint]').text()).toBe(hint)
    })
})

describe('the slip', () => {
    const sale = {
        id: 5,
        reference: 'SI-2026-0042',
        customer: 'Dela Cruz',
        posted_at: '24 Nov 2026, 2:15 PM',
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
        due_date: '09 Dec 2026',
        terms_days: 15,
        lines: [],
    }

    it('prints the days agreed beside the due date', () => {
        const text = mount(SaleSlip, { props: { sale } }).text()

        expect(text).toContain('Terms')
        expect(text).toContain('15 days')
        expect(text).toContain('09 Dec 2026')
    })
})
