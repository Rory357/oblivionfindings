import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createElement, StrictMode, type CSSProperties } from 'react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import FlashToaster from './components/flash-toaster';
import OfflineStatusBanner from './components/offline-status-banner';
import { initializeAppearance } from './hooks/use-appearance';
import { resolveInertiaPage } from './inertia-pages';
import { bootEmarOffline } from './lib/emar-offline';
import { bootOfflineQueue, setOfflineQueueActor } from './lib/offline-queue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function renderInertiaPage(
    Component: any,
    pageProps: Record<string, unknown>,
    key: number | null,
) {
    const page = <Component key={key} {...pageProps} />;

    if (typeof Component.layout === 'function') {
        return Component.layout(page);
    }

    if (Array.isArray(Component.layout)) {
        return Component.layout
            .concat(page)
            .reverse()
            .reduce((children: unknown, Layout: any) =>
                createElement(Layout, { children, ...pageProps }),
            );
    }

    return page;
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: resolveInertiaPage,
    setup({ el, App, props }) {
        const initialPageProps = props.initialPage.props as {
            appearance?: Parameters<typeof initializeAppearance>[0];
            auth?: { user?: { id?: number | string } | null };
        };
        initializeAppearance(initialPageProps.appearance);
        setOfflineQueueActor(initialPageProps.auth?.user?.id);
        bootEmarOffline();
        bootOfflineQueue();
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props}>
                    {({ Component, props: pageProps, key }) => {
                        const auth = (
                            pageProps as {
                                auth?: {
                                    user?: { id?: number | string } | null;
                                };
                            }
                        ).auth;
                        setOfflineQueueActor(auth?.user?.id);

                        return (
                            <>
                                <OfflineStatusBanner />
                                {renderInertiaPage(Component, pageProps, key)}
                                <FlashToaster />
                                <Toaster
                                    richColors
                                    position="top-right"
                                    style={
                                        {
                                            '--success-text':
                                                'hsl(140, 100%, 18%)',
                                        } as CSSProperties
                                    }
                                />
                            </>
                        );
                    }}
                </App>
            </StrictMode>,
        );
    },
    progress: {
        delay: 100,
        color: '#7c3aed',
        includeCSS: true,
        showSpinner: false,
    },
});
