/**
 * The supplier a new purchase request suggests (phase 30).
 *
 * The first line whose product names a preferred supplier decides it — the
 * request asks for one supplier, and the first item is what it is mostly
 * about. A supplier no longer on the form's list (switched off) is passed
 * over, because the form could not show it.
 *
 * @param {Array<{product_id:number|string}>} lines
 * @param {Array<{value:number, preferred_supplier_id?:number|null}>} products
 * @param {Array<{value:number}>} suppliers
 * @returns {number|null}
 */
export function usualSupplier(lines, products, suppliers) {
    const offered = new Set(suppliers.map((supplier) => String(supplier.value)))

    for (const line of lines ?? []) {
        if (line.product_id === '' || line.product_id === null || line.product_id === undefined) continue

        const product = products.find((p) => String(p.value) === String(line.product_id))
        const id = product?.preferred_supplier_id

        if (id && offered.has(String(id))) return id
    }

    return null
}
