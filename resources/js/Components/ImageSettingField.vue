<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { ImageUp, LoaderCircle, Trash2, Upload } from '@lucide/vue'
import { confirmDelete } from '../lib/swal'

/**
 * A picture held by a setting, such as the company logo.
 *
 * Unlike every other settings field it saves at once: picking a file
 * uploads it, and Remove asks first and then removes it. The route comes
 * from the server as `field.upload_url`, the same address for both.
 *
 * What it shows is the server's value, not the settings form's. The form
 * still holds the value from when the page opened, and is never sent for
 * this field (see SettingsSchema::isImage()), so reading it here would show
 * the old logo after an upload.
 */
const props = defineProps({
    field: { type: Object, required: true },
    /** Given to the Upload button, so the field's label points at it. */
    id: { type: String, default: null },
    disabled: { type: Boolean, default: false },
})

const ACCEPT = 'image/jpeg,image/png,image/webp'
const MAX_BYTES = 2 * 1024 * 1024

const page = usePage()
const current = computed(() => page.props.values?.[props.field.name] || '')

const fileInput = ref(null)
const busy = ref(false)
const error = ref(null)

const label = computed(() => props.field.label.toLowerCase())

// Every other field on the page keeps what was typed but not yet saved.
const visit = {
    preserveScroll: true,
    preserveState: true,
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
}

function choose() {
    error.value = null
    fileInput.value?.click()
}

function upload(event) {
    const file = event.target.files?.[0] ?? null

    // Cleared at once, so picking the same file again still uploads it.
    event.target.value = ''

    if (!file) return

    // Checked here as well, so a large file is refused before it is sent.
    // The server checks again; this only saves the wait.
    if (file.size > MAX_BYTES) {
        error.value = 'The picture must be 2 MB or smaller.'
        return
    }

    router.post(
        props.field.upload_url,
        { image: file },
        {
            ...visit,
            forceFormData: true,
            onError: (errors) => {
                error.value = errors.image ?? Object.values(errors)[0] ?? 'The upload failed.'
            },
        }
    )
}

async function remove() {
    const agreed = await confirmDelete({
        title: `Remove the ${label.value}?`,
        text: 'The initials show in its place until a new one is uploaded.',
        confirmText: 'Yes, remove it',
    })

    if (!agreed) return

    error.value = null
    router.delete(props.field.upload_url, visit)
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-4">
            <div
                class="grid size-24 shrink-0 place-items-center overflow-hidden rounded-field border border-line bg-surface p-2"
            >
                <img
                    v-if="current"
                    :src="current"
                    :alt="`Current ${label}`"
                    class="max-h-full max-w-full object-contain"
                />
                <ImageUp v-else :size="24" class="text-ink-subtle" aria-hidden="true" />
            </div>

            <div v-if="!disabled" class="flex flex-wrap items-center gap-2">
                <input
                    ref="fileInput"
                    type="file"
                    :accept="ACCEPT"
                    class="hidden"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="upload"
                />

                <button
                    :id="id"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-field border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink shadow-xs-soft transition-colors hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    @click="choose"
                >
                    <LoaderCircle
                        v-if="busy"
                        :size="15"
                        class="animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    <Upload v-else :size="15" aria-hidden="true" />
                    {{ busy ? 'Saving…' : current ? `Upload a new ${label}` : `Upload ${label}` }}
                </button>

                <button
                    v-if="current"
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-field px-3 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    @click="remove"
                >
                    <Trash2 :size="15" aria-hidden="true" />
                    Remove
                </button>
            </div>
        </div>

        <p v-if="error" class="mt-2 text-xs font-medium text-danger-700" role="alert">
            {{ error }}
        </p>
    </div>
</template>
