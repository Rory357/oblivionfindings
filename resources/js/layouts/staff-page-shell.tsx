import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    CalendarDays,
    ClipboardList,
    Clock3,
    Home,
    Pill,
    Settings,
    type LucideIcon,
} from 'lucide-react';
import { useState, type PropsWithChildren, type ReactNode } from 'react';

import { AppSidebar } from '@/components/app-sidebar';
import PullToRefresh from '@/components/pull-to-refresh';
import {
    StaffBottomNav,
    type StaffBottomNavItem,
} from '@/components/staff-bottom-nav';
import { StaffHeader } from '@/components/staff-header';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { useIsDesktopLg } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';

type StaffPageShellProps = PropsWithChildren<{
    title: ReactNode;
    subtitle?: ReactNode;
    /**
     * Optional single primary action rendered in the compact header.
     * Keep this slot lean — one button or a small cluster.
     */
    headerAction?: ReactNode;
    backHref?: string;
    backLabel?: string;
    /**
     * Override the bottom nav items if a specific staff page needs custom
     * targets. Defaults to the shared Home / Meds / Clock / Report / More set.
     */
    bottomNavItems?: StaffBottomNavItem[];
    /**
     * Called when the user taps the "More" bottom nav item.
     * Intended as the hook point for a future drawer — optional for now.
     */
    onMore?: () => void;
    /**
     * When true, hide the desktop sidebar and render the frontline header on
     * all breakpoints. Most pages should keep the default (false) so managers
     * and admins still see the usual chrome on larger screens.
     */
    mobileOnly?: boolean;
    className?: string;
}>;

type MoreLink = {
    label: string;
    description: string;
    href: string;
    icon: LucideIcon;
};

const MORE_LINKS: MoreLink[] = [
    {
        label: 'My day',
        description: 'Home, shifts, meds, and action items',
        href: '/my-day',
        icon: Home,
    },
    {
        label: 'My roster',
        description: 'Today, upcoming, and recent shifts',
        href: '/my-roster',
        icon: CalendarDays,
    },
    {
        label: 'Meds today',
        description: 'Due meds, rounds, and PRN quick record',
        href: '/meds/today',
        icon: Pill,
    },
    {
        label: 'Attendance',
        description: 'Clock history and shift matching',
        href: '/attendance',
        icon: Clock3,
    },
    {
        label: 'My calendar',
        description: 'Upcoming shifts and personal schedule',
        href: '/my-calendar',
        icon: CalendarDays,
    },
    {
        label: 'Notifications',
        description: 'Unread updates and acknowledgements',
        href: '/notifications',
        icon: Bell,
    },
    {
        label: 'Report incident',
        description: 'Start a new incident or review recent ones',
        href: '/incidents',
        icon: ClipboardList,
    },
    {
        label: 'Settings & profile',
        description: 'Profile, password, and notification settings',
        href: '/settings/profile',
        icon: Settings,
    },
];

/**
 * Frontline / staff layout shell.
 *
 * Structure:
 *   - compact StaffHeader (sticky top)
 *   - main content area with bottom padding that clears the mobile nav
 *   - persistent StaffBottomNav below `lg`
 *   - desktop sidebar from the existing AppSidebar (kept for parity with
 *     manager/admin expectations unless `mobileOnly` is set)
 *
 * Later PRs will consume this shell for My Day, eMAR flows, the incident
 * wizard, and clock actions. This PR wires the primitives only — it does not
 * re-implement those flows.
 */
export default function StaffPageShell({
    children,
    title,
    subtitle,
    headerAction,
    backHref,
    backLabel,
    bottomNavItems,
    onMore,
    mobileOnly = false,
    className,
}: StaffPageShellProps) {
    const defaultSidebarOpen = usePage<SharedData>().props.sidebarOpen ?? true;
    const { collapsed, setExpanded } = useAppSidebarState(defaultSidebarOpen);
    const isDesktopLg = useIsDesktopLg();
    const [moreOpen, setMoreOpen] = useState(false);
    const showBuiltInMoreSheet = !onMore;
    const handleMore = onMore ?? (() => setMoreOpen(true));

    return (
        <div className="min-h-svh w-full overflow-x-hidden">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
            >
                Skip to main content
            </a>

            {/* Below lg the sidebar was CSS-hidden but still mounted, so
                frontline phones paid for the full 200+-item nav tree on
                every page. Skip mounting it entirely there. */}
            {!mobileOnly && isDesktopLg ? (
                <div className="hidden lg:block">
                    <AppSidebar
                        collapsed={collapsed}
                        onCollapsedChange={(nextCollapsed) =>
                            setExpanded(!nextCollapsed)
                        }
                    />
                </div>
            ) : null}

            <main
                id="main-content"
                className={cn(
                    'relative flex min-h-svh flex-col bg-background',
                    !mobileOnly && (collapsed ? 'lg:ml-16' : 'lg:ml-64'),
                    'transition-[margin-left] duration-200 ease-in-out',
                    className,
                )}
            >
                <StaffHeader
                    title={title}
                    subtitle={subtitle}
                    action={headerAction}
                    backHref={backHref}
                    backLabel={backLabel}
                />

                <div
                    className={cn(
                        'staff-shell-content flex-1 px-4 pt-4 md:px-6 md:pt-6',
                    )}
                >
                    <PullToRefresh>{children}</PullToRefresh>
                </div>
            </main>

            <StaffBottomNav items={bottomNavItems} onMore={handleMore} />

            {showBuiltInMoreSheet ? (
                <Sheet open={moreOpen} onOpenChange={setMoreOpen}>
                    <SheetContent
                        side="bottom"
                        className="rounded-t-3xl pb-[max(env(safe-area-inset-bottom,0px),1rem)]"
                    >
                        <SheetHeader className="pr-12">
                            <SheetTitle>More</SheetTitle>
                            <SheetDescription>
                                Quick worker links that keep you inside the
                                frontline flow.
                            </SheetDescription>
                        </SheetHeader>
                        <div className="grid gap-2 px-4 pb-4">
                            {MORE_LINKS.map((item) => {
                                const Icon = item.icon;

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        onClick={() => setMoreOpen(false)}
                                        className="frontline-focus flex items-center gap-3 rounded-2xl border border-border/80 bg-card px-4 py-3 text-left transition-colors hover:bg-accent"
                                    >
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-muted text-foreground">
                                            <Icon className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <div className="text-sm font-semibold">
                                                {item.label}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {item.description}
                                            </div>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </SheetContent>
                </Sheet>
            ) : null}
        </div>
    );
}
