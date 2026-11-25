<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppSidebar from '../Components/AppSidebar.vue'
import AppTopbar from '../Components/AppTopbar.vue'
import DeveloperCredit from '../Components/DeveloperCredit.vue'
import SlipPrinter from '../Components/SlipPrinter.vue'
import { useFlash } from '../composables/useFlash'
import { useSidebar } from '../composables/useSidebar'

const props = defineProps({
    title: { type: String, default: '' },
})

const page = usePage()
const { collapsed, mobileOpen, isDesktop, iconsOnly, toggle, closeMobile } = useSidebar()

useFlash()

// Page transitions key off the page name, so the body fades on every visit.
const transitionKey = computed(() => page.component)
</script>

<template>
    <Head :title="props.title" />

    <div class="min-h-dvh bg-canvas print:bg-white">
        <AppSidebar
            :collapsed="collapsed"
            :icons-only="iconsOnly"
            :mobile-open="mobileOpen"
            :is-desktop="isDesktop"
            @toggle="toggle"
            @close="closeMobile"
        />

        <div
            class="flex min-h-dvh flex-col transition-[padding] duration-300 ease-[var(--ease-out-quint)] print:pl-0!"
            :class="iconsOnly ? 'lg:pl-(--sidebar-w-collapsed)' : 'lg:pl-(--sidebar-w)'"
        >
            <!-- A page printed on paper (a statement of account) carries no
                 sidebar, top bar or footer. A sale slip prints apart. -->
            <AppTopbar class="print:hidden" :title="props.title" @toggle-sidebar="toggle" />

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8 print:p-0">
                <Transition name="page" mode="out-in" appear>
                    <!-- The full width beside the sidebar (20 November 2026):
                         capped at 1280 px it left wide empty margins on a
                         large screen. A form that reads better narrow keeps
                         its own max-width inside the page. -->
                    <div :key="transitionKey" class="w-full">
                        <slot />
                    </div>
                </Transition>
            </main>

            <footer
                class="flex flex-col items-center justify-center gap-x-2 gap-y-1 px-4 pb-6 text-center text-xs text-ink-subtle sm:flex-row sm:px-6 lg:px-8 print:hidden"
            >
                <span>{{ page.props.appName }}</span>
                <span class="hidden sm:inline" aria-hidden="true">&middot;</span>
                <DeveloperCredit logo="navy" logo-class="size-4" />
            </footer>
        </div>
    </div>

    <!-- A sale slip, while one is printing. Off screen otherwise. -->
    <SlipPrinter />
</template>
