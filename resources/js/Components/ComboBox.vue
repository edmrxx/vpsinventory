<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Check, ChevronDown, Search, X } from '@lucide/vue'
import { flagComboOpen } from '../lib/comboBoxFlag'

/**
 * A dropdown you can type into.
 *
 * Every picker in the app is one of these, so there is one way to choose
 * something from a list and no raw <select> anywhere. See README section 4.
 *
 * Typing narrows the list; it never becomes the value. Clicking away without
 * picking puts the box back to whatever is actually selected.
 *
 * Every list can be typed into, however short. The search was once switched
 * on only past seven options, on the idea that a short list is quicker to
 * read than to type. On real data that was wrong: every list in the system
 * was under the threshold, so the search nobody could see was the search
 * nobody had. It would also have switched itself on as the data grew, and a
 * form that behaves one way this year and another way next year is a form
 * nobody trusts. Do not put the threshold back.
 *
 * The list is teleported to the body and positioned from the input's own
 * rectangle. It has to be: on a purchase the lines sit inside a card that
 * clips its contents, inside a dialog that scrolls, and a list rendered in
 * place would be cut in half.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: '' },
    /**
     * [{ value, label, keywords? }]. `keywords` is searched beside the label
     * but never shown: a product's brand, model and barcode (phase 30), so
     * typing "Boysen" or scanning a label finds it. Anything else on the
     * row is ignored.
     */
    options: { type: Array, default: () => [] },
    /** The first row, which clears the choice. Null means a pick is required. */
    placeholder: { type: String, default: null },
    /** Shown in the box when nothing is chosen and no placeholder row exists. */
    emptyText: { type: String, default: 'Select…' },
    invalid: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** 'md' for a form, 'sm' for a filter bar. */
    size: { type: String, default: 'md' },
    /** How many matches to draw at once. */
    limit: { type: Number, default: 50 },
})

const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const input = ref(null)
const list = ref(null)

const open = ref(false)
const query = ref('')
const highlighted = ref(0)
const position = ref({ top: 0, bottom: 0, left: 0, width: 0, openUp: false })

/**
 * Set for a moment after a choice is made.
 *
 * The focus is handed back to the box so the keyboard keeps working, and
 * focus is what opens the list — without this, picking something would shut
 * the list and open it again at once.
 */
let justChose = false

const uid = Math.random().toString(36).slice(2, 9)
const listId = `combo-list-${uid}`

/**
 * A native select hands back strings, while the server sends numbers. The
 * two have to meet somewhere, so every comparison is made as text.
 */
const same = (a, b) => String(a ?? '') === String(b ?? '')

const selected = computed(() =>
    props.options.find((option) => same(option.value, props.modelValue)) ?? null
)

/** What the box shows when it is not being typed into. */
const selectedLabel = computed(() => {
    if (selected.value) return selected.value.label

    return props.modelValue === '' || props.modelValue === null
        ? (props.placeholder ?? '')
        : ''
})

/** The label and any hidden keywords, as one lower-case string to search. */
function searchable(option) {
    const words = option.keywords ? ` ${option.keywords}` : ''

    return `${option.label}${words}`.toLowerCase()
}

/**
 * The row whose barcode is exactly what was typed. A scanner types the code
 * and presses Enter; "123" must pick the product labelled 123, not the first
 * one whose barcode merely contains it.
 */
function scanned() {
    const typed = query.value.trim().toLowerCase()

    if (typed === '') return null

    return props.options.find((option) => option.barcode && String(option.barcode).toLowerCase() === typed) ?? null
}

/** The placeholder row clears the choice, so filters can be emptied. */
const rows = computed(() => {
    const all = props.placeholder !== null
        ? [{ value: '', label: props.placeholder, isPlaceholder: true }, ...props.options]
        : props.options

    const needle = query.value.trim().toLowerCase()

    if (needle === '') return all

    // While a search is being typed the clearing row goes too, so a search
    // that finds nothing says so instead of showing one lonely "All …".
    // Clearing is still a click away on the X.
    return all.filter(
        (option) => ! option.isPlaceholder && searchable(option).includes(needle)
    )
})

const shown = computed(() => rows.value.slice(0, props.limit))
const hiddenCount = computed(() => Math.max(0, rows.value.length - shown.value.length))

const heights = { md: 'h-11 text-sm px-3.5', sm: 'h-9 text-sm px-2' }
const height = computed(() => heights[props.size] ?? heights.md)

/* ----------------------------------------------------------- Positioning */

function place() {
    if (!root.value) return

    const box = root.value.getBoundingClientRect()
    const below = window.innerHeight - box.bottom
    const openUp = below < 260 && box.top > below

    position.value = {
        top: box.bottom + 4,
        bottom: window.innerHeight - box.top + 4,
        left: box.left,
        width: box.width,
        openUp,
    }
}

/** The dialog body scrolls; one measure a frame is enough to follow it. */
let frame = null

function onScroll() {
    if (frame !== null) return

    frame = requestAnimationFrame(() => {
        frame = null
        place()
    })
}

/* --------------------------------------------------------------- Opening */

async function openList() {
    if (props.disabled || open.value || justChose) return

    open.value = true
    query.value = ''
    highlighted.value = Math.max(0, rows.value.findIndex((o) => same(o.value, props.modelValue)))

    place()
    await nextTick()
    scrollHighlightedIntoView()
}

function closeList() {
    open.value = false
    query.value = ''
}

function toggle() {
    open.value ? closeList() : openList()
}

function choose(option) {
    if (!option) return

    emit('update:modelValue', option.isPlaceholder ? '' : option.value)
    closeList()

    justChose = true
    input.value?.focus()
    justChose = false
}

/* -------------------------------------------------------------- Keyboard */

function move(step) {
    if (!open.value) {
        openList()

        return
    }

    const count = shown.value.length

    if (count === 0) return

    highlighted.value = (highlighted.value + step + count) % count
    scrollHighlightedIntoView()
}

function scrollHighlightedIntoView() {
    const el = list.value?.querySelector(`[data-index="${highlighted.value}"]`)
    el?.scrollIntoView({ block: 'nearest' })
}

function onKeydown(event) {
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            move(1)
            break
        case 'ArrowUp':
            event.preventDefault()
            move(-1)
            break
        case 'Home':
            if (open.value) {
                event.preventDefault()
                highlighted.value = 0
                scrollHighlightedIntoView()
            }
            break
        case 'End':
            if (open.value) {
                event.preventDefault()
                highlighted.value = shown.value.length - 1
                scrollHighlightedIntoView()
            }
            break
        case 'Enter':
            if (open.value) {
                // The purchase form submits on Enter, so the key must not
                // reach it while a list is open.
                event.preventDefault()
                event.stopPropagation()
                choose((highlighted.value === 0 && scanned()) || shown.value[highlighted.value])
            }
            break
        case 'Escape':
            if (open.value) {
                // Stopped here, or the dialog behind would close as well and
                // take the half-filled form with it.
                event.preventDefault()
                event.stopPropagation()
                closeList()
            }
            break
        case 'Tab':
            closeList()
            break
        default:
            break
    }
}

/* ----------------------------------------------------------------- Input */

function onInput(event) {
    query.value = event.target.value
    highlighted.value = 0

    if (!open.value) {
        open.value = true
        place()
    }
}

/**
 * Typed text is a search, never a value. Leaving the box without picking
 * puts back whatever is really selected.
 */
function onBlur(event) {
    if (list.value?.contains(event.relatedTarget)) return

    closeList()
}

function clear(event) {
    event.stopPropagation()
    emit('update:modelValue', '')
    closeList()
}

/* ------------------------------------------------------------ Lifecycle */

function onDocumentPointerDown(event) {
    if (root.value?.contains(event.target) || list.value?.contains(event.target)) return

    closeList()
}

watch(open, (isOpen) => {
    if (typeof document === 'undefined') return

    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown, true)
        // The box moves with the page, so the list has to follow it.
        window.addEventListener('scroll', onScroll, true)
        window.addEventListener('resize', place)

        flagComboOpen(true)

        return
    }

    document.removeEventListener('pointerdown', onDocumentPointerDown, true)
    window.removeEventListener('scroll', onScroll, true)
    window.removeEventListener('resize', place)
    flagComboOpen(false)
})

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return

    document.removeEventListener('pointerdown', onDocumentPointerDown, true)
    window.removeEventListener('scroll', onScroll, true)
    window.removeEventListener('resize', place)

    if (open.value) flagComboOpen(false)
})

defineOptions({ inheritAttrs: false })
</script>

<template>
    <div ref="root" class="relative">
        <div
            class="flex w-full items-center rounded-field border bg-surface shadow-xs-soft transition-colors focus-within:border-brand-400"
            :class="[
                height,
                invalid ? 'border-danger-500/60' : 'border-line-strong',
                disabled ? 'cursor-not-allowed bg-surface-sunken' : '',
                size === 'sm' ? 'border-line bg-surface-muted' : '',
            ]"
            @click="!disabled && (open ? input?.focus() : openList())"
        >
            <Search v-if="open" :size="15" class="mr-2 shrink-0 text-ink-subtle" />

            <input
                ref="input"
                v-bind="$attrs"
                type="text"
                role="combobox"
                autocomplete="off"
                spellcheck="false"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-activedescendant="open ? `${listId}-${highlighted}` : undefined"
                aria-haspopup="listbox"
                :disabled="disabled"
                :value="open ? query : selectedLabel"
                :placeholder="open ? 'Type to search…' : (selectedLabel || emptyText)"
                class="min-w-0 flex-1 bg-transparent text-ink outline-none placeholder:text-ink-subtle disabled:cursor-not-allowed"
                :class="!selected && !open ? 'text-ink-subtle' : ''"
                @focus="openList"
                @input="onInput"
                @blur="onBlur"
                @keydown="onKeydown"
            />

            <button
                v-if="selected && placeholder !== null && !disabled"
                type="button"
                class="mr-0.5 grid size-5 shrink-0 place-items-center rounded text-ink-subtle transition-colors hover:bg-surface-sunken hover:text-ink"
                aria-label="Clear"
                tabindex="-1"
                @click="clear"
            >
                <X :size="13" />
            </button>

            <ChevronDown
                :size="16"
                class="ml-1 shrink-0 text-ink-subtle transition-transform"
                :class="open ? 'rotate-180' : ''"
                @click.stop="toggle"
            />
        </div>

        <!-- Teleported, so a clipping card or a scrolling dialog cannot cut
             the list in half. Above the Modal (1000), below Swal (100000). -->
        <Teleport to="body">
            <div
                v-if="open"
                :id="listId"
                ref="list"
                role="listbox"
                class="fixed z-5000 overflow-y-auto rounded-field border border-line bg-surface py-1 shadow-popover"
                :style="{
                    top: position.openUp ? 'auto' : `${position.top}px`,
                    bottom: position.openUp ? `${position.bottom}px` : 'auto',
                    left: `${position.left}px`,
                    width: `${position.width}px`,
                    maxHeight: 'min(18rem, 50vh)',
                }"
            >
                <p v-if="shown.length === 0" class="px-3 py-2.5 text-sm text-ink-subtle">
                    Nothing matches “{{ query }}”.
                </p>

                <button
                    v-for="(option, index) in shown"
                    :id="`${listId}-${index}`"
                    :key="`${option.value}-${index}`"
                    :data-index="index"
                    type="button"
                    role="option"
                    :aria-selected="same(option.value, modelValue)"
                    class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm transition-colors sm:py-2"
                    :class="[
                        index === highlighted ? 'bg-brand-50 text-brand-700' : 'text-ink',
                        option.isPlaceholder ? 'text-ink-subtle' : '',
                    ]"
                    @mousedown.prevent
                    @mouseenter="highlighted = index"
                    @click="choose(option)"
                >
                    <span class="min-w-0 truncate">{{ option.label }}</span>
                    <Check
                        v-if="same(option.value, modelValue) && !option.isPlaceholder"
                        :size="15"
                        class="shrink-0 text-brand-600"
                    />
                </button>

                <p
                    v-if="hiddenCount > 0"
                    class="border-t border-line px-3 py-2 text-2xs text-ink-subtle"
                >
                    {{ hiddenCount }} more — type to narrow the list.
                </p>
            </div>
        </Teleport>
    </div>
</template>
