import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // One entry per app. The till ships first; the dashboard and the
            // platform panel are added here as they arrive.
            input: [
                'resources/css/app.css',
                'resources/js/till/main.js',
                'resources/js/dashboard/main.js',
            ],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        // Vite runs on the host while Laravel runs in a container, so the dev
        // server has to be reachable from outside it.
        host: '0.0.0.0',
        hmr: { host: 'localhost' },
    },
});
