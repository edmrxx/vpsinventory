<script setup>
import { computed } from 'vue'
import { Plus, Trash2 } from '@lucide/vue'
import SelectField from './SelectField.vue'
import TextField from './TextField.vue'
import { count, lineCentavos, lineTotal as priceLine, quantityStep, toMilli, useMoney, vatSplit } from '../lib/money'

/**
 * The lines of a document: product, quantity, price, total.
 *
 * Built for purchases, and meant to be reused by sales, returns and
 * adjustments. Only the wording differs between them, so that is what the
 * props carry.
 *
 * The totals here are for reading. The server works them out again from the
 * quantity and the price, and ignores anything this component sends.
 */
const props = defineProps({
    /** The lines: [{ product_id, unit_id, quantity, unit_cost }] */
    modelValue: { type: Array, required: true },
    /**
     * [{ value, label, sku, cost, unit, decimals, units }]
     *
     * `units` lists the product's own unit first, then any others it is
     * bought and sold in: [{ value, label, factor, price, cost, decimals }].
     */
    products: { type: Array, default: () => [] },
    /**
     * Let each line pick a unit: 1 box rather than 100 pcs. Off on an
     * adjustment, which counts in the product's own unit only.
     */
    withUnits: { type: Boolean, default: false },
    /** The form's errors, keyed `lines.0.quantity` and so on. */
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, default: 'lines' },
    priceLabel: { type: String, default: 'Unit Cost' },
    /** Which key on a line holds the price: unit_cost buying, unit_price selling. */
    priceField: { type: String, default: 'unit_cost' },
    /** Which figure on a product fills that price in. */
    productPriceField: { type: String, default: 'cost' },
    /** Show what is on hand beside the product, and warn when it runs short. */
    showStock: { type: Boolean, default: false },
    /** What the stock line calls this document: "12 left after this sale." */
    documentNoun: { type: String, default: 'sale' },
    /** Off when the price is not typed but worked out, as on a decrease. */
    showPrice: { type: Boolean, default: true },
    /** Warn when the price is below what the goods cost. */
    warnBelowCost: { type: Boolean, default: false },
    /**
     * The VAT rate to show the running total split at, or 0 for none.
     *
     * Only for a document with no discount of its own. A sale takes its
     * discount off before the VAT is worked out, so that screen does the
     * split under its own total instead of here.
     */
    vatRate: { type: [Number, String], default: 0 },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const { money, symbol } = useMoney()

const byId = computed(() => {
    const map = {}
    props.products.forEach((p) => {
        map[p.value] = p
    })

    return map
})

function priceOf(line) {
    return line[props.priceField]
}

/**
 * Quantity times price, to the centavo, the way the server prices it.
 *
 * Money is added in centavos and quantities in thousandths, both whole
 * numbers, because adding decimals drifts: 0.1 + 0.2 is famously not 0.3.
 */
function lineTotal(line) {
    return priceLine(priceOf(line), line.quantity)
}

/** The units a line may pick from: the product's own first. */
function unitsOf(line) {
    if (!props.withUnits) return []

    return byId.value[line.product_id]?.units ?? []
}

/** The unit a line is typed in, or null when the form offers none. */
function unitOf(line) {
    const units = unitsOf(line)

    return units.find((unit) => String(unit.value) === String(line.unit_id)) ?? units[0] ?? null
}

/** 2.5 may be typed only where the unit picked allows decimals. */
function stepFor(line) {
    const unit = unitOf(line)

    return quantityStep(unit ? unit.decimals : byId.value[line.product_id]?.decimals)
}

/**
 * The line in the product's own unit, in thousandths: 2 boxes of 100 is
 * 200 pieces. Rounded only for drawing; the server refuses a line that
 * does not come out exact.
 */
function baseMilli(line) {
    const factor = unitOf(line)?.factor ?? 1

    return Math.round((toMilli(line.quantity) * toMilli(factor)) / 1000)
}

/** "= 200 pcs" under a line typed in another unit. */
function inOwnUnit(line) {
    const unit = unitOf(line)

    if (!unit || toMilli(unit.factor) === 1000) return null

    return `= ${count(baseMilli(line) / 1000)} ${byId.value[line.product_id]?.unit ?? ''}`.trim()
}

/** What is left after this document's own lines are taken off. */
function stockAfter(line) {
    const product = byId.value[line.product_id]

    if (!product || product.on_hand === undefined) return null

    const asked = props.modelValue
        .filter((row) => String(row.product_id) === String(line.product_id))
        .reduce((sum, row) => sum + baseMilli(row), 0)

    return (toMilli(product.on_hand) - asked) / 1000
}

/**
 * Selling below what it cost is allowed, but worth saying out loud.
 *
 * Weighed per unit: a box of 100 against 100 times the piece cost.
 */
function belowCost(line) {
    if (!props.warnBelowCost) return false

    const product = byId.value[line.product_id]
    const cost = unitOf(line)?.cost ?? product?.cost

    if (!product || cost === undefined) return false

    return Number(priceOf(line)) > 0 && Number(priceOf(line)) < Number(cost)
}

const total = computed(() =>
    props.modelValue.reduce((sum, line) => sum + lineCentavos(priceOf(line), line.quantity), 0) / 100
)

const vat = computed(() => vatSplit(total.value, props.vatRate))

const totalQuantity = computed(() =>
    props.modelValue.reduce((sum, line) => sum + toMilli(line.quantity), 0) / 1000
)

function update(index, field, value) {
    const lines = props.modelValue.map((line, i) =>
        i === index ? { ...line, [field]: value } : line
    )

    emit('update:modelValue', lines)
}

/**
 * Picking a product brings its own cost in, as a starting point.
 *
 * The typed figure is kept, but only if it is really the user's: a cost
 * still sitting at zero, or still showing what the product being replaced
 * costs, is the component's own and gets refilled.
 */
function pickProduct(index, productId) {
    const line = props.modelValue[index]
    const picked = byId.value[productId]
    const previous = unitOf(line) ?? byId.value[line.product_id]
    const field = props.priceField
    const from = props.productPriceField

    const untouched =
        !Number(line[field]) ||
        (previous && Number(line[field]) === Number(previous[from]))

    // A new product starts in its own unit.
    const ownUnit = props.withUnits ? (picked?.units?.[0] ?? null) : null

    const lines = props.modelValue.map((row, i) =>
        i === index
            ? {
                  ...row,
                  product_id: productId,
                  ...(props.withUnits ? { unit_id: ownUnit?.value ?? '' } : {}),
                  [field]: picked && untouched ? (ownUnit ?? picked)[from] : row[field],
              }
            : row
    )

    emit('update:modelValue', lines)
}

/**
 * Switching a line to a box brings the box price in, the same way picking
 * a product brings its price: only over a figure the user has not typed.
 */
function pickUnit(index, unitId) {
    const line = props.modelValue[index]
    const field = props.priceField
    const from = props.productPriceField
    const previous = unitOf(line)
    const picked = unitsOf(line).find((unit) => String(unit.value) === String(unitId))

    const untouched =
        !Number(line[field]) ||
        (previous && Number(line[field]) === Number(previous[from]))

    const lines = props.modelValue.map((row, i) =>
        i === index
            ? {
                  ...row,
                  unit_id: unitId,
                  [field]: picked && untouched ? picked[from] : row[field],
              }
            : row
    )

    emit('update:modelValue', lines)
}

function addLine() {
    emit('update:modelValue', [
        ...props.modelValue,
        {
            product_id: '',
            ...(props.withUnits ? { unit_id: '' } : {}),
            quantity: 1,
            [props.priceField]: '0.00',
        },
    ])
}

function removeLine(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}

function errorFor(index, field) {
    return props.errors[`${props.errorPrefix}.${index}.${field}`]
}

const listError = computed(() => props.errors[props.errorPrefix])

/**
 * The same columns with or without units: the unit picker sits under the
 * number rather than beside it, so the product name keeps its room on a
 * tablet. Written out in full, because Tailwind only generates a class it
 * can read in the source.
 */
const columns = computed(() =>
    props.showPrice
        ? 'md:grid-cols-[2.5rem_1fr_7rem_9rem_9rem_3rem]'
        : 'md:grid-cols-[2.5rem_1fr_7rem_3rem]'
)
</script>

<template>
    <section class="card overflow-hidden">
        <!-- Column headings, on a wide screen only: each line becomes its own
             little card on a phone, where headings would be noise. -->
        <div
            class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:items-center md:gap-3"
            :class="columns"
        >
            <span class="text-center">#</span>
            <span>Product</span>
            <span class="text-right">Qty</span>
            <span v-if="showPrice" class="text-right">{{ priceLabel }}</span>
            <span v-if="showPrice" class="text-right">Total</span>
            <span></span>
        </div>

        <p v-if="listError" class="border-b border-line bg-danger-50 px-4 py-2.5 text-xs font-medium text-danger-700">
            {{ listError }}
        </p>

        <div class="divide-y divide-line">
            <div
                v-for="(line, index) in modelValue"
                :key="index"
                class="grid gap-3 p-4 md:items-start md:gap-3"
                :class="columns"
            >
                <span class="hidden pt-3 text-center text-xs text-ink-subtle md:block">
                    {{ index + 1 }}
                </span>

                <div class="min-w-0">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        Product
                    </span>
                    <SelectField
                        :model-value="line.product_id"
                        :options="products"
                        :invalid="!!errorFor(index, 'product_id')"
                        :disabled="disabled"
                        placeholder="Pick a product"
                        @update:model-value="pickProduct(index, $event)"
                    />
                    <p v-if="errorFor(index, 'product_id')" class="mt-1.5 text-xs font-medium text-danger-700">
                        {{ errorFor(index, 'product_id') }}
                    </p>
                    <p
                        v-else-if="showStock && stockAfter(line) !== null"
                        class="mt-1.5 text-xs"
                        :class="stockAfter(line) < 0 ? 'font-medium text-danger-700' : 'text-ink-subtle'"
                    >
                        <template v-if="stockAfter(line) < 0">
                            Short by {{ count(Math.abs(stockAfter(line))) }} — not enough on hand.
                        </template>
                        <template v-else>
                            {{ count(stockAfter(line)) }} left after this {{ documentNoun }}.
                        </template>
                    </p>
                </div>

                <div>
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        Qty
                    </span>
                    <div class="flex flex-col gap-2">
                        <TextField
                            :model-value="line.quantity"
                            type="number"
                            :min="stepFor(line)"
                            :step="stepFor(line)"
                            :invalid="!!errorFor(index, 'quantity')"
                            @update:model-value="update(index, 'quantity', $event)"
                        />
                        <!-- Bought or sold by the box as well: pick which. -->
                        <SelectField
                            v-if="unitsOf(line).length > 1"
                            :model-value="unitOf(line)?.value ?? ''"
                            :options="unitsOf(line)"
                            :invalid="!!errorFor(index, 'unit_id')"
                            :disabled="disabled"
                            :aria-label="`Unit for line ${index + 1}`"
                            @update:model-value="pickUnit(index, $event)"
                        />
                    </div>
                    <p v-if="errorFor(index, 'quantity')" class="mt-1.5 text-xs font-medium text-danger-700">
                        {{ errorFor(index, 'quantity') }}
                    </p>
                    <p v-else-if="errorFor(index, 'unit_id')" class="mt-1.5 text-xs font-medium text-danger-700">
                        {{ errorFor(index, 'unit_id') }}
                    </p>
                    <p v-else-if="inOwnUnit(line)" class="mt-1.5 text-xs text-ink-subtle">
                        {{ inOwnUnit(line) }}
                    </p>
                </div>

                <div v-if="showPrice">
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        {{ priceLabel }}
                    </span>
                    <TextField
                        :model-value="line[priceField]"
                        type="number"
                        min="0"
                        step="0.01"
                        :invalid="!!errorFor(index, priceField)"
                        @update:model-value="update(index, priceField, $event)"
                    />
                    <p v-if="errorFor(index, priceField)" class="mt-1.5 text-xs font-medium text-danger-700">
                        {{ errorFor(index, priceField) }}
                    </p>
                    <p v-else-if="belowCost(line)" class="mt-1.5 text-xs font-medium text-warning-700">
                        Below cost.
                    </p>
                </div>

                <div v-if="showPrice" class="flex items-center justify-between md:h-11 md:justify-end">
                    <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        Total
                    </span>
                    <span class="tabular-nums text-sm font-semibold text-ink">
                        {{ money(lineTotal(line)) }}
                    </span>
                </div>

                <div class="flex md:h-11 md:items-center md:justify-center">
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-field px-2.5 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50 disabled:cursor-not-allowed disabled:opacity-40 md:px-0"
                        :disabled="disabled || modelValue.length === 1"
                        :title="modelValue.length === 1 ? 'A document needs at least one line' : 'Remove this line'"
                        @click="removeLine(index)"
                    >
                        <Trash2 :size="16" />
                        <span class="md:hidden">Remove</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add, and the running total -->
        <div
            class="flex flex-col gap-3 border-t border-line bg-surface-muted px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <button
                type="button"
                class="inline-flex h-9 w-fit items-center gap-1.5 rounded-field px-3 text-sm font-medium text-brand-700 transition-colors hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="disabled"
                @click="addLine"
            >
                <Plus :size="16" />
                Add item
            </button>

            <div class="flex items-baseline gap-4">
                <span class="text-xs text-ink-subtle">
                    {{ modelValue.length }} line{{ modelValue.length === 1 ? '' : 's' }},
                    {{ count(totalQuantity) }} unit{{ totalQuantity === 1 ? '' : 's' }}
                </span>
                <template v-if="showPrice">
                    <template v-if="Number(vatRate) > 0">
                        <span class="text-xs text-ink-muted">Net of VAT</span>
                        <span class="tabular-nums text-sm text-ink">{{ money(vat.net) }}</span>
                        <span class="text-xs text-ink-muted">VAT ({{ Number(vatRate) }}%)</span>
                        <span class="tabular-nums text-sm text-ink">{{ money(vat.vat) }}</span>
                    </template>
                    <span class="text-xs text-ink-muted">Total</span>
                    <span class="tabular-nums text-lg font-bold text-ink">{{ money(total) }}</span>
                </template>
            </div>
        </div>
    </section>
</template>
