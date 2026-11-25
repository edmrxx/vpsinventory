import { count, toMilli } from './money'

/**
 * Put a recalled hold back on the counter, against the shelf as it is now.
 *
 * A hold keeps only what was rung up: which product, which unit, how many.
 * Everything else is read again from the till's own product list, because
 * time has passed since it was parked:
 *
 * - the **price** is today's price, the price any sale rung up now would
 *   carry;
 * - a product **no longer for sale**, or a unit it is no longer sold in, is
 *   left off;
 * - a quantity **past what is on the shelf** is cut to what is left — the
 *   last few were sold to somebody else meanwhile;
 * - a **customer** no longer active puts the sale back on Walk-in.
 *
 * Each of those is said in `changes`, so the cashier can tell the customer
 * before taking the money. Nothing here is the final word: the sale is
 * still weighed against the shelf by the server when it posts.
 *
 * @param {{ customer_id?: number|null, discount?: string|number, lines: Array<{product_id:number, unit_id:number|null, quantity:string|number, unit_price?:string}> }} hold
 * @param {Array} products  The till's `products` prop.
 * @param {Array<{value:number,label:string}>} customers  The till's `customers` prop.
 * @param {number|null} walkInId
 * @param {(value:any) => string} [money]  How to write an amount in a message.
 * @returns {{ lines: Array, customerId: number|null, discount: string, changes: string[] }}
 */
export function rebuildHold(hold, products, customers, walkInId, money = (value) => Number(value).toFixed(2)) {
    const byId = new Map(products.map((product) => [String(product.id), product]))

    /** How much of each product's own unit the rebuilt lines already take, in thousandths. */
    const taken = new Map()

    const lines = []
    const changes = []

    for (const held of hold.lines ?? []) {
        const product = byId.get(String(held.product_id))

        if (!product) {
            changes.push('A product on it is no longer for sale, so it was left off.')

            continue
        }

        const units = product.units?.length
            ? product.units
            : [{ value: '', label: product.unit, factor: 1, price: product.price, decimals: product.decimals }]

        const unit = held.unit_id === null || held.unit_id === undefined || held.unit_id === ''
            ? units[0]
            : units.find((option) => String(option.value) === String(held.unit_id))

        if (!unit) {
            changes.push(`${product.name} is no longer sold by that unit, so it was left off.`)

            continue
        }

        const factor = toMilli(unit.factor) || 1000
        const inOwnUnit = (milli) => Math.round((milli * factor) / 1000)
        const free = toMilli(product.on_hand) - (taken.get(product.id) ?? 0)

        let quantity = toMilli(held.quantity)

        if (inOwnUnit(quantity) > free) {
            // As many of this unit as still fit, whole where the unit is whole.
            let fit = Math.max(Math.floor((free * 1000) / factor), 0)

            if (!unit.decimals) fit = Math.floor(fit / 1000) * 1000

            if (fit <= 0) {
                changes.push(`${product.name} has sold out since, so it was left off.`)

                continue
            }

            changes.push(`Only ${count(fit / 1000)} ${unit.label ?? ''} of ${product.name} left, so the quantity was cut to that.`.replace(/\s+/g, ' '))
            quantity = fit
        }

        if (held.unit_price !== undefined && Math.round(Number(held.unit_price) * 100) !== Math.round(Number(unit.price) * 100)) {
            changes.push(`${product.name} is now ${money(unit.price)}, not ${money(held.unit_price)}.`)
        }

        taken.set(product.id, (taken.get(product.id) ?? 0) + inOwnUnit(quantity))

        lines.push({
            key: `${product.id}:${unit.value}`,
            ...product,
            unit_id: unit.value,
            unit_code: unit.label,
            factor: unit.factor,
            price: unit.price,
            decimals: !!unit.decimals,
            quantity: quantity / 1000,
        })
    }

    const customer = customers.find((option) => String(option.value) === String(hold.customer_id))

    if (hold.customer_id && !customer) {
        changes.push('The customer on it is no longer active, so the sale is back on Walk-in.')
    }

    const discount = Number(hold.discount ?? 0)

    return {
        lines,
        customerId: customer ? customer.value : walkInId,
        discount: discount > 0 ? String(hold.discount) : '',
        changes,
    }
}
