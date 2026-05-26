import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

export type CapacityRow = {
    id: number;
    name: string;
    role: string | null;
    initials: string;
    hue: number;
    days: number[];
    target: number;
    open?: boolean;
};

export type CapacityHeatmapPaneProps = {
    stats: MicroStat[];
    days: Date[];
    rows: CapacityRow[];
    todayKey: string | null;
};

function ymdKey(d: Date) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function loadClass(hours: number) {
    if (hours === 0) {
        return 'bg-muted text-muted-foreground/60';
    }
    if (hours <= 4) {
        return 'bg-status-info-bg text-status-info';
    }
    if (hours <= 8) {
        return 'bg-status-info/30 text-status-info-foreground';
    }
    if (hours <= 12) {
        return 'bg-status-warning/30 text-status-warning-foreground';
    }
    return 'bg-status-critical/30 text-status-critical-foreground';
}

export function CapacityHeatmapPane({
    stats,
    days,
    rows,
    todayKey,
}: CapacityHeatmapPaneProps) {
    const totals = rows.map((r) => r.days.reduce((s, x) => s + x, 0));
    const totalScheduled = totals.reduce((s, x) => s + x, 0);
    const dayTotals = Array.from({ length: days.length }, (_, di) =>
        rows.reduce((s, r) => s + (r.days[di] ?? 0), 0),
    );

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />

            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div
                    className="grid border-b border-border bg-muted/50"
                    style={{
                        gridTemplateColumns: `220px repeat(${days.length}, minmax(0, 1fr)) 120px`,
                    }}
                >
                    <div className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Staff · {rows.length} rostered
                    </div>
                    {days.map((d, i) => {
                        const key = ymdKey(d);
                        const isToday = todayKey === key;
                        return (
                            <div
                                key={i}
                                className={cn(
                                    'px-2 py-2 text-center text-[11px]',
                                    isToday && 'bg-primary/10 text-primary',
                                )}
                            >
                                <div className="font-semibold uppercase">
                                    {d
                                        .toLocaleDateString(undefined, {
                                            weekday: 'short',
                                        })
                                        .toUpperCase()}
                                </div>
                                <div className="mt-0.5 text-xs font-bold tabular-nums">
                                    {d.toLocaleDateString(undefined, {
                                        day: '2-digit',
                                        month: 'short',
                                    })}
                                </div>
                            </div>
                        );
                    })}
                    <div className="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Total · Target
                    </div>
                </div>
                <div className="divide-y divide-border">
                    {rows.length === 0 ? (
                        <div className="p-6 text-center text-sm text-muted-foreground">
                            No staff rostered this week.
                        </div>
                    ) : null}
                    {rows.map((row, ri) => {
                        const total = totals[ri];
                        const overload = total > row.target + 4;
                        const under =
                            !row.open && total < row.target - 8 && row.target > 0;
                        return (
                            <div
                                key={row.id}
                                className="grid"
                                style={{
                                    gridTemplateColumns: `220px repeat(${days.length}, minmax(0, 1fr)) 120px`,
                                }}
                            >
                                <div className="flex items-center gap-2 px-3 py-2">
                                    <div
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-bold uppercase"
                                        style={{
                                            background: `hsl(${row.hue} 55% 90%)`,
                                            color: `hsl(${row.hue} 50% 35%)`,
                                        }}
                                    >
                                        {row.initials}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold">
                                            {row.name}
                                        </div>
                                        {row.role ? (
                                            <div className="truncate text-[11px] text-muted-foreground">
                                                {row.role}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                {row.days.map((h, di) => {
                                    const isToday = todayKey === ymdKey(days[di]);
                                    return (
                                        <div
                                            key={di}
                                            className={cn(
                                                'flex items-center justify-center border-l border-border text-sm font-bold tabular-nums',
                                                loadClass(h),
                                                isToday &&
                                                    'shadow-[inset_0_0_0_1px_var(--primary)]',
                                            )}
                                            title={`${row.name} · ${days[di].toLocaleDateString()} · ${h}h`}
                                        >
                                            {h > 0 ? `${h}h` : '—'}
                                        </div>
                                    );
                                })}
                                <div
                                    className={cn(
                                        'flex flex-col items-center justify-center border-l border-border',
                                        overload &&
                                            'bg-status-critical-bg text-status-critical',
                                        under && 'bg-status-warning-bg text-status-warning',
                                    )}
                                >
                                    <div className="text-sm font-bold tabular-nums">
                                        {total}h
                                    </div>
                                    <div className="text-[10px] text-muted-foreground">
                                        / {row.target || '—'}h
                                    </div>
                                    {overload ? (
                                        <span className="mt-1 rounded bg-status-critical px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                                            Overload
                                        </span>
                                    ) : under ? (
                                        <span className="mt-1 rounded bg-status-warning px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                                            Under
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })}
                    <div
                        className="grid border-t border-border bg-muted/30"
                        style={{
                            gridTemplateColumns: `220px repeat(${days.length}, minmax(0, 1fr)) 120px`,
                        }}
                    >
                        <div className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Day totals
                        </div>
                        {dayTotals.map((t, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-center border-l border-border text-xs font-semibold tabular-nums"
                            >
                                {t}h
                            </div>
                        ))}
                        <div className="flex items-center justify-center border-l border-border text-sm font-bold tabular-nums">
                            {totalScheduled}h
                        </div>
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
                <span className="font-semibold uppercase tracking-wider text-muted-foreground">
                    Load
                </span>
                <span className="inline-block h-3 w-5 rounded bg-muted" />
                <span className="inline-block h-3 w-5 rounded bg-status-info-bg" />
                <span className="inline-block h-3 w-5 rounded bg-status-info/30" />
                <span className="inline-block h-3 w-5 rounded bg-status-warning/30" />
                <span className="inline-block h-3 w-5 rounded bg-status-critical/30" />
                <span>Off · 0–4h · 4–8h · 8–12h · 12h+ ⚠</span>
            </div>
        </div>
    );
}

export default CapacityHeatmapPane;
