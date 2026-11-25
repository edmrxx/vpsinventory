/**
 * How many ComboBox lists are open across the whole page.
 *
 * While any is open, `data-combo-open` sits on the body and `Modal.vue`
 * leaves Escape alone, so the first press closes the list and the second
 * closes the dialog.
 *
 * The count has to live here, in a module. Code at the top of a `<script
 * setup>` block runs once per component, so a counter declared there counts
 * only its own instance: closing the first of two open lists would take the
 * flag away while the second was still open.
 */
let openCount = 0

export function flagComboOpen(isOpen) {
    if (typeof document === 'undefined') return

    openCount = Math.max(0, openCount + (isOpen ? 1 : -1))

    if (openCount > 0) {
        document.body.dataset.comboOpen = 'true'

        return
    }

    delete document.body.dataset.comboOpen
}

/** Used by the tests to start from a known state. */
export function resetComboFlag() {
    openCount = 0

    if (typeof document !== 'undefined') delete document.body.dataset.comboOpen
}
