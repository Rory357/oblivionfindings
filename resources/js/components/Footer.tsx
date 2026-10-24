import { JourneyWordmark } from '@/components/brand/brand-lockup';
import { SiteLockup } from '@/components/Header';
import { enquiryUrl, phoneUrl } from '@/components/public-provider';
import { demo, regionList } from '@/components/public-site/content';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Link } from '@inertiajs/react';

const columns: { title: string; links: { label: string; href: string }[] }[] = [
    {
        title: 'Our support',
        links: [
            { label: 'Support at home', href: `${siteLinks.support}#home` },
            {
                label: 'In the community',
                href: `${siteLinks.support}#community`,
            },
            {
                label: 'Short breaks (respite)',
                href: `${siteLinks.support}#respite`,
            },
            { label: 'Funding help', href: siteLinks.funding },
        ],
    },
    {
        title: 'Get in touch',
        links: [
            { label: 'Make a referral', href: siteLinks.referral },
            { label: 'Request a callback', href: enquiryUrl('callback') },
            { label: 'Contact us', href: siteLinks.contact },
        ],
    },
    {
        title: 'About',
        links: [
            { label: 'About us', href: siteLinks.about },
            { label: 'Careers', href: siteLinks.careers },
            { label: 'Staff sign in', href: siteLinks.signIn },
            { label: 'Family portal', href: siteLinks.familySignIn },
        ],
    },
];

/**
 * The public website footer (mockup v2): the brand slot, where the provider
 * works and how to call, three link groups, then © the provider, one small
 * "Powered by oblivion care" line when the site carries a client's brand, and
 * the Privacy / Terms / Accessibility pages.
 */
export default function Footer() {
    const { name, hasClientBrand, demo: isDemo, provider } = usePublicSite();
    const phone = provider.phone?.trim();
    const hours = provider.response_hours?.trim();

    return (
        <footer className="journey-site-footer">
            <div className="journey-site-container grid gap-10 py-12 md:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div className="space-y-4">
                    <span className="block text-journey-paper">
                        <SiteLockup />
                    </span>
                    <p className="max-w-xs text-sm">
                        {isDemo
                            ? `Supported living across ${regionList(demo.regions)}.`
                            : 'Supported living, shaped around you.'}
                    </p>
                    {(phone || hours) && (
                        <p className="text-sm">
                            {phone && (
                                <a
                                    href={phoneUrl(phone)}
                                    className="inline-flex min-h-[44px] items-center font-semibold"
                                >
                                    {phone}
                                </a>
                            )}
                            {phone && hours && <br />}
                            {hours}
                        </p>
                    )}
                </div>
                {columns.map((column) => (
                    <nav
                        key={column.title}
                        aria-label={column.title}
                        className="space-y-3 text-sm"
                    >
                        <p className="font-semibold text-journey-paper">
                            {column.title}
                        </p>
                        <ul>
                            {column.links.map((link) => (
                                <li key={link.label}>
                                    <Link
                                        href={link.href}
                                        className="inline-flex min-h-[44px] items-center"
                                    >
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                ))}
            </div>
            <div className="journey-site-container flex flex-wrap items-center justify-between gap-3 border-t border-journey-paper/10 py-5 text-sm">
                <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>
                        © {new Date().getFullYear()}
                        {name ? ` ${name}` : ''}
                    </span>
                    {hasClientBrand && (
                        <span className="flex items-center gap-1.5">
                            Powered by
                            <JourneyWordmark className="text-[13px] text-current" />
                            <span className="sr-only">Oblivion Care</span>
                        </span>
                    )}
                </span>
                <span className="flex flex-wrap gap-x-5">
                    <Link
                        href={siteLinks.privacy}
                        className="inline-flex min-h-[44px] items-center"
                    >
                        Privacy
                    </Link>
                    <Link
                        href={siteLinks.terms}
                        className="inline-flex min-h-[44px] items-center"
                    >
                        Terms
                    </Link>
                    <Link
                        href={siteLinks.accessibility}
                        className="inline-flex min-h-[44px] items-center"
                    >
                        Accessibility
                    </Link>
                </span>
            </div>
        </footer>
    );
}
