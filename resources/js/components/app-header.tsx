import {
    EventHorizonWordmark,
    resolveWordmarkName,
} from '@/components/event-horizon-wordmark';
import GlobalNavSearch from '@/components/global-nav-search';
import GlobalQueryBar from '@/components/global-query-bar';
import InboxMenus from '@/components/inbox-menus';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SheetTrigger } from '@/components/ui/sheet';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    Menu,
    MessageSquareText,
    ShieldAlert,
} from 'lucide-react';

/**
 * The Event Horizon command header (APP_SHELL_STYLE_GUIDE.md §2).
 *
 * Painted with the sidebar tokens so header + sidebar read as one continuous
 * ink chrome (dark in light mode by design). Anatomy, left → right: ring-O
 * wordmark, day + date pinned to the sidebar seam (left-[256px] = w-64),
 * truly-centred command search (grid 1fr/auto/1fr — flex spacers drift
 * off-centre), then Report incident / Clock in-out / Ask / Messages /
 * inbox bells / user avatar. No "Live"/sync chip — removed by design.
 * The centred search never yields to the date: full date ≥1320px, short
 * form 1140–1320px, hidden below 1140px.
 *
 * Badge semantics (never swap them): violet count = conversations waiting;
 * red count/dot = alerts needing attention.
 */

const INK_ICON_BUTTON =
    'relative flex size-9 shrink-0 items-center justify-center rounded-lg text-sidebar-foreground transition-colors outline-none hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring';

export function AppHeader({
    showMobileMenuTrigger = false,
}: {
    showMobileMenuTrigger?: boolean;
}) {
    const page = usePage<SharedData>();
    const { auth } = page.props;
    const can = (auth as any)?.can;
    const branding = (page.props as any)?.branding as
        | { name?: string; logoUrl?: string | null }
        | undefined;
    const unreadMessages = Number((auth as any)?.unreadMessageCount ?? 0);
    const getInitials = useInitials();

    const canReportIncident = !!can?.incidents?.create;
    const canClock = !!(
        can?.timesheets?.viewAny ||
        can?.timesheets?.viewAssigned ||
        can?.shifts?.viewAssigned ||
        can?.shifts?.manageAny
    );
    const canMessages = !!(can?.messages?.viewAny || can?.shifts?.viewAny);

    const handleStopImpersonating = () => {
        router.post('/system/users/stop-impersonating');
    };

    const today = new Date();
    const longDay = today.toLocaleDateString('en-GB', {
        weekday: 'long',
        timeZone: WORKER_TIMEZONE,
    });
    const longDate = today.toLocaleDateString('en-GB', {
        timeZone: WORKER_TIMEZONE,
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
    const shortDay = today.toLocaleDateString('en-GB', {
        weekday: 'short',
        timeZone: WORKER_TIMEZONE,
    });
    const shortDate = today.toLocaleDateString('en-GB', {
        timeZone: WORKER_TIMEZONE,
        day: 'numeric',
        month: 'short',
    });

    return (
        <>
            {auth.impersonating && (
                <div className="flex items-center justify-between gap-2 bg-primary px-6 py-2 text-sm font-medium text-primary-foreground md:px-4">
                    <div className="flex items-center gap-2">
                        <ShieldAlert className="h-4 w-4 shrink-0" />
                        <span>
                            You are impersonating{' '}
                            <strong>{auth.user.name}</strong>
                            {auth.impersonator && (
                                <> (logged in as {auth.impersonator.name})</>
                            )}
                        </span>
                    </div>
                    <Button
                        size="sm"
                        className="shrink-0 border border-primary-foreground/30 bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                        onClick={handleStopImpersonating}
                    >
                        Stop Impersonating
                    </Button>
                </div>
            )}

            <header className="sticky top-0 z-50 grid h-[58px] w-full grid-cols-[1fr_auto_1fr] items-center gap-2 bg-sidebar px-3 text-sidebar-foreground md:px-4">
                {/* Day + date, flush to the sidebar seam. Stays put when the
                    sidebar collapses; the centred search always wins the
                    space fight (tiers documented in the file docblock). */}
                <div className="pointer-events-none absolute top-1/2 left-[256px] hidden -translate-y-1/2 items-baseline gap-1.5 text-sm whitespace-nowrap min-[1140px]:flex">
                    <span className="font-semibold text-sidebar-accent-foreground">
                        <span className="min-[1320px]:hidden">{shortDay}</span>
                        <span className="hidden min-[1320px]:inline">
                            {longDay}
                        </span>
                    </span>
                    <span>
                        <span className="min-[1320px]:hidden">{shortDate}</span>
                        <span className="hidden min-[1320px]:inline">
                            {longDate}
                        </span>
                    </span>
                </div>

                {/* Left — mobile menu + wordmark */}
                <div className="flex min-w-0 items-center gap-1">
                    {showMobileMenuTrigger && (
                        <SheetTrigger asChild>
                            <button
                                type="button"
                                className={cn(INK_ICON_BUTTON, 'md:hidden')}
                            >
                                <Menu className="size-5" />
                                <span className="sr-only">Toggle menu</span>
                            </button>
                        </SheetTrigger>
                    )}
                    <Link
                        href="/dashboard"
                        prefetch
                        aria-label={`${resolveWordmarkName(branding?.name)} — home`}
                        className="flex min-w-0 items-center rounded-lg px-1.5 py-1.5 transition-colors outline-none hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                    >
                        <EventHorizonWordmark
                            name={branding?.name}
                            logoUrl={branding?.logoUrl}
                        />
                    </Link>
                </div>

                {/* Centre — command search (truly centred by the grid) */}
                <div className="flex min-w-0 justify-center">
                    <GlobalNavSearch variant="header" />
                </div>

                {/* Right cluster */}
                <div className="flex items-center justify-end gap-1.5">
                    {canReportIncident && (
                        <Button
                            asChild
                            size="sm"
                            className="hidden lg:inline-flex"
                        >
                            <Link href="/incidents/create" prefetch>
                                <AlertTriangle className="size-4" />
                                Report incident
                            </Link>
                        </Button>
                    )}
                    {canClock && (
                        <Link
                            href="/attendance"
                            prefetch
                            className="hidden h-9 items-center gap-2 rounded-lg border border-sidebar-border bg-sidebar-accent/50 px-3 text-sm font-medium text-sidebar-accent-foreground transition-colors outline-none hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:flex"
                        >
                            <Clock className="size-4" />
                            Clock in/out
                        </Link>
                    )}
                    <GlobalQueryBar variant="icon" />
                    {canMessages && (
                        <Link
                            href="/operations/messages"
                            prefetch
                            aria-label={
                                unreadMessages > 0
                                    ? `Messages — ${unreadMessages} unread`
                                    : 'Messages'
                            }
                            className={INK_ICON_BUTTON}
                        >
                            <MessageSquareText className="size-5" />
                            {unreadMessages > 0 && (
                                <span className="absolute -top-0.5 -right-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-none font-bold text-primary-foreground shadow-sm">
                                    {unreadMessages > 99
                                        ? '99+'
                                        : unreadMessages}
                                </span>
                            )}
                        </Link>
                    )}
                    <InboxMenus tone="ink" />
                    {auth.user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label={`Open user menu for ${auth.user.name}`}
                                    className="ml-0.5 flex size-9 shrink-0 items-center justify-center rounded-full transition-shadow outline-none hover:ring-2 hover:ring-sidebar-border focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                                >
                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={auth.user.avatar}
                                            alt={auth.user.name}
                                        />
                                        <AvatarFallback className="rounded-full bg-sidebar-accent text-xs text-sidebar-accent-foreground">
                                            {getInitials(auth.user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-56" align="end">
                                <UserMenuContent user={auth.user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </header>
        </>
    );
}
