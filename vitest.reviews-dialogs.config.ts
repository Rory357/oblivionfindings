import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vitest/config';
import { nodeModulesFsAllow } from './build/node-modules-fs-allow';

export default defineConfig({
    plugins: [
        // Match vite.config.ts: the regression only appears after compilation.
        react({
            babel: { plugins: ['babel-plugin-react-compiler'] },
        }),
        {
            name: 'verify-review-dialog-react-compiler',
            enforce: 'post',
            transform(code, id) {
                const source = id.split('?')[0].replace(/\\/g, '/');
                if (
                    source.endsWith('/resources/js/pages/emar/reviews/_review-dialogs.tsx') &&
                    !code.includes('compiler-runtime')
                ) {
                    throw new Error(
                        'Review dialog regression must run with the production Babel React compiler.',
                    );
                }
                return null;
            },
        },
    ],
    resolve: {
        alias: { '@': path.resolve(__dirname, 'resources/js') },
    },
    server: { fs: { allow: nodeModulesFsAllow(__dirname) } },
    esbuild: { jsx: 'automatic' },
    test: {
        name: 'p05-review-dialogs-compiled',
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/pages/emar/reviews/dialogs.render.test.tsx'],
        setupFiles: ['./resources/js/test/setup.ts'],
        maxWorkers: 1,
    },
});
