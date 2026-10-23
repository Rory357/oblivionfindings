import { formatPageTitle } from '@/lib/page-title';
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import AuthJourneyLayout from './auth-journey-layout';

const page = vi.hoisted(() => ({
    url: '/login',
    props: {} as Record<string, unknown>,
    headTitle: undefined as string | undefined,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: ({ title }: { title?: string }) => {
        page.headTitle = title;
        return null;
    },
    Link: ({
        href,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url} {...props} />,
}));
vi.mock('@/routes', () => ({
    home: () => ({ url: '/', method: 'get' }),
    privacy: () => ({ url: '/privacy', method: 'get' }),
    terms: () => ({ url: '/terms', method: 'get' }),
}));
vi.mock('@/components/auth/locale-switch', () => ({
    LocaleSwitch: () => null,
}));
beforeEach(() => {
    page.props = {};
    page.headTitle = undefined;
});
afterEach(cleanup);

const brandHalf = () =>
    document.querySelector<HTMLElement>('section.journey-brand')!;

it('shows the client’s photo under the scrims, with Back to website and Powered by', () => {
    page.props = {
        branding: {
            name: 'Kōwhai Living',
            heroUrl: '/storage/branding/hero.jpg',
        },
    };
    render(
        <AuthJourneyLayout title="Sign in" backToWebsite>
            <h1>Sign in</h1>
        </AuthJourneyLayout>,
    );
    const half = brandHalf();
    expect(half).toHaveAttribute('data-brand-half', 'photo');
    expect(half).toHaveAttribute('aria-label', 'Kōwhai Living');
    // Decorative: the page's purpose is signing in.
    const photo = half.querySelector('img.journey-brand-photo');
    expect(photo).toHaveAttribute('src', '/storage/branding/hero.jpg');
    expect(photo).toHaveAttribute('alt', '');
    expect(half.querySelector('.journey-brand-scrim')).not.toBeNull();
    expect(half.querySelector('.journey-brand-welcome')).not.toBeNull();
    // The islands art is Oblivion's own default only.
    expect(half.querySelector('.journey-hero-mark')).toBeNull();
    expect(
        within(half).getByRole('link', { name: 'Back to website' }),
    ).toHaveAttribute('href', '/');
    expect(within(half).getByText('Powered by')).toBeInTheDocument();
    // On phones the brand half is hidden, so the link is also at the top of the form.
    expect(
        within(screen.getByRole('main')).getByRole('link', {
            name: 'Back to website',
        }),
    ).toBeInTheDocument();
    // The client's site: the tab reads "Sign in · Kōwhai Living", with no app-name suffix.
    expect(formatPageTitle(page.headTitle ?? '', 'Oblivion Findings')).toBe(
        'Sign in · Kōwhai Living',
    );
});

it('greets whānau over a photo instead of the islands line', () => {
    page.props = {
        branding: { name: 'Kōwhai Living', heroUrl: '/hero.jpg' },
    };
    render(
        <AuthJourneyLayout audience="family" title="Family portal sign in">
            <h1>Family portal</h1>
        </AuthJourneyLayout>,
    );
    expect(screen.getByText('Kia ora, whānau.')).toBeInTheDocument();
    expect(screen.getByText('Good to see you.')).toBeInTheDocument();
    expect(screen.queryByText('Separate islands.')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Back to website' }),
    ).not.toBeInTheDocument();
});

it('shows a plain brand-colour panel for a client without a photo — never the islands', () => {
    page.props = { branding: { name: 'Kōwhai Living', logoUrl: null } };
    render(
        <AuthJourneyLayout title="Sign in" signing>
            <h1>Sign in</h1>
        </AuthJourneyLayout>,
    );
    const half = brandHalf();
    expect(half).toHaveAttribute('data-brand-half', 'panel');
    expect(half).toHaveClass('journey-brand--panel');
    expect(half.querySelector('img')).toBeNull();
    expect(half.querySelector('.journey-hero-mark')).toBeNull();
    // The journey animation belongs to the islands art.
    expect(document.querySelector('.journey-auth')).not.toHaveClass(
        'is-signing',
    );
});

it('keeps the islands as Oblivion’s own default with no client branding, and no Oblivion Care label fallback', () => {
    page.props = { branding: { name: 'OblivionFindings' } };
    render(
        <AuthJourneyLayout title="Sign in" signing>
            <h1>Sign in</h1>
        </AuthJourneyLayout>,
    );
    const half = brandHalf();
    expect(half).toHaveAttribute('data-brand-half', 'islands');
    expect(half.querySelector('.journey-hero-mark')).not.toBeNull();
    expect(half).toHaveAttribute('aria-label', 'About staff sign in');
    expect(within(half).queryByText('Powered by')).not.toBeInTheDocument();
    expect(document.querySelector('.journey-auth')).toHaveClass('is-signing');
    // Oblivion's own default keeps the usual "<page> - <app name>" title.
    expect(formatPageTitle(page.headTitle ?? '', 'Oblivion Findings')).toBe(
        'Sign in - Oblivion Findings',
    );
});
