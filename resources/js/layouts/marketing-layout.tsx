import Footer from '@/components/Footer';
import Header from '@/components/Header';
import { type PublicProvider } from '@/components/public-provider';
import { Head, usePage } from '@inertiajs/react';
import React from 'react';

interface MarketingLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const MarketingLayout: React.FC<MarketingLayoutProps> = ({
    children,
    title,
    description = 'Explore supported living, share your goals and get help understanding funding and the next steps towards support.',
}) => {
    const provider = usePage().props.provider as PublicProvider | undefined;
    const name = provider?.name ?? 'Oblivion Care';
    const fullTitle = title
        ? `${title} · ${name}`
        : `${name} · Supported living`;

    return (
        <>
            <Head>
                <title>{fullTitle}</title>
                <meta name="description" content={description} />
                <meta property="og:title" content={fullTitle} />
                <meta property="og:description" content={description} />
                <meta property="og:type" content="website" />
            </Head>

            <div className="min-h-screen bg-background text-foreground">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-background focus:p-3 focus:ring-2 focus:ring-ring"
                >
                    Skip to content
                </a>
                {/* Gradient background - subtle in light, more visible in dark */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none fixed inset-0 -z-10 overflow-hidden"
                >
                    <div className="absolute inset-0 bg-gradient-to-b from-background via-muted/30 to-background" />
                    <div className="absolute top-0 left-1/4 h-[500px] w-[500px] rounded-full bg-primary/5 blur-3xl dark:bg-primary/5" />
                </div>

                {/* Page container */}
                <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                    {/* Top nav */}
                    <Header name={name} />

                    {/* Main content */}
                    <main
                        id="main-content"
                        tabIndex={-1}
                        className="mt-8 sm:mt-12"
                    >
                        {children}
                    </main>

                    {/* Footer */}
                    <Footer provider={provider} />
                </div>
            </div>
        </>
    );
};

export default MarketingLayout;
