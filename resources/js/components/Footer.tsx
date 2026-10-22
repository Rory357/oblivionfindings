import {
    BrandLockup,
    JourneyWordmark,
    useCustomBrandName,
} from '@/components/brand/brand-lockup';
import {
    enquiryUrl,
    phoneUrl,
    type PublicProvider,
} from '@/components/public-provider';
import { useAppearance } from '@/hooks/use-appearance';
import { Link } from '@inertiajs/react';
import { Phone } from 'lucide-react';

export default function Footer({ provider }: { provider?: PublicProvider }) {
    const customName = useCustomBrandName();
    const { appearance } = useAppearance();
    const dark =
        appearance === 'dark' ||
        (appearance === 'system' &&
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);
    const groups = [
        {
            title: 'Getting support',
            links: [
                { href: '/services', label: 'Our support' },
                { href: '/funding', label: 'Funding assistance' },
                { href: enquiryUrl('referral'), label: 'Make a referral' },
            ],
        },
        {
            title: 'Stay connected',
            links: [
                { href: '/contact', label: 'Contact us' },
                { href: '/careers', label: 'Careers' },
                { href: '/login', label: 'Staff and family sign in' },
            ],
        },
        {
            title: 'Your information',
            links: [
                { href: '/privacy-policy', label: 'Privacy' },
                { href: '/terms', label: 'Website terms' },
                { href: '/accessibility', label: 'Accessibility' },
            ],
        },
    ];
    return (
        <footer className="mt-16 border-t border-border pt-8">
            <div className="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <BrandLockup surface={dark ? 'dark' : 'light'} />
                    <p className="text-subtle mt-3">
                        Start with a conversation about your goals, your choices
                        and the support that matters to you.
                    </p>
                    {provider?.phone && (
                        <a
                            href={phoneUrl(provider.phone)}
                            className="frontline-tap text-subtle mt-3 inline-flex items-center gap-2 rounded focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Phone className="size-4" />
                            {provider.phone}
                        </a>
                    )}
                </div>
                {groups.map((group) => (
                    <nav key={group.title} aria-label={group.title}>
                        <h2 className="font-medium">{group.title}</h2>
                        <ul className="mt-2">
                            {group.links.map((link) => (
                                <li key={link.href}>
                                    <Link
                                        href={link.href}
                                        className="frontline-tap text-subtle inline-flex items-center rounded py-2 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                ))}
            </div>
            <p className="text-caption mt-8 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border py-6 text-muted-foreground">
                <span>
                    © {new Date().getFullYear()}{' '}
                    {customName ?? provider?.name ?? 'Oblivion Care'}. Support
                    availability and funding are confirmed individually.
                </span>
                {customName && (
                    <span className="flex items-center gap-1.5">
                        Powered by
                        <JourneyWordmark className="text-[13px] text-current" />
                        <span className="sr-only">Oblivion Care</span>
                    </span>
                )}
            </p>
        </footer>
    );
}
