import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

const heavyVendorChunkGroups: Array<[string, string[]]> = [
    ['vendor-calendar', ['/@fullcalendar/', '/preact/']],
    ['vendor-charts', ['/recharts/', '/d3-']],
    ['vendor-maps', ['/leaflet/', '/react-leaflet/']],
];

function manualChunks(id: string) {
    const normalizedId = id.replace(/\\/g, '/');

    if (!normalizedId.includes('/node_modules/')) {
        return;
    }

    for (const [chunkName, packageMatchers] of heavyVendorChunkGroups) {
        if (packageMatchers.some((matcher) => normalizedId.includes(matcher))) {
            return chunkName;
        }
    }
}

export default defineConfig({
    server: {
        cors: true,
    },
    build: {
        modulePreload: false,
        rollupOptions: {
            output: {
                manualChunks,
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            detectTls: 'oblivionfindings.test',
        }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});
