// Referrals mockup v2 — the client demo website ("Oblivion Living").
// Hash routes; real app components; fictional data; no requests.
import { useCustomBrandName } from '@/components/brand/brand-lockup';
import { setMockLinkFallback } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { SignInPage, UploadPage } from './page-access';
import { ApplicationStatusPage, CareersPage, JobPage } from './page-careers';
import { ContactPage } from './page-contact';
import { HomePage } from './page-home';
import { AboutPage, FundingPage, LegalPage, SupportPage } from './page-info';
import { ReferralPage } from './page-referral';
import { ReviewBar, showReviewNotice, type HowKey } from './review';
import './styles.css';

type Resolved = { title: string; how: HowKey; page: ReactNode };

function resolve(hash: string): Resolved {
    const [page = 'home', sub, extra] = hash
        .replace(/^#\/?/, '')
        .split('/')
        .filter(Boolean);
    switch (page) {
        case 'support':
            return {
                title: 'Our support',
                how: 'content',
                page: <SupportPage focus={sub} />,
            };
        case 'funding':
            return {
                title: 'Funding help',
                how: 'funding',
                page: <FundingPage />,
            };
        case 'about':
            return { title: 'About us', how: 'content', page: <AboutPage /> };
        case 'careers':
            if (!sub)
                return {
                    title: 'Careers',
                    how: 'careers',
                    page: <CareersPage />,
                };
            if (sub === 'status')
                return {
                    title: 'Your application',
                    how: 'careers-status',
                    page: <ApplicationStatusPage />,
                };
            return {
                title: 'Careers',
                how: 'careers',
                page: <JobPage slug={sub} apply={extra === 'apply'} />,
            };
        case 'referral':
        case 'enquiry':
            return {
                title: 'Make a referral',
                how: 'referral',
                page: <ReferralPage purpose={sub} />,
            };
        case 'contact':
            return {
                title: 'Contact us',
                how: 'contact',
                page: <ContactPage />,
            };
        case 'sign-in':
            return {
                title: sub === 'family' ? 'Family portal sign in' : 'Sign in',
                how: 'sign-in',
                page: (
                    <SignInPage
                        audience={sub === 'family' ? 'family' : 'staff'}
                    />
                ),
            };
        case 'upload':
            return {
                title: 'Secure upload',
                how: 'upload',
                page: <UploadPage />,
            };
        case 'privacy':
            return {
                title: 'Privacy',
                how: 'legal',
                page: <LegalPage kind="privacy" />,
            };
        case 'terms':
            return {
                title: 'Terms of use',
                how: 'legal',
                page: <LegalPage kind="terms" />,
            };
        case 'accessibility':
            return {
                title: 'Accessibility',
                how: 'legal',
                page: <LegalPage kind="accessibility" />,
            };
        default:
            return { title: 'Home', how: 'home', page: <HomePage /> };
    }
}

// Real components that link to app pages this demo doesn't have say so.
setMockLinkFallback((url) =>
    showReviewNotice(
        `Not part of this demo: in the real app this opens ${url.replace(/\?.*$/, '')}.`,
    ),
);

function App() {
    // `seq` remounts the page on every hash change, like a fresh page load.
    const [nav, setNav] = useState({ hash: window.location.hash, seq: 0 });
    useEffect(() => {
        const sync = () => {
            setNav((previous) => ({
                hash: window.location.hash,
                seq: previous.seq + 1,
            }));
            window.scrollTo(0, 0);
        };
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, []);
    const resolved = resolve(nav.hash);
    const name = useCustomBrandName() ?? 'Oblivion Care';
    useEffect(() => {
        document.title = `${resolved.title} · ${name}`;
    }, [resolved.title, name]);
    const hash = nav.hash || '#/home';
    return (
        <div className="journey-site">
            <ReviewBar hash={hash} howKey={resolved.how} />
            <div key={nav.seq}>{resolved.page}</div>
        </div>
    );
}

createRoot(document.getElementById('root')!).render(<App />);
