<script setup>
/*
 * The ordinary sign-in. The header, the hero and the card's frame are
 * SignInLayout, shared with the kiosk (Auth/Kiosk.vue); this page is what is
 * inside the card. It follows the client's mockup (design/login-mockup.png)
 * to the pixel, with one addition the mockup does not show: the way to the
 * kiosk screen, which the shared tablet needs.
 *
 * "Forgot password?" goes nowhere yet: there is no password reset in this
 * system, and an administrator sets a new password from Users.
 *
 * Font sizes are in px on purpose: Tailwind's text-sm and text-base also set
 * a line height, and the design gives its own.
 */
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Check, LockKeyhole, User } from '@lucide/vue'
import SignInField from '../../Components/SignInField.vue'
import SignInIcon from '../../Components/SignInIcon.vue'
import SignInLayout from '../../Layouts/SignInLayout.vue'

defineOptions({ layout: SignInLayout })

defineProps({
    status: { type: String, default: null },
})

const form = useForm({
    username: '',
    password: '',
    remember: false,
})

/*
 * Where each message goes.
 *
 * A field sent empty gets its own message, and that one belongs under the
 * field. A wrong password, a throttle and a kiosk refusal also arrive on
 * `username`, but they are about the attempt, not the box, so they go above
 * the button. Which is which is decided by what was sent, not by what the
 * box holds now, so a message does not jump while the user types.
 *
 * The fields carry no `required` attribute, so an empty field reaches the
 * server and gets its message rather than the browser's bubble.
 */
const sentEmpty = ref({ username: false, password: false })

function fieldError(name) {
    return sentEmpty.value[name] ? form.errors[name] : null
}

const generalErrors = computed(() => [
    ...new Set(
        Object.entries(form.errors)
            .filter(([name]) => !sentEmpty.value[name])
            .map(([, message]) => message),
    ),
])

const generalErrorId = computed(() => (generalErrors.value.length ? 'login-error' : null))

function submit() {
    sentEmpty.value = {
        username: form.username.trim() === '',
        password: form.password === '',
    }

    form.post('/login', {
        // Keep the typed username, clear the password either way.
        onFinish: () => form.reset('password'),
    })
}

// Buttons and links: a green outline, shown to the keyboard only. Yellow
// would vanish against the yellow Sign in button.
const keyFocus =
    'focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-goe-green'
</script>

<template>
    <div
        class="mx-auto flex h-[calc(87*var(--u))] w-[calc(90*var(--u))] items-center justify-center rounded-[calc(18*var(--u))] border-[length:calc(2*var(--u))] border-goe-green bg-goe-green-tint text-goe-green"
    >
        <SignInIcon name="cubes" class="h-[calc(58.6*var(--u))] w-[calc(61.6*var(--u))]" :stroke-width="2.4" />
    </div>
    <p class="m-0 mt-[calc(8*var(--u))] text-center text-[length:calc(17*var(--u))] leading-none font-bold tracking-[0.02em] text-goe-green uppercase sm:text-[length:calc(20*var(--u))] lg:text-[length:calc(20*var(--u))]">
        GO Enterprises Inventory
    </p>
    <h1 class="m-0 mt-[calc(21*var(--u))] text-center text-[length:calc(40*var(--u))] leading-none font-extrabold tracking-[-0.02em] text-goe-ink sm:text-[length:calc(52*var(--u))] lg:text-[length:calc(52*var(--u))]">
        Welcome back
    </h1>
    <p class="m-0 mt-[calc(11.5*var(--u))] text-center text-[length:calc(18*var(--u))] leading-none text-goe-muted sm:text-[length:calc(21.5*var(--u))] lg:text-[length:calc(21.5*var(--u))]">Sign in to manage your inventory.</p>
    <p v-if="status" class="m-0 mt-[calc(12*var(--u))] text-center text-[length:calc(15*var(--u))] font-medium text-goe-green" role="status">
        {{ status }}
    </p>

    <form class="mt-[calc(23.5*var(--u))] flex flex-col gap-[calc(15.5*var(--u))] sm:-mr-[calc(2*var(--u))] sm:ml-px" novalidate @submit.prevent="submit">
        <SignInField
            id="username"
            v-model="form.username"
            :icon="User"
            icon-class="size-[calc(33*var(--u))]"
            label="Username"
            name="username"
            autocomplete="username"
            autocapitalize="none"
            spellcheck="false"
            autofocus
            aria-required="true"
            placeholder="Enter your username"
            :error="fieldError('username')"
            :described-by="generalErrorId"
        />

        <SignInField
            id="password"
            v-model="form.password"
            :icon="LockKeyhole"
            icon-class="size-[calc(28*var(--u))]"
            type="password"
            label="Password"
            name="password"
            autocomplete="current-password"
            aria-required="true"
            placeholder="Enter your password"
            :error="fieldError('password')"
            :described-by="generalErrorId"
        />

        <!-- Remember me, and the way to a new password. Each target is 48 px
             tall, more than the row shows. -->
        <div class="mt-[calc(5.5*var(--u))] flex flex-wrap items-center justify-between gap-x-[calc(16*var(--u))] gap-y-[calc(12*var(--u))]">
            <label for="remember" class="-my-[calc(11*var(--u))] flex cursor-pointer items-center gap-[calc(13*var(--u))] py-[calc(11*var(--u))] select-none">
                <span class="relative flex size-[calc(26*var(--u))] shrink-0">
                    <input
                        id="remember"
                        v-model="form.remember"
                        type="checkbox"
                        name="remember"
                        class="peer m-0 size-[calc(26*var(--u))] cursor-pointer appearance-none rounded-[calc(6*var(--u))] border-[length:calc(1.5*var(--u))] border-[#8a8f8c] bg-white transition-colors checked:border-goe-green checked:bg-goe-green motion-reduce:transition-none"
                        :class="keyFocus"
                    />
                    <Check
                        class="pointer-events-none absolute inset-0 m-auto hidden size-[calc(18*var(--u))] text-white peer-checked:block"
                        :stroke-width="3"
                        absolute-stroke-width
                        aria-hidden="true"
                    />
                </span>
                <span class="text-[length:calc(18*var(--u))] leading-none font-medium whitespace-nowrap text-goe-ink">Remember me</span>
            </label>
            <a
                href="#"
                class="-my-[calc(11*var(--u))] inline-flex items-center rounded-[calc(6*var(--u))] py-[calc(11*var(--u))] text-[length:calc(17.5*var(--u))] leading-none font-bold whitespace-nowrap text-goe-green hover:underline"
                :class="keyFocus"
                @click.prevent
            >
                Forgot password?
            </a>
        </div>

        <!-- Wrong password, too many tries, or not a kiosk account. -->
        <div v-if="generalErrorId" :id="generalErrorId" role="alert" class="text-[length:calc(15*var(--u))] leading-snug text-goe-error">
            <p v-for="message in generalErrors" :key="message" class="m-0">{{ message }}</p>
        </div>

        <button
            type="submit"
            class="mt-[calc(15*var(--u))] flex h-[calc(66*var(--u))] pointer-coarse:min-h-12 w-full cursor-pointer items-center justify-center gap-[calc(14*var(--u))] rounded-[calc(12*var(--u))] pl-[calc(5*var(--u))] border-0 bg-goe-button text-[length:calc(25.5*var(--u))] leading-none font-bold text-goe-button-ink transition-colors hover:bg-goe-button-hover disabled:cursor-not-allowed disabled:opacity-80 disabled:hover:bg-goe-button motion-reduce:transition-none"
            :class="keyFocus"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Signing in...' : 'Sign in' }}
            <SignInIcon v-if="!form.processing" name="arrow" class="h-[calc(18*var(--u))] w-[calc(26*var(--u))]" :stroke-width="2.6" />
        </button>
    </form>

    <p class="m-0 mt-[calc(19.5*var(--u))] flex items-center justify-center gap-[calc(12*var(--u))] text-[length:calc(16*var(--u))] leading-none text-goe-muted">
        <LockKeyhole class="size-[calc(22*var(--u))] shrink-0" :stroke-width="1.75" absolute-stroke-width aria-hidden="true" />
        Authorized personnel only
    </p>

    <!-- Not in the mockup, kept on purpose: the same sign-in, laid out for
         the shared tablet. A different screen, not a different way in: it
         posts to this very route. -->
    <div data-test="kiosk-entry">
        <Link
            href="/kiosk"
            class="mt-[calc(20*var(--u))] flex h-[calc(52*var(--u))] pointer-coarse:min-h-12 w-full items-center justify-center rounded-[calc(10*var(--u))] border border-goe-line bg-white text-[length:calc(17*var(--u))] font-medium text-goe-ink transition-colors hover:bg-goe-hover motion-reduce:transition-none"
            :class="keyFocus"
        >
            Kiosk sign in
        </Link>
        <p class="m-0 mt-[calc(10*var(--u))] text-center text-[length:calc(14*var(--u))] leading-snug text-goe-muted">For the shared tablet in the store or warehouse.</p>
    </div>
</template>
