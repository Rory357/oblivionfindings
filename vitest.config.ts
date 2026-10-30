import path from 'node:path';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { nodeModulesFsAllow } from './build/node-modules-fs-allow';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    // Worktrees only: lets `?url` imports (the pdf.js worker) load from the
    // parent checkout's node_modules; undefined keeps Vite's default.
    server: {
        fs: { allow: nodeModulesFsAllow(__dirname) },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['./resources/js/test/setup.ts'],
    },
});
