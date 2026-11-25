import { onBeforeUnmount, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The paper and margins every full-page document prints on (phase 35),
 * from Settings → Print Layouts.
 *
 * The rule is put in the page only while a document page is open, and taken
 * out when it closes: the sale slip prints from other screens and must never
 * inherit an A4 page. "The printer's own setting" adds no rule at all, which
 * is how every page printed before this setting existed.
 */

const PAPER = { a4: 'A4', letter: 'letter', long: '8.5in 13in', legal: 'legal' }
const MARGINS = { narrow: '8mm', normal: '12mm', wide: '18mm' }

/** The CSS for the chosen paper and margins, or '' to leave the printer alone. */
export function pageRule(printing = {}) {
    const rules = []

    if (PAPER[printing.paper_size]) rules.push(`size: ${PAPER[printing.paper_size]};`)
    if (MARGINS[printing.margins]) rules.push(`margin: ${MARGINS[printing.margins]};`)

    return rules.length ? `@media print { @page { ${rules.join(' ')} } }` : ''
}

export const PRINT_LAYOUT_ID = 'maf-print-layout'

export function usePrintLayout() {
    const page = usePage()
    let style = null

    onMounted(() => {
        document.getElementById(PRINT_LAYOUT_ID)?.remove()

        const css = pageRule(page.props.printing ?? {})
        if (!css) return

        style = document.createElement('style')
        style.id = PRINT_LAYOUT_ID
        style.textContent = css
        document.head.appendChild(style)
    })

    onBeforeUnmount(() => {
        style?.remove()
        style = null
    })
}
