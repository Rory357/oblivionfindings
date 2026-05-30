import { useMemo, useState } from 'react';

import { cn } from '@/lib/utils';

/** Minimal shift shape the overlap grid needs. */
export type OverlapShift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    user_id: number | null;
    staff: string | null;
    site_id: number | null;
    site: string | null;
};

export type CoverageOverlapPaneProps = {
    shifts: OverlapShift[];
    days: Date[];
    todayKey: string | null;
};

type Mode = 'site' | 'staff';

const MODES: { value: Mode; label: string }[] = [
    { value: 'site', label: 'Site' },
    { value: 'staff', label: 'Staff' },
];

type Cell = {
    count: number;
    hours: number;
    /** Two or more shifts in this cell overlap in clock time. */
    overlap: boolean;
    /** Shifts in this cell with nobody assigned yet. */
    open: number;
};

type Row = {
    key: string;
    label: string;
    sub: string | null;
    /** Sorts unassigned buckets to the bottom. */
    trailing: boolean;
    cells: Cell[];
    total: number;
    hours: number;
};

function ymd(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function shiftHours(s: OverlapShift): number {
    const start = new Date(s.starts_at).getTime();
    const end = new Date(s.ends_at).getTime();
    if (!start || !end || end <= start) return 0;
    return (end - start) / 3_600_000;
}

/** True when any two shifts in the list overlap in clock time. */
function hasTimeOverlap(list: OverlapShift[]): boolean {
    if (list.length < 2) return false;
    const sorted = [...list].sort(
        (a, b) =>
            new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime(),
    );
    for (let i = 1; i < sorted.length; i++) {
        if (
            new Date(sorted[i].starts_at).getTime() <
            new Date(sorted[i - 1].ends_at).getTime()
        ) {
            return true;
        }
    }
    return false;
}

function fmtHours(h: number): string {
    if (h <= 0) return '0h';
    return `${h >= 10 ? Math.round(h) : Math.round(h * 10) / 10}h`;
}

export function CoverageOverlapPane({
    shifts,
    days,
    todayKey,
}: CoverageOverlapPaneProps) {
    const [mode, setMode] = useState<Mode>('site');

    const dayKeys = useMemo(() => days.map(ymd), [days]);

    const rows = useMemo<Row[]>(() => {
        const active = shifts.filter((s) => s.status !== 'cancelled');

        const groups = new Map<
            string,
            {
                label: string;
                sub: string | null;
                trailing: boolean;
                byDay: Map<string, OverlapShift[]>;
            }
        >();

        for (const s of active) {
            let key: string;
            let label: string;
            let sub: string | null = null;
            let trailing = false;

            if (mode === 'site') {
                if (s.site_id != null) {
                    key = `site-${s.site_id}`;
                    label = s.site ?? `Site ${s.site_id}`;
                } else {
                    key = 'no-site';
                    label = 'No site';
                    sub = 'No location set';
                    trailing = true;
                }
            } else {
                if (s.user_id != null) {
                    key = `staff-${s.user_id}`;
                    label = s.staff ?? `Staff ${s.user_id}`;
                } else {
                    key = 'open';
                    label = 'Open · unassigned';
                    sub = 'Needs cover';
                    trailing = true;
                }
            }

            let bucket = groups.get(key);
            if (!bucket) {
                bucket = { label, sub, trailing, byDay: new Map() };
                groups.set(key, bucket);
            }
            const dk = ymd(new Date(s.starts_at));
            if (!bucket.byDay.has(dk)) bucket.byDay.set(dk, []);
            bucket.byDay.get(dk)!.push(s);
        }

        const out: Row[] = [];
        for (const [key, bucket] of groups) {
            let total = 0;
            let hours = 0;
            const cells = dayKeys.map((dk) => {
                const list = bucket.byDay.get(dk) ?? [];
                const cellHours = list.reduce((a, s) => a + shiftHours(s), 0);
                total += list.length;
                hours += cellHours;
                return {
                    count: list.length,
                    hours: cellHours,
                    overlap: hasTimeOverlap(list),
                    open: list.filter((s) => s.user_id == null).length,
                } satisfies Cell;
            });
            out.push({
                key,
                label: bucket.label,
                sub: bucket.sub,
                trailing: bucket.trailing,
                cells,
                total,
                hours,
            });
        }

        out.sort((a, b) => {
            if (a.trailing !== b.trailing) return a.trailing ? 1 : -1;
            return a.label.localeCompare(b.label);
        });
        return out;
    }, [shifts, dayKeys, mode]);

    const overlapCells = useMemo(
        () =>
            rows.reduce(
                (acc, r) => acc + r.cells.filter((c) => c.overlap).length,
                0,
            ),
        [rows],
    );

    const gridCols = `200px repeat(${days.length}, minmax(0, 1fr)) 84px`;
    const entityLabel = mode === 'site' ? 'Site' : 'Staff';

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-[14px] border border-border bg-card px-4 py-2.5">
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold tracking-tight">
                        Coverage overlap
                    </h3>
                    <p className="text-[11px] text-muted-foreground">
                        Shifts per {mode} each day ·{' '}
                        {overlapCells > 0 ? (
                            <span className="font-medium text-status-warning">
                                {overlapCells} overlapping cell
                                {overlapCells === 1 ? '' : 's'}
                            </span>
                        ) : (
                            'no time overlaps'
                        )}
                    </p>
                </div>
                {/* eslint-disable-next-line no-restricted-syntax -- segmented Site/Staff selector container, not a Card. */}
                <div
                    role="tablist"
                    aria-label="Group coverage by"
                    className="inline-flex rounded-md border border-border bg-background p-0.5"
                >
                    {MODES.map((opt) => {
                        const active = mode === opt.value;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- segmented Site/Staff selector; not a shadcn Button.
                            <button
                                key={opt.value}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                onClick={() => setMode(opt.value)}
                                className={cn(
                                    'rounded-sm px-3 py-1 text-xs font-semibold transition-colors',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-accent',
                                )}
                            >
                                {opt.label}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="overflow-x-auto rounded-[14px] border border-border bg-card">
                <div style={{ minWidth: 720 }}>
                    <div
                        className="grid border-b border-border bg-muted/50"
                        style={{ gridTemplateColumns: gridCols }}
                    >
                        <div className="px-3 py-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {entityLabel}
                        </div>
                        {days.map((d, i) => {
                            const isToday = todayKey === dayKeys[i];
                            return (
                                <div
                                    key={i}
                                    className={cn(
                                        'px-2 py-2 text-center text-[11px] font-semibold tracking-wider text-muted-foreground uppercase',
                                        isToday && 'bg-primary/10 text-primary',
                                    )}
                                >
                                    {d.toLocaleDateString(undefined, {
                                        weekday: 'short',
                                    })}
                                </div>
                            );
                        })}
                        <div className="px-2 py-2 text-center text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Week
                        </div>
                    </div>

                    {rows.length === 0 ? (
                        <div className="p-6 text-center text-sm text-muted-foreground">
                            No shifts scheduled this week.
                        </div>
                    ) : null}

                    {rows.map((row) => (
                        <div
                            key={row.key}
                            className="grid border-t border-border"
                            style={{ gridTemplateColumns: gridCols }}
                        >
                            <div
                                className={cn(
                                    'flex min-w-0 flex-col justify-center border-r border-border px-3 py-2.5',
                                    row.trailing
                                        ? 'bg-status-warning-bg/40'
                                        : 'bg-muted/30',
                                )}
                            >
                                <span className="truncate text-sm font-medium">
                                    {row.label}
                                </span>
                                {row.sub ? (
                                    <span className="truncate text-[10px] text-muted-foreground">
                                        {row.sub}
                                    </span>
                                ) : null}
                            </div>
                            {row.cells.map((cell, ci) => (
                                <OverlapCellView
                                    key={ci}
                                    cell={cell}
                                    isToday={todayKey === dayKeys[ci]}
                                />
                            ))}
                            <div className="flex flex-col items-center justify-center border-l border-border px-2 py-2.5">
                                <span className="text-sm font-bold tabular-nums">
                                    {row.total}
                                </span>
                                <span className="text-[10px] text-muted-foreground tabular-nums">
                                    {fmtHours(row.hours)}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-4 text-[11px] text-muted-foreground">
                <LegendSwatch
                    className="bg-primary/10 text-primary"
                    label="Covered"
                />
                <LegendSwatch
                    className="bg-status-warning-bg text-status-warning ring-1 ring-status-warning/50 ring-inset"
                    label="Overlap · two or more at once"
                />
                <LegendSwatch
                    className="border border-dashed border-status-warning/60 text-status-warning"
                    label="Includes open shift"
                />
                <span className="inline-flex items-center gap-1.5">
                    <span className="text-muted-foreground/40">—</span>
                    No shift
                </span>
            </div>
        </div>
    );
}

function OverlapCellView({ cell, isToday }: { cell: Cell; isToday: boolean }) {
    if (cell.count === 0) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center border-l border-border py-2.5 text-muted-foreground/40',
                    isToday && 'bg-primary/5',
                )}
            >
                —
            </div>
        );
    }

    const allOpen = cell.open > 0 && cell.open === cell.count;
    const tone = cell.overlap
        ? 'bg-status-warning-bg text-status-warning ring-1 ring-inset ring-status-warning/50'
        : cell.count > 1
          ? 'bg-primary/15 text-primary'
          : 'bg-primary/5 text-foreground';

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-0.5 border-l border-border py-2',
                isToday && 'bg-primary/5',
            )}
        >
            <div
                className={cn(
                    'flex h-7 min-w-[28px] items-center justify-center rounded-md px-1.5 text-xs font-bold tabular-nums',
                    tone,
                    cell.open > 0 &&
                        !cell.overlap &&
                        'border border-dashed border-status-warning/60',
                )}
                title={
                    cell.overlap
                        ? `${cell.count} shifts overlap in time`
                        : `${cell.count} shift${cell.count === 1 ? '' : 's'}`
                }
            >
                {cell.count}
            </div>
            <div className="text-[10px] text-muted-foreground tabular-nums">
                {cell.overlap
                    ? 'overlap'
                    : allOpen
                      ? 'open'
                      : fmtHours(cell.hours)}
            </div>
        </div>
    );
}

function LegendSwatch({
    className,
    label,
}: {
    className: string;
    label: string;
}) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className={cn(
                    'inline-flex h-4 w-5 items-center justify-center rounded',
                    className,
                )}
                aria-hidden="true"
            />
            <span>{label}</span>
        </span>
    );
}

export default CoverageOverlapPane;
