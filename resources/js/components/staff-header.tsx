import { Link } from '@inertiajs/react';
import { ArrowLeft, Bell, ChevronDown, Search } from 'lucide-react';
import { type ComponentType, type ReactNode, useEffect, useState } from 'react';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type StaffHeaderGlobalLink = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    href: string;
};

export type StaffHeaderSearch = {
    placeholder?: string;
    hint?: string;
    onSubmit?: (query: string) => void;
};

export type StaffHeaderLiveIndicator = {
    lastUpdatedAt: Date | null;
    isRefreshing?: boolean;
    /** Click handler bound to the chip (typically refreshNow). */
    onRefresh?: () => void;
};

export type StaffHeaderNotifications = {
    count: number;
    href: string;
};

type StaffHeaderProps = {
    title: ReactNode;
    subtitle?: ReactNode;
    action?: ReactNode;
    backHref?: string;
    backLabel?: string;
    className?: string;

    /**
     * When set, the title becomes a button that fires this callback. Pair with
     * `titlePopover` to render the popover anchored to the title.
     */
    onTitleClick?: () => void;
    /** Render a small chevron next to the title to hint clickability. */
    titleChevron?: boolean;
    /**
     * Popover anchored to the title (e.g. month grid). Caller controls its
     * visibility via `onTitleClick`; we just render it here so positioning
     * stays adjacent to the title.
     */
    titlePopover?: ReactNode;
    /** Whether `titlePopover` is currently open (rotates the chevron). */
    titleOpen?: boolean;

    /** Desktop-only: row of 44×44 icon links to global pages. */
    globalLinks?: StaffHeaderGlobalLink[];
    /** Desktop-only: search pill on the right of the global-links row. */
    search?: StaffHeaderSearch;
    /** Responsive "Live · 12s" freshness chip. */
    liveIndicator?: StaffHeaderLiveIndicator;
    /** Responsive bell button with a critical-tone badge. */
    notifications?: StaffHeaderNotifications;
};

/**
 * Header for frontline / staff pages.
 *
 * Mobile callers (the original use case) pass only `title` + `subtitle` +
 * `action` and get the original compact bar. Extended callers such as
 * `/my-day` add desktop links/search and responsive live/notification
 * controls; the action row wraps below the title on a narrow viewport.
 */
export function StaffHeader({
    title,
    subtitle,
    action,
    backHref,
    backLabel = 'Back',
    className,
    onTitleClick,
    titleChevron = false,
    titlePopover,
    titleOpen = false,
    globalLinks,
    search,
    liveIndicator,
    notifications,
}: StaffHeaderProps) {
    const hasDesktopChrome = !!(
        globalLinks?.length ||
        search ||
        liveIndicator ||
        notifications
    );

    const titleInner = (
        <div className="min-w-0 flex-1">
            <h1 className="flex items-center gap-1.5 truncate text-base leading-tight font-semibold tracking-tight">
                <span className="truncate">{title}</span>
                {titleChevron ? (
                    <ChevronDown
                        className={cn(
                            'h-3 w-3 shrink-0 text-muted-foreground transition-transform duration-150',
                            titleOpen && 'rotate-180',
                        )}
                    />
                ) : null}
            </h1>
            {subtitle ? (
                <p
                    className={cn(
                        'text-xs text-muted-foreground',
                        hasDesktopChrome
                            ? 'whitespace-normal lg:truncate'
                            : 'truncate',
                    )}
                >
                    {subtitle}
                </p>
            ) : null}
        </div>
    );

    return (
        <header
            className={cn(
                'sticky top-0 z-30 flex min-h-14 items-center gap-x-3 gap-y-2 border-b border-border/50 bg-background/95 px-4 pb-2 backdrop-blur supports-[backdrop-filter]:bg-background/80',
                // Honour the top safe-area inset so the title clears the
                // status bar when the app is launched in standalone/PWA
                // mode. Resolves to pt-2 in a normal browser tab.
                'pt-[calc(env(safe-area-inset-top,0px)+0.5rem)]',
                hasDesktopChrome && 'flex-wrap md:px-7 lg:flex-nowrap',
                className,
            )}
        >
            {backHref ? (
                <Link
                    href={backHref}
                    aria-label={backLabel}
                    className="frontline-focus -ml-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <ArrowLeft className="h-5 w-5" />
                </Link>
            ) : null}

            <div className="relative min-w-0 flex-1">
                {onTitleClick ? (
                    // eslint-disable-next-line no-restricted-syntax -- title acts as a popover trigger with custom typography; not a shadcn Button.
                    <button
                        type="button"
                        onClick={onTitleClick}
                        className={cn(
                            'frontline-focus min-h-11 max-w-full rounded-md px-1.5 py-0.5 text-left transition-colors',
                            titleOpen ? 'bg-muted' : 'hover:bg-muted',
                        )}
                        aria-haspopup="dialog"
                        aria-expanded={titleOpen}
                    >
                        {titleInner}
                    </button>
                ) : (
                    titleInner
                )}
                {titlePopover}
            </div>

            {globalLinks && globalLinks.length > 0 ? (
                <nav
                    className="ml-3 hidden gap-0.5 md:flex"
                    aria-label="Global links"
                >
                    <TooltipProvider delayDuration={200}>
                        {globalLinks.map((link) => (
                            <StaffHeaderGlobalLinkButton
                                key={link.href}
                                link={link}
                            />
                        ))}
                    </TooltipProvider>
                </nav>
            ) : null}

            {search ? <StaffHeaderSearchInput search={search} /> : null}

            <div
                className={cn(
                    'flex items-center gap-2 [&>a]:min-h-11 [&>a]:min-w-11 [&>button]:min-h-11 [&>button]:min-w-11',
                    hasDesktopChrome
                        ? 'w-full shrink-0 basis-full justify-between lg:ml-auto lg:w-auto lg:basis-auto lg:justify-start'
                        : 'ml-auto shrink-0',
                )}
                data-staff-header-actions
            >
                {action}
                {liveIndicator ? (
                    <StaffHeaderLiveChip indicator={liveIndicator} />
                ) : null}
                {notifications ? (
                    <StaffHeaderNotificationsBell
                        notifications={notifications}
                    />
                ) : null}
            </div>
        </header>
    );
}

function StaffHeaderGlobalLinkButton({
    link,
}: {
    link: StaffHeaderGlobalLink;
}) {
    const Icon = link.icon;
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Link
                    href={link.href}
                    aria-label={link.label}
                    className="frontline-focus inline-flex h-11 w-11 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <Icon className="h-4 w-4" />
                </Link>
            </TooltipTrigger>
            <TooltipContent side="bottom">{link.label}</TooltipContent>
        </Tooltip>
    );
}

function StaffHeaderSearchInput({ search }: { search: StaffHeaderSearch }) {
    const [value, setValue] = useState('');
    return (
        <form
            className="ml-2 hidden h-11 w-[200px] items-center gap-2 rounded-md border border-border bg-background px-3 text-xs text-muted-foreground transition-colors focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/40 hover:bg-muted md:flex"
            onSubmit={(event) => {
                event.preventDefault();
                search.onSubmit?.(value);
            }}
        >
            <Search className="h-3.5 w-3.5" />
            <input
                type="search"
                value={value}
                placeholder={search.placeholder ?? 'Search…'}
                onChange={(event) => setValue(event.target.value)}
                className="h-full min-w-0 flex-1 border-0 bg-transparent text-xs text-foreground outline-none placeholder:text-muted-foreground"
                aria-label={search.placeholder ?? 'Search'}
            />
            {search.hint ? (
                <span className="text-[10.5px] text-text-faint">
                    {search.hint}
                </span>
            ) : null}
        </form>
    );
}

function StaffHeaderLiveChip({
    indicator,
}: {
    indicator: StaffHeaderLiveIndicator;
}) {
    const age = useFreshness(indicator.lastUpdatedAt);
    const label = indicator.lastUpdatedAt ? `Live · ${age}` : 'Live';
    const body = (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11.5px] text-muted-foreground">
            <span
                className={cn(
                    'h-1.5 w-1.5 rounded-full bg-status-success',
                    indicator.isRefreshing && 'animate-pulse',
                )}
            />
            {label}
        </span>
    );
    if (indicator.onRefresh) {
        return (
            // eslint-disable-next-line no-restricted-syntax -- pill chip wraps a freshness label, not a shadcn Button.
            <button
                type="button"
                onClick={indicator.onRefresh}
                aria-label="Refresh now"
                className="frontline-focus frontline-tap inline-flex items-center justify-center rounded-full"
            >
                {body}
            </button>
        );
    }
    return body;
}

function StaffHeaderNotificationsBell({
    notifications,
}: {
    notifications: StaffHeaderNotifications;
}) {
    return (
        <Link
            href={notifications.href}
            aria-label={`Notifications (${notifications.count})`}
            className="frontline-focus relative inline-flex h-11 w-11 items-center justify-center rounded-md border border-border text-foreground hover:bg-muted"
        >
            <Bell className="h-4 w-4" />
            {notifications.count > 0 ? (
                <span className="absolute top-1 right-1 inline-flex h-3.5 min-w-[14px] items-center justify-center rounded-full border-2 border-background bg-status-critical px-1 text-[9px] font-bold text-status-critical-foreground">
                    {notifications.count}
                </span>
            ) : null}
        </Link>
    );
}

/**
 * Returns a short human-readable freshness label like "12s", "3m", "1h"
 * that ticks every 5s while the page is in the foreground.
 */
function useFreshness(lastUpdatedAt: Date | null): string {
    const [, setTick] = useState(0);
    useEffect(() => {
        if (!lastUpdatedAt) return;
        const t = setInterval(() => setTick((n) => n + 1), 5_000);
        return () => clearInterval(t);
    }, [lastUpdatedAt]);

    if (!lastUpdatedAt) return '—';
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - lastUpdatedAt.getTime()) / 1000),
    );
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    return `${hours}h`;
}

export default StaffHeader;
