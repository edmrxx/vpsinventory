import { nextTick, shallowRef } from 'vue'

/**
 * Printing a sale slip.
 *
 * The slip is drawn by `Components/SaleSlip.vue` from the sale's own
 * `detail()` payload — the one the sale page and its dialog already read —
 * so a printed slip can never say something the screen does not.
 *
 * It is printed from the page the person is on, not from a new window or a
 * hidden frame: a new tab on a counter tablet strands the cashier, and the
 * app refuses to be framed (`X-Frame-Options: DENY`) for good reasons.
 * `SlipPrinter.vue` keeps the slip in the document, hidden on screen; while
 * printing, `body.printing-slip` hides everything else on paper (see
 * `app.css`), so only the slip comes out.
 */

/** The sale on paper right now, or null. Read by `SlipPrinter.vue`. */
export const slipOnPaper = shallowRef(null)

let printing = false

/**
 * Print the slip for a sale.
 *
 * @param {object|number} source  The sale itself (the `sale` of a detail
 *   payload), or its id, which is then read from `/sales/{id}/preview`.
 * @returns {Promise<boolean>}  False if it could not be read.
 */
export async function printSlip(source) {
    if (printing) return false

    printing = true

    try {
        const sale = typeof source === 'object' && source !== null ? source : await readSale(source)

        if (!sale) return false

        slipOnPaper.value = sale

        await nextTick()
        await picturesReady(document.getElementById('sale-slip'))

        document.body.classList.add('printing-slip')
        window.print()

        // Most desktop browsers wait in print() until the dialog closes, but
        // a tablet's may return at once and lay the page out afterwards. So
        // the till is put back only when somebody next touches the screen,
        // by which time the slip has certainly gone to the printer.
        letGoOnNextTouch()

        return true
    } finally {
        printing = false
    }
}

/**
 * Print the slip for an order the kiosk sent to the cashier (CR-2): marked
 * NOT PAID, for the customer to carry to the cashier. Drawn by
 * `OrderSlip.vue` from the order as the till already holds it.
 *
 * @param {object} order  The `pending` of a pending payment's detail payload.
 * @returns {Promise<boolean>}
 */
export function printOrderSlip(order) {
    if (!order) return Promise.resolve(false)

    return printSlip({ ...order, __order: true })
}

/** The sale as its dialog reads it. */
async function readSale(id) {
    try {
        const response = await fetch(`/sales/${id}/preview`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })

        if (!response.ok) return null

        return (await response.json()).sale ?? null
    } catch {
        return null
    }
}

/** The logo has to be on the page before it can be on the paper. */
function picturesReady(root) {
    const pending = [...(root?.querySelectorAll('img') ?? [])].filter((img) => !img.complete)

    return Promise.all(
        pending.map(
            (img) =>
                new Promise((resolve) => {
                    img.addEventListener('load', resolve, { once: true })
                    img.addEventListener('error', resolve, { once: true })
                    // A logo that never answers does not hold up the slip.
                    setTimeout(resolve, 1500)
                })
        )
    )
}

function letGoOnNextTouch() {
    const letGo = () => {
        document.body.classList.remove('printing-slip')
        window.removeEventListener('pointerdown', letGo, true)
        window.removeEventListener('keydown', letGo, true)
    }

    window.addEventListener('pointerdown', letGo, true)
    window.addEventListener('keydown', letGo, true)
}
