<script setup>
import { Head } from '@inertiajs/vue3'
import { Box } from '@lucide/vue'
import DeveloperCredit from '../Components/DeveloperCredit.vue'
import SignInIcon from '../Components/SignInIcon.vue'
import { useFlash } from '../composables/useFlash'

/**
 * The sign-in shell for GO Enterprises, worn by the ordinary login
 * (Pages/Auth/Login.vue) and the kiosk (Pages/Auth/Kiosk.vue). It is the
 * client's mockup, kept in design/login-mockup.png (1586 × 992), drawn to
 * the pixel at that size and scaled down from there to fit the window.
 *
 * - The header bar: the round logo and the company's name on the left,
 *   "Inventory system" on the right.
 * - The green hero on the left (59.5%): the badge, the headline, three
 *   features, the illustration and the markets.
 * - The white card on the right, which is the page's own slot, with one line
 *   under it.
 *
 * Both pages wear it, so going from one to the other keeps everything but
 * the card in place.
 *
 * The illustration is cut from the mockup (public/images/login-hero.png,
 * 944 × 395) and never drawn larger than that (944 units): the cut is all the detail
 * there is. The hero's gradient is the mockup's own two greens, and the
 * picture's edges fade into it, so there is no seam. The two faint arcs
 * above it are drawn here, behind it, where the cut ends.
 *
 * Every measurement is a number of `--u` (`goe-scale` in app.css), the
 * mockup's pixel. From 1024 px up it shrinks with the window, across and
 * down, so the whole page fits the screen at 100% zoom with its proportions
 * kept — a laptop no longer gets the 1586 px drawing. Below 1024 px it is
 * 1 px, the card comes first and the hero follows it, without the
 * illustration. The hero keeps the mockup's share of the width (59.52%), and
 * the illustration's right edge fades into it when the hero is wider than
 * the picture. On a touch screen the fields and buttons stay 48 px tall at
 * least (`pointer-coarse:`), whatever the unit. Font sizes are set apart
 * from line heights on purpose: Tailwind's text-sm and text-base set both,
 * and the design gives its own.
 *
 * Its colours are the `goe-*` tokens in app.css, which nothing else uses.
 * The error screens keep GuestLayout.
 */
const props = defineProps({
    title: { type: String, default: '' },
})

useFlash()

/*
 * The three features, each spaced as the mockup draws it: `icon` is the
 * drawing's own box, `gap` the room before the text. Written out in full,
 * because Tailwind never generates a class put together at run time.
 */
const features = [
    {
        icon: 'cubes',
        label: 'Track stock',
        iconClass: 'ml-[clamp(4px,0.57vw,9px)] lg:ml-[calc(9*var(--u))] h-[clamp(30px,2.446vw,38.8px)] lg:h-[calc(38.8*var(--u))] w-[clamp(34px,2.825vw,44.8px)] lg:w-[calc(44.8*var(--u))]',
        gap: 'gap-[clamp(10px,1.135vw,18px)] lg:gap-[calc(18*var(--u))]',
        text: 'text-[clamp(16px,1.28vw,20.3px)] lg:text-[length:calc(20.3*var(--u))] lg:-top-[calc(1.5*var(--u))]',
    },
    {
        icon: 'arrows',
        label: 'Monitor movement',
        narrow: true,
        iconClass: 'h-[clamp(24px,1.955vw,31px)] lg:h-[calc(31*var(--u))] w-[clamp(30px,2.459vw,39px)] lg:w-[calc(39*var(--u))]',
        gap: 'gap-[clamp(10px,1.324vw,21px)] lg:gap-[calc(21*var(--u))]',
        text: 'w-[clamp(80px,6.75vw,107px)] lg:w-[calc(107*var(--u))] text-[clamp(16px,1.324vw,21px)] lg:text-[length:calc(21*var(--u))] lg:-top-[calc(4.5*var(--u))]',
    },
    {
        icon: 'clipboard',
        label: 'Stay organized',
        iconClass: 'h-[clamp(32px,2.648vw,42px)] lg:h-[calc(42*var(--u))] w-[clamp(27px,2.27vw,36px)] lg:w-[calc(36*var(--u))] lg:-mt-[calc(6*var(--u))]',
        gap: 'gap-[clamp(10px,1.45vw,23px)] lg:gap-[calc(23*var(--u))]',
        text: 'text-[clamp(16px,1.375vw,21.8px)] lg:text-[length:calc(21.8*var(--u))] lg:-top-[calc(4.5*var(--u))]',
    },
]

const markets = ['Residential', 'Commercial', 'Industrial']
</script>

<template>
    <Head :title="props.title">
        <!-- The headings are Inter 800. The app loads Inter up to 700 only,
             so the one weight is added here, for these two pages. -->
        <link
            head-key="font-inter-800"
            rel="stylesheet"
            href="https://fonts.googleapis.com/css2?family=Inter:wght@800&display=swap"
        />
    </Head>

    <div class="goe-scale flex min-h-dvh w-full flex-col bg-goe-page font-sans text-goe-ink">
        <!-- ------------------------------------------------ Header bar -->
        <header
            class="flex h-[calc(88*var(--u))] shrink-0 items-center justify-between px-[calc(16*var(--u))] sm:px-[calc(24*var(--u))] lg:h-[calc(132*var(--u))] lg:items-start lg:pr-[calc(52*var(--u))] lg:pl-[calc(62*var(--u))]"
        >
            <div class="flex min-w-0 items-center gap-[calc(12*var(--u))] sm:gap-[calc(16*var(--u))] lg:items-start lg:gap-0">
                <img
                    src="/images/go-enterprises-logo.png"
                    alt="GO Enterprises Hardware & Electrical Supplies, since 2015"
                    width="119"
                    height="119"
                    class="size-[calc(70*var(--u))] shrink-0 object-contain lg:mt-[calc(2.7*var(--u))] lg:-ml-[calc(4.6*var(--u))] lg:size-[calc(119*var(--u))]"
                />
                <span
                    class="hidden h-[calc(55*var(--u))] w-[calc(1.5*var(--u))] shrink-0 bg-goe-rule sm:block lg:mt-[calc(29*var(--u))] lg:ml-[calc(15.6*var(--u))]"
                    aria-hidden="true"
                />
                <div class="min-w-0 lg:mt-[calc(22.5*var(--u))] lg:ml-[calc(22*var(--u))]">
                    <p
                        class="m-0 truncate text-[length:calc(22*var(--u))] leading-none font-extrabold tracking-[-0.01em] text-goe-brand-ink uppercase sm:text-[length:calc(26*var(--u))] lg:text-[length:calc(40*var(--u))]"
                    >
                        GO Enterprises
                    </p>
                    <p class="m-0 mt-[calc(6*var(--u))] truncate text-[length:calc(14*var(--u))] leading-none text-[#3a3a3a] sm:text-[length:calc(16*var(--u))] lg:mt-[calc(2.5*var(--u))] lg:text-[length:calc(22.5*var(--u))]">
                        Hardware &amp; Electrical Supplies
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center text-goe-brand-ink lg:mt-[calc(38*var(--u))] lg:items-start">
                <Box class="size-[calc(28*var(--u))] lg:-mt-[calc(2.5*var(--u))] lg:size-[calc(40*var(--u))]" :stroke-width="1.6" aria-hidden="true" />
                <span class="mx-[calc(12*var(--u))] hidden h-[calc(33*var(--u))] w-[calc(1.5*var(--u))] bg-goe-rule sm:block lg:mx-0 lg:ml-[calc(21*var(--u))]" aria-hidden="true" />
                <p
                    class="m-0 hidden text-[length:calc(15*var(--u))] leading-none font-bold tracking-[0.065em] uppercase sm:block lg:mt-[calc(3.5*var(--u))] lg:ml-[calc(21*var(--u))] lg:text-[length:calc(21*var(--u))]"
                >
                    Inventory system
                </p>
            </div>
        </header>

        <div class="flex flex-1 flex-col lg:grid lg:grid-cols-[minmax(0,59.52%)_minmax(0,1fr)]">
            <!-- ------------------------------------------- Login card -->
            <main
                class="order-first flex min-w-0 flex-col items-center px-[calc(16*var(--u))] pt-[calc(8*var(--u))] pb-[calc(32*var(--u))] sm:px-[calc(24*var(--u))] lg:order-none lg:col-start-2 lg:row-start-1 lg:pt-[calc(24*var(--u))] lg:pr-[calc(35*var(--u))] lg:pb-[calc(40*var(--u))] lg:pl-[calc(30*var(--u))]"
            >
                <div
                    class="w-full max-w-[calc(577*var(--u))] rounded-[calc(28*var(--u))] border border-goe-card-line bg-white px-[calc(20*var(--u))] pt-[calc(32*var(--u))] pb-[calc(32*var(--u))] shadow-[0_10px_40px_rgba(0,0,0,0.08)] sm:px-[calc(46*var(--u))] lg:pt-[calc(35*var(--u))] lg:pb-[calc(33.5*var(--u))]"
                >
                    <slot />
                </div>
                <!-- The developer's credit, where the mockup's caption was (taken off
                     on 24 November 2026). It takes no more height than the caption
                     did, so the page keeps the mockup's height and never scrolls. -->
                <p class="m-0 mt-[calc(24*var(--u))] text-center text-[length:calc(14*var(--u))] leading-none text-goe-muted lg:mt-[calc(28*var(--u))] lg:text-[length:calc(16*var(--u))]">
                    <DeveloperCredit logo="navy" logo-class="size-[calc(20*var(--u))]" />
                </p>
            </main>

            <!-- ---------------------------------------------------- Hero -->
            <section
                class="relative isolate flex min-w-0 flex-col overflow-hidden bg-linear-to-tr from-goe-hero-from to-goe-hero-to px-[calc(24*var(--u))] pt-[calc(40*var(--u))] pb-[calc(40*var(--u))] text-white sm:px-[calc(40*var(--u))] lg:col-start-1 lg:row-start-1 lg:rounded-tr-[calc(30*var(--u))] lg:rounded-br-[calc(18*var(--u))] lg:pt-[calc(56*var(--u))] lg:pr-0 lg:pb-[calc(47*var(--u))] lg:pl-[calc(74*var(--u))]"
            >
                <!-- The faint grid in the top-right corner. -->
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute top-0 right-0 -z-10 h-[calc(230*var(--u))] w-[calc(285*var(--u))] bg-[linear-gradient(to_right,rgb(255_255_255/0.09)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.09)_1px,transparent_1px)] bg-size-[calc(32*var(--u))_calc(32*var(--u))] bg-position-[0_0,0_calc(30*var(--u))] mask-[radial-gradient(ellipse_100%_100%_at_100%_0%,#000_55%,transparent_100%)]"
                />

                <p
                    class="m-0 flex h-[calc(41*var(--u))] items-center self-start rounded-full border-[length:calc(1.5*var(--u))] border-goe-yellow px-[calc(28*var(--u))] text-[clamp(12px,1.103vw,17.5px)] lg:text-[length:calc(17.5*var(--u))] leading-none font-bold tracking-[0.1em] text-goe-yellow uppercase max-lg:h-9 max-lg:px-4"
                >
                    Built for everyday operations
                </p>

                <h2
                    class="m-0 mt-[calc(24*var(--u))] text-[clamp(36px,4.35vw,69px)] lg:text-[length:calc(69*var(--u))] leading-[1.058] font-extrabold tracking-[-0.02em] [text-shadow:0_2px_12px_rgb(0_0_0/0.22)] lg:mt-[calc(20.5*var(--u))]"
                >
                    <span class="block text-white">Everything in place.</span>
                    <span class="block text-goe-yellow">Business in motion.</span>
                </h2>

                <p class="m-0 mt-[calc(16*var(--u))] text-[clamp(17px,1.797vw,28.5px)] lg:text-[length:calc(28.5*var(--u))] leading-[1.25] text-goe-subhead lg:mt-[calc(8.5*var(--u))] lg:leading-none">
                    Your inventory, organized. Your operations, in <span class="text-goe-mint">control.</span>
                </p>

                <ul class="m-0 mt-[calc(32*var(--u))] flex list-none flex-wrap items-center gap-y-[calc(16*var(--u))] p-0 lg:mt-[calc(37.5*var(--u))] lg:flex-nowrap">
                    <template v-for="(feature, index) in features" :key="feature.label">
                        <li
                            v-if="index > 0"
                            class="mx-[clamp(12px,calc(3.85vw-27.6px),33.5px)] lg:mx-[calc(33.5*var(--u))] h-[calc(49*var(--u))] w-[calc(1.5*var(--u))] bg-white/25 max-sm:hidden"
                            aria-hidden="true"
                        />
                        <li class="flex items-center max-sm:w-full" :class="feature.gap">
                            <SignInIcon :name="feature.icon" class="shrink-0 text-[#f1f5e6]" :class="feature.iconClass" />
                            <span
                                class="relative leading-[1.08] font-medium"
                                :class="[feature.text, feature.narrow ? '' : 'whitespace-nowrap']"
                            >
                                {{ feature.label }}
                            </span>
                        </li>
                    </template>
                </ul>

                <!-- The illustration, at the foot of the hero. Hidden below
                     1024 px, where the hero is short and follows the card. -->
                <div
                    class="mt-auto hidden max-w-[calc(944*var(--u))] pt-[calc(9*var(--u))] lg:-ml-[calc(74*var(--u))] lg:block lg:w-[calc(100%+74*var(--u))]"
                >
                    <div class="relative">
                        <svg
                            aria-hidden="true"
                            class="pointer-events-none absolute left-0 w-full"
                            style="top: -50.633%; height: 75.949%"
                            viewBox="0 -200 944 300"
                            preserveAspectRatio="none"
                            fill="none"
                        >
                            <defs>
                                <linearGradient id="hero-arc-outer" gradientUnits="userSpaceOnUse" x1="745" y1="0" x2="815" y2="0">
                                    <stop offset="0" stop-color="#fff4c4" stop-opacity="0" />
                                    <stop offset="1" stop-color="#fff4c4" stop-opacity="0.17" />
                                </linearGradient>
                                <linearGradient id="hero-arc-inner" gradientUnits="userSpaceOnUse" x1="815" y1="0" x2="850" y2="0">
                                    <stop offset="0" stop-color="#fff4c4" stop-opacity="0" />
                                    <stop offset="1" stop-color="#fff4c4" stop-opacity="0.17" />
                                </linearGradient>
                            </defs>
                            <path d="M 745 -159.5 A 323.8 323.8 0 0 1 944 -16.5" stroke="url(#hero-arc-outer)" stroke-width="1.5" />
                            <path d="M 815 -64.9 A 293.6 293.6 0 0 1 924.4 100" stroke="url(#hero-arc-inner)" stroke-width="1.5" />
                        </svg>
                        <picture>
                            <source srcset="/images/login-hero.webp" type="image/webp" />
                            <img
                                src="/images/login-hero.png"
                                alt=""
                                width="944"
                                height="395"
                                class="relative block h-auto w-full [mask-composite:intersect] mask-[linear-gradient(to_bottom,transparent_0,#000_5%,#000_97.5%,transparent_100%),linear-gradient(to_right,#000_93%,transparent_100%)]"
                                draggable="false"
                            />
                        </picture>
                    </div>
                </div>

                <div
                    class="mt-[calc(32*var(--u))] flex flex-wrap items-center justify-between gap-x-[calc(24*var(--u))] gap-y-[calc(8*var(--u))] text-[clamp(15px,1.261vw,20px)] lg:text-[length:calc(20*var(--u))] leading-none lg:mt-0 lg:-ml-[calc(6*var(--u))] lg:pr-[calc(46*var(--u))]"
                >
                    <ul class="m-0 flex list-none items-center gap-[clamp(10px,1.072vw,17px)] lg:gap-[calc(17*var(--u))] p-0">
                        <template v-for="(market, index) in markets" :key="market">
                            <li v-if="index > 0" class="px-px" aria-hidden="true">/</li>
                            <li>{{ market }}</li>
                        </template>
                    </ul>
                    <p class="m-0 text-[clamp(15px,1.387vw,22px)] lg:text-[length:calc(22*var(--u))] font-bold text-goe-yellow">Since 2015</p>
                </div>
            </section>
        </div>
    </div>
</template>
