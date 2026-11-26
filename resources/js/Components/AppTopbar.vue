<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Bell, ChevronDown, CircleCheck, LogOut, Menu, UserCog } from '@lucide/vue'
import { confirmLogout } from '../lib/swal'
import AppearanceSwitch from './AppearanceSwitch.vue'
import { usePermissions } from '../composables/usePermissions'
import { navigation } from '../navigation'

defineProps({
    title: { type: String, default: '' },
})

const emit = defineEmits(['toggle-sidebar'])

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const { primaryRole } = usePermissions()

const menuOpen = ref(false)
const menuRef = ref(null)

/*
 * The bell: what waits on this person (24 November 2026) — the same counts
 * as the red badges on the sidebar, worked out by `SidebarBadges` on the
 * server, each with its words and a link to the list narrowed to exactly
 * those. The red mark shows only while something waits.
 */
const bellOpen = ref(false)
const bellRef = ref(null)

const notifications = computed(() => page.props.notifications ?? [])
const waitingTotal = computed(() => notifications.value.reduce((sum, item) => sum + Number(item.count || 0), 0))

/** Each item's icon and menu name, from the sidebar entry with the same `badge`. */
const menuItems = Object.fromEntries(
    navigation.flatMap((section) => section.items).filter((item) => item.badge).map((item) => [item.badge, item])
)

const onDocumentClick = (event) => {
    if (menuRef.value && !menuRef.value.contains(event.target)) {
        menuOpen.value = false
    }

    if (bellRef.value && !bellRef.value.contains(event.target)) {
        bellOpen.value = false
    }
}

const onEscape = (event) => {
    if (event.key === 'Escape') {
        menuOpen.value = false
        bellOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick)
    document.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('keydown', onEscape)
})

async function logout() {
    menuOpen.value = false
    await confirmLogout()
}
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-(--topbar-h) items-center gap-3 border-b border-line bg-surface/85 px-4 backdrop-blur-md sm:px-6"
    >
        <button
            type="button"
            class="grid size-9 shrink-0 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink lg:hidden"
            aria-label="Open menu"
            @click="emit('toggle-sidebar')"
        >
            <Menu :size="20" />
        </button>

        <h1 v-if="title" class="truncate text-base font-semibold text-ink">
            {{ title }}
        </h1>

        <!-- The light or dark screen (phase 35.1), where the search box was —
             that box searched nothing (26 November 2026, on the user's GO!).
             Icons only on a phone. -->
        <AppearanceSwitch class="ml-auto shrink-0" />

        <!-- What waits on this person: the same counts as the sidebar badges. -->
        <div ref="bellRef" class="relative shrink-0">
            <button
                type="button"
                class="relative grid size-9 place-items-center rounded-field text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
                :class="bellOpen ? 'bg-surface-sunken text-ink' : ''"
                :aria-label="waitingTotal > 0 ? `Notifications: ${waitingTotal} waiting` : 'Notifications'"
                :aria-expanded="bellOpen"
                aria-haspopup="true"
                data-bell
                @click="bellOpen = !bellOpen"
            >
                <Bell :size="18" />
                <span
                    v-if="waitingTotal > 0"
                    class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-danger-500 px-1 text-center text-[10px] leading-4 font-bold text-ink-inverse tabular-nums ring-2 ring-surface"
                    data-bell-count
                >
                    {{ waitingTotal > 99 ? '99+' : waitingTotal }}
                </span>
            </button>

            <Transition
                enter-active-class="transition duration-150 ease-[var(--ease-out-quint)]"
                leave-active-class="transition duration-100"
                enter-from-class="opacity-0 -translate-y-1 scale-98"
                leave-to-class="opacity-0 -translate-y-1 scale-98"
            >
                <div
                    v-if="bellOpen"
                    class="absolute right-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] origin-top-right rounded-card border border-line bg-surface p-1.5 shadow-popover"
                    data-bell-panel
                >
                    <p class="border-b border-line px-3 py-2.5 text-sm font-semibold text-ink">Waiting for you</p>

                    <div v-if="notifications.length === 0" class="flex flex-col items-center gap-2 px-3 py-8 text-center">
                        <CircleCheck :size="22" class="text-success-600" />
                        <p class="text-sm text-ink-muted">Nothing is waiting for you.</p>
                    </div>

                    <ul v-else class="mt-1 space-y-0.5">
                        <li v-for="item in notifications" :key="item.key">
                            <Link
                                :href="item.href"
                                class="flex items-start gap-3 rounded-field px-3 py-2.5 transition-colors hover:bg-surface-sunken"
                                data-bell-item
                                @click="bellOpen = false"
                            >
                                <component
                                    :is="menuItems[item.key]?.icon ?? Bell"
                                    :size="17"
                                    class="mt-0.5 shrink-0 text-ink-subtle"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-ink">{{ menuItems[item.key]?.label ?? item.key }}</span>
                                    <span class="block text-xs text-ink-muted">{{ item.count }} {{ item.count === 1 ? item.text[0] : item.text[1] }}</span>
                                </span>
                                <span class="shrink-0 rounded-full bg-danger-500 px-1.5 py-0.5 text-2xs leading-none font-bold text-ink-inverse tabular-nums">
                                    {{ item.count > 99 ? '99+' : item.count }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </Transition>
        </div>

        <!-- Account menu -->
        <div ref="menuRef" class="relative shrink-0">
            <button
                type="button"
                class="flex items-center gap-2.5 rounded-field py-1.5 pr-2 pl-1.5 transition-colors hover:bg-surface-sunken"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="menuOpen = !menuOpen"
            >
                <span
                    class="grid size-8 place-items-center rounded-full bg-brand-600 text-2xs font-bold text-ink-inverse"
                >
                    {{ user?.initials ?? '—' }}
                </span>
                <span class="hidden flex-col items-start leading-tight sm:flex">
                    <span class="text-sm font-medium text-ink">{{ user?.name }}</span>
                    <span class="text-2xs text-ink-subtle">@{{ user?.username }}</span>
                </span>
                <ChevronDown
                    :size="15"
                    class="text-ink-subtle transition-transform duration-200"
                    :class="menuOpen ? 'rotate-180' : ''"
                />
            </button>

            <Transition
                enter-active-class="transition duration-150 ease-[var(--ease-out-quint)]"
                leave-active-class="transition duration-100"
                enter-from-class="opacity-0 -translate-y-1 scale-98"
                leave-to-class="opacity-0 -translate-y-1 scale-98"
            >
                <div
                    v-if="menuOpen"
                    class="absolute right-0 mt-2 w-56 origin-top-right rounded-card border border-line bg-surface p-1.5 shadow-popover"
                    role="menu"
                >
                    <div class="border-b border-line px-3 py-2.5">
                        <p class="truncate text-sm font-medium text-ink">
                            {{ user?.name }}
                        </p>
                        <p class="truncate text-xs text-ink-subtle capitalize">
                            {{ primaryRole ?? 'No role' }}
                        </p>
                    </div>


                    <button
                        type="button"
                        class="mt-1.5 flex w-full cursor-not-allowed items-center gap-2.5 rounded-field px-3 py-2 text-sm text-ink-subtle"
                        role="menuitem"
                        disabled
                    >
                        <UserCog :size="16" />
                        Profile
                        <span class="ml-auto text-2xs uppercase">Soon</span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center gap-2.5 rounded-field px-3 py-2 text-sm font-medium text-danger-700 transition-colors hover:bg-danger-50"
                        role="menuitem"
                        @click="logout"
                    >
                        <LogOut :size="16" />
                        Log out
                    </button>
                </div>
            </Transition>
        </div>
    </header>
</template>
