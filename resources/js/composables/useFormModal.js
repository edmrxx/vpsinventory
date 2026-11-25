import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { confirmAction, showValidationErrors } from '../lib/swal'

/**
 * The create-and-edit dialog behind every master list screen.
 *
 * The row the user clicked already holds everything the form needs, so the
 * dialog opens with no request at all. See README section 4, step 6.
 *
 *     const modal = useFormModal({
 *         url: '/suppliers',
 *         blank: { code: '', name: '', is_active: true },
 *         fromRow: (row) => ({ code: row.code, name: row.name, is_active: row.is_active }),
 *         fields: ['code', 'name'],
 *         noun: 'supplier',
 *     })
 *
 * @param {object}   options
 * @param {string}   options.url      The resource root, e.g. "/suppliers".
 * @param {object}   options.blank    The values a new record starts with.
 * @param {Function} options.fromRow  Turns a table row into form values.
 * @param {string[]} options.fields   Field names that show their own error.
 *                                    "units.*" covers every key under units.
 * @param {string}   options.noun     Used in the headings, e.g. "supplier".
 * @param {Function} [options.label]  Names the record being edited.
 */
export function useFormModal({ url, blank, fromRow, fields = [], noun, label }) {
    const open = ref(false)
    const mode = ref('create')
    const editing = ref(null)

    const form = useForm({ ...blank })

    const isEdit = computed(() => mode.value === 'edit')

    const title = computed(() =>
        isEdit.value ? `Edit ${noun}` : `New ${noun}`
    )

    const subtitle = computed(() => {
        if (!isEdit.value || !editing.value) return ''

        return label ? label(editing.value) : ''
    })

    const submitLabel = computed(() =>
        isEdit.value ? `Save ${noun}` : `Create ${noun}`
    )

    function openCreate() {
        // Start from the blank values every time, so the dialog never shows
        // what the last record held.
        form.defaults({ ...blank })
        form.reset()
        form.clearErrors()

        editing.value = null
        mode.value = 'create'
        open.value = true
    }

    function openEdit(row) {
        const values = fromRow(row)

        form.defaults(values)
        form.reset()
        form.clearErrors()

        editing.value = row
        mode.value = 'edit'
        open.value = true
    }

    /** Close, asking first when there is unsaved typing to lose. */
    async function close() {
        if (form.processing) return

        if (form.isDirty) {
            const agreed = await confirmAction({
                icon: 'warning',
                title: 'Close without saving?',
                text: 'The changes in this form will be lost.',
                confirmText: 'Yes, close',
            })

            if (!agreed) return
        }

        open.value = false
        form.clearErrors()
    }

    /**
     * Is there a file in this form?
     *
     * PHP does not read an uploaded file on a PUT request — it only parses
     * multipart data on a POST. So an edit carrying a file is posted with
     * `_method: 'put'`, which Laravel routes to `update()` exactly as a
     * real PUT would. Forms with no file keep the plain PUT they have
     * always used, so the four dialogs that upload nothing are untouched.
     */
    function carriesFile(values) {
        return Object.values(values ?? {}).some((value) => value instanceof File)
    }

    /** Does a field on the form already show this error under its own box? */
    function ownsError(key) {
        return fields.some((field) =>
            field.endsWith('.*') ? key.startsWith(field.slice(0, -1)) : field === key
        )
    }

    function submit() {
        const options = {
            // The list behind the dialog keeps its page, sort and scroll.
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                open.value = false
                form.clearErrors()
                form.reset()
            },
            onError: (errors) => {
                // A field error already shows under its own box. The alert is
                // only for the rules that belong to no single field.
                const loose = Object.entries(errors)
                    .filter(([key]) => !ownsError(key))
                    .map(([, message]) => message)

                if (loose.length) showValidationErrors(loose)
            },
        }

        const target = isEdit.value ? `${url}/${editing.value.id}` : url

        if (isEdit.value && carriesFile(form.data())) {
            // The transform lives on the form, so it is set again on every
            // submit rather than left behind for the next one.
            form.transform((data) => ({ ...data, _method: 'put' })).post(target, options)

            return
        }

        form.transform((data) => data)

        if (isEdit.value) {
            form.put(target, options)

            return
        }

        form.post(target, options)
    }

    return {
        open,
        mode,
        form,
        editing,
        isEdit,
        title,
        subtitle,
        submitLabel,
        openCreate,
        openEdit,
        close,
        submit,
    }
}
