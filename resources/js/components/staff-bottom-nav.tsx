import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    Clock,
    Home,
    Menu,
    Pill,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type StaffBottomNavItemKey = 'home' | 'meds' | 'clock' | 'report' | 'more';

export type StaffBottomNavItem = {
    key: StaffBottomNavItemKey;
    label: string;
    icon: LucideIcon;
    href: string;
    /**
     * When provided, the nav item renders as a button instead of a Link.
     * Used for the "More" drawer / placeholder hook.
     */
    onClick?: () => void;
    /**
     * Optional badge shown over the icon, e.g. pending count.
     */
    badge?: ReactNode;
    /**
     * When true, mark the item as the active route.
     * If omitted, active state is inferred from the current URL.
     */
    active?: boolean;
};

type StaffBottomNavProps = {
    /**
     * Called when the user taps the "More" item.
     * If omitted, the "More" item links to `/` by default (safe placeholder).
     */
    onMore?: () => void;
    /**
     * Override of the default nav items — keep to 5 for the frontline shell.
     */
    items?: StaffBottomNavItem[];
    className?: string;
};

const DEFAULT_ITEMS: ReadonlyArray<Omit<StaffBottomNavItem, 'onClick'>> = [
    // PR 3 — Home points at the canonical frontline route `/my-day`.
    { key: 'home', label: 'Home', icon: Home, href: '/my-day' },
    { key: 'meds', label: 'Meds', icon: Pill, href: '/emar' },
    // Clock slot keeps its deep-link hash on the home page. Future PRs wire the
    // actual clock workflow; this PR only canonicalises the URL.
    { key: 'clock', label: 'Clock', icon: Clock, href: '/my-day#clock' },
    { key: 'report', label: 'Report', icon: ClipboardList, href: '/incidents' },
    { key: 'more', label: 'More', icon: Menu, href: '/' },
];

function isHrefActive(currentUrl: string, href: string): boolean {
    if (!href || href === '/') return currentUrl === '/';
    // Strip hash for comparison — a bottom nav target like /my-day#clock
    // should still be treated as active on /my-day.
    const [pathname] = href.split('#');
    if (!pathname) return false;
    if (currentUrl === pathname) return true;
    return currentUrl.startsWith(`${pathname}/`);
}

/**
 * Persistent mobile bottom navigation for staff / frontline pages.
 *
 * - Hidden on lg and above — desktop keeps the existing sidebar.
 * - Exactly 5 slots: Home, Meds, Clock, Report, More.
 * - Each slot is a 48x48 minimum touch target.
 * - Active state uses a visible indicator (no hover-only cues).
 * - Respects iOS safe-area-bottom via pb-[env(safe-area-inset-bottom)].
 */
export function StaffBottomNav({ onMore, items, className }: StaffBottomNavProps) {
    const { url } = usePage();

    const resolvedItems: StaffBottomNavItem[] = (items ?? DEFAULT_ITEMS).map(
        (item) => {
            if (item.key === 'more' && onMore) {
                return { ...item, onClick: onMore };
            }
            return { ...item };
        },
    );

    return (
        <nav
            aria-label="Frontline navigation"
            className={cn(
                'fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80 lg:hidden',
                'pb-[env(safe-area-inset-bottom)]',
                className,
            )}
        >
            <ul className="mx-auto grid max-w-screen-md grid-cols-5">
                {resolvedItems.map((item) => {
                    const active =
                        item.active ?? isHrefActive(url ?? '', item.href);
                    const Icon = item.icon;

                    const content = (
                        <span
                            className={cn(
                                'relative flex h-full w-full min-h-12 flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-[11px] font-medium',
                                active
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'absolute top-0 left-1/2 h-0.5 w-8 -translate-x-1/2 rounded-full transition-opacity',
                                    active ? 'bg-primary opacity-100' : 'opacity-0',
                                )}
                            />
                            <span className="relative">
                                <Icon
                                    className="h-5 w-5"
                                    strokeWidth={active ? 2.25 : 2}
                                />
                                {item.badge ? (
                                    <span className="absolute -top-1 -right-2 inline-flex min-h-[16px] min-w-[16px] items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-none text-destructive-foreground">
                                        {item.badge}
                                    </span>
                                ) : null}
                            </span>
                            <span className="leading-none">{item.label}</span>
                        </span>
                    );

                    const commonClass =
                        'flex min-h-12 items-stretch justify-center outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background';

                    return (
                        <li key={item.key} className="contents">
                            {item.onClick ? (
                                <button
                                    type="button"
                                    onClick={item.onClick}
                                    aria-current={active ? 'page' : undefined}
                                    aria-label={item.label}
                                    className={commonClass}
                                >
                                    {content}
                                </button>
                            ) : (
                                <Link
                                    href={item.href}
                                    aria-current={active ? 'page' : undefined}
                                    aria-label={item.label}
                                    className={commonClass}
                                >
                                    {content}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

export default StaffBottomNav;
