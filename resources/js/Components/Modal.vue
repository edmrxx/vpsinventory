<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { X } from '@lucide/vue'
import { Swal } from '../lib/swal'

/**
 * The dialog the short master forms open in.
 *
 * A master form is small enough that sending the user to another page costs
 * more than it gives. The list stays behind the dialog, with its page, sort
 * and filters untouched.
 *
 * It is a plain layered element, not a native <dialog>. A native one puts
 * itself in the browser's top layer, which would leave every SweetAlert2
 * alert — the validation summary and the unsaved-changes question — stuck
 * behind it and impossible to click.
 */
const props = defineProps({
    /** The parent owns this. The dialog never closes itself. */
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    /** 'sm' | 'md' | 'lg' */
    size: { type: String, default: 'md' },
    /** Clicking the backdrop closes it. Turn off while saving. */
    closeOnBackdrop: { type: Boolean, default: true },
    /** Stops Escape, the backdrop and the X, for example mid-save. */
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const panel = ref(null)

/**
 * Did this click start on the backdrop?
 *
 * Selecting text in the form and releasing the mouse outside it must not
 * throw the form away, so a click only counts when it both began and ended
 * on the backdrop.
 */
let pressedOnBackdrop = false

function onPointerDown(event) {
    pressedOnBackdrop = event.target === event.currentTarget
}

function onBackdropClick(event) {
    if (!props.closeOnBackdrop) return
    if (event.target !== event.currentTarget) return
    if (!pressedOnBackdrop) return

    pressedOnBackdrop = false
    close()
}

/** What had focus before the dialog opened, so it can be given back. */
let previouslyFocused = null

const widths = {
    sm: 'sm:max-w-md',
    md: 'sm:max-w-2xl',
    lg: 'sm:max-w-4xl',
}

const width = computed(() => widths[props.size] ?? widths.md)

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',')

function focusable() {
    if (!panel.value) return []

    return Array.from(panel.value.querySelectorAll(focusableSelector)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement
    )
}

function close() {
    if (props.busy) return

    // The parent decides whether this actually closes: it may want to ask
    // about unsaved typing first.
    emit('close')
}

/**
 * While something inside owns the keyboard, this dialog keeps its hands off.
 *
 * A SweetAlert2 alert does: without this, one Escape would answer the
 * question and shut the form behind it. So does an open ComboBox list, which
 * flags itself on the body — the first Escape closes the list, the second
 * closes the dialog.
 */
function swalHasTheKeyboard() {
    if (document.body.dataset.comboOpen === 'true') return true

    try {
        return Swal.isVisible()
    } catch {
        return false
    }
}

function onKeydown(event) {
    if (!props.open || swalHasTheKeyboard()) return

    if (event.key === 'Escape') {
        event.stopPropagation()
        close()

        return
    }

    if (event.key !== 'Tab') return

    // Keep Tab inside the dialog.
    const items = focusable()

    if (items.length === 0) {
        event.preventDefault()

        return
    }

    const first = items[0]
    const last = items[items.length - 1]
    const active = document.activeElement

    if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
        event.preventDefault()
        last.focus()
    } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
    }
}

watch(
    () => props.open,
    async (isOpen) => {
        if (typeof document === 'undefined') return

        if (isOpen) {
            previouslyFocused = document.activeElement
            // The page behind must not scroll with the dialog.
            document.body.style.overflow = 'hidden'
            document.addEventListener('keydown', onKeydown, true)

            await nextTick()

            // Land on the first box to type in, not on the close button,
            // which happens to come first in the markup.
            const items = focusable()
            const firstInput = items.find((el) =>
                ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)
            )

            ;(firstInput ?? items[0])?.focus()

            return
        }

        document.body.style.overflow = ''
        document.removeEventListener('keydown', onKeydown, true)

        // Give focus back to whatever opened the dialog.
        await nextTick()
        previouslyFocused?.focus?.()
        previouslyFocused = null
    },
    // Run on mount too. A dialog rendered already open would otherwise
    // never attach its key handler, and Escape would do nothing.
    { immediate: true }
)

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return

    document.body.style.overflow = ''
    document.removeEventListener('keydown', onKeydown, true)
})
</script>

<template>
    <Teleport to="body">
        <!-- Below the SweetAlert2 container, which sits at 100000. -->
        <Transition
            enter-active-class="transition-opacity duration-150 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-1000" role="presentation">
                <div class="absolute inset-0 bg-scrim/40 backdrop-blur-[2px]" />

                <div
                    class="absolute inset-0 overflow-y-auto p-0 sm:p-6"
                    @mousedown="onPointerDown"
                    @click="onBackdropClick"
                >
                    <Transition
                        appear
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="translate-y-3 scale-[0.98] opacity-0"
                        leave-active-class="transition duration-150 ease-in"
                        leave-to-class="translate-y-2 scale-[0.99] opacity-0"
                    >
                        <div
                            v-if="open"
                            ref="panel"
                            role="dialog"
                            aria-modal="true"
                            :aria-label="title || undefined"
                            class="relative mx-auto flex h-full w-full flex-col bg-surface shadow-popover sm:h-auto sm:rounded-panel"
                            :class="width"
                        >
                            <!-- Header -->
                            <header
                                class="flex shrink-0 items-start justify-between gap-4 border-b border-line px-5 py-4 sm:px-6"
                            >
                                <div class="min-w-0">
                                    <h2 class="truncate text-base font-semibold text-ink">
                                        {{ title }}
                                    </h2>
                                    <p v-if="subtitle" class="mt-0.5 text-xs text-ink-subtle">
                                        {{ subtitle }}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="-m-1 grid size-8 shrink-0 place-items-center rounded-field text-ink-subtle transition-colors hover:bg-surface-sunken hover:text-ink"
                                    aria-label="Close"
                                    :disabled="busy"
                                    @click="close"
                                >
                                    <X :size="18" />
                                </button>
                            </header>

                            <!-- Body: the long product form scrolls here, not the page -->
                            <div
                                class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:max-h-[calc(100vh-13rem)] sm:px-6"
                            >
                                <slot />
                            </div>

                            <footer
                                v-if="$slots.footer"
                                class="flex shrink-0 items-center justify-end gap-2 border-t border-line bg-surface-muted px-5 py-4 sm:rounded-b-panel sm:px-6"
                            >
                                <slot name="footer" />
                            </footer>
                        </div>
                    </Transition>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
