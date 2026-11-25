<script setup>
import { computed, ref } from 'vue'
import { Eye, EyeOff } from '@lucide/vue'

/**
 * One field on a sign-in page: the label, the input, and the message under
 * it when the server refused it. Used by the login and the kiosk screens.
 *
 * `icon` is a Lucide icon drawn inside the box on the left (a person for the
 * username, a lock for the password). With `type="password"` an eye on the
 * right turns the input into plain text and back; its label says which.
 *
 * Every other attribute (name, autocomplete, placeholder, autofocus,
 * required ...) goes onto the input itself, not the wrapper.
 *
 * Which message belongs under which field is the page's decision: it passes
 * the one for this field as `error`. `describedBy` names a message elsewhere
 * on the page that also describes this field, read when it has none of its
 * own.
 */
defineOptions({ inheritAttrs: false })

const model = defineModel({ type: String, default: '' })

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    icon: { type: [Object, Function], default: null },
    // The icon's size: the person is drawn a little larger than the lock.
    iconClass: { type: String, default: 'size-[calc(30*var(--u))]' },
    error: { type: String, default: null },
    describedBy: { type: String, default: null },
})

const shown = ref(false)

const isPassword = computed(() => props.type === 'password')
const inputType = computed(() => (isPassword.value && shown.value ? 'text' : props.type))

// Hidden characters are spaced out a little, as the mockup draws them.
const masked = computed(() => isPassword.value && !shown.value && model.value !== '')

const errorId = computed(() => `${props.id}-error`)
const describedBy = computed(() => (props.error ? errorId.value : props.describedBy) || undefined)

/*
 * The box: red when refused, and while anything in it has focus a green
 * border with a yellow glow. Written out in full, because Tailwind never
 * generates a class put together at run time.
 */
const box = computed(() => [
    'flex h-[calc(52*var(--u))] pointer-coarse:min-h-12 w-full items-center rounded-[calc(10*var(--u))] border bg-white transition-[border-color,box-shadow] focus-within:border-goe-green focus-within:ring-4 focus-within:ring-goe-focus motion-reduce:transition-none',
    props.error ? 'border-goe-error' : 'border-goe-line',
    isPassword.value ? 'pr-[calc(7.5*var(--u))]' : 'pr-[calc(16*var(--u))]',
])
</script>

<template>
    <div>
        <label :for="id" class="mb-[calc(9.5*var(--u))] block text-[length:calc(19*var(--u))] leading-none font-bold text-goe-ink">
            {{ label }}
        </label>

        <div :class="box">
            <span v-if="icon" class="flex w-[calc(66*var(--u))] shrink-0 justify-center pl-px text-goe-muted">
                <component :is="icon" :class="iconClass" :stroke-width="1.75" absolute-stroke-width aria-hidden="true" />
            </span>
            <input
                v-bind="$attrs"
                :id="id"
                v-model="model"
                :type="inputType"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="h-full min-w-0 flex-1 border-0 bg-transparent text-[length:calc(19*var(--u))] text-goe-ink outline-none placeholder:text-goe-placeholder"
                :class="[icon ? 'pl-0' : 'pl-[calc(16*var(--u))]', masked ? 'pb-[calc(4*var(--u))] tracking-[calc(4.3*var(--u))]' : '']"
            />
            <button
                v-if="isPassword"
                type="button"
                class="flex size-[calc(48*var(--u))] pointer-coarse:size-12 shrink-0 cursor-pointer items-center justify-center rounded-[calc(8*var(--u))] border-0 bg-transparent text-goe-muted transition-colors hover:text-goe-ink focus-visible:outline-3 focus-visible:outline-offset-0 focus-visible:outline-goe-yellow"
                :aria-label="shown ? 'Hide password' : 'Show password'"
                @click="shown = !shown"
            >
                <component :is="shown ? EyeOff : Eye" class="size-[calc(30*var(--u))]" :stroke-width="1.75" absolute-stroke-width aria-hidden="true" />
            </button>
        </div>

        <p v-if="error" :id="errorId" role="alert" class="mt-[calc(6*var(--u))] text-[length:calc(14*var(--u))] text-goe-error">
            {{ error }}
        </p>
    </div>
</template>
