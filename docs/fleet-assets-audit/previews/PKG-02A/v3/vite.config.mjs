import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
const root = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(root, '../../../../..');
export default defineConfig({
    root,
    cacheDir: path.join(root, '.vite'),
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: { '@': path.join(repo, 'resources/js') },
        dedupe: ['react', 'react-dom'],
    },
    server: {
        host: '127.0.0.1',
        port: 4334,
        strictPort: true,
        fs: {
            allow: [repo, 'C:/Users/steph/Herd/oblivionfindings/node_modules'],
        },
        headers: {
            'Cache-Control': 'no-store',
            'X-PKG-Preview': 'PKG-02A-v3-2b9f',
        },
    },
    build: { outDir: path.join(root, 'dist'), emptyOutDir: true },
});


