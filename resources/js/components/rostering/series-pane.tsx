import { CalendarClock, Plus, Repeat, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

/* ------------------------------------------------------------------ */
/*  Shared types (also consumed by the series pop-up)                  */
/* ------------------------------------------------------------------ */

export type RosterSeriesRow = {
    id: number;
    status: string;
    shift_type?: string | null;
    client: { id: number; name: string } | null;
    site?: { id: number; name: string; type?: string | null } | null;
    staff: { id: number; name: string } | null;
    service_context: { id: number; name: string; type?: string | null } | null;
    location?: string | null;
    weekdays: string[];
    starts_time?: string | null;
    ends_time?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    start_date?: string | null;
    end_date?: string | null;
    occurrences_total: number;
    active_occurrences_count: number;
    open_occurrences_count: number;
    replacement_occurrences_count: number;
    next_starts_at?: string | null;
};

const SERIES_DAYS: { code: string; label: string }[] = [
    { code: 'mon', label: 'Mon' },
    { code: 'tue', label: 'Tue' },
    { code: 'wed', label: 'Wed' },
    { code: 'thu', label: 'Thu' },
    { code: 'fri', label: 'Fri' },
    { code: 'sat', label: 'Sat' },
    { code: 'sun', label: 'Sun' },
];

export type SeriesPaneProps = {
    /** null = not yet loaded (lazy). */
    series: RosterSeriesRow[] | null;
    loading?: boolean;
    canManage: boolean;
    onView: (series: RosterSeriesRow) => void;
    onNewRecurring: () => void;
};

function seriesTitle(row: RosterSeriesRow): string {
    return row.client?.name ?? 'Recurring support series';
}

function timeLabel(row: RosterSeriesRow): string {
    if (!row.starts_time || !row.ends_time) return 'Time not set';
    const overnight = row.ends_time <= row.starts_time;
    return `${row.starts_time}–${row.ends_time}${overnight ? ' overnight' : ''}`;
}

/* ------------------------------------------------------------------ */
/*  Week strip — which weekdays the pattern runs                       */
/* ------------------------------------------------------------------ */

function WeekStrip({ weekdays }: { weekdays: string[] }) {
    const set = useMemo(() => new Set(weekdays), [weekdays]);
    return (
        <div className="flex gap-1">
            {SERIES_DAYS.map((day) => {
                const on = set.has(day.code);
                return (
                    <div
                        key={day.code}
                        title={day.label}
                        className={cn(
                            'flex h-8 flex-1 items-center justify-center rounded-md border text-[10px] font-semibold tracking-wide uppercase',
                            on
                                ? 'border-primary/30 bg-primary/10 text-primary'
                                : 'border-border bg-muted/40 text-muted-foreground',
                        )}
                    >
                        {day.label}
                    </div>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Card                                                               */
/* ------------------------------------------------------------------ */

function SeriesCard({
    row,
    onView,
}: {
    row: RosterSeriesRow;
    onView: () => void;
}) {
    const cancelled = row.status === 'cancelled';
    const next = row.next_starts_at
        ? new Date(row.next_starts_at).toLocaleDateString('en-NZ', {
              weekday: 'short',
              day: '2-digit',
              month: 'short',
          })
        : 'No future occurrence';

    return (
        <div
            role="button"
            tabIndex={0}
            data-test={`series-card-${row.id}`}
            onClick={onView}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onView();
                }
            }}
            className={cn(
                'group flex cursor-pointer flex-col gap-3 rounded-[14px] border border-border bg-card p-4 text-left shadow-sm transition-colors',
                'hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                cancelled && 'opacity-75',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-bold tracking-tight">
                        {seriesTitle(row)}
                    </h3>
                    <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
                        {timeLabel(row)}
                        {row.location ? ` · ${row.location}` : ''}
                    </p>
                </div>
                <span
                    className={cn(
                        'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize',
                        cancelled
                            ? 'bg-status-critical-bg text-status-critical'
                            : 'bg-status-success-bg text-status-success',
                    )}
                >
                    {row.status}
                </span>
            </div>

            <WeekStrip weekdays={row.weekdays} />

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                <span>
                    Staff:{' '}
                    <span className="font-medium text-foreground">
                        {row.staff?.name ?? 'Unassigned pattern'}
                    </span>
                </span>
                <span aria-hidden>·</span>
                <span className="inline-flex items-center gap-1">
                    <CalendarClock className="h-3 w-3" />
                    {next}
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                    {row.active_occurrences_count} active
                </span>
                {row.open_occurrences_count > 0 ? (
                    <span className="rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-semibold text-status-warning tabular-nums">
                        {row.open_occurrences_count} open
                    </span>
                ) : null}
                {row.replacement_occurrences_count > 0 ? (
                    <span className="rounded-full bg-status-info-bg px-2 py-0.5 text-[11px] font-semibold text-status-info tabular-nums">
                        {row.replacement_occurrences_count} replacement
                    </span>
                ) : null}
                {row.is_sleepover ? (
                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                        Sleepover
                    </span>
                ) : null}
                {row.is_on_call ? (
                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                        On-call
                    </span>
                ) : null}
            </div>

            <div className="mt-auto pt-1">
                <Button
                    size="sm"
                    variant="outline"
                    className="w-full"
                    onClick={(e) => {
                        e.stopPropagation();
                        onView();
                    }}
                >
                    View series
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pane                                                               */
/* ------------------------------------------------------------------ */

export function SeriesPane({
    series,
    loading = false,
    canManage,
    onView,
    onNewRecurring,
}: SeriesPaneProps) {
    const [search, setSearch] = useState('');
    const [activeOnly, setActiveOnly] = useState(false);

    const list = useMemo(() => series ?? [], [series]);

    const stats: MicroStat[] = useMemo(() => {
        const open = list.reduce((sum, s) => sum + s.open_occurrences_count, 0);
        const replacements = list.reduce(
            (sum, s) => sum + s.replacement_occurrences_count,
            0,
        );
        const active = list.filter((s) => s.status !== 'cancelled').length;
        return [
            { label: 'Series', value: list.length, tone: 'info' },
            { label: 'Active', value: active, tone: 'ok' },
            {
                label: 'Open occurrences',
                value: open,
                tone: open > 0 ? 'warn' : 'ok',
            },
            {
                label: 'Replacements',
                value: replacements,
                tone: replacements > 0 ? 'warn' : 'ok',
            },
        ];
    }, [list]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return list.filter((s) => {
            if (activeOnly && s.status === 'cancelled') return false;
            if (!q) return true;
            return (
                seriesTitle(s).toLowerCase().includes(q) ||
                (s.staff?.name ?? '').toLowerCase().includes(q) ||
                (s.location ?? '').toLowerCase().includes(q)
            );
        });
    }, [list, search, activeOnly]);

    if (series === null || loading) {
        return (
            <div className="space-y-4">
                <MicroStats stats={stats} />
                <div className="rounded-[14px] border border-border bg-card p-10 text-center text-sm text-muted-foreground shadow-sm">
                    Loading recurring series…
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search client, staff, location…"
                            className="h-9 w-64 pl-8"
                        />
                    </div>
                    <div className="inline-flex gap-1 rounded-lg bg-muted p-1">
                        {[
                            { key: false, label: 'All' },
                            { key: true, label: 'Active' },
                        ].map((opt) => (
                            <Button
                                unstyled
                                key={String(opt.key)}
                                type="button"
                                aria-pressed={activeOnly === opt.key}
                                onClick={() => setActiveOnly(opt.key)}
                                className={cn(
                                    'rounded-md px-3 py-1 text-[13px] font-semibold transition-colors',
                                    activeOnly === opt.key
                                        ? 'bg-card text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {opt.label}
                            </Button>
                        ))}
                    </div>
                </div>
                {canManage ? (
                    <Button size="sm" onClick={onNewRecurring}>
                        <Plus className="h-4 w-4" /> New recurring shift
                    </Button>
                ) : null}
            </div>

            {filtered.length > 0 ? (
                <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                    {filtered.map((row) => (
                        <SeriesCard
                            key={row.id}
                            row={row}
                            onView={() => onView(row)}
                        />
                    ))}
                </div>
            ) : (
                <div className="flex flex-col items-center gap-3 rounded-[14px] border border-dashed border-border bg-card p-10 text-center shadow-sm">
                    <span className="grid h-12 w-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                        <Repeat className="h-6 w-6" />
                    </span>
                    <div>
                        <p className="text-sm font-semibold">
                            {list.length === 0
                                ? 'No recurring series yet'
                                : 'No series match your filters'}
                        </p>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                            {list.length === 0
                                ? 'Create a recurring shift to generate a weekly pattern of occurrences.'
                                : 'Try clearing the search or the Active filter.'}
                        </p>
                    </div>
                    {canManage && list.length === 0 ? (
                        <Button size="sm" onClick={onNewRecurring}>
                            <Plus className="h-4 w-4" /> New recurring shift
                        </Button>
                    ) : null}
                </div>
            )}
        </div>
    );
}

export default SeriesPane;
