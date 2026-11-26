<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight } from '@lucide/vue'
import Modal from './Modal.vue'

/**
 * Pick a day, on a tablet.
 *
 * A month at a time, with cells big enough for a thumb. It replaced a
 * transparent `<input type="date">` laid over the label — an old trick
 * that is not reliable on a tablet: in Safari and some Android browsers
 * the tap simply does nothing, and nothing says why.
 *
 * A day that has not happened cannot be chosen. Days that saw a sale are
 * marked, which answers the question somebody asks as soon as they open a
 * calendar: when were we actually open?
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** The day being looked at, as YYYY-MM-DD. */
    modelValue: { type: String, required: true },
    /** The latest day that may be chosen, usually today. */
    max: { type: String, required: true },
    /** Days in the shown month that have a sale on them. */
    traded: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'close', 'month'])

/** Which month the grid is showing. Follows the chosen day when reopened. */
const cursor = ref(props.modelValue.slice(0, 7))

watch(
    () => [props.open, props.modelValue],
    ([open]) => {
        if (open) cursor.value = props.modelValue.slice(0, 7)
    }
)

const weekdays = ['M', 'T', 'W', 'T', 'F', 'S', 'S']

function parse(value) {
    const [year, month, day] = value.split('-').map(Number)

    return new Date(year, month - 1, day ?? 1)
}

function stamp(date) {
    const month = `${date.getMonth() + 1}`.padStart(2, '0')
    const day = `${date.getDate()}`.padStart(2, '0')

    return `${date.getFullYear()}-${month}-${day}`
}

const monthLabel = computed(() =>
    parse(cursor.value).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
)

/**
 * The cells of the grid.
 *
 * Starts on a Monday, so the leading blanks are however many days the
 * first of the month sits past it.
 */
const cells = computed(() => {
    const first = parse(cursor.value)
    const year = first.getFullYear()
    const month = first.getMonth()

    // getDay() calls Sunday 0; this week begins on Monday.
    const blanks = (first.getDay() + 6) % 7
    const days = new Date(year, month + 1, 0).getDate()

    const out = Array.from({ length: blanks }, () => null)

    for (let day = 1; day <= days; day++) {
        const date = stamp(new Date(year, month, day))

        out.push({
            day,
            date,
            future: date > props.max,
            chosen: date === props.modelValue,
            traded: props.traded.includes(date),
        })
    }

    return out
})

/** The next month is out of reach once it is entirely in the future. */
const canGoForward = computed(() => `${cursor.value}-01` < props.max.slice(0, 7) + '-01')

function shiftMonth(by) {
    const date = parse(cursor.value)

    date.setMonth(date.getMonth() + by)

    if (by > 0 && !canGoForward.value) return

    cursor.value = stamp(date).slice(0, 7)

    // The marks belong to the month on screen, so they are fetched again.
    emit('month', `${cursor.value}-01`)
}

function choose(cell) {
    if (!cell || cell.future) return

    emit('update:modelValue', cell.date)
    emit('close')
}

function quick(offset) {
    const date = parse(props.max)

    date.setDate(date.getDate() + offset)

    emit('update:modelValue', stamp(date))
    emit('close')
}
</script>

<template>
    <Modal :open="open" title="Pick a day" size="sm" @close="$emit('close')">
        <div class="space-y-4">
            <!-- The two anybody actually wants -->
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="h-11 rounded-field bg-surface-sunken text-sm font-semibold text-ink transition hover:bg-brand-50 hover:text-brand-700"
                    @click="quick(0)"
                >
                    Today
                </button>
                <button
                    type="button"
                    class="h-11 rounded-field bg-surface-sunken text-sm font-semibold text-ink transition hover:bg-brand-50 hover:text-brand-700"
                    @click="quick(-1)"
                >
                    Yesterday
                </button>
            </div>

            <div class="flex items-center justify-between gap-2">
                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-field text-ink-muted transition hover:bg-surface-sunken hover:text-ink"
                    aria-label="The month before"
                    @click="shiftMonth(-1)"
                >
                    <ChevronLeft :size="18" />
                </button>

                <p class="text-sm font-semibold text-ink">{{ monthLabel }}</p>

                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-field text-ink-muted transition hover:bg-surface-sunken hover:text-ink disabled:opacity-30"
                    :disabled="!canGoForward"
                    aria-label="The month after"
                    @click="shiftMonth(1)"
                >
                    <ChevronRight :size="18" />
                </button>
            </div>

            <div class="grid grid-cols-7 gap-1 text-center">
                <span
                    v-for="(name, index) in weekdays"
                    :key="index"
                    class="py-1 text-2xs font-semibold tracking-wider text-ink-subtle uppercase"
                >
                    {{ name }}
                </span>

                <template v-for="(cell, index) in cells" :key="index">
                    <span v-if="!cell" />

                    <button
                        v-else
                        type="button"
                        class="relative grid h-11 place-items-center rounded-field text-sm font-medium transition"
                        :class="[
                            cell.chosen
                                ? 'bg-brand-600 font-bold text-ink-inverse'
                                : cell.future
                                  ? 'cursor-not-allowed text-ink-subtle/40'
                                  : 'text-ink hover:bg-surface-sunken',
                        ]"
                        :disabled="cell.future"
                        @click="choose(cell)"
                    >
                        {{ cell.day }}

                        <!-- A day that saw a sale -->
                        <span
                            v-if="cell.traded"
                            class="absolute bottom-1.5 size-1 rounded-full"
                            :class="cell.chosen ? 'bg-white/80' : 'bg-brand-500'"
                        />
                    </button>
                </template>
            </div>

            <p class="flex items-center gap-1.5 text-2xs text-ink-subtle">
                <span class="size-1 rounded-full bg-brand-500" />
                A dot marks a day that had a sale.
            </p>
        </div>
    </Modal>
</template>
