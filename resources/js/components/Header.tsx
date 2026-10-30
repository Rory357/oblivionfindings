import { BrandLockup } from '@/components/brand/brand-lockup';
import {
    navKeyForUrl,
    siteLinks,
    usePublicSite,
    type SiteNavKey,
} from '@/components/public-site/site';
import { Button } from '@/components/ui/button';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, LogIn, Menu, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const nav: { key: SiteNavKey; label: string; href: string }[] = [
    { key: 'support', label: 'Our support', href: siteLinks.support },
    { key: 'funding', label: 'Funding help', href: siteLinks.funding },
    { key: 'about', label: 'About us', href: siteLinks.about },
    { key: 'careers', label: 'Careers', href: siteLinks.careers },
    { key: 'contact', label: 'Contact us', href: siteLinks.contact },
];

/** The brand slot as the home link (Settings › Branding) — never a hard-coded logo. */
export function SiteLockup() {
    const { name } = usePublicSite();
    return (
        <Link
            href={siteLinks.home}
            className="flex min-h-[44px] min-w-0 items-center rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={name ? `${name} home` : 'Home'}
        >
            <BrandLockup surface="dark" name={name} />
        </Link>
    );
}

/**
 * The public website header (mockup v2): on the ink band, the brand slot,
 * Our support · Funding help · About us · Careers · Contact us, then
 * "Sign in" and the primary "Make a referral". The full nav shows from
 * 1280 px (xl); below that a menu button opens the nav under a horizon line.
 */
export default function Header({ current }: { current?: SiteNavKey | null }) {
    const [open, setOpen] = useState(false);
    const toggle = useRef<HTMLButtonElement>(null);
    const { url } = usePage();
    const active = current ?? navKeyForUrl(url);
    useEffect(() => setOpen(false), [url]);
    const close = () => setOpen(false);
    const ariaCurrent = (key: SiteNavKey) =>
        active === key ? ('page' as const) : undefined;

    return (
        <header className="journey-site-container flex min-h-[76px] items-center justify-between gap-4 py-3">
            <SiteLockup />
            <nav
                aria-label="Main"
                className="hidden items-center gap-0.5 xl:flex"
            >
                {nav.map((item) => (
                    <Link
                        key={item.key}
                        href={item.href}
                        className="journey-site-navlink"
                        aria-current={ariaCurrent(item.key)}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>
            <div className="hidden items-center gap-2 xl:flex">
                <Link
                    href={siteLinks.signIn}
                    className="journey-site-navlink gap-2"
                >
                    <LogIn className="size-4" aria-hidden />
                    Sign in
                </Link>
                <Button asChild className="frontline-tap">
                    <Link
                        href={siteLinks.referral}
                        aria-current={ariaCurrent('referral')}
                    >
                        Make a referral
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                </Button>
            </div>
            <Button
                ref={toggle}
                variant="outline"
                size="icon"
                className="size-[44px] xl:hidden"
                aria-expanded={open}
                aria-controls="site-menu"
                aria-label={open ? 'Close menu' : 'Open menu'}
                onClick={() => setOpen((value) => !value)}
            >
                {open ? (
                    <X className="size-5" aria-hidden />
                ) : (
                    <Menu className="size-5" aria-hidden />
                )}
            </Button>
            {open && (
                <div
                    id="site-menu"
                    className="journey-site-horizon absolute inset-x-0 top-[76px] z-50 bg-journey-ink px-5 pt-1 pb-6 shadow-lg xl:hidden"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            close();
                            toggle.current?.focus();
                        }
                    }}
                >
                    <nav aria-label="Main" className="flex flex-col py-2">
                        {nav.map((item) => (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="journey-site-navlink"
                                aria-current={ariaCurrent(item.key)}
                                onClick={close}
                            >
                                {item.label}
                            </Link>
                        ))}
                        <Link
                            href={siteLinks.signIn}
                            className="journey-site-navlink gap-2"
                            onClick={close}
                        >
                            <LogIn className="size-4" aria-hidden />
                            Sign in
                        </Link>
                    </nav>
                    <Button asChild className="frontline-tap w-full">
                        <Link href={siteLinks.referral} onClick={close}>
                            Make a referral
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </Button>
                </div>
            )}
        </header>
    );
}
