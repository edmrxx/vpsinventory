/**
 * The category and unit lists a product form may pick from.
 *
 * The screen is sent every category and unit once, because the filter bar
 * above the table has to show the ones that are switched off as well: older
 * products still point at them.
 *
 * A form must not offer those, with one exception — the row the product
 * being edited already holds. Dropping it would silently change the product
 * the moment somebody saved an unrelated field.
 *
 * @param {{categories?: Array, units?: Array}} options  From the server.
 * @param {object|null} editing  The row being edited, or null for a new one.
 */
export function productPickers(options, editing) {
    return {
        categories: pick(options?.categories ?? [], editing?.category_id),
        units: pick(options?.units ?? [], editing?.unit_id, (o) => `${o.label} — ${o.name}`),
        // The usual supplier (phase 30): active ones, plus the one this
        // product already names if it was switched off since.
        suppliers: pick(options?.suppliers ?? [], editing?.preferred_supplier_id),
    }
}

function pick(all, keep, labeller) {
    return all
        .filter((option) => option.is_active || option.value === keep)
        .map((option) => ({
            value: option.value,
            // A unit says whether its products may be counted in parts.
            ...(option.allows_decimals !== undefined ? { allows_decimals: !!option.allows_decimals } : {}),
            // A unit's bare code, "pcs", for sentences that name it.
            ...(labeller ? { code: option.label } : {}),
            label: option.is_active
                ? (labeller ? labeller(option) : option.label)
                : `${option.label} (inactive)`,
        }))
}
