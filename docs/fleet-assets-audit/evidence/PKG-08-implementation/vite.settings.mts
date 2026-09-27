import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';
export default defineConfig({
    root: process.cwd(),
    cacheDir: path.resolve('storage/framework/pkg08-vite'),
    resolve: { alias: { '@': path.resolve('resources/js') } },
    server: { host: '127.0.0.1', port: 5194, strictPort: true, cors: { origin: 'http://127.0.0.1:8794' }, hmr: { host: '127.0.0.1' } },
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: false, detectTls: false }), react(), tailwindcss()],
});
