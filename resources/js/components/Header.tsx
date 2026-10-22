import { BrandLockup } from '@/components/brand/brand-lockup';
import { enquiryUrl } from '@/components/public-provider';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { Link, usePage } from '@inertiajs/react';
import { Menu, Moon, Sun, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function Header({ name = 'Oblivion Care' }: { name?: string }) {
    const [open, setOpen] = useState(false);
    const { url } = usePage();
    const { appearance, updateAppearance } = useAppearance();
    useEffect(() => setOpen(false), [url]);
    const dark =
        appearance === 'dark' ||
        (appearance === 'system' &&
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);
    const links = [
        { href: '/services', label: 'Our support' },
        { href: '/funding', label: 'Funding help' },
        { href: '/about', label: 'About us' },
        { href: '/contact', label: 'Contact' },
    ];
    const navigation = links.map((item) => (
        <Link
            key={item.href}
            href={item.href}
            aria-current={url.split('?')[0] === item.href ? 'page' : undefined}
            onClick={() => setOpen(false)}
            className="frontline-tap text-subtle flex items-center rounded-lg px-3 py-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring aria-[current=page]:bg-muted aria-[current=page]:text-foreground"
        >
            {item.label}
        </Link>
    ));
    return (
        <header className="relative flex flex-wrap items-center justify-between gap-3 border-b border-border pb-5">
            {/* The brand slot (Settings › Branding): Journey lockup, a client logo or a client name — never a hard-coded mark. */}
            <Link
                href="/"
                aria-label={`${name} home`}
                className="frontline-tap flex min-w-0 items-center rounded-lg focus-visible:ring-2 focus-visible:ring-ring"
            >
                <BrandLockup surface={dark ? 'dark' : 'light'} />
            </Link>
            <nav
                aria-label="Main navigation"
                className="hidden items-center gap-1 lg:flex"
            >
                {navigation}
            </nav>
            <div className="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="icon"
                    className="frontline-tap"
                    aria-label={
                        dark ? 'Use light appearance' : 'Use dark appearance'
                    }
                    onClick={() => updateAppearance(dark ? 'light' : 'dark')}
                >
                    {dark ? (
                        <Sun className="size-4" />
                    ) : (
                        <Moon className="size-4" />
                    )}
                </Button>
                <Link
                    href="/login"
                    className="text-subtle hidden items-center rounded-lg px-3 py-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:flex"
                >
                    Staff and family sign in
                </Link>
                <Button asChild className="hidden sm:inline-flex">
                    <Link href={enquiryUrl()}>Explore support</Link>
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className="frontline-tap lg:hidden"
                    aria-expanded={open}
                    aria-controls="public-mobile-menu"
                    aria-label={open ? 'Close navigation' : 'Open navigation'}
                    onClick={() => setOpen(!open)}
                >
                    {open ? (
                        <X className="size-4" />
                    ) : (
                        <Menu className="size-4" />
                    )}
                </Button>
            </div>
            {open && (
                <nav
                    id="public-mobile-menu"
                    aria-label="Mobile navigation"
                    className="w-full space-y-1 rounded-xl border border-border bg-card p-3 lg:hidden"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            setOpen(false);
                            document
                                .querySelector<HTMLButtonElement>(
                                    '[aria-controls="public-mobile-menu"]',
                                )
                                ?.focus();
                        }
                    }}
                >
                    {navigation}
                    <Link
                        href={enquiryUrl()}
                        onClick={() => setOpen(false)}
                        className="frontline-tap flex items-center rounded-lg px-3 py-2 font-medium text-primary focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Explore support
                    </Link>
                    <Link
                        href="/login"
                        className="frontline-tap text-subtle flex items-center rounded-lg px-3 py-2 focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        Staff and family sign in
                    </Link>
                </nav>
            )}
        </header>
    );
}
