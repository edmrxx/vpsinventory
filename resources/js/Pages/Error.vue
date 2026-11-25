<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Ban, ServerCrash, SearchX, Wrench } from '@lucide/vue'
import AppLayout from '../Layouts/AppLayout.vue'
import GuestLayout from '../Layouts/GuestLayout.vue'
import PrimaryButton from '../Components/PrimaryButton.vue'

const props = defineProps({
    status: { type: Number, required: true },
})

const page = usePage()

// Signed in, so keep the shell. Signed out, so use the plain panel.
defineOptions({
    layout: (h, child) =>
        h(child.props?.auth?.user ? AppLayout : GuestLayout, { title: 'Error' }, () => child),
})

const copy = {
    403: {
        icon: Ban,
        title: 'You may not open this page',
        text: 'Your role does not carry the permission this page needs. Ask an administrator if you think it should.',
    },
    404: {
        icon: SearchX,
        title: 'Page not found',
        text: 'The page you asked for does not exist, or it was removed.',
    },
    500: {
        icon: ServerCrash,
        title: 'Something went wrong',
        text: 'The server could not finish the request. Please try again.',
    },
    503: {
        icon: Wrench,
        title: 'Down for maintenance',
        text: 'The app is being updated. Please come back shortly.',
    },
}

const detail = computed(() => copy[props.status] ?? copy[500])
const signedIn = computed(() => !!page.props.auth?.user)
</script>

<template>
    <!-- Signed out, this sits inside the sign-in card, so the height stays
         modest. Signed in, the layout gives it the room. -->
    <div
        class="flex flex-col items-center justify-center py-6 text-center"
        :class="signedIn ? 'min-h-[60vh]' : 'min-h-[14rem]'"
    >
        <span
            class="grid size-16 place-items-center rounded-full bg-surface-sunken text-ink-muted"
        >
            <component :is="detail.icon" :size="28" />
        </span>

        <p class="mt-5 font-mono text-2xs font-semibold tracking-widest text-ink-subtle uppercase">
            Error {{ status }}
        </p>
        <h2 class="mt-2 text-xl font-semibold tracking-tight text-ink">{{ detail.title }}</h2>
        <p class="mt-2 max-w-md text-sm text-ink-muted">{{ detail.text }}</p>

        <div class="mt-7">
            <PrimaryButton :href="signedIn ? '/dashboard' : '/login'">
                {{ signedIn ? 'Back to dashboard' : 'Go to login' }}
            </PrimaryButton>
        </div>
    </div>
</template>
