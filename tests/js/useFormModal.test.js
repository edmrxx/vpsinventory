import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * How the master dialog submits.
 *
 * PHP does not read an uploaded file on a PUT request. So an edit carrying
 * a file has to be posted with `_method: 'put'`, while every other edit
 * keeps the plain PUT it has always used. All five master dialogs share
 * this one composable, so getting it wrong breaks Categories, Units,
 * Suppliers and Customers as well as Products.
 */

const calls = []

const form = {
    data: () => form.values,
    values: {},
    errors: {},
    processing: false,
    isDirty: false,
    defaults: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
    transform(callback) {
        calls.push(['transform', callback({ a: 1 })])

        return form
    },
    put(url, options) {
        calls.push(['put', url])
        form.lastOptions = options
    },
    post(url, options) {
        calls.push(['post', url])
        form.lastOptions = options
    },
}

vi.mock('@inertiajs/vue3', () => ({ useForm: () => form }))
vi.mock('../../resources/js/lib/swal', () => ({
    confirmAction: vi.fn(),
    showValidationErrors: vi.fn(),
}))

const { useFormModal } = await import('../../resources/js/composables/useFormModal')
const { showValidationErrors } = await import('../../resources/js/lib/swal')

function build() {
    return useFormModal({
        url: '/products',
        blank: { name: '', photo: null },
        fromRow: (row) => ({ name: row.name, photo: null }),
        fields: ['name'],
        noun: 'product',
    })
}

describe('useFormModal submit', () => {
    beforeEach(() => {
        calls.length = 0
        form.values = {}
    })

    it('posts a new record', () => {
        const modal = build()

        modal.openCreate()
        modal.submit()

        expect(calls.at(-1)).toEqual(['post', '/products'])
    })

    it('puts an edit that carries no file', () => {
        const modal = build()

        modal.openEdit({ id: 7, name: 'Cap' })
        form.values = { name: 'Cap', photo: null }
        modal.submit()

        expect(calls.at(-1)).toEqual(['put', '/products/7'])
    })

    it('posts an edit that carries a file, spoofing the method', () => {
        const modal = build()

        modal.openEdit({ id: 7, name: 'Cap' })
        form.values = { name: 'Cap', photo: new File(['x'], 'cap.jpg') }
        modal.submit()

        expect(calls.at(-2)[0]).toBe('transform')
        expect(calls.at(-2)[1]).toEqual({ a: 1, _method: 'put' })
        expect(calls.at(-1)).toEqual(['post', '/products/7'])
    })

    it('drops the spoof again once the file is gone', () => {
        const modal = build()

        modal.openEdit({ id: 7, name: 'Cap' })
        form.values = { name: 'Cap', photo: new File(['x'], 'cap.jpg') }
        modal.submit()

        form.values = { name: 'Cap', photo: null }
        modal.submit()

        // The transform lives on the form, so a second save with no file
        // must not still be carrying `_method` from the first.
        expect(calls.at(-2)[1]).toEqual({ a: 1 })
        expect(calls.at(-1)).toEqual(['put', '/products/7'])
    })
})

describe('useFormModal errors', () => {
    it('leaves an error a field shows under its own box out of the alert, rows included', () => {
        const modal = useFormModal({
            url: '/products',
            blank: { name: '', units: [] },
            fromRow: (row) => row,
            fields: ['name', 'units.*'],
            noun: 'product',
        })

        showValidationErrors.mockClear()
        modal.openCreate()
        modal.submit()

        form.lastOptions.onError({
            name: 'Required.',
            'units.0.factor': 'Say how many it holds.',
            sku: 'Taken.',
        })

        expect(showValidationErrors).toHaveBeenCalledWith(['Taken.'])
    })
})
