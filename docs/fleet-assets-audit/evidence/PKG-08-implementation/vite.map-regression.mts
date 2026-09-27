import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vite';

// Separate test-only server. Never substitute a fake SDK in the application preview.
export default defineConfig({
    root: process.cwd(),
    cacheDir: path.resolve('storage/framework/pkg08-map-regression'),
    resolve: { alias: { '@': path.resolve('resources/js') } },
    server: { host: '127.0.0.1', port: 5195, strictPort: true },
    plugins: [
        {
            name: 'pkg08-synthetic-map-sdk',
            enforce: 'pre',
            resolveId(source, importer) {
                if (
                    source === './google-sdk' &&
                    importer
                        ?.replaceAll('\\', '/')
                        .endsWith('/maps/google-map.tsx')
                ) {
                    return path.resolve(
                        'docs/fleet-assets-audit/evidence/PKG-08-implementation/map-sdk-fixture.ts',
                    );
                }
            },
        },
        react(),
        tailwindcss(),
    ],
});
