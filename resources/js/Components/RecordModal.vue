<script setup>
import { ref, watch } from 'vue'
import { TriangleAlert } from '@lucide/vue'
import Modal from './Modal.vue'

/**
 * A dialog that reads one record without leaving the list.
 *
 * The master dialogs fill themselves from the row already on screen, which
 * costs no request at all. A document cannot: its lines are not on the row,
 * and putting forty documents' worth of lines on a list page is exactly the
 * payload `PerformanceTest` exists to prevent. So this one fetches, and
 * shows a skeleton while it waits — the same bargain the dashboard makes.
 *
 * What it fetches is the controller's own `detail()` payload, the same one
 * the full page renders, so the dialog and the page cannot disagree.
 *
 * The record is handed to the caller through a scoped slot:
 *
 *     <RecordModal :open="open" :url="url" @close="open = false" v-slot="{ data }">
 *         <p>{{ data.purchase.reference }}</p>
 *     </RecordModal>
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /** Where to read the record from. Null while nothing is chosen. */
    url: { type: String, default: null },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    size: { type: String, default: 'lg' },
})

defineEmits(['close'])

const data = ref(null)
const loading = ref(false)
const error = ref('')

/**
 * Read the record.
 *
 * Each open gets its own token. A slow first request landing after a second
 * one would otherwise overwrite the newer record with the older one.
 */
let token = 0

async function load(url) {
    const mine = ++token

    data.value = null
    error.value = ''
    loading.value = true

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })

        if (mine !== token) return

        if (!response.ok) {
            error.value = response.status === 403
                ? 'You may not read this record.'
                : 'That record could not be read.'

            return
        }

        data.value = await response.json()
    } catch {
        if (mine === token) error.value = 'The record could not be reached. Check your connection.'
    } finally {
        if (mine === token) loading.value = false
    }
}

watch(
    () => [props.open, props.url],
    ([open, url]) => {
        if (open && url) load(url)
    },
    { immediate: true }
)
</script>

<template>
    <Modal :open="open" :title="title" :subtitle="subtitle" :size="size" @close="$emit('close')">
        <!-- Waiting. The shape stands in for the record so the dialog does
             not jump when the real thing arrives. -->
        <div v-if="loading" class="space-y-4" aria-busy="true">
            <div class="h-4 w-1/3 animate-pulse rounded bg-surface-sunken"></div>
            <div class="space-y-2">
                <div v-for="n in 4" :key="n" class="h-10 animate-pulse rounded-field bg-surface-sunken"></div>
            </div>
            <span class="sr-only">Reading the record…</span>
        </div>

        <div
            v-else-if="error"
            class="flex items-start gap-2.5 rounded-field bg-danger-50 p-4 text-sm text-danger-700"
        >
            <TriangleAlert :size="16" class="mt-px shrink-0" />
            <span>{{ error }}</span>
        </div>

        <slot v-else-if="data" :data="data" />
    </Modal>
</template>
