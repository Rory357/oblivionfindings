import Footer from '@/components/Footer';
import Header from '@/components/Header';
import { usePublicSite, type SiteNavKey } from '@/components/public-site/site';
import { Head } from '@inertiajs/react';
import { useRef, type ReactNode } from 'react';

type PublicSiteLayoutProps = {
    children: ReactNode;
    /** Page name for the browser tab: "<title> · <provider>". */
    title?: string;
    description?: string;
    /** The page intro inside the ink band (PageIntro, or the home hero). */
    intro?: ReactNode;
    /** Marks a nav item current; defaults to the one the URL belongs to. */
    current?: SiteNavKey | null;
};

/**
 * The public website shell (client demo site, mockup v2, approved 23 Oct
 * 2026): skip link, the ink band (site header + page intro), main, and the
 * site footer. Every public page uses it — the provider pages, Make a
 * referral, secure upload and Careers — so the site has one header and one
 * footer. It speaks as the provider and never falls back to "Oblivion Care".
 */
export default function PublicSiteLayout({
    children,
    title,
    description = 'Supported living, shaped around you. Share your goals and get help with funding and the next steps towards support.',
    intro,
    current,
}: PublicSiteLayoutProps) {
    const main = useRef<HTMLElement>(null);
    const { name } = usePublicSite();
    const fullTitle =
        [title, name].filter(Boolean).join(' · ') || 'Supported living';

    return (
        <div className="journey-site">
            <Head>
                <title>{fullTitle}</title>
                <meta name="description" content={description} />
                <meta property="og:title" content={fullTitle} />
                <meta property="og:description" content={description} />
                <meta property="og:type" content="website" />
            </Head>
            {/* eslint-disable-next-line no-restricted-syntax -- skip link: moves focus to <main> without changing the URL */}
            <button
                type="button"
                className="journey-site-skip"
                onClick={() => main.current?.focus()}
            >
                Skip to main content
            </button>
            <div className="journey-site-ink relative">
                <Header current={current} />
                {intro}
            </div>
            <main
                ref={main}
                id="main-content"
                tabIndex={-1}
                className="outline-none"
            >
                {children}
            </main>
            <Footer />
        </div>
    );
}

export { PublicSiteLayout };
