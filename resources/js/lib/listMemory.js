/**
 * Every list remembers where you were (24 November 2026).
 *
 * The shared table writes down, for its list, the address it is showing —
 * sort, filters, search, rows per page and page, all in the query string —
 * how far down the page was scrolled, and the row last opened. Every
 * "Back to …" link, breadcrumb and Cancel button that leads to a bare list
 * address goes to that address instead, and the list scrolls back and
 * marks the row, so nobody has to sort and search again.
 *
 * The sidebar never reads it: a list opened from the menu starts fresh.
 *
 * Kept in `sessionStorage`, so it belongs to one browser tab and goes when
 * the tab closes. A convenience only: every read and write is wrapped, and
 * with storage blocked the links simply open the list from the start.
 */

const KEY = 'goe.lists'
const RETURN_KEY = 'goe.lists.return'

function storage() {
    try {
        return window.sessionStorage
    } catch {
        return null
    }
}

function readAll() {
    try {
        const parsed = JSON.parse(storage()?.getItem(KEY) ?? '{}')

        return parsed && typeof parsed === 'object' ? parsed : {}
    } catch {
        return {}
    }
}

function writeAll(all) {
    try {
        storage()?.setItem(KEY, JSON.stringify(all))
    } catch {
        // Full or blocked: the links fall back to the list from the start.
    }
}

/** The path of an address: "/customers?sort=balance" -> "/customers". */
export function pathOf(href) {
    if (!href) return ''

    try {
        return new URL(href, window.location.origin).pathname
    } catch {
        return String(href).split(/[?#]/)[0]
    }
}

/** What is remembered for a list, or null. */
export function recall(path) {
    return readAll()[pathOf(path)] ?? null
}

/** Write down part of a list's state: `{ url }`, `{ scroll }` or `{ row }`. */
export function remember(path, changes) {
    const key = pathOf(path)

    if (!key) return

    const all = readAll()
    all[key] = { ...(all[key] ?? {}), ...changes }
    writeAll(all)
}

/**
 * Where a link to a list should go: the address last seen there, or the
 * link as it is. Only a bare list address is changed — one that already
 * carries a query, or leads anywhere else, is left alone.
 */
export function listHref(href) {
    if (!href || /[?#]/.test(href)) return href

    const saved = recall(href)

    return saved?.url && pathOf(saved.url) === pathOf(href) ? saved.url : href
}

/** Say that the next visit to this list is a return, to scroll back and mark the row. */
export function markReturn(href) {
    try {
        storage()?.setItem(RETURN_KEY, pathOf(href))
    } catch {
        // Nothing to restore then; the list still opens where it was.
    }
}

/** Is this mount of the list a return? Asked once: the answer is used up. */
export function takeReturn(path) {
    try {
        const s = storage()

        if (s?.getItem(RETURN_KEY) === pathOf(path)) {
            s.removeItem(RETURN_KEY)

            return true
        }
    } catch {
        // Treated as a fresh visit.
    }

    return false
}

/** Forget everything, for the tests. */
export function forgetAll() {
    try {
        storage()?.removeItem(KEY)
        storage()?.removeItem(RETURN_KEY)
    } catch {
        // Nothing kept.
    }
}
