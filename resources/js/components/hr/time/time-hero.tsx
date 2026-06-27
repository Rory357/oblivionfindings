/* eslint-disable no-restricted-syntax -- The Timekeeping hero is a bespoke
 * manager command band mirroring the People/My-HR heroes: HeroStats are
 * link-buttons, quick-actions and alert chips sit on the brand gradient, and the
 * right rail toggles a live "on now" avatar stack against a weekly-hours bar.
 * These are custom on-gradient surfaces (raw <button>/<div>); every colour is a
 * semantic token (primary / status-* / --hr-amber) so tenant theming propagates. */
import { router } from '@inertiajs/react';
import {
    ArrowRight,
    Clock,
    Download,
    FileText,
    Plus,
    UserPlus,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

import { formatElapsed, type KpiStats, type OnNowItem, type WeeklyDay } from './types';

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

export type TimeHeroHandlers = {
    onAddEntry?: () => void;
    onClockOnBehalf?: () => void;
    onReviewTimesheets: () => void;
    onExport?: () => void;
    onStatOnNow: () => void;
    onStatHours: () => void;
    onStatApproval: () => void;
    onStatExceptions: () => void;
    onViewAllOnNow: () => void;
};

export type TimeAlertChip = { key: string; label: string; onClick: () => void };

/**
 * The Timekeeping hero — a brand-gradient manager oversight band. NO personal
 * clock (clocking lives on My Day / My HR). Left: title, four glanceable
 * HeroStats, quick actions and live alert chips. Right: a toggle between the
 * live "on now" avatar stack and a weekly team-hours bar (persisted to
 * localStorage). Non-managers get the slim read-only fallback band.
 */
export function TimeHero({
    tenantName,
    isManager,
    kpi,
    onNow,
    weekly,
    alerts = [],
    handlers,
    selfHoursWeek,
    isOnClock,
}: {
    tenantName: string;
    isManager: boolean;
    kpi: KpiStats;
    onNow: OnNowItem[];
    weekly: WeeklyDay[];
    alerts?: TimeAlertChip[];
    handlers?: TimeHeroHandlers;
    selfHoursWeek: number;
    isOnClock: boolean;
}) {
    const [right, setRight] = useState<'onNow' | 'hours'>('onNow');

    useEffect(() => {
        const stored = window.localStorage.getItem('hrTime.heroRight');
        if (stored === 'onNow' || stored === 'hours') setRight(stored);
    }, []);

    const setHero = (mode: 'onNow' | 'hours') => {
        setRight(mode);
        window.localStorage.setItem('hrTime.heroRight', mode);
    };

    if (!isManager) {
        return <WorkerHero hoursWeek={selfHoursWeek} isOnClock={isOnClock} />;
    }

    const maxDay = Math.max(1, ...weekly.map((d) => d.hours));

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[30px_34px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Clock className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] font-bold leading-[1.05] tracking-tight">
                                Timekeeping
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                Review team time, fix exceptions and keep {tenantName}{' '}
                                payroll-ready
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-[18px] flex flex-wrap gap-0.5">
                        <HeroStat
                            label="Clocked in now"
                            value={kpi.clocked_in_now}
                            onClick={handlers?.onStatOnNow}
                        />
                        <HeroStat
                            label="Team hours · this week"
                            value={kpi.team_hours_week}
                            suffix="h"
                            onClick={handlers?.onStatHours}
                        />
                        <HeroStat
                            label="Awaiting approval"
                            value={kpi.awaiting_approval}
                            amber={kpi.awaiting_approval > 0}
                            onClick={handlers?.onStatApproval}
                        />
                        <HeroStat
                            label="Exceptions"
                            value={kpi.exceptions_count}
                            amber={kpi.exceptions_count > 0}
                            onClick={handlers?.onStatExceptions}
                        />
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {handlers?.onAddEntry ? (
                            <button
                                type="button"
                                onClick={handlers.onAddEntry}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <Plus className="h-[15px] w-[15px]" />
                                Add time entry
                            </button>
                        ) : null}
                        {handlers?.onClockOnBehalf ? (
                            <QuickAction
                                icon={UserPlus}
                                label="Clock on behalf"
                                onClick={handlers.onClockOnBehalf}
                            />
                        ) : null}
                        {handlers?.onReviewTimesheets ? (
                            <QuickAction
                                icon={FileText}
                                label="Review shift timesheets"
                                onClick={handlers.onReviewTimesheets}
                            />
                        ) : null}
                        {handlers?.onExport ? (
                            <QuickAction
                                icon={Download}
                                label="Export"
                                onClick={handlers.onExport}
                            />
                        ) : null}
                    </div>

                    {/* alert chips */}
                    {alerts.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                                Needs attention
                            </span>
                            {alerts.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={chip.onClick}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: on now / hours ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[20px_22px] sm:w-[332px] sm:border-l sm:border-t-0">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/55">
                            On the clock
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                            <RailTab
                                label="On now"
                                active={right === 'onNow'}
                                onClick={() => setHero('onNow')}
                            />
                            <RailTab
                                label="Hours"
                                active={right === 'hours'}
                                onClick={() => setHero('hours')}
                            />
                        </div>
                    </div>

                    {right === 'onNow' ? (
                        <div className="mt-1 flex flex-col gap-[7px]">
                            {onNow.length === 0 ? (
                                <p className="py-4 text-center text-[12px] text-primary-foreground/65">
                                    No one is clocked in right now.
                                </p>
                            ) : (
                                onNow.slice(0, 5).map((p) => (
                                    <div key={p.id} className="flex items-center gap-2.5">
                                        <span className="relative grid h-[30px] w-[30px] flex-none place-items-center rounded-full bg-primary-foreground/[0.18] text-[11px] font-bold">
                                            {p.initials}
                                            <span className="absolute -bottom-px -right-px h-2 w-2 rounded-full bg-[color:var(--hr-amber)] ring-2 ring-[color:color-mix(in_oklch,var(--primary)_60%,black)]" />
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-[12.5px] font-semibold">
                                            {p.name}
                                        </span>
                                        <span className="text-[11px] font-bold tabular-nums text-primary-foreground/85">
                                            {formatElapsed(p.elapsed_minutes)}
                                        </span>
                                    </div>
                                ))
                            )}
                            {onNow.length > 5 ? (
                                <button
                                    type="button"
                                    onClick={handlers?.onViewAllOnNow}
                                    className="mt-1 text-left text-[11.5px] font-semibold text-primary-foreground/80 hover:text-primary-foreground"
                                >
                                    +{onNow.length - 5} more · view all →
                                </button>
                            ) : null}
                        </div>
                    ) : (
                        <>
                            <div className="mt-2 flex h-[120px] items-end gap-2">
                                {weekly.map((d) => (
                                    <div
                                        key={d.date}
                                        className="flex h-full flex-1 flex-col items-center justify-end gap-1.5"
                                    >
                                        <span className="text-[9.5px] font-semibold tabular-nums text-primary-foreground/70">
                                            {d.hours > 0 ? d.hours : ''}
                                        </span>
                                        <div
                                            className="w-full rounded-[4px] bg-primary-foreground/85 transition-[height] motion-reduce:transition-none"
                                            style={{
                                                height: `${Math.max(3, (d.hours / maxDay) * 88)}px`,
                                            }}
                                        />
                                        <span className="text-[10px] font-semibold text-primary-foreground/60">
                                            {d.day}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-2 text-[11.5px] text-primary-foreground/70">
                                {kpi.team_hours_week}h logged · {kpi.avg_hours_per_day}h
                                avg/day
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

function WorkerHero({ hoursWeek, isOnClock }: { hoursWeek: number; isOnClock: boolean }) {
    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="relative flex flex-wrap items-center gap-6 p-[26px_32px]">
                <span className="grid h-[50px] w-[50px] flex-none place-items-center rounded-[15px] border border-primary-foreground/20 bg-primary-foreground/15">
                    <Clock className="h-6 w-6" />
                </span>
                <div className="min-w-0 flex-1">
                    <h1 className="text-[24px] font-bold tracking-tight">Timekeeping</h1>
                    <p className="mt-1 text-[13px] font-medium text-primary-foreground/[0.78]">
                        Your hours at a glance — clocking happens on My Day
                    </p>
                </div>
                <div className="flex items-center gap-6">
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                            Your hours · this week
                        </div>
                        <div className="text-[24px] font-bold tabular-nums">
                            {hoursWeek}
                            <span className="text-[15px] text-primary-foreground/70">h</span>
                        </div>
                    </div>
                    <div>
                        <div className="text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                            Status
                        </div>
                        <div className="mt-1 inline-flex items-center gap-1.5 text-[15px] font-bold">
                            <span
                                className="h-2 w-2 rounded-full"
                                style={{
                                    background: isOnClock
                                        ? 'var(--status-success)'
                                        : 'var(--hr-amber)',
                                }}
                            />
                            {isOnClock ? 'On the clock' : 'Off the clock'}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.visit('/my-day')}
                        className="inline-flex h-10 items-center gap-2 rounded-[10px] bg-primary-foreground px-[18px] text-[13px] font-bold text-primary"
                    >
                        <Clock className="h-[15px] w-[15px]" />
                        Clock in on My Day
                        <ArrowRight className="h-[15px] w-[15px]" />
                    </button>
                </div>
            </div>
            <div className="relative border-t border-primary-foreground/[0.14] bg-black/[0.08] px-8 py-3 text-[12px] text-primary-foreground/[0.78]">
                You&apos;re seeing the read-only view. Team review, corrections and
                approvals are for team leaders &amp; admins.
            </div>
        </div>
    );
}

function HeroStat({
    label,
    value,
    suffix,
    amber,
    onClick,
}: {
    label: string;
    value: string | number;
    suffix?: string;
    amber?: boolean;
    onClick?: () => void;
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
                    'text-[22px] font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
                {suffix ? (
                    <span className="text-[15px] font-semibold text-primary-foreground/70">
                        {suffix}
                    </span>
                ) : null}
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
            className="inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

function RailTab({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'h-6 rounded-md px-2.5 text-[11px] font-bold transition-colors',
                active
                    ? 'bg-primary-foreground text-primary'
                    : 'text-primary-foreground/80 hover:text-primary-foreground',
            )}
        >
            {label}
        </button>
    );
}

export default TimeHero;
