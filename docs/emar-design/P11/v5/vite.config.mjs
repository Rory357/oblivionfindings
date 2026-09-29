// P11 v2 design preview — built the same way as the Fleet previews (PKG-02B v13):
// `@` → resources/js so every primitive is the app's real component, and the
// Tailwind plugin so resources/css/app.css tokens apply. Synthetic data only.
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
    resolve: { alias: { '@': path.join(repo, 'resources/js') }, dedupe: ['react', 'react-dom'] },
    build: { outDir: path.join(root, 'dist'), emptyOutDir: true, chunkSizeWarningLimit: 4000 },
});
