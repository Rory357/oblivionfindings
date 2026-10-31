import Footer from '@/components/Footer';
import Header from '@/components/Header';
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { navKeyForUrl, resolveSiteName } from './site';

const page = vi.hoisted(() => ({
    url: '/',
    props: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    Head: () => null,
}));
beforeEach(() => {
    page.url = '/';
    page.props = {};
});
afterEach(cleanup);

describe('the provider name', () => {
    it('comes from Settings › Branding, then the referral settings, and never falls back to Oblivion Care', () => {
        expect(resolveSiteName('Kōwhai Living', 'Example Support')).toBe(
            'Kōwhai Living',
        );
        expect(resolveSiteName('Oblivion Care', 'Example Support')).toBe(
            'Example Support',
        );
        expect(resolveSiteName('oblivionfindings', '  ')).toBeNull();
        expect(resolveSiteName(null, 'Oblivion Care')).toBeNull();
    });
});

describe('the site header', () => {
    it('has the approved nav, Sign in and the primary Make a referral', () => {
        page.url = '/funding';
        page.props = { branding: { name: 'Kōwhai Living' } };
        render(<Header />);
        const nav = screen.getAllByRole('navigation', { name: 'Main' })[0];
        expect(
            within(nav)
                .getAllByRole('link')
                .map((link) => [link.textContent, link.getAttribute('href')]),
        ).toEqual([
            ['Our support', '/services'],
            ['Funding help', '/funding'],
            ['About us', '/about'],
            ['Careers', '/careers'],
            ['Contact us', '/contact'],
        ]);
        expect(
            within(nav).getByRole('link', { name: 'Funding help' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute(
            'href',
            '/login',
        );
        expect(
            screen.getByRole('link', { name: /Make a referral/ }),
        ).toHaveAttribute('href', '/referrals/new');
        expect(
            screen.getByRole('link', { name: 'Kōwhai Living home' }),
        ).toHaveAttribute('href', '/');
        expect(screen.getByText('Kōwhai Living')).toBeInTheDocument();
    });

    it('shows the provider name from the referral settings when Branding has none', () => {
        page.props = {
            branding: { name: 'OblivionFindings' },
            provider: { name: 'Example Support' },
        };
        render(<Header />);
        expect(
            screen.getByRole('link', { name: 'Example Support home' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Example Support')).toBeInTheDocument();
    });

    it('maps public URLs to their nav item', () => {
        expect(navKeyForUrl('/services/supported_living')).toBe('support');
        expect(navKeyForUrl('/referrals/new?purpose=funding')).toBe('referral');
        expect(navKeyForUrl('/careers/jobs/support-worker')).toBe('careers');
        expect(navKeyForUrl('/terms')).toBe('legal');
    });
});

describe('the site footer', () => {
    it('carries the provider, its contact line and a small Powered by line for a client brand', () => {
        page.props = {
            branding: { name: 'Kōwhai Living' },
            provider: {
                name: 'Kōwhai Living',
                phone: '0800 111 222',
                response_hours: 'Weekdays, 9am to 5pm',
            },
        };
        render(<Footer />);
        const year = new Date().getFullYear();
        expect(screen.getByText(`© ${year} Kōwhai Living`)).toBeInTheDocument();
        expect(screen.getByText('Powered by')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: '0800 111 222' }),
        ).toHaveAttribute('href', 'tel:0800111222');
        expect(
            screen.getByRole('link', { name: 'Short breaks (respite)' }),
        ).toHaveAttribute('href', '/services#respite');
        expect(
            screen.getByRole('link', { name: 'Request a callback' }),
        ).toHaveAttribute('href', '/referrals/new?purpose=callback');
        expect(
            screen.getByRole('link', { name: 'Family portal' }),
        ).toHaveAttribute('href', '/portal/login');
        for (const [label, href] of [
            ['Privacy', '/privacy-policy'],
            ['Terms', '/terms'],
            ['Accessibility', '/accessibility'],
        ])
            expect(screen.getByRole('link', { name: label })).toHaveAttribute(
                'href',
                href,
            );
    });

    it('never names Oblivion Care as the provider, and drops Powered by on our own install', () => {
        page.props = {
            branding: { name: 'Oblivion Care' },
            provider: { name: 'Oblivion Care', phone: '' },
        };
        render(<Footer />);
        const year = new Date().getFullYear();
        const copyright = screen.getByText(`© ${year}`);
        expect(copyright.textContent).toBe(`© ${year}`);
        expect(screen.queryByText('Powered by')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Home' })).toBeInTheDocument();
    });

    it('shows the demo provider’s regions only on the demonstration server', () => {
        page.props = { branding: { name: 'Oblivion Living' }, demoSite: true };
        const { unmount } = render(<Footer />);
        expect(
            screen.getByText(
                'Supported living across Kāpiti Coast, Porirua, Lower Hutt and Wellington.',
            ),
        ).toBeInTheDocument();
        unmount();
        page.props = { branding: { name: 'Kōwhai Living' } };
        render(<Footer />);
        expect(screen.queryByText(/Kāpiti Coast/)).not.toBeInTheDocument();
    });
});
