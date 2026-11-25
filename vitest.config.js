import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

/**
 * Tests for the front end.
 *
 * Kept apart from vite.config.js on purpose: that one is built around the
 * Laravel plugin, which wants a running app and a manifest. A test run wants
 * neither, so it gets its own, with only the Vue compiler and a fake browser.
 *
 *     npm run test:js
 *
 * Most of the app is tested from the server with PHPUnit, which is cheaper
 * and covers more. This is for behaviour PHPUnit cannot reach: typing, arrow
 * keys and clicking away. See README section 10.
 */
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        setupFiles: ['tests/js/setup.js'],
        include: ['tests/js/**/*.test.js'],
        restoreMocks: true,
    },
})
