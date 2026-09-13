import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import path from 'node:path';

// Isolated verification assets. No Wayfinder generation or shared hot/build writes.
export default defineConfig({
    resolve: { alias: { '@': path.resolve(process.cwd(), 'resources/js') } },
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], buildDirectory: 'ticket-workflow-review' }), react(), tailwindcss()],
    build: { outDir: 'public/ticket-workflow-review', emptyOutDir: true },
});
