<script setup>
/*
 * Font sizes are in px for the same reason as on Login.vue: Tailwind's
 * text-sm and text-base also set a line height, and the design gives its own.
 */
import { Link, useForm } from '@inertiajs/vue3'
import { LockKeyhole, User } from '@lucide/vue'
import SignInField from '../../Components/SignInField.vue'
import SignInIcon from '../../Components/SignInIcon.vue'
import SignInLayout from '../../Layouts/SignInLayout.vue'

/**
 * Signing in from the tablet on the floor.
 *
 * It wears the same shell and fields as the ordinary sign-in (SignInLayout,
 * SignInField), so the two read as one system.
 *
 * It is a different screen, not a different way in. Posting to the same
 * route means the same validation, the same throttle per username and IP,
 * and the same session handling, with no second code path to keep in step.
 *
 * There is no "remember me" here on purpose. A kiosk is a shared device,
 * and a shared device that stays signed in is an unlocked door.
 */
defineOptions({ layout: SignInLayout })

defineProps({
    status: { type: String, default: null },
})

const form = useForm({
    username: '',
    password: '',
    // Which screen this was typed on. The server only ever uses it to
    // narrow what is allowed — an account without `kiosk.use` is turned
    // away here and told to use the ordinary screen.
    from: 'kiosk',
})

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}

// Buttons and links: a green outline, shown to the keyboard only. Yellow
// would vanish against the yellow Sign in button.
const keyFocus =
    'focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-goe-green'
</script>

<template>
    <p class="m-0 text-center text-[length:calc(20*var(--u))] leading-none font-bold tracking-[0.02em] text-goe-green uppercase">Shared device</p>
    <h1 class="m-0 mt-[calc(19*var(--u))] text-center text-[length:calc(40*var(--u))] leading-none font-extrabold tracking-[-0.02em] text-goe-ink sm:text-[length:calc(52*var(--u))] lg:text-[length:calc(52*var(--u))]">
        Kiosk sign in
    </h1>
    <p class="m-0 mt-[calc(12*var(--u))] text-center text-[length:calc(17*var(--u))] leading-[1.45] text-goe-muted">
        Enter your own username and password. Sign out when you walk away.
    </p>
    <p v-if="status" class="m-0 mt-[calc(12*var(--u))] text-center text-[length:calc(15*var(--u))] font-medium text-goe-green" role="status">
        {{ status }}
    </p>

    <!-- Every message sits under the field the server put it on. -->
    <form class="mt-[calc(25*var(--u))] flex flex-col gap-[calc(15.5*var(--u))]" @submit.prevent="submit">
        <SignInField
            id="kiosk-username"
            v-model="form.username"
            :icon="User"
            icon-class="size-[calc(33*var(--u))]"
            label="Username"
            name="username"
            autocomplete="username"
            autocapitalize="none"
            autocorrect="off"
            spellcheck="false"
            required
            autofocus
            placeholder="Enter your username"
            :error="form.errors.username"
        />

        <SignInField
            id="kiosk-password"
            v-model="form.password"
            :icon="LockKeyhole"
            icon-class="size-[calc(28*var(--u))]"
            type="password"
            label="Password"
            name="password"
            autocomplete="current-password"
            required
            placeholder="Enter your password"
            :error="form.errors.password"
        />

        <button
            type="submit"
            class="mt-[calc(15*var(--u))] flex h-[calc(66*var(--u))] pointer-coarse:min-h-12 w-full cursor-pointer items-center justify-center gap-[calc(14*var(--u))] rounded-[calc(12*var(--u))] border-0 bg-goe-button text-[length:calc(25.5*var(--u))] leading-none font-bold text-goe-button-ink transition-colors hover:bg-goe-button-hover disabled:cursor-not-allowed disabled:opacity-80 disabled:hover:bg-goe-button motion-reduce:transition-none"
            :class="keyFocus"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Signing in...' : 'Sign in' }}
            <SignInIcon v-if="!form.processing" name="arrow" class="h-[calc(18*var(--u))] w-[calc(26*var(--u))]" :stroke-width="2.6" />
        </button>

        <!-- Said plainly, because the tablet is shared. -->
        <p class="m-0 text-[length:calc(15*var(--u))] leading-normal text-goe-muted">
            This device does not stay signed in. Everything you do is recorded against your own name, so sign out
            before you hand it on.
        </p>
    </form>

    <div class="my-[calc(24.5*var(--u))] h-px bg-goe-divider" aria-hidden="true" />

    <Link
        href="/login"
        class="flex h-[calc(52*var(--u))] pointer-coarse:min-h-12 w-full items-center justify-center rounded-[calc(10*var(--u))] border border-goe-line bg-white text-[length:calc(17*var(--u))] font-medium text-goe-ink transition-colors hover:bg-goe-hover motion-reduce:transition-none"
        :class="keyFocus"
    >
        Back to the ordinary sign in
    </Link>
</template>
