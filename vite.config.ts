import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { defineConfig } from 'vite';
import { nodeModulesFsAllow } from './build/node-modules-fs-allow';
import { pdfjsAssets } from './build/pdfjs-assets';

const heavyVendorChunkGroups: Array<[string, string[]]> = [
    ['vendor-inertia', ['/@inertiajs/']],
    // Anchored to /node_modules/react/ so packages that merely contain
    // "/react/" in their path (e.g. @fullcalendar/react) don't get pulled
    // into the always-loaded react chunk and drag their whole vendor
    // family onto every page.
    [
        'vendor-react',
        [
            '/node_modules/react/',
            '/node_modules/react-dom/',
            '/node_modules/scheduler/',
        ],
    ],
    [
        'vendor-ui',
        ['/@radix-ui/', '/@headlessui/', '/cmdk/', '/input-otp/', '/sonner/'],
    ],
    [
        'vendor-utils',
        ['/class-variance-authority/', '/clsx/', '/tailwind-merge/'],
    ],
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
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        cors: true,
        // Worktrees only: also allow the parent checkout's node_modules
        // (undefined — Vite's default — everywhere else).
        fs: { allow: nodeModulesFsAllow(__dirname) },
    },
    build: {
        // Keep immutable hashed assets for pages opened before a rebuild.
        // Do not prune them during deploy: old tabs still import those URLs.
        emptyOutDir: false,
        // NOTE: modulePreload must stay ON (the default). Disabling it made
        // the browser discover each page's ~100-500 chunks one at a time in
        // a serial waterfall instead of preloading them in parallel.
        rollupOptions: {
            output: {
                manualChunks,
            },
        },
    },
    optimizeDeps: {
        exclude: ['pdfjs-dist'],
        // Pre-bundle the core deps so a dev-server cold start doesn't hit
        // "new dependencies optimized" full-page reloads while navigating.
        include: [
            'react',
            'react-dom',
            'react-dom/client',
            '@inertiajs/react',
            'lucide-react',
            'clsx',
            'tailwind-merge',
            'class-variance-authority',
        ],
    },
    plugins: [
        pdfjsAssets(),
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
            patterns: ['routes/**/*.php'],
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});
