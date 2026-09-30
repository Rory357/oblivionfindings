// eMAR P07b v1 design preview — the P01 v1 scaffold (itself the Fleet
// PKG-02B v13 scaffold): `@` → resources/js so every primitive is the app's
// REAL component, and the Tailwind plugin so resources/css/app.css tokens
// apply. Synthetic data only.
//
// As in P01, `@inertiajs/react` resolves to a local, read-only shim
// (src/inertia-shim.tsx, copied unchanged from P01 v1) so shared pieces that
// import Inertia (Breadcrumbs, LaravelPagination, meter links) render without
// an application server.
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
