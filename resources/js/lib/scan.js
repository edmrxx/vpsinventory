/**
 * Finding a product at the till by what was typed or scanned (phase 30).
 *
 * A barcode scanner is a keyboard: it types the code and presses Enter. The
 * till's search box narrows the tiles as it types, and on Enter adds the
 * product whose barcode — or SKU — is exactly what was typed. Exact, so a
 * scan of "123" never adds the product labelled "41234".
 */

/** Lower-case, trimmed, so a scan and a stored code compare alike. */
function fold(value) {
    return String(value ?? '').trim().toLowerCase()
}

/**
 * Whether a product answers a search: its name, SKU, brand, model or
 * barcode holds the words typed.
 *
 * @param {{name:string, sku:string, brand?:string|null, model?:string|null, barcode?:string|null}} product
 * @param {string} term
 */
export function matchesProduct(product, term) {
    const needle = fold(term)

    if (needle === '') return true

    return [product.name, product.sku, product.brand, product.model, product.barcode]
        .some((field) => fold(field).includes(needle))
}

/**
 * The one product a scan names, or null.
 *
 * A barcode wins over a SKU: a label is what a scanner reads, and a SKU is
 * the fallback for somebody typing the code by hand.
 *
 * @template {{barcode?:string|null, sku:string}} T
 * @param {T[]} products
 * @param {string} term
 * @returns {T|null}
 */
export function scannedProduct(products, term) {
    const code = fold(term)

    if (code === '') return null

    return (
        products.find((product) => product.barcode && fold(product.barcode) === code) ??
        products.find((product) => fold(product.sku) === code) ??
        null
    )
}
