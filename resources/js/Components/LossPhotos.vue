<script setup>
import { ref } from 'vue'
import { X } from '@lucide/vue'

/**
 * The evidence on a loss report (phase 33), as thumbnails that open large.
 * Each address is the report's own photo route, so only somebody who may
 * read the report ever sees one.
 */
defineProps({
    photos: { type: Array, default: () => [] },
})

const large = ref(null)
</script>

<template>
    <div>
        <div v-if="photos.length" class="grid grid-cols-3 gap-3 sm:grid-cols-5">
            <button
                v-for="photo in photos"
                :key="photo.id"
                type="button"
                class="overflow-hidden rounded-field border border-line transition-opacity hover:opacity-90"
                :title="photo.name ?? 'Photo'"
                @click="large = photo"
            >
                <img :src="photo.url" :alt="photo.name ?? 'Evidence photo'" loading="lazy" class="aspect-square w-full object-cover" />
            </button>
        </div>
        <p v-else class="text-xs text-ink-muted">No photo was added.</p>

        <Teleport to="body">
            <div
                v-if="large"
                class="fixed inset-0 z-50 grid place-items-center bg-ink/80 p-4"
                role="dialog"
                aria-modal="true"
                @click.self="large = null"
                @keydown.esc="large = null"
            >
                <button
                    type="button"
                    class="absolute top-4 right-4 grid size-9 place-items-center rounded-full bg-surface text-ink shadow"
                    aria-label="Close photo"
                    @click="large = null"
                >
                    <X :size="18" />
                </button>
                <img :src="large.url" :alt="large.name ?? 'Evidence photo'" class="max-h-[85vh] max-w-full rounded-field shadow-lg" />
            </div>
        </Teleport>
    </div>
</template>
