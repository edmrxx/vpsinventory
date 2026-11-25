<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { ShieldCheck, TrendingUp, Users } from '@lucide/vue'
import BrandMark from '../Components/BrandMark.vue'
import DeveloperCredit from '../Components/DeveloperCredit.vue'
import InventoryArtwork from '../Components/InventoryArtwork.vue'
import { useFlash } from '../composables/useFlash'

/**
 * The sign-in shell.
 *
 * It keeps a navy and gold identity of its own, from the tokens in app.css,
 * rather than the brand colour chosen in Theme Settings. This is the first
 * screen anybody sees, and it should look the same for everybody.
 */
const props = defineProps({
    title: { type: String, default: '' },
})

const page = usePage()
const company = computed(() => page.props.company ?? {})

/**
 * The name beside the logo at the top.
 *
 * It is the short name from Settings -> Company Information, so the mark can
 * carry the system's own name while the panel below shows the full company
 * name. Left empty, both read the same.
 */
const markName = computed(() => company.value.short_name || company.value.name)

const highlights = [
    { icon: ShieldCheck, label: 'Secure & Audited' },
    { icon: Users, label: 'Role-Based Access' },
    { icon: TrendingUp, label: 'Real-Time Reports' },
]

useFlash()
</script>

<template>
    <Head :title="props.title" />

    <div class="relative min-h-dvh bg-navy-900">
        <!-- A soft wash across the whole page, so the card sits in light. -->
        <div
            class="pointer-events-none absolute inset-0"
            style="
                background-image:
                    radial-gradient(
                        70% 60% at 12% 8%,
                        oklch(0.36 0.058 264 / 0.75),
                        transparent 72%
                    ),
                    radial-gradient(
                        60% 55% at 92% 95%,
                        oklch(0.185 0.04 264 / 0.9),
                        transparent 70%
                    );
            "
            aria-hidden="true"
        />

        <div class="relative grid min-h-dvh lg:grid-cols-2">
            <!-- ---------------------------------------------- Brand panel -->
            <section
                class="relative hidden overflow-hidden px-12 py-12 lg:flex lg:flex-col lg:justify-between xl:px-16"
            >
                <!-- The circle, and the goods inside it. -->
                <div
                    class="pointer-events-none absolute top-1/2 left-1/2 aspect-square w-[34rem] -translate-x-[46%] -translate-y-1/2 rounded-full bg-navy-700/25"
                    aria-hidden="true"
                >
                    <InventoryArtwork
                        class="absolute top-1/2 left-1/2 w-[26rem] -translate-x-1/2 -translate-y-1/2 text-navy-950/55"
                    />
                </div>

                <!-- Mark -->
                <div class="relative flex items-center gap-3.5">
                    <BrandMark size-class="h-10 w-10" />
                    <span
                        class="font-display text-lg font-semibold tracking-[0.3em] text-white uppercase"
                    >
                        {{ markName }}
                    </span>
                </div>

                <!-- The name, and what the system is for -->
                <div class="relative max-w-lg">
                    <!-- The same serif as the mark at the top, so the panel
                         reads as one. The sizes are 70% of what they were,
                         which is why they are written out rather than taken
                         from the step scale. -->
                    <h2
                        class="font-display text-[3.15rem] leading-[0.95] font-bold tracking-tight text-white uppercase xl:text-[4.2rem]"
                    >
                        {{ company.name }}
                    </h2>

                    <p class="mt-6 max-w-md text-base leading-relaxed text-white/65">
                        Track items, stock and suppliers in one organized, secure
                        system.
                    </p>

                    <span
                        class="mt-8 block h-0.5 w-16 rounded-full bg-gold-400"
                        aria-hidden="true"
                    />

                    <ul class="mt-8 space-y-3.5">
                        <li
                            v-for="item in highlights"
                            :key="item.label"
                            class="flex items-center gap-3 text-sm text-white/80"
                        >
                            <component
                                :is="item.icon"
                                :size="17"
                                class="shrink-0 text-gold-400"
                                aria-hidden="true"
                            />
                            {{ item.label }}
                        </li>
                    </ul>
                </div>

                <div class="relative space-y-1.5 text-xs text-white/40">
                    <p>
                        © {{ new Date().getFullYear() }}
                        {{ company.legal_name || company.name }}. All rights reserved.
                    </p>
                    <p class="text-white/55"><DeveloperCredit logo="white" logo-class="size-4" /></p>
                </div>
            </section>

            <!-- ------------------------------------------------ Form panel -->
            <section class="flex items-center justify-center px-5 py-10 sm:px-8">
                <div class="w-full max-w-[26rem]">
                    <!-- Small screens get the mark here instead. -->
                    <div class="mb-7 flex items-center justify-center gap-3 lg:hidden">
                        <BrandMark size-class="h-9 w-9" />
                        <span
                            class="font-display text-base font-semibold tracking-[0.3em] text-white uppercase"
                        >
                            {{ markName }}
                        </span>
                    </div>

                    <!-- The card, with its gold edge. -->
                    <div
                        class="overflow-hidden rounded-panel bg-surface shadow-[0_24px_60px_-18px_oklch(0.12_0.04_264/0.7)]"
                    >
                        <span class="block h-1 w-full bg-gold-400" aria-hidden="true" />

                        <div class="px-7 py-8 sm:px-9">
                            <slot />
                        </div>
                    </div>

                    <p class="mt-6 text-center text-xs text-white/40 lg:hidden">
                        © {{ new Date().getFullYear() }}
                        {{ company.legal_name || company.name }}. All rights reserved.
                    </p>
                    <p class="mt-1.5 text-center text-xs text-white/55 lg:hidden">
                        <DeveloperCredit logo="white" logo-class="size-4" />
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
