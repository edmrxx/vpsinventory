<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * The foot of every full-page document (phase 35), from Settings → Print
 * Layouts: the document's terms, when it has any, and the closing line.
 *
 * It renders nothing at all until somebody types them, so every page prints
 * as it did before. The lines saying a page is not an official receipt or an
 * invoice stay in the pages themselves; this never replaces them.
 */
const props = defineProps({
    /** The terms for this kind of document, or empty. */
    terms: { type: String, default: '' },
    termsLabel: { type: String, default: 'Terms' },
})

const page = usePage()
const closing = computed(() => (page.props.printing?.footer_note ?? '').trim())
const terms = computed(() => (props.terms ?? '').trim())
</script>

<template>
    <div v-if="terms || closing" class="mt-8 break-inside-avoid space-y-4">
        <section v-if="terms">
            <p class="text-2xs font-semibold tracking-wider text-ink-muted uppercase">{{ termsLabel }}</p>
            <p class="mt-1 text-xs whitespace-pre-line text-ink">{{ terms }}</p>
        </section>
        <p v-if="closing" class="border-t border-line pt-3 text-center text-2xs whitespace-pre-line text-ink-muted">
            {{ closing }}
        </p>
    </div>
</template>
