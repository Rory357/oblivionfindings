/* eslint-disable no-restricted-syntax -- The overview board uses styled native
 * <button>s for the row reveal-on-hover actions and recent-activity rows (custom
 * layout surfaces, not shadcn <Button> cases). Colours stay token-based. */
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    Clock,
    Coffee,
    Moon,
    TimerReset,
} from 'lucide-react';
import { type MouseEvent } from 'react';

import { cn } from '@/lib/utils';

import {
    avatarStyle,
    formatElapsed,
    payTypeLabel,
    type ExceptionItem,
    type OnNowItem,
    type RecentActivityItem,
    type WeeklyDay,
} from './types';

const EXCEPTION_ICON: Record<string, typeof AlertTriangle> = {
    missed_clock_out: TimerReset,
    break_fail: Coffee,
    overtime: AlertTriangle,
    loadings: Moon,
};

const SEVERITY_TONE: Record<string, { wrap: string; badge: string }> = {
    critical: {
        wrap: 'bg-status-critical-bg text-status-critical',
        badge: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    },
    warning: {
        wrap: 'bg-status-warning-bg text-status-warning',
        badge: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    },
    info: {
        wrap: 'bg-status-info-bg text-status-info',
        badge: 'border-status-info/30 bg-status-info-bg text-status-info',
    },
};

export function OverviewPane({
    exceptions,
    weekly,
    teamHoursWeek,
    onNow,
    onNowCount,
    recent,
    onException,
    onExceptionContext,
    onPersonContext,
    onActivityClick,
}: {
    exceptions: ExceptionItem[];
    weekly: WeeklyDay[];
    teamHoursWeek: number;
    onNow: OnNowItem[];
    onNowCount: number;
    recent: RecentActivityItem[];
    onException: (e: ExceptionItem) => void;
    onExceptionContext: (e: ExceptionItem, ev: MouseEvent) => void;
    onPersonContext: (p: OnNowItem, ev: MouseEvent) => void;
    onActivityClick: (r: RecentActivityItem) => void;
}) {
    const maxDay = Math.max(1, ...weekly.map((d) => d.hours));
    const today = new Date().toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    return (
        <div className="grid items-start gap-[18px] lg:grid-cols-[1fr_360px]">
            {/* ── left column ── */}
            <div className="flex min-w-0 flex-col gap-[18px]">
                {/* Exceptions board */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center justify-between border-b border-border px-[18px] py-4">
                        <div className="flex items-center gap-2.5">
                            <span className="grid h-[30px] w-[30px] place-items-center rounded-[9px] bg-status-warning-bg text-status-warning">
                                <AlertTriangle className="h-[17px] w-[17px]" />
                            </span>
                            <div>
                                <h2 className="text-[15px] font-bold">Exceptions</h2>
                                <p className="mt-px text-[12px] text-muted-foreground">
                                    {exceptions.length === 0
                                        ? 'All clear'
                                        : `${exceptions.length} ${exceptions.length === 1 ? 'item needs' : 'items need'} a look`}
                                </p>
                            </div>
                        </div>
                        <span className="text-[11.5px] font-semibold text-muted-foreground">
                            {today}
                        </span>
                    </div>

                    {exceptions.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-5 py-[42px] text-center">
                            <span className="grid h-[50px] w-[50px] place-items-center rounded-[15px] bg-status-success-bg text-status-success">
                                <CheckCircle2 className="h-7 w-7" />
                            </span>
                            <div className="text-[14.5px] font-bold">No exceptions</div>
                            <div className="max-w-[280px] text-[12.5px] text-muted-foreground">
                                Everyone&apos;s accounted for. Breaks, clock-outs,
                                overtime and loadings all check out for today.
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col">
                            {exceptions.map((e) => {
                                const Icon = EXCEPTION_ICON[e.kind] ?? AlertTriangle;
                                const tone = SEVERITY_TONE[e.severity];
                                return (
                                    <div
                                        key={e.id}
                                        onContextMenu={(ev) => onExceptionContext(e, ev)}
                                        className="group relative flex items-center gap-3.5 border-b border-border px-[18px] py-[13px] transition-colors last:border-b-0 hover:bg-muted/60 hover:shadow-[inset_3px_0_0_var(--primary)]"
                                    >
                                        <span
                                            className={cn(
                                                'grid h-9 w-9 flex-none place-items-center rounded-[10px]',
                                                tone.wrap,
                                            )}
                                        >
                                            <Icon className="h-[18px] w-[18px]" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-[13.5px] font-bold">
                                                    {e.title}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'rounded-full border px-2 py-0.5 text-[10.5px] font-semibold',
                                                        tone.badge,
                                                    )}
                                                >
                                                    {e.badge}
                                                </span>
                                            </div>
                                            <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                                                {e.detail}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => onException(e)}
                                            className="inline-flex h-[30px] flex-none items-center gap-1.5 rounded-lg border border-border bg-card px-3 text-[12px] font-semibold opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 motion-reduce:opacity-100"
                                        >
                                            {e.action === 'correct'
                                                ? 'Correct'
                                                : e.action === 'edit'
                                                  ? 'Amend'
                                                  : 'View'}
                                            <ArrowRight className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                {/* Weekly hours */}
                <section className="rounded-2xl border border-border bg-card px-[18px] py-4">
                    <div className="mb-3.5 flex items-center justify-between">
                        <h2 className="text-[15px] font-bold">Team hours · this week</h2>
                        <span className="text-[12px] font-semibold text-muted-foreground">
                            <span className="text-foreground">{teamHoursWeek}h</span> logged
                        </span>
                    </div>
                    <div className="flex h-[130px] items-end gap-3.5">
                        {weekly.map((d) => (
                            <div
                                key={d.date}
                                className="flex h-full flex-1 flex-col items-center justify-end gap-1.5"
                            >
                                <span className="text-[11px] font-bold tabular-nums">
                                    {d.hours > 0 ? d.hours : ''}
                                </span>
                                <div
                                    className="w-full rounded-[5px] bg-primary/80 transition-[height] motion-reduce:transition-none"
                                    style={{
                                        height: `${Math.max(4, (d.hours / maxDay) * 96)}px`,
                                    }}
                                />
                                <span className="text-[11px] font-semibold text-muted-foreground">
                                    {d.day}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            </div>

            {/* ── right column ── */}
            <div className="flex min-w-0 flex-col gap-[18px]">
                {/* On now */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center gap-2 border-b border-border px-4 py-3.5">
                        <span className="h-2 w-2 rounded-full bg-status-info motion-safe:animate-pulse" />
                        <h2 className="flex-1 text-[15px] font-bold">On now</h2>
                        <span className="rounded-full bg-status-info-bg px-2.5 py-0.5 text-[12px] font-bold text-status-info">
                            {onNowCount} clocked in
                        </span>
                    </div>
                    <div className="flex max-h-[330px] flex-col overflow-y-auto">
                        {onNow.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-5 py-10 text-center">
                                <Clock className="h-7 w-7 text-muted-foreground/50" />
                                <div className="text-[13px] font-semibold text-muted-foreground">
                                    No one is clocked in
                                </div>
                            </div>
                        ) : (
                            onNow.map((p) => (
                                <div
                                    key={p.id}
                                    onContextMenu={(ev) => onPersonContext(p, ev)}
                                    className="flex items-center gap-2.5 px-4 py-2.5 transition-colors hover:bg-muted/60 hover:shadow-[inset_3px_0_0_var(--primary)]"
                                >
                                    <span
                                        className="relative grid h-[34px] w-[34px] flex-none place-items-center rounded-full text-[12px] font-bold"
                                        style={avatarStyle(p.user_id)}
                                    >
                                        {p.initials}
                                        <span className="absolute -bottom-px -right-px h-[9px] w-[9px] rounded-full bg-status-info ring-2 ring-card" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-semibold">
                                            {p.name}
                                        </div>
                                        <div className="truncate text-[11.5px] text-muted-foreground">
                                            {p.meta}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-[12.5px] font-bold tabular-nums">
                                            {formatElapsed(p.elapsed_minutes)}
                                        </div>
                                        {p.pay_type !== 'standard' ? (
                                            <span className="text-[10.5px] font-semibold text-muted-foreground">
                                                {payTypeLabel(p.pay_type)}
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </section>

                {/* Recent activity */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center gap-2 border-b border-border px-4 py-3.5">
                        <CalendarClock className="h-4 w-4 text-muted-foreground" />
                        <h2 className="text-[15px] font-bold">Recent activity</h2>
                    </div>
                    <div className="flex flex-col py-1">
                        {recent.length === 0 ? (
                            <div className="px-4 py-8 text-center text-[12.5px] text-muted-foreground">
                                Nothing logged yet today.
                            </div>
                        ) : (
                            recent.map((r) => (
                                <button
                                    key={r.id}
                                    type="button"
                                    onClick={() => onActivityClick(r)}
                                    className="flex items-center gap-2.5 px-4 py-2 text-left transition-colors hover:bg-muted/60 hover:shadow-[inset_3px_0_0_var(--primary)]"
                                >
                                    <span
                                        className={cn(
                                            'h-2 w-2 flex-none rounded-full',
                                            r.action.includes('out')
                                                ? 'bg-muted-foreground/50'
                                                : 'bg-status-success',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1 truncate text-[12.5px]">
                                        <span className="font-semibold">{r.user_name}</span>{' '}
                                        <span className="text-muted-foreground">
                                            {r.action}
                                        </span>
                                        {r.on_behalf ? (
                                            <span className="ml-1 text-status-warning">
                                                · on behalf
                                            </span>
                                        ) : null}
                                    </span>
                                    <span className="flex-none text-[11.5px] tabular-nums text-muted-foreground">
                                        {r.time} ago
                                    </span>
                                </button>
                            ))
                        )}
                    </div>
                </section>
            </div>
        </div>
    );
}

export default OverviewPane;
