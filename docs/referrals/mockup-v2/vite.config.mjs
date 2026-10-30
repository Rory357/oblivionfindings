import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// Referrals mockup v2 — the client demo website. Real app components
// (`@` = resources/js) and the real tokens (resources/css/app.css); fictional
// fixtures only, no application requests.
const root = fileURLToPath(new URL('.', import.meta.url));
const repo = fileURLToPath(new URL('../../../', import.meta.url));
// The package's export map hides its ESM file, so alias it by path for the shim.
const inertiaEsm = createRequire(import.meta.url)
    .resolve('@inertiajs/react')
    .replace(/index\.js$/, 'index.esm.js');

export default defineConfig({
    root,
    base: './',
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: [
            { find: /^@inertiajs\/react\/real$/, replacement: inertiaEsm },
            {
                find: /^@inertiajs\/react$/,
                replacement: root + 'inertia-shim.ts',
            },
            // The sign-in layout with the client's hero photo (Stephan,
            // 21 Oct): the real login pages render inside this mockup copy.
            {
                find: /^@\/layouts\/auth\/auth-journey-layout$/,
                replacement: root + 'auth-layout-shim.tsx',
            },
            { find: '@', replacement: repo + 'resources/js' },
        ],
    },
    server: {
        host: '127.0.0.1',
        port: 5189,
        strictPort: true,
        fs: {
            allow: [repo, inertiaEsm.replace(/dist[\\/]index\.esm\.js$/, '')],
        },
    },
    build: { outDir: 'dist', emptyOutDir: true },
});
