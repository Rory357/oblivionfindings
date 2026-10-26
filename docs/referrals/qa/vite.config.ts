import laravel from 'laravel-vite-plugin';
import { defineConfig, mergeConfig } from 'vite';
import main from '../../../vite.config';

// Keep the main React/Tailwind/PDF configuration; replace only the runtime entry.
// Wayfinder-generated routes already live in this checkout.
const plugins = main.plugins ?? [];
main.plugins = [
    {
        name: 'referral-preview-style-sources',
        enforce: 'pre',
        transform(code, id) {
            if (id.replaceAll('\\', '/').endsWith('/resources/css/app.css')) {
                // All application UI sources live under resources. Do not scan
                // archived mockup bundles and diagnostic output for class names.
                return code.replace(
                    "@import 'tailwindcss';",
                    "@import 'tailwindcss' source('../');",
                );
            }
        },
    },
    plugins[0],
    laravel({
        input: ['resources/css/app.css', 'resources/js/app.tsx'],
        hotFile: 'storage/referrals-preview.hot',
        detectTls: false,
        refresh: false,
    }),
    plugins[2],
    plugins[3],
];

// Isolated preview: never change public/hot or the normal application build.
const preview = mergeConfig(
    main,
    defineConfig({
        cacheDir: 'node_modules/.vite-referrals-preview',
        optimizeDeps: {
            noDiscovery: true,
            include: [
                'axios',
                'leaflet',
                'recharts',
                'sonner',
                'use-sync-external-store/shim',
                'use-sync-external-store/shim/with-selector',
                'use-sync-external-store/with-selector',
                '@radix-ui/react-dialog',
                '@radix-ui/react-alert-dialog',
                '@radix-ui/react-dropdown-menu',
                '@radix-ui/react-select',
                '@radix-ui/react-checkbox',
                '@radix-ui/react-slot',
                '@radix-ui/react-label',
                '@radix-ui/react-accordion',
                '@radix-ui/react-popover',
                '@radix-ui/react-tooltip',
            ],
        },
        server: {
            host: '127.0.0.1',
            port: 5187,
            strictPort: true,
            https: undefined,
            // The application checkout also holds large collector and test-output trees.
            // Browser QA reloads explicitly; it does not need a recursive file watcher.
            watch: null,
            hmr: false,
        },
    }),
);

// mergeConfig ignores null overrides. Set the documented disable value afterwards.
preview.server!.watch = null;
export default preview;
