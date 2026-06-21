/* eslint-disable no-restricted-syntax -- The My HR hero is a bespoke employee
 * self-service band: the clock dissolves into the brand gradient, the avatar
 * opens an account popover, stats are link-buttons and the footer carries a
 * calendar + "needs you" strip. These are custom on-gradient layout surfaces
 * (raw <button>/<div>), not shadcn <Button>/<Card> cases. Colours stay
 * token-based (primary / primary-foreground; amber injected as a CSS var) so
 * tenant white-label theming still propagates. */
import { router } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    Check,
    ChevronDown,
    ChevronRight,
    LogOut,
    MapPin,
    MessagesSquare,
    PenLine,
    ScrollText,
    Send,
    User,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

import { MyHrCalendar } from './my-hr-calendar';
import { MyHrClockCard } from './my-hr-clock-card';
import type { MyHrShellData } from './my-hr-types';

export type MyHrHeroHandlers = {
    onRequestLeave?: () => void;
    onSendKudos?: () => void;
    onPrep1on1?: () => void;
};

/** Hero-scoped palette. `--primary` is the tenant brand (Settings → Branding)
 *  so the gradient re-themes per tenant; the bright amber is tuned for the
 *  open-actions value + "needs you" dots reading on the purple band. */
const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    ['--hr-amber-soft' as string]:
        'color-mix(in oklch, oklch(0.86 0.13 90) 25%, transparent)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 60%, color-mix(in oklch, var(--primary) 92%, white 6%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

function greetingFor(hour: number): { greeting: string; wave: string } {
    if (hour < 12) return { greeting: 'Mōrena', wave: '🌅' };
    if (hour < 17) return { greeting: 'Kia ora', wave: '☀️' };
    return { greeting: 'Pō mārie', wave: '🌙' };
}

function formatNextShift(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const weekday = d.toLocaleDateString('en-NZ', { weekday: 'short' });
    const h = d.getHours();
    const m = d.getMinutes();
    const ampm = h < 12 ? 'a' : 'p';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${weekday} ${h12}:${String(m).padStart(2, '0')}${ampm}`;
}

/**
 * The shared My HR hero — brand-gradient employee self-service band with a
 * time-aware te-reo greeting, the date / role / site meta line, an avatar
 * account popover, four glanceable stat links, three quick actions, the inline
 * glass clock panel, and a footer carrying the month calendar + a "needs you"
 * action strip. Rendered above the tab strip on every `/hr/my/*` page via
 * {@link MyHrShell}.
 */
export function MyHrHero({
    myHr,
    handlers,
}: {
    myHr: MyHrShellData;
    handlers?: MyHrHeroHandlers;
}) {
    const { profile, counts, weekly, nextShift, calendar } = myHr;
    const { greeting, wave } = greetingFor(new Date().getHours());
    const dateLabel = new Date().toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    const [avatarOpen, setAvatarOpen] = useState(false);
    const [calOpen, setCalOpen] = useState(false);

    // Esc closes the account popover.
    useEffect(() => {
        if (!avatarOpen) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setAvatarOpen(false);
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [avatarOpen]);

    const openActions = counts.docsToSign + counts.policiesDue + counts.onesToAck;
    const primaryNeed =
        counts.docsToSign > 0
            ? '/hr/my/documents'
            : counts.policiesDue > 0
              ? '/hr/my/policies'
              : counts.onesToAck > 0
                ? '/hr/my/one'
                : '/hr/my';

    const requestLeave =
        handlers?.onRequestLeave ?? (() => router.visit('/hr/my/leave'));
    const sendKudos = handlers?.onSendKudos ?? (() => router.visit('/hr/my'));
    const prep1on1 = handlers?.onPrep1on1 ?? (() => router.visit('/hr/my/one'));

    // "Needs you" chips, one per non-zero open-actions category. Collapses to a
    // single summary chip at 4+ total tasks (mirrors the Open-actions stat).
    const needs: { label: string; icon: LucideIcon; href: string }[] = [];
    if (counts.docsToSign > 0)
        needs.push({
            label: `Sign ${counts.docsToSign} document${counts.docsToSign === 1 ? '' : 's'}`,
            icon: PenLine,
            href: '/hr/my/documents',
        });
    if (counts.policiesDue > 0)
        needs.push({
            label: `Attest ${counts.policiesDue} polic${counts.policiesDue === 1 ? 'y' : 'ies'}`,
            icon: ScrollText,
            href: '/hr/my/policies',
        });
    if (counts.onesToAck > 0)
        needs.push({
            label: `Acknowledge ${counts.onesToAck} 1:1${counts.onesToAck === 1 ? '' : 's'}`,
            icon: Check,
            href: '/hr/my/one',
        });
    const needsCollapsed = openActions >= 4;

    const monthShiftCount = Object.entries(calendar.events).reduce(
        (n, [date, evs]) =>
            date.startsWith(calendar.month)
                ? n + evs.filter((e) => e.type === 'shift').length
                : n,
        0,
    );
    const monthLabel = new Date(
        Number(calendar.month.split('-')[0]),
        Number(calendar.month.split('-')[1]) - 1,
        1,
    ).toLocaleDateString('en-NZ', { month: 'long', year: 'numeric' });

    return (
        <div
            style={HERO_STYLE}
            className="relative rounded-[24px] text-primary-foreground"
        >
            {/* decorative orb */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute right-[24%] -top-20 h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[520px] p-[34px_36px]">
                    <div className="flex items-center gap-4">
                        {/* avatar + account popover */}
                        <div className="relative flex-none">
                            <button
                                type="button"
                                onClick={() => setAvatarOpen((v) => !v)}
                                aria-label="Account menu"
                                aria-haspopup="menu"
                                aria-expanded={avatarOpen}
                                className="flex h-[60px] w-[60px] items-center justify-center overflow-hidden rounded-full border-2 border-primary-foreground/25 bg-primary-foreground/15 text-xl font-bold text-primary-foreground transition-transform duration-200 hover:scale-[1.09] hover:shadow-[0_12px_26px_-8px_rgba(0,0,0,0.45),0_0_0_4px_rgba(255,255,255,0.32)] motion-reduce:transition-none"
                            >
                                {profile.avatar ? (
                                    <img
                                        src={profile.avatar}
                                        alt=""
                                        className="h-full w-full object-cover"
                                    />
                                ) : (
                                    profile.initials
                                )}
                            </button>
                            {avatarOpen ? (
                                <>
                                    <button
                                        type="button"
                                        aria-label="Close account menu"
                                        onClick={() => setAvatarOpen(false)}
                                        className="fixed inset-0 z-[55] cursor-default"
                                    />
                                    <div
                                        role="menu"
                                        aria-label="Account"
                                        className="absolute left-0 top-[calc(100%+11px)] z-[56] min-w-[224px] rounded-[13px] border border-border bg-popover p-1.5 text-popover-foreground shadow-[0_26px_60px_-18px_rgba(20,10,40,0.5)] animate-in fade-in-0 slide-in-from-top-1 duration-150 motion-reduce:animate-none"
                                    >
                                        <div className="flex items-center gap-3 px-2.5 pb-2.5 pt-2">
                                            <div className="flex h-[38px] w-[38px] flex-none items-center justify-center rounded-full bg-primary text-[13px] font-bold text-primary-foreground">
                                                {profile.initials}
                                            </div>
                                            <div className="min-w-0">
                                                <div className="truncate text-[13.5px] font-bold">
                                                    {profile.name}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {profile.position_title ??
                                                        'Team member'}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="mx-1.5 mb-1 h-px bg-border" />
                                        <AccountItem
                                            icon={User}
                                            label="My profile"
                                            onClick={() => {
                                                setAvatarOpen(false);
                                                router.visit('/hr/my/profile');
                                            }}
                                        />
                                        <AccountItem
                                            icon={CalendarClock}
                                            label="Availability & preferences"
                                            onClick={() => {
                                                setAvatarOpen(false);
                                                router.visit('/hr/my/profile');
                                            }}
                                        />
                                        <AccountItem
                                            icon={LogOut}
                                            label="Sign out"
                                            danger
                                            onClick={() => {
                                                setAvatarOpen(false);
                                                router.post('/logout');
                                            }}
                                        />
                                    </div>
                                </>
                            ) : null}
                        </div>

                        {/* greeting + meta */}
                        <div className="min-w-0">
                            <h1 className="text-[28px] font-bold leading-[1.05] tracking-tight">
                                {greeting}, {profile.first_name}{' '}
                                <span className="text-[22px]">{wave}</span>
                            </h1>
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-primary-foreground/75">
                                <span className="inline-flex items-center gap-1.5 font-semibold">
                                    <CalendarDays className="h-[13px] w-[13px]" />
                                    {dateLabel}
                                </span>
                                {profile.position_title ? (
                                    <>
                                        <span className="text-primary-foreground/40">
                                            ·
                                        </span>
                                        <span>{profile.position_title}</span>
                                    </>
                                ) : null}
                                {profile.site_name ? (
                                    <>
                                        <span className="text-primary-foreground/40">
                                            ·
                                        </span>
                                        <span className="inline-flex items-center gap-1.5">
                                            <MapPin className="h-[13px] w-[13px]" />
                                            {profile.site_name}
                                        </span>
                                    </>
                                ) : null}
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-5 flex flex-wrap gap-0.5">
                        <HeroStat
                            label="This week"
                            value={`${weekly.total_hours.toFixed(1)}h`}
                            onClick={() => router.visit('/hr/my/time')}
                        />
                        <HeroStat
                            label="Next shift"
                            value={formatNextShift(nextShift?.starts_at ?? null)}
                            onClick={() => router.visit('/hr/my/time')}
                        />
                        <HeroStat
                            label="Open actions"
                            value={openActions}
                            amber
                            onClick={() => router.visit(primaryNeed)}
                        />
                        <HeroStat
                            label="Kudos"
                            value={counts.kudosThisMonth}
                            onClick={() => router.visit('/hr/my')}
                        />
                    </div>

                    {/* quick actions */}
                    <div className="mt-6 flex flex-wrap gap-x-[18px] gap-y-2 text-[12.5px] font-semibold">
                        <QuickAction
                            icon={CalendarDays}
                            label="Request leave"
                            onClick={requestLeave}
                        />
                        <QuickAction
                            icon={Send}
                            label="Send kudos"
                            onClick={sendKudos}
                        />
                        <QuickAction
                            icon={MessagesSquare}
                            label="Prep 1:1"
                            onClick={prep1on1}
                        />
                    </div>
                </div>

                {/* ── right column: glass clock panel ── */}
                <MyHrClockCard
                    activeClock={myHr.activeClock}
                    todayTotal={myHr.todayTotal}
                    siteName={profile.site_name}
                    nextShift={nextShift}
                />
            </div>

            {/* ── footer: calendar + needs you ── */}
            <div className="relative flex flex-wrap items-center justify-between gap-4 rounded-b-[24px] border-t border-primary-foreground/15 bg-black/[0.08] px-[22px] py-3">
                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => setCalOpen((v) => !v)}
                        aria-haspopup="dialog"
                        aria-expanded={calOpen}
                        className="inline-flex items-center gap-2.5 rounded-[10px] border border-primary-foreground/25 bg-primary-foreground/15 px-3.5 py-2 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                    >
                        <CalendarDays className="h-[15px] w-[15px]" />
                        {monthLabel}
                        <ChevronDown
                            className={cn(
                                'h-3 w-3 transition-transform duration-200 motion-reduce:transition-none',
                                calOpen && 'rotate-180',
                            )}
                        />
                    </button>
                    <span className="text-[11.5px] text-primary-foreground/65">
                        {monthShiftCount} shift{monthShiftCount === 1 ? '' : 's'} this
                        month
                    </span>
                </div>

                <div className="flex items-center gap-2.5">
                    <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                        Needs you
                    </span>
                    {openActions === 0 ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary-foreground/75">
                            <Check className="h-3.5 w-3.5" />
                            All caught up
                        </span>
                    ) : needsCollapsed ? (
                        <button
                            type="button"
                            onClick={() => router.visit(primaryNeed)}
                            className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/15 py-1.5 pl-2.5 pr-2.5 text-xs font-bold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                        >
                            <NeedsDot />
                            {openActions} tasks need you
                            <ChevronRight className="h-3 w-3" />
                        </button>
                    ) : (
                        needs.map((n) => (
                            <button
                                key={n.label}
                                type="button"
                                onClick={() => router.visit(n.href)}
                                className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/15 py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                            >
                                <NeedsDot />
                                <n.icon className="h-[13px] w-[13px]" />
                                {n.label}
                            </button>
                        ))
                    )}
                </div>

                <MyHrCalendar
                    open={calOpen}
                    onClose={() => setCalOpen(false)}
                    feed={calendar}
                />
            </div>
        </div>
    );
}

function HeroStat({
    label,
    value,
    amber,
    onClick,
}: {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                {label}
            </span>
            <span
                className={cn(
                    'text-xl font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </button>
    );
}

function QuickAction({
    icon: Icon,
    label,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-2 text-primary-foreground/90 transition-opacity hover:opacity-100 hover:text-primary-foreground"
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
        </button>
    );
}

function AccountItem({
    icon: Icon,
    label,
    onClick,
    danger,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
    danger?: boolean;
}) {
    return (
        <button
            type="button"
            role="menuitem"
            onClick={onClick}
            className={cn(
                'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[12.5px] font-semibold transition-colors hover:bg-muted',
                danger ? 'text-status-critical' : 'text-foreground',
            )}
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

function NeedsDot() {
    return (
        <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
    );
}

export default MyHrHero;
