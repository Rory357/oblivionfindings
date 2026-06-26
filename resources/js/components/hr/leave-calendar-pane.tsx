/* eslint-disable no-restricted-syntax -- The leave calendar is a bespoke roster
 * swimlane: per-staff rows × per-day columns with absolutely-positioned leave
 * bars (raw <button>/<div> + per-leave-type category colours), the view toggle
 * and site chips are custom selector buttons, not shadcn <Button>/<Card>. */
import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Search,
    TriangleAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

import { LeaveAvatar, leaveTypeMeta } from './leave-hub-parts';

export type LeaveCalendarEntry = {
    id: number;
    user_id: number;
    user_name: string;
    site: string | null;
    leave_type: string;
    period: string;
    status: string;
    hours?: number;
    reason?: string | null;
    submitted_at?: string | null;
    start: string;
    end: string;
};

export type LeaveCalendarFeed = {
    month: string;
    month_label: string;
    start: string;
    end: string;
    entries: LeaveCalendarEntry[];
    people: Array<{ user_id: number; name: string; site: string | null }>;
    public_holidays: Record<
        string,
        { name: string; is_national: boolean; region: string | null }
    >;
};

type View = 'month' | 'week' | 'day';

const pad = (n: number) => String(n).padStart(2, '0');
const DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

/** Local (not UTC) Y-m-d — matches how the day columns + winStart index are
 *  built, so "today" lines up in NZ (UTC+12/13) instead of being a day off. */
function localDateStr(d: Date): string {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
}

export function LeaveCalendarPane({
    calendar,
    currentMonth,
    onOpenEntry,
}: {
    calendar: LeaveCalendarFeed | null | undefined;
    currentMonth?: string;
    onOpenEntry?: (entry: LeaveCalendarEntry) => void;
}) {
    const [view, setView] = useState<View>('month');
    const [query, setQuery] = useState('');
    const [site, setSite] = useState<string>('all');
    // Client-side window start (index into the month's days) for week / day views.
    const [winStart, setWinStart] = useState<number>(() => {
        const today = new Date();
        return today.getDate() - 1;
    });

    const go = (month: string) =>
        router.get(
            '/hr/leave',
            { tab: 'calendar', month },
            { preserveState: true, preserveScroll: true },
        );

    const allDays = useMemo(() => {
        if (!calendar) return [];
        const [year, monthNum] = calendar.month.split('-').map(Number);
        const count = new Date(year, monthNum, 0).getDate();
        const todayStr = localDateStr(new Date());
        return Array.from({ length: count }, (_, i) => {
            const day = i + 1;
            const date = `${year}-${pad(monthNum)}-${pad(day)}`;
            const dow = new Date(year, monthNum - 1, day).getDay();
            return {
                day,
                date,
                dow,
                dowLabel: DOW[dow],
                weekend: dow === 0 || dow === 6,
                holiday: calendar.public_holidays[date],
                isToday: date === todayStr,
            };
        });
    }, [calendar]);

    const winSize = view === 'month' ? allDays.length : view === 'week' ? 7 : 1;
    const maxStart = Math.max(0, allDays.length - winSize);
    // `winStart` is the focus day; the visible window starts on the focus day
    // (Day view) or on the Monday of the focus day's week (Week view).
    const focus = Math.min(
        Math.max(0, winStart),
        Math.max(0, allDays.length - 1),
    );
    const clampedStart = (() => {
        if (view === 'month') return 0;
        if (view === 'day') return Math.min(focus, maxStart);
        const dow = allDays[focus]?.dow ?? 1; // 0=Sun … 6=Sat
        return Math.max(0, Math.min(focus - ((dow + 6) % 7), maxStart));
    })();
    const visibleDays = useMemo(
        () =>
            view === 'month'
                ? allDays
                : allDays.slice(clampedStart, clampedStart + winSize),
        [allDays, view, clampedStart, winSize],
    );

    const sites = useMemo(() => {
        if (!calendar) return [];
        return Array.from(
            new Set(
                calendar.people
                    .map((p) => p.site)
                    .filter((s): s is string => !!s),
            ),
        ).sort();
    }, [calendar]);

    const people = useMemo(() => {
        if (!calendar) return [];
        const q = query.trim().toLowerCase();
        return calendar.people.filter(
            (p) =>
                (site === 'all' || p.site === site) &&
                (q === '' || p.name.toLowerCase().includes(q)),
        );
    }, [calendar, query, site]);

    // Coverage-at-risk: the site/day with the most people concurrently off.
    const coverage = useMemo(() => {
        if (!calendar) return null;
        let peak = { site: '', date: '', count: 0 };
        const bySite: Record<string, Record<string, Set<number>>> = {};
        calendar.entries.forEach((e) => {
            const s = e.site ?? 'Unassigned';
            allDays.forEach((d) => {
                if (e.start <= d.date && e.end >= d.date) {
                    bySite[s] ??= {};
                    bySite[s][d.date] ??= new Set();
                    bySite[s][d.date].add(e.user_id);
                }
            });
        });
        Object.entries(bySite).forEach(([s, days]) =>
            Object.entries(days).forEach(([date, set]) => {
                if (set.size > peak.count)
                    peak = { site: s, date, count: set.size };
            }),
        );
        return peak.count >= 2 ? peak : null;
    }, [calendar, allDays]);

    if (!calendar) {
        return (
            <div className="rounded-[14px] border border-border bg-card py-12 text-center text-sm text-muted-foreground">
                Loading calendar…
            </div>
        );
    }

    const N = visibleDays.length;
    const visStart = visibleDays[0]?.date ?? calendar.start;
    const visEnd = visibleDays[N - 1]?.date ?? calendar.end;

    const barsFor = (userId: number) =>
        calendar.entries
            .filter(
                (e) =>
                    e.user_id === userId &&
                    e.start <= visEnd &&
                    e.end >= visStart,
            )
            .map((e) => {
                // Bar starting before the window clips to the left edge (idx 0);
                // a -1 (starts after the window) shouldn't survive the filter, but
                // clamp to the last column rather than the left edge just in case.
                const sRaw = visibleDays.findIndex((d) => d.date >= e.start);
                const sIdx = sRaw === -1 ? N - 1 : sRaw;
                let eIdx = visibleDays.findIndex((d) => d.date >= e.end);
                if (eIdx === -1) eIdx = N - 1;
                const span = Math.max(1, eIdx - sIdx + 1);
                const meta = leaveTypeMeta(e.leave_type);
                const pending = e.status === 'pending';
                return { entry: e, sIdx, span, meta, pending };
            });

    const onPrev = () => {
        if (view === 'month') {
            go(shiftMonth(calendar.month, -1));
            return;
        }
        const step = view === 'week' ? 7 : 1;
        if (focus - step < 0) {
            // Stepped before day 1 — into the previous month, near its end.
            setWinStart(9999);
            go(shiftMonth(calendar.month, -1));
        } else {
            setWinStart(focus - step);
        }
    };
    const onNext = () => {
        if (view === 'month') {
            go(shiftMonth(calendar.month, 1));
            return;
        }
        const step = view === 'week' ? 7 : 1;
        const next = focus + step;
        if (next > allDays.length - 1) {
            // Stepped past the last day — into the next month, by the overflow.
            setWinStart(next - allDays.length);
            go(shiftMonth(calendar.month, 1));
        } else {
            setWinStart(next);
        }
    };
    const onToday = () => {
        const todayMonth = currentMonth ?? calendar.month;
        if (todayMonth !== calendar.month) {
            go(todayMonth);
            return;
        }
        setWinStart(new Date().getDate() - 1);
    };

    return (
        <div className="flex flex-col gap-3.5">
            {/* toolbar: view · nav · period · legend */}
            <div className="flex flex-wrap items-center gap-2.5">
                <div className="inline-flex gap-0.5 rounded-[10px] border border-border bg-card p-0.5">
                    {(['month', 'week', 'day'] as View[]).map((v) => (
                        <button
                            key={v}
                            type="button"
                            onClick={() => setView(v)}
                            className={cn(
                                'rounded-[7px] px-3.5 py-1.5 text-xs font-bold capitalize transition-colors',
                                view === v
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {v}
                        </button>
                    ))}
                </div>
                <div className="inline-flex items-center gap-1">
                    <button
                        type="button"
                        onClick={onPrev}
                        aria-label="Previous"
                        className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-foreground hover:bg-muted"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        onClick={onToday}
                        className="h-[34px] rounded-[9px] border border-border bg-card px-3 text-[12.5px] font-bold text-foreground hover:bg-muted"
                    >
                        Today
                    </button>
                    <button
                        type="button"
                        onClick={onNext}
                        aria-label="Next"
                        className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-foreground hover:bg-muted"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </button>
                </div>
                <MonthPicker
                    value={calendar.month}
                    label={calendar.month_label}
                    onPick={go}
                />
                <div className="ml-auto flex items-center gap-3.5 text-[11.5px] text-muted-foreground">
                    <LegendSwatch kind="approved" label="Approved" />
                    <LegendSwatch kind="pending" label="Pending" />
                    <LegendSwatch kind="holiday" label="Public holiday" />
                </div>
            </div>

            {/* toolbar: search + sites */}
            <div className="flex flex-wrap items-center gap-2.5">
                <div className="relative flex items-center">
                    <Search className="pointer-events-none absolute left-2.5 h-4 w-4 text-muted-foreground" />
                    <input
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search team member…"
                        className="h-[34px] w-[210px] rounded-[10px] border border-border bg-card pr-3 pl-8 text-[13px] outline-none focus:border-primary"
                    />
                </div>
                <div className="inline-flex flex-wrap gap-0.5 rounded-[10px] border border-border bg-card p-0.5">
                    <SiteChip
                        label="All sites"
                        active={site === 'all'}
                        onClick={() => setSite('all')}
                    />
                    {sites.map((s) => (
                        <SiteChip
                            key={s}
                            label={s}
                            active={site === s}
                            onClick={() => setSite(s)}
                        />
                    ))}
                </div>
            </div>

            {/* coverage banner */}
            {coverage ? (
                <div className="flex items-center gap-2 rounded-[12px] border border-status-warning/30 bg-status-warning-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-status-warning">
                    <TriangleAlert className="h-4 w-4 flex-none" />
                    Coverage at risk —{' '}
                    <strong className="font-extrabold">
                        {coverage.site}, {coverage.date}
                    </strong>
                    : {coverage.count} team members off. Consider staggering
                    leave or arranging backfill.
                </div>
            ) : null}

            {/* grid */}
            <div className="overflow-x-auto rounded-[14px] border border-border bg-card">
                <div style={{ minWidth: 168 + N * 40 }}>
                    {/* day header */}
                    <div className="flex border-b border-border">
                        <div className="w-[168px] flex-none px-3.5 py-2.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                            Team member
                        </div>
                        <div className="flex flex-1">
                            {visibleDays.map((d) => (
                                <div
                                    key={d.date}
                                    className={cn(
                                        'flex-1 border-l border-border py-1.5 text-center',
                                        d.holiday
                                            ? 'bg-[color:var(--hr-cal-holiday)]'
                                            : d.weekend
                                              ? 'bg-muted/40'
                                              : '',
                                    )}
                                    style={
                                        {
                                            '--hr-cal-holiday':
                                                'color-mix(in oklab, var(--status-warning) 12%, var(--card))',
                                        } as React.CSSProperties
                                    }
                                    title={d.holiday?.name}
                                >
                                    <div className="text-[9.5px] font-bold text-muted-foreground">
                                        {d.dowLabel}
                                    </div>
                                    <div
                                        className={cn(
                                            'text-[12px] font-bold',
                                            d.isToday && 'text-primary',
                                        )}
                                    >
                                        {d.day}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* rows */}
                    {people.length === 0 ? (
                        <div className="px-4 py-6 text-[12.5px] text-muted-foreground">
                            {calendar.people.length === 0
                                ? 'No leave booked this month.'
                                : `No team member matches your filters.`}
                        </div>
                    ) : (
                        people.map((p) => (
                            <div
                                key={p.user_id}
                                className="flex border-b border-border last:border-b-0"
                            >
                                <div className="flex w-[168px] flex-none items-center gap-2.5 px-3.5 py-2.5">
                                    <LeaveAvatar name={p.name} size={28} />
                                    <div className="min-w-0">
                                        <div className="truncate text-[12.5px] font-bold">
                                            {p.name}
                                        </div>
                                        {p.site ? (
                                            <div className="text-[10px] text-muted-foreground">
                                                {p.site}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="relative flex flex-1">
                                    {visibleDays.map((d) => (
                                        <div
                                            key={d.date}
                                            className={cn(
                                                'min-h-[46px] flex-1 border-l border-border',
                                                d.holiday
                                                    ? 'bg-[color:var(--hr-cal-holiday)]'
                                                    : d.weekend
                                                      ? 'bg-muted/30'
                                                      : '',
                                            )}
                                            style={
                                                {
                                                    '--hr-cal-holiday':
                                                        'color-mix(in oklab, var(--status-warning) 10%, var(--card))',
                                                } as React.CSSProperties
                                            }
                                        />
                                    ))}
                                    {barsFor(p.user_id).map((b) => (
                                        <button
                                            key={b.entry.id}
                                            type="button"
                                            onClick={() =>
                                                onOpenEntry?.(b.entry)
                                            }
                                            title={`${b.entry.user_name} · ${b.meta.label}${b.pending ? ' (pending)' : ''} · ${b.entry.start} – ${b.entry.end}`}
                                            className="absolute top-[9px] flex h-7 items-center overflow-hidden rounded-[7px] border px-2 text-[10.5px] font-bold whitespace-nowrap"
                                            style={{
                                                left: `${(b.sIdx / N) * 100}%`,
                                                width: `calc(${(b.span / N) * 100}% - 4px)`,
                                                marginLeft: 2,
                                                color: b.meta.color,
                                                borderColor: `color-mix(in oklab, ${b.meta.color} ${b.pending ? '45%' : '35%'}, var(--card))`,
                                                borderStyle: b.pending
                                                    ? 'dashed'
                                                    : 'solid',
                                                background: b.pending
                                                    ? `repeating-linear-gradient(45deg, color-mix(in oklab, ${b.meta.color} 20%, var(--card)), color-mix(in oklab, ${b.meta.color} 20%, var(--card)) 3px, var(--card) 3px, var(--card) 6px)`
                                                    : `color-mix(in oklab, ${b.meta.color} 16%, var(--card))`,
                                            }}
                                        >
                                            {b.meta.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            <div className="text-[11.5px] text-muted-foreground">
                Pending leave shows hatched — click a bar to open the request.
            </div>
        </div>
    );
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** Click the period label to jump months/years via a small calendar dropdown. */
function MonthPicker({
    value,
    label,
    onPick,
}: {
    value: string;
    label: string;
    onPick: (month: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const selYear = Number(value.split('-')[0]);
    const selMonth = Number(value.split('-')[1]); // 1-12
    const [pickYear, setPickYear] = useState(selYear);

    return (
        <Popover
            open={open}
            onOpenChange={(o) => {
                setOpen(o);
                if (o) setPickYear(selYear);
            }}
        >
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="inline-flex min-w-[150px] items-center gap-1.5 rounded-[9px] px-2 py-1 text-base font-extrabold tracking-tight transition-colors hover:bg-muted"
                >
                    {label}
                    <ChevronDown className="h-4 w-4 text-muted-foreground" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-60 p-3">
                <div className="mb-2.5 flex items-center justify-between">
                    <button
                        type="button"
                        onClick={() => setPickYear((y) => y - 1)}
                        aria-label="Previous year"
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </button>
                    <span className="text-sm font-extrabold tabular-nums">
                        {pickYear}
                    </span>
                    <button
                        type="button"
                        onClick={() => setPickYear((y) => y + 1)}
                        aria-label="Next year"
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </button>
                </div>
                <div className="grid grid-cols-3 gap-1.5">
                    {MONTHS.map((m, i) => {
                        const isSel =
                            pickYear === selYear && i + 1 === selMonth;
                        return (
                            <button
                                key={m}
                                type="button"
                                onClick={() => {
                                    onPick(`${pickYear}-${pad(i + 1)}`);
                                    setOpen(false);
                                }}
                                className={cn(
                                    'rounded-md py-1.5 text-xs font-bold transition-colors',
                                    isSel
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted',
                                )}
                            >
                                {m}
                            </button>
                        );
                    })}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function LegendSwatch({
    kind,
    label,
}: {
    kind: 'approved' | 'pending' | 'holiday';
    label: string;
}) {
    const style: React.CSSProperties =
        kind === 'approved'
            ? {
                  background: 'color-mix(in oklab, var(--primary) 18%, white)',
                  border: '1px solid color-mix(in oklab, var(--primary) 35%, white)',
              }
            : kind === 'pending'
              ? {
                    background:
                        'repeating-linear-gradient(45deg, color-mix(in oklab, var(--primary) 20%, white), color-mix(in oklab, var(--primary) 20%, white) 3px, white 3px, white 6px)',
                    border: '1px dashed color-mix(in oklab, var(--primary) 45%, white)',
                }
              : {
                    background:
                        'color-mix(in oklab, var(--status-warning) 40%, white)',
                };
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-4 flex-none rounded-[3px]" style={style} />
            {label}
        </span>
    );
}

function SiteChip({
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
            className={cn(
                'rounded-[7px] px-2.5 py-1.5 text-xs font-semibold transition-colors',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted',
            )}
        >
            {label}
        </button>
    );
}

export default LeaveCalendarPane;
