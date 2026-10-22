import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
const root = fileURLToPath(new URL('.', import.meta.url));
const repo = fileURLToPath(new URL('../../../', import.meta.url));
export default defineConfig({
    root,
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': repo + 'resources/js' } },
    server: {
        host: '127.0.0.1',
        port: 5186,
        strictPort: true,
        fs: { allow: [repo] },
    },
    build: { outDir: 'dist', emptyOutDir: true },
});
