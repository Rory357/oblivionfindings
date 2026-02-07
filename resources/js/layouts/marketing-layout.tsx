import Footer from '@/components/Footer';
import Header from '@/components/Header';
import { Head } from '@inertiajs/react';
import React from 'react';

interface MarketingLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

const MarketingLayout: React.FC<MarketingLayoutProps> = ({
    children,
    title,
    description = 'Oblivion Findings helps supported living providers manage residents, visits, notes, and compliance in one simple, web-based dashboard.',
}) => {
    const fullTitle = title
        ? `${title} · Oblivion Findings`
        : 'Oblivion Findings · Supported Living Platform';

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
                {/* Gradient background - subtle in light, more visible in dark */}
                <div className="pointer-events-none fixed inset-0 -z-10">
                    <div className="absolute inset-0 bg-gradient-to-b from-background via-muted/30 to-background" />
                    <div className="absolute top-0 left-1/4 h-[500px] w-[500px] rounded-full bg-primary/5 blur-3xl dark:bg-primary/5" />
                    <div className="absolute right-1/4 bottom-0 h-[400px] w-[400px] rounded-full bg-emerald-500/5 blur-3xl dark:bg-emerald-500/5" />
                </div>

                {/* Page container */}
                <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                    {/* Top nav */}
                    <Header />

                    {/* Main content */}
                    <main className="mt-8 sm:mt-12">{children}</main>

                    {/* Footer */}
                    <Footer />
                </div>
            </div>
        </>
    );
};

export default MarketingLayout;
