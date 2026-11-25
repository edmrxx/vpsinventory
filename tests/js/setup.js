import { afterEach, beforeEach, vi } from 'vitest'
import { resetComboFlag } from '../../resources/js/lib/comboBoxFlag'

/**
 * The gaps jsdom leaves.
 *
 * These are real browser APIs the component is right to use. jsdom simply
 * does not implement them, so they are filled in rather than worked around
 * in the component itself.
 */

// jsdom has no layout, so it does not scroll anything.
Element.prototype.scrollIntoView = vi.fn()

// Every rectangle is zero without layout, which is fine: the tests are about
// behaviour, not about where the list lands on screen.
beforeEach(() => {
    document.body.innerHTML = ''
    resetComboFlag()
})

afterEach(() => {
    document.body.innerHTML = ''
    resetComboFlag()
})
