// The public website speaks as the provider (Stephan, 20 Oct 2026: it is the
// client's own site, never Oblivion Care marketing). Everything here resolves
// who "we" are from Settings › Branding and the published referral settings.
import type { PublicProvider } from '@/components/public-provider';
import { usePage } from '@inertiajs/react';

export type SiteBranding = {
    name?: string | null;
    logoUrl?: string | null;
    logoDarkUrl?: string | null;
    /** The website and sign-in photo (Settings › Branding). */
    heroUrl?: string | null;
    /** Its description for screen readers; empty = decorative. */
    heroAlt?: string | null;
};

/** The published provider projection, plus the Contact us details from
 *  Settings › Referrals (blank when not set). */
export type SiteProvider = Partial<PublicProvider> & {
    email?: string | null;
    address?: string | null;
    opening_hours?: string | null;
};

type SiteProps = {
    branding?: SiteBranding;
    provider?: SiteProvider;
    /** config('demo.site'): the Oblivion Living demonstration server. */
    demoSite?: boolean;
};

/** Names that mean "no client name": ours, the framework's and the repo's. */
/* eslint-disable design/no-oblivion-fallback -- the resolver must recognise our stock names in order to reject them */
const STOCK_NAMES = new Set([
    'oblivion care',
    'oblivioncare',
    'oblivion findings',
    'oblivionfindings',
    'laravel',
]);
/* eslint-enable design/no-oblivion-fallback */

/**
 * The provider's own name: Settings › Branding first, then the published
 * referral settings' `public.provider_name`. Never falls back to "Oblivion
 * Care" — null means no name is set, and copy reads neutrally instead.
 */
export function resolveSiteName(
    brandingName?: string | null,
    providerName?: string | null,
): string | null {
    for (const candidate of [brandingName, providerName]) {
        const trimmed = candidate?.trim() ?? '';
        if (trimmed !== '' && !STOCK_NAMES.has(trimmed.toLowerCase())) {
            return trimmed;
        }
    }

    return null;
}

export type PublicSite = {
    /** The provider's name, or null when none is set (never "Oblivion Care"). */
    name: string | null;
    /** Any client branding at all: a name, a logo or a photo. Without it the
     *  site is Oblivion's own install and keeps the islands default. */
    hasClientBrand: boolean;
    heroUrl: string | null;
    heroAlt: string;
    /** The demonstration server: our fictional photos and facts may show. */
    demo: boolean;
    provider: SiteProvider;
};

export function usePublicSite(): PublicSite {
    const { branding, provider, demoSite } = usePage<SiteProps>().props;
    const name = resolveSiteName(branding?.name, provider?.name);
    const heroUrl = branding?.heroUrl ?? null;

    return {
        name,
        hasClientBrand: Boolean(
            name || branding?.logoUrl || branding?.logoDarkUrl || heroUrl,
        ),
        heroUrl,
        heroAlt: branding?.heroAlt?.trim() ?? '',
        demo: demoSite === true,
        provider: provider ?? {},
    };
}

/** Where the main nav and footer point (real routes, not the mockup's hashes). */
export const siteLinks = {
    home: '/',
    support: '/services',
    funding: '/funding',
    about: '/about',
    careers: '/careers',
    contact: '/contact',
    referral: '/referrals/new',
    signIn: '/login',
    familySignIn: '/portal/login',
    privacy: '/privacy-policy',
    terms: '/terms',
    accessibility: '/accessibility',
} as const;

export type SiteNavKey =
    | 'home'
    | 'support'
    | 'funding'
    | 'about'
    | 'careers'
    | 'contact'
    | 'referral'
    | 'legal'
    | 'upload';

/** The nav item a URL belongs to, so the header marks it current. */
export function navKeyForUrl(url: string): SiteNavKey | null {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    if (path === '/') return 'home';
    if (path === '/services' || path.startsWith('/services/')) return 'support';
    if (path === '/funding') return 'funding';
    if (path === '/about') return 'about';
    if (path === '/careers' || path.startsWith('/careers/')) return 'careers';
    if (path === '/contact') return 'contact';
    if (path === '/referrals/new') return 'referral';
    if (path.startsWith('/referrals/documents/')) return 'upload';
    if (['/privacy-policy', '/terms', '/accessibility'].includes(path))
        return 'legal';

    return null;
}
