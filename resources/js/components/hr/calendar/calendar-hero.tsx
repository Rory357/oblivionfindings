/* eslint-disable no-restricted-syntax -- Like the People hero, the Calendar
 * command band is a bespoke on-gradient surface: HeroStats are link-buttons,
 * quick-actions + "needs attention" chips sit on the brand gradient, and the
 * right rail is a custom "Up next" list. These are raw <button> layout cases,
 * not shadcn <Button>/<Card>. Colours stay token-based (primary / status-* /
 * --hr-amber injected as a CSS var) so tenant white-label theming propagates.
 * Mirrors resources/js/components/hr/people-hero.tsx. No clock — manager lens. */
import {
    CalendarDays,
    CalendarPlus,
    Layers,
    LocateFixed,
    Rss,
    type LucideIcon,
} from 'lucide-react';
import { type CSSProperties } from 'react';

import { type CalendarLayer } from '@/lib/calendar/layer-feed';
import { cn } from '@/lib/utils';

export type CalendarHeroStats = {
    eventsThisWeek: number;
    onLeaveToday: number;
    coverageGapsToday: number;
    renewalsSoon: number;
};

export type UpNextEntry = {
    id: string;
    layer: CalendarLayer;
    title: string;
    start: string;
    allDay: boolean;
    deepLink: string | null;
};

export type CalendarNeedChip = {
    key: string;
    label: string;
    onClick: () => void;
};

export type CalendarHeroHandlers = {
    onNewEvent?: () => void;
    onToday?: () => void;
    onSubscribe?: () => void;
    onManageLayers?: () => void;
    onStatEvents?: () => void;
    onStatLeave?: () => void;
    onStatGaps?: () => void;
    onStatRenewals?: () => void;
    onUpNext?: (entry: UpNextEntry) => void;
};

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

/** Per-layer swatch token for the "Up next" dots. */
const LAYER_DOT: Record<CalendarLayer, string> = {
    event: 'var(--category-hr)',
    leave: 'var(--status-neutral)',
    shift: 'var(--live)',
    holiday: 'var(--status-warning)',
    compliance: 'var(--status-critical)',
    milestone: 'var(--category-finance)',
};

function relativeDay(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(d);
    target.setHours(0, 0, 0, 0);
    const days = Math.round((target.getTime() - today.getTime()) / 86_400_000);
    if (days === 0) return 'Today';
    if (days === 1) return 'Tomorrow';
    if (days > 1 && days < 7)
        return target.toLocaleDateString('en-NZ', { weekday: 'short' });
    return target.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

/**
 * The `/hr/calendar` hero — a brand-gradient command band above the tab strip.
 * Left: medallion + "Calendar" + context, four glanceable stats (Events this
 * week / On leave today / Coverage gaps today / Renewals ≤30d), quick actions
 * and a "needs attention" chip row. Right: a fixed-width "Up next" rail listing
 * the next handful of entries across active layers. No clock.
 */
export function CalendarHero({
    stats,
    upNext,
    canManage,
    siteCount,
    needs = [],
    handlers,
}: {
    stats: CalendarHeroStats;
    upNext: UpNextEntry[];
    canManage: boolean;
    siteCount: number;
    needs?: CalendarNeedChip[];
    handlers?: CalendarHeroHandlers;
}) {
    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[24%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[32px_36px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <CalendarDays className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                Calendar
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                Everything happening across the organisation —
                                events, leave, shifts and renewals in one view
                                {siteCount > 0
                                    ? ` · ${siteCount} ${siteCount === 1 ? 'site' : 'sites'}`
                                    : ''}
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="mt-[18px] -ml-3 flex flex-wrap gap-0.5">
                        <HeroStat
                            label="Events · this week"
                            value={stats.eventsThisWeek}
                            onClick={handlers?.onStatEvents}
                        />
                        <HeroStat
                            label="On leave · today"
                            value={stats.onLeaveToday}
                            onClick={handlers?.onStatLeave}
                        />
                        <HeroStat
                            label="Coverage gaps · today"
                            value={stats.coverageGapsToday}
                            amber={stats.coverageGapsToday > 0}
                            onClick={handlers?.onStatGaps}
                        />
                        <HeroStat
                            label="Renewals ≤30d"
                            value={stats.renewalsSoon}
                            amber={stats.renewalsSoon > 0}
                            onClick={handlers?.onStatRenewals}
                        />
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {canManage && handlers?.onNewEvent ? (
                            <button
                                type="button"
                                onClick={handlers.onNewEvent}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <CalendarPlus className="h-[15px] w-[15px]" />
                                New event
                            </button>
                        ) : null}
                        {handlers?.onToday ? (
                            <QuickAction
                                icon={LocateFixed}
                                label="Today"
                                onClick={handlers.onToday}
                            />
                        ) : null}
                        {handlers?.onSubscribe ? (
                            <QuickAction
                                icon={Rss}
                                label="Subscribe"
                                onClick={handlers.onSubscribe}
                            />
                        ) : null}
                        {handlers?.onManageLayers ? (
                            <QuickAction
                                icon={Layers}
                                label="Manage layers"
                                onClick={handlers.onManageLayers}
                            />
                        ) : null}
                    </div>

                    {/* needs attention */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/50 uppercase">
                                Needs attention
                            </span>
                            {needs.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={chip.onClick}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pr-3 pl-2.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: Up next ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[340px] sm:border-t-0 sm:border-l">
                    <span className="mb-2 text-[10px] font-bold tracking-[0.1em] text-primary-foreground/55 uppercase">
                        Up next
                    </span>
                    {upNext.length === 0 ? (
                        <p className="mt-2 text-[12px] text-primary-foreground/60">
                            Nothing scheduled in the next 30 days.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1">
                            {upNext.map((entry) => (
                                <li key={entry.id}>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            handlers?.onUpNext?.(entry)
                                        }
                                        className="flex w-full items-center gap-2.5 rounded-[10px] px-2 py-1.5 text-left transition-colors hover:bg-primary-foreground/10"
                                    >
                                        <span
                                            className="h-2 w-2 flex-none rounded-full"
                                            style={{
                                                background:
                                                    LAYER_DOT[entry.layer],
                                            }}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-[12.5px] font-medium text-primary-foreground/90">
                                            {entry.title}
                                        </span>
                                        <span className="flex-none text-[11px] font-semibold text-primary-foreground/60 tabular-nums">
                                            {relativeDay(entry.start)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
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
    onClick?: () => void;
}) {
    const inner = (
        <>
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'text-[22px] font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </>
    );
    if (!onClick) {
        return (
            <span className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left">
                {inner}
            </span>
        );
    }
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            {inner}
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
            className="inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

export default CalendarHero;
