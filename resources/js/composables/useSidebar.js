import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const COOKIE = 'sidebar_collapsed'
const ONE_YEAR = 60 * 60 * 24 * 365

/**
 * Sidebar state.
 *
 * On a wide screen the sidebar is always visible and can fold to icons only.
 * The folded state is kept in a cookie, so the server knows it on the next
 * page load and the layout does not flash open.
 *
 * On a small screen the sidebar slides over the page instead, and it closes
 * by itself after the user opens a page.
 */
export function useSidebar() {
    const page = usePage()

    const collapsed = ref(page.props.sidebarCollapsed === true)
    const mobileOpen = ref(false)
    const isDesktop = ref(true)

    let media = null

    const syncMedia = (event) => {
        isDesktop.value = event.matches
        if (event.matches) mobileOpen.value = false
    }

    onMounted(() => {
        media = window.matchMedia('(min-width: 1024px)')
        isDesktop.value = media.matches
        media.addEventListener('change', syncMedia)
    })

    onBeforeUnmount(() => {
        media?.removeEventListener('change', syncMedia)
    })

    // Stop the page behind the drawer from scrolling.
    watch(mobileOpen, (open) => {
        if (typeof document === 'undefined') return
        document.body.style.overflow = open ? 'hidden' : ''
    })

    onBeforeUnmount(() => {
        if (typeof document !== 'undefined') document.body.style.overflow = ''
    })

    // Close the drawer once the next page starts to load.
    const stopListening = router.on('start', () => {
        mobileOpen.value = false
    })

    onBeforeUnmount(() => stopListening())

    watch(collapsed, (value) => {
        if (typeof document === 'undefined') return

        document.cookie = `${COOKIE}=${value ? '1' : '0'};path=/;max-age=${ONE_YEAR};SameSite=Lax`
        document.documentElement.dataset.sidebar = value ? 'collapsed' : 'expanded'
    })

    function toggle() {
        if (isDesktop.value) {
            collapsed.value = !collapsed.value
        } else {
            mobileOpen.value = !mobileOpen.value
        }
    }

    return {
        collapsed,
        mobileOpen,
        isDesktop,
        toggle,
        closeMobile: () => (mobileOpen.value = false),
        /** True when only the icons show. */
        iconsOnly: computed(() => isDesktop.value && collapsed.value),
    }
}
