import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    build: {
        /*
         * Vendor code changes far less often than the app's own code, so it
         * is split into its own files. A change to a page then only makes the
         * browser fetch that page again, not the whole framework.
         *
         * ApexCharts is left out on purpose: it is imported on demand in
         * resources/js/lib/charts.js, so it already lands in its own chunk
         * and never reaches the login page.
         */
        rollupOptions: {
            output: {
                manualChunks: {
                    vue: ['vue', '@inertiajs/vue3'],
                    alerts: ['sweetalert2'],
                },
            },
        },
        // ApexCharts alone is larger than this, and it is deliberately
        // separate, so the warning would only be noise.
        chunkSizeWarningLimit: 1024,
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
