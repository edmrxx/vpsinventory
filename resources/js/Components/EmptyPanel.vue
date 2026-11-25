<script setup>
import { Inbox } from '@lucide/vue'

/**
 * A panel with nothing to show.
 *
 * Said in words rather than left as an empty box, because "no returns this
 * month" is an answer and a blank card is not.
 */
defineProps({
    heading: { type: String, required: true },
    text: { type: String, default: 'Nothing to show for this period.' },
})
</script>

<template>
    <section class="card p-5">
        <h3 class="text-sm font-semibold text-ink">{{ heading }}</h3>

        <div class="mt-6 flex flex-col items-center justify-center py-10 text-center">
            <span class="grid size-11 place-items-center rounded-full bg-surface-sunken text-ink-subtle">
                <Inbox :size="20" />
            </span>
            <p class="mt-3 max-w-xs text-sm text-ink-subtle">{{ text }}</p>
        </div>
    </section>
</template>
