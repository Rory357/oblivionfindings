// eMAR P01 v2 design preview — built the same way as the Fleet previews
// (docs/fleet-assets-audit/previews/PKG-02B/v13): `@` → resources/js so every
// primitive is the app's REAL component, and the Tailwind plugin so
// resources/css/app.css tokens apply. Synthetic data only.
//
// One addition over the Fleet scaffold: `@inertiajs/react` resolves to a
// local, read-only shim (src/inertia-shim.tsx). The real components keep
// their own code; the shim only turns Link/router visits into this preview's
// hash routes and supplies synthetic page props, so shared pieces that import
// Inertia (Breadcrumbs, LaravelPagination, SiteCalendar, meter links) render
// as they do in the app without an application server.
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

const root = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(root, '../../../..');

export default defineConfig({
    root,
    base: './',
    cacheDir: path.join(root, '.vite'),
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: [
            { find: /^@inertiajs\/react$/, replacement: path.join(root, 'src/inertia-shim.tsx') },
            { find: /^@\//, replacement: path.join(repo, 'resources/js') + '/' },
        ],
        dedupe: ['react', 'react-dom'],
    },
    build: { outDir: path.join(root, 'dist'), emptyOutDir: true, chunkSizeWarningLimit: 6000 },
});
