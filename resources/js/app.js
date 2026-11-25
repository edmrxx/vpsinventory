import '../css/app.css'

import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import swal, { sessionExpired, toastError } from './lib/swal'

const appName = import.meta.env.VITE_APP_NAME || 'MAF Inventory'

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue')
        const page = pages[`./Pages/${name}.vue`]

        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}.vue`)
        }

        return page()
    },

    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)

        // Charts are heavy, so the plugin is registered on demand by the
        // pages that use it (see resources/js/lib/charts.js).
        app.config.globalProperties.$swal = swal

        app.mount(el)
    },

    progress: {
        color: '#6d4aff',
        delay: 120,
        showSpinner: false,
    },
})

/* -------------------------------------------------------------------------- */
/* Global request failures                                                    */
/* -------------------------------------------------------------------------- */

// A dead session returns 419. The server turns that into a redirect with a
// `sessionExpired` flag (see bootstrap/app.php), but a plain XHR can still
// fail outright, so catch it here too.
router.on('invalid', (event) => {
    const status = event.detail.response?.status

    if (status === 419 || status === 401) {
        event.preventDefault()
        sessionExpired()
    }
})

router.on('exception', (event) => {
    // Let Inertia report real errors in development.
    if (import.meta.env.DEV) return

    event.preventDefault()
    toastError('Something went wrong. Please try again.')
})
