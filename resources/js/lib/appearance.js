/**
 * The light or dark screen (phase 35.1, CR-3).
 *
 * The server writes the choice onto <html> on a full page load, and the
 * script in the page head resolves "Same as my device". Signing in and out
 * are Inertia visits, though, which never reload <html> — so after every
 * visit `syncAppearance()` puts the shared `appearance` prop back on it:
 * a dark user is dark the moment they sign in, and the sign-in page is light
 * again the moment they sign out.
 *
 * `isDark` is the one reactive answer for code that draws in colour by
 * itself (the charts). CSS needs nothing from here: it reads data-theme.
 */
import { ref } from 'vue'

export const APPEARANCES = ['light', 'dark', 'system']

/** True while the screen is dark. */
export const isDark = ref(false)

let deviceQuery = null

function deviceIsDark() {
    try {
        deviceQuery ??= window.matchMedia('(prefers-color-scheme: dark)')

        return deviceQuery.matches
    } catch {
        return false
    }
}

/**
 * What a choice comes to on this device: 'light' or 'dark'.
 *
 * @param {string|null|undefined} appearance  light, dark, system or null.
 * @param {boolean} deviceDark  Whether the device is set to dark.
 */
export function resolveTheme(appearance, deviceDark) {
    if (appearance === 'dark') return 'dark'
    if (appearance === 'system') return deviceDark ? 'dark' : 'light'

    return 'light'
}

/**
 * Put a choice on <html>. Null (a guest) takes both attributes away, so
 * the sign-in pages are always light.
 *
 * @param {string|null|undefined} appearance
 */
export function applyAppearance(appearance) {
    if (typeof document === 'undefined') return

    const root = document.documentElement

    if (!APPEARANCES.includes(appearance)) {
        delete root.dataset.appearance
        delete root.dataset.theme
        isDark.value = false

        return
    }

    const theme = resolveTheme(appearance, deviceIsDark())

    root.dataset.appearance = appearance
    root.dataset.theme = theme
    isDark.value = theme === 'dark'
}

/**
 * Called once at start and after every visit with the page's props.
 *
 * @param {{appearance?: string|null}|undefined} props
 */
export function syncAppearance(props) {
    applyAppearance(props?.appearance ?? null)
}

/**
 * Follow the device while "Same as my device" is chosen. The head script
 * does this before Vue starts; this keeps `isDark` in step afterwards.
 */
export function watchDevice() {
    try {
        deviceQuery ??= window.matchMedia('(prefers-color-scheme: dark)')
        deviceQuery.addEventListener('change', () => {
            const root = document.documentElement

            if (root.dataset.appearance === 'system') {
                applyAppearance('system')
            }
        })
    } catch {
        // No matchMedia (an old browser or a test): the choice still applies.
    }
}
