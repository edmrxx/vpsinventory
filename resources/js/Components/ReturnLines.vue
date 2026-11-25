<script setup>
import { computed } from 'vue'
import TextField from './TextField.vue'
import { count, lineCentavos, quantityStep, sumQuantities, toMilli, useMoney } from '../lib/money'

/**
 * The lines of a return.
 *
 * Not `LineItems`: nothing is picked here. The lines are the document being
 * returned against, and the only thing to enter is how many of each are
 * coming back. Each box is capped at what is still returnable, which the
 * server works out and sends.
 */
const props = defineProps({
    /** [{ sale_line_id | purchase_line_id, quantity }] */
    modelValue: { type: Array, required: true },
    /** The lines of the document, with `returnable` and `decimals` on each. */
    lines: { type: Array, default: () => [] },
    /** Which key on a line holds the price to show. */
    priceField: { type: String, default: 'unit_price' },
    priceLabel: { type: String, default: 'Price' },
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const { money } = useMoney()

function quantityAt(index) {
    return Number(props.modelValue[index]?.quantity ?? 0)
}

function set(index, value) {
    const line = props.lines[index]
    // Thousandths: 2.5 m stays 2.5 m where the unit is sold by measure,
    // and anything else is cut to whole units, as before.
    const typed = Math.max(0, toMilli(value))
    const asked = line?.decimals ? typed : Math.floor(typed / 1000) * 1000
    // Capped here as well as on the server, so the box cannot even be typed
    // past what is left.
    const capped = Math.min(asked, toMilli(line?.returnable ?? 0)) / 1000

    emit('update:modelValue', props.modelValue.map((line, i) =>
        i === index ? { ...line, quantity: capped } : line
    ))
}

function all(index) {
    set(index, props.lines[index]?.returnable ?? 0)
}

function errorFor(index) {
    return props.errors[`lines.${index}.quantity`]
}

const totalComing = computed(() => sumQuantities(props.modelValue.map((line) => line.quantity)))

const refund = computed(() =>
    props.modelValue.reduce(
        (sum, line, index) => sum + lineCentavos(props.lines[index]?.[props.priceField], line.quantity),
        0
    ) / 100
)

const listError = computed(() => props.errors.lines)
</script>

<template>
    <section class="card overflow-hidden">
        <div
            class="hidden border-b border-line bg-surface-muted px-4 py-2.5 text-2xs font-semibold tracking-wider text-ink-muted uppercase md:grid md:grid-cols-[1fr_6rem_6rem_7rem_9rem] md:items-center md:gap-3"
        >
            <span>Product</span>
            <span class="text-right">On the document</span>
            <span class="text-right">Already back</span>
            <span class="text-right">{{ priceLabel }}</span>
            <span class="text-right">Coming back</span>
        </div>

        <p v-if="listError" class="border-b border-line bg-danger-50 px-4 py-2.5 text-xs font-medium text-danger-700">
            {{ listError }}
        </p>

        <div class="divide-y divide-line">
            <div
                v-for="(line, index) in lines"
                :key="line.id"
                class="grid gap-3 p-4 md:grid-cols-[1fr_6rem_6rem_7rem_9rem] md:items-start md:gap-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-ink">{{ line.name }}</p>
                    <p class="font-mono text-2xs text-ink-subtle">{{ line.sku }}</p>
                    <!-- Sold or bought by the box, and comes back by the piece. -->
                    <p v-if="line.typed_as" class="mt-0.5 text-2xs text-ink-subtle">
                        Entered as {{ line.typed_as }}. Returned in {{ line.unit }}.
                    </p>
                </div>

                <div class="flex items-center justify-between md:block md:h-11 md:pt-2.5 md:text-right">
                    <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        On the document
                    </span>
                    <span class="tabular-nums text-sm text-ink-muted">{{ count(line.quantity) }}</span>
                </div>

                <div class="flex items-center justify-between md:block md:h-11 md:pt-2.5 md:text-right">
                    <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        Already back
                    </span>
                    <span class="tabular-nums text-sm text-ink-muted">
                        {{ count(line.returned_quantity) }}
                    </span>
                </div>

                <div class="flex items-center justify-between md:block md:h-11 md:pt-2.5 md:text-right">
                    <span class="text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        {{ priceLabel }}
                    </span>
                    <span class="tabular-nums text-sm text-ink-muted">
                        {{ money(line[priceField]) }}
                    </span>
                </div>

                <div>
                    <span class="mb-1.5 block text-2xs font-semibold tracking-wider text-ink-muted uppercase md:hidden">
                        Coming back
                    </span>

                    <div v-if="line.returnable > 0" class="flex items-center gap-1.5">
                        <TextField
                            :model-value="quantityAt(index)"
                            type="number"
                            min="0"
                            :step="quantityStep(line.decimals)"
                            :max="line.returnable"
                            :invalid="!!errorFor(index)"
                            :disabled="disabled"
                            @update:model-value="set(index, $event)"
                        />
                        <button
                            type="button"
                            class="h-11 shrink-0 rounded-field px-2.5 text-xs font-medium text-brand-700 transition-colors hover:bg-brand-50"
                            :disabled="disabled"
                            title="Return everything that is left"
                            @click="all(index)"
                        >
                            All
                        </button>
                    </div>

                    <p v-else class="text-xs text-ink-subtle md:pt-3 md:text-right">
                        Nothing left to return
                    </p>

                    <p v-if="errorFor(index)" class="mt-1.5 text-xs font-medium text-danger-700">
                        {{ errorFor(index) }}
                    </p>
                    <p v-else-if="line.returnable > 0" class="mt-1.5 text-xs text-ink-subtle">
                        Up to {{ count(line.returnable) }}
                    </p>
                </div>
            </div>
        </div>

        <div
            class="flex flex-col gap-2 border-t border-line bg-surface-muted px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="text-xs text-ink-subtle">
                {{ count(totalComing) }} unit{{ totalComing === 1 ? '' : 's' }} coming back
            </span>
            <span class="flex items-baseline gap-3">
                <span class="text-xs text-ink-muted">Value</span>
                <span class="tabular-nums text-lg font-bold text-ink">{{ money(refund) }}</span>
            </span>
        </div>
    </section>
</template>
