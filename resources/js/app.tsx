import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import FlashToaster from './components/flash-toaster';
import { initializeTheme } from './hooks/use-appearance';
import { bootEmarOffline } from './lib/emar-offline';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        bootEmarOffline();
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <>
                    <App {...props} />
                    <FlashToaster />
                    <Toaster richColors position="top-right" />
                </>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
