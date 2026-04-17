import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createElement, StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import FlashToaster from './components/flash-toaster';
import OfflineStatusBanner from './components/offline-status-banner';
import { initializeTheme } from './hooks/use-appearance';
import { bootEmarOffline } from './lib/emar-offline';
import { bootOfflineQueue } from './lib/offline-queue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function renderInertiaPage(Component: any, pageProps: Record<string, unknown>, key: number | null) {
    const page = <Component key={key} {...pageProps} />;

    if (typeof Component.layout === 'function') {
        return Component.layout(page);
    }

    if (Array.isArray(Component.layout)) {
        return Component.layout
            .concat(page)
            .reverse()
            .reduce((children: unknown, Layout: any) => createElement(Layout, { children, ...pageProps }));
    }

    return page;
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        bootEmarOffline();
        bootOfflineQueue();
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props}>
                    {({ Component, props: pageProps, key }) => (
                        <>
                            <OfflineStatusBanner />
                            {renderInertiaPage(Component, pageProps, key)}
                            <FlashToaster />
                            <Toaster richColors position="top-right" />
                        </>
                    )}
                </App>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
