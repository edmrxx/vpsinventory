<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { LogOut, PanelLeftClose, PanelLeftOpen, X } from '@lucide/vue'
import BrandMark from './BrandMark.vue'
import NavLink from './NavLink.vue'
import { visibleNavigation } from '../navigation'
import { usePermissions } from '../composables/usePermissions'
import { confirmLogout } from '../lib/swal'

defineProps({
    collapsed: { type: Boolean, default: false },
    iconsOnly: { type: Boolean, default: false },
    mobileOpen: { type: Boolean, default: false },
    isDesktop: { type: Boolean, default: true },
})

const emit = defineEmits(['toggle', 'close'])

const page = usePage()
const { can } = usePermissions()

// The company name and tagline come from the settings table.
const company = computed(() => page.props.company ?? {})
// Items the user may not open never reach the screen.
const sections = computed(() => visibleNavigation(can))

// The same confirmation the account menu in the top bar uses.
async function logout() {
    emit('close')
    await confirmLogout()
}
</script>

<template>
    <!-- Dim the page behind the drawer on a small screen. -->
    <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-150"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div
            v-if="mobileOpen && !isDesktop"
            class="fixed inset-0 z-30 bg-ink/40 backdrop-blur-sm lg:hidden"
            @click="emit('close')"
        />
    </Transition>

    <aside
        class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-line bg-surface transition-[width,transform] duration-300 ease-[var(--ease-out-quint)] print:hidden"
        :class="[
            iconsOnly ? 'w-(--sidebar-w-collapsed)' : 'w-(--sidebar-w)',
            mobileOpen ? 'translate-x-0 shadow-popover' : '-translate-x-full',
            'lg:translate-x-0 lg:shadow-none',
        ]"
        aria-label="Main navigation"
    >
        <!-- Brand -->
        <div
            class="flex h-(--topbar-h) shrink-0 items-center gap-2.5 border-b border-line px-4"
            :class="iconsOnly ? 'justify-center px-0' : ''"
        >
            <Link
                href="/dashboard"
                class="flex items-center gap-2.5 overflow-hidden rounded-field"
            >
                <BrandMark
                    size-class="h-9 w-9"
                    fallback-class="rounded-field bg-brand-600 text-ink-inverse shadow-brand"
                />
                <span v-if="!iconsOnly" class="flex flex-col leading-tight">
                    <span class="truncate text-sm font-semibold text-ink">{{ company.name }}</span>
                    <span v-if="company.tagline" class="truncate text-2xs font-medium text-ink-subtle uppercase">
                        {{ company.tagline }}
                    </span>
                </span>
            </Link>

            <button
                v-if="!isDesktop"
                type="button"
                class="ml-auto grid size-9 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink lg:hidden"
                aria-label="Close menu"
                @click="emit('close')"
            >
                <X :size="18" />
            </button>
        </div>

        <!-- Menu -->
        <nav class="scrollbar-slim flex-1 overflow-y-auto px-3 py-4">
            <div
                v-for="(section, index) in sections"
                :key="section.group"
                :class="index > 0 ? 'mt-6' : ''"
            >
                <p
                    v-if="!iconsOnly"
                    class="px-3 pb-2 text-2xs font-semibold tracking-wider text-ink-subtle uppercase"
                >
                    {{ section.group }}
                </p>
                <div
                    v-else
                    class="mx-auto mb-2 h-px w-6 bg-line"
                    aria-hidden="true"
                />

                <ul class="space-y-1">
                    <li v-for="item in section.items" :key="item.label">
                        <NavLink :item="item" :icons-only="iconsOnly" />
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Log out and the fold control -->
        <div class="shrink-0 space-y-1 border-t border-line p-3">
            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-field px-3 py-2.5 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50"
                :class="iconsOnly ? 'justify-center px-0' : ''"
                title="Log out"
                @click="logout"
            >
                <LogOut :size="18" class="shrink-0" aria-hidden="true" />
                <span v-if="!iconsOnly">Log out</span>
            </button>

            <button
                type="button"
                class="flex w-full items-center gap-3 rounded-field px-3 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                :class="iconsOnly ? 'justify-center px-0' : ''"
                :title="collapsed ? 'Expand menu' : 'Fold menu'"
                @click="emit('toggle')"
            >
                <component
                    :is="collapsed ? PanelLeftOpen : PanelLeftClose"
                    :size="18"
                    class="shrink-0"
                    aria-hidden="true"
                />
                <span v-if="!iconsOnly">Fold menu</span>
            </button>
        </div>
    </aside>
</template>
