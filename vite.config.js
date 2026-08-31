import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        // SEMENTARA: minifier native (rolldown/esbuild) crash "Bus error" di
        // environment ini. Dimatikan dulu sampai diinvestigasi.
        // TODO: cari akar masalah minifier atau upgrade Vite.
        minify: false,
    },
});
