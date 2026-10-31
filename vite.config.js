import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Fonts are not loaded from third-party CDNs (CLAUDE.md §7: default deny for external transmission).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Inside the `node` container: listen on all interfaces; the browser reaches it via localhost:5173.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        watch: {
            // File events do not cross the Windows drive mount into WSL, so poll when asked (compose sets VITE_POLL=1).
            usePolling: process.env.VITE_POLL === '1',
            interval: 500,
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});
