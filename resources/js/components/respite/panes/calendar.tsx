/**
 * Calendar pane — occupancy timeline (gantt) across respite homes. One row per
 * home, one lane-packed bar per booking, weekend shading + a today line.
 * Overlapping bars in a home flag a capacity clash. Reads the same booking
 * records the unified Site Calendar surfaces (RespiteObligationProvider), so
 * this view and the site calendars never disagree.
 */
import { cn } from '@/lib/utils';
import { CalendarDays, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { FilterChip, PaneHead } from '../pane-kit';
import type { RespiteBookingRow, RespiteHome } from '../types';

const DAY_W = 32;
const LABEL_W = 150;
const DAYS_SHOWN = 42;
const LANE_H = 30;

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
const DOW = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

function startOfDay(d: Date): Date {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}
function dayIndex(iso: string | null, base: Date): number | null {
    if (!iso) return null;
    const d = startOfDay(new Date(iso));
    if (Number.isNaN(d.getTime())) return null;
    return Math.round((d.getTime() - base.getTime()) / 8.64e7);
}

type PlacedBar = RespiteBookingRow & { s: number; e: number; lane: number };

function packLanes(bars: Omit<PlacedBar, 'lane'>[]): {
    placed: PlacedBar[];
    lanes: number;
} {
    const laneEnds: number[] = [];
    const placed = bars
        .slice()
        .sort((a, b) => a.s - b.s)
        .map((bar) => {
            let lane = 0;
            while (laneEnds[lane] != null && laneEnds[lane] >= bar.s) lane++;
            laneEnds[lane] = bar.e;
            return { ...bar, lane };
        });
    return { placed, lanes: Math.max(1, laneEnds.length) };
}

export function CalendarPane({
    bookings,
    homes,
}: {
    bookings: RespiteBookingRow[];
    homes: RespiteHome[];
}) {
    const [scope, setScope] = useState('all');

    const today = startOfDay(new Date());
    // Window starts on the Monday of this week.
    const base = new Date(today);
    base.setDate(today.getDate() - ((today.getDay() + 6) % 7));
    const days = Array.from({ length: DAYS_SHOWN }, (_, i) => {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        return d;
    });
    const todayIdx = dayIndex(today.toISOString(), base);

    const homeRows = (homes.length ? homes : inferHomes(bookings)).filter(
        (h) => scope === 'all' || scope === h.name,
    );

    // month spans for the header
    const months: { label: string; span: number }[] = [];
    days.forEach((d) => {
        const last = months[months.length - 1];
        const label = `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
        if (last && last.label === label) last.span += 1;
        else months.push({ label, span: 1 });
    });

    return (
        <div>
            <PaneHead
                icon={CalendarDays}
                title="Calendar"
                count="Occupancy · 6 weeks"
            >
                <FilterChip
                    active={scope === 'all'}
                    onClick={() => setScope('all')}
                >
                    All homes
                </FilterChip>
                {homeRows.length || scope !== 'all'
                    ? (homes.length ? homes : inferHomes(bookings)).map((h) => (
                          <FilterChip
                              key={h.id}
                              active={scope === h.name}
                              onClick={() => setScope(h.name)}
                          >
                              {h.name}
                          </FilterChip>
                      ))
                    : null}
            </PaneHead>

            <div className="mb-3.5 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-2.5 w-3.5 rounded-sm bg-status-success" />{' '}
                    In-house stay
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-2.5 w-3.5 rounded-sm bg-primary" />{' '}
                    Confirmed booking
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="h-3 w-0.5 bg-status-critical" /> Today
                </span>
            </div>

            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div className="overflow-x-auto">
                    <div style={{ minWidth: LABEL_W + DAYS_SHOWN * DAY_W }}>
                        {/* month + day header */}
                        <div className="sticky top-0 z-[2] border-b border-border bg-card">
                            <div className="flex">
                                <div
                                    className="shrink-0 border-r border-border"
                                    style={{ width: LABEL_W }}
                                />
                                {months.map((m, i) => (
                                    <div
                                        key={i}
                                        className="border-r border-border px-2 py-1.5 text-[11.5px] font-bold text-muted-foreground"
                                        style={{ width: m.span * DAY_W }}
                                    >
                                        {m.label}
                                    </div>
                                ))}
                            </div>
                            <div className="flex">
                                <div
                                    className="flex shrink-0 items-center border-r border-border px-3 text-[11px] font-semibold text-muted-foreground"
                                    style={{ width: LABEL_W }}
                                >
                                    Home
                                </div>
                                {days.map((d, i) => {
                                    const weekend =
                                        d.getDay() === 0 || d.getDay() === 6;
                                    const isToday = i === todayIdx;
                                    return (
                                        <div
                                            key={i}
                                            className={cn(
                                                'py-1 text-center text-[10px]',
                                                weekend && 'bg-muted/50',
                                                isToday
                                                    ? 'font-bold text-status-critical'
                                                    : 'text-muted-foreground',
                                            )}
                                            style={{ width: DAY_W }}
                                        >
                                            <div>{DOW[d.getDay()]}</div>
                                            <div className="text-[11px] tabular-nums">
                                                {d.getDate()}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* home rows */}
                        {homeRows.map((home, hi) => (
                            <HomeRow
                                key={home.id}
                                home={home}
                                bookings={bookings}
                                base={base}
                                days={days}
                                todayIdx={todayIdx}
                                first={hi === 0}
                            />
                        ))}
                        {homeRows.length === 0 ? (
                            <div className="px-4 py-10 text-center text-sm text-muted-foreground">
                                No respite homes configured.
                            </div>
                        ) : null}
                    </div>
                </div>
            </div>

            <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Sparkles className="h-3.5 w-3.5 text-primary" />
                Bars show room occupancy. Overlapping bars in a home flag a
                capacity clash to resolve.
            </p>
        </div>
    );
}

function HomeRow({
    home,
    bookings,
    base,
    days,
    todayIdx,
    first,
}: {
    home: RespiteHome;
    bookings: RespiteBookingRow[];
    base: Date;
    days: Date[];
    todayIdx: number | null;
    first: boolean;
}) {
    const bars = bookings
        .filter((b) => b.site === home.name && b.status !== 'cancelled')
        .map((b) => {
            const rawS = dayIndex(b.start, base);
            const rawE = dayIndex(b.end, base);
            if (rawS == null || rawE == null) return null;
            const s = Math.max(0, rawS);
            const e = Math.min(days.length - 1, rawE);
            if (e < 0 || s > days.length - 1 || e < s) return null;
            return { ...b, s, e };
        })
        .filter((x): x is Omit<PlacedBar, 'lane'> => x != null);

    const { placed, lanes } = packLanes(bars);
    const rowH = lanes * LANE_H + 12;

    return (
        <div className={cn('flex', !first && 'border-t border-border')}>
            <div
                className="flex shrink-0 flex-col justify-center border-r border-border p-3"
                style={{ width: LABEL_W }}
            >
                <div className="text-[13px] font-bold">{home.name}</div>
                <div className="text-[11px] text-muted-foreground">
                    {home.capacity || '—'} respite beds
                </div>
            </div>
            <div
                className="relative"
                style={{ height: rowH, width: days.length * DAY_W }}
            >
                {days.map((d, i) => {
                    const weekend = d.getDay() === 0 || d.getDay() === 6;
                    return (
                        <div
                            key={i}
                            className={cn(
                                'absolute top-0 h-full border-r border-border/50',
                                weekend && 'bg-muted/40',
                            )}
                            style={{ left: i * DAY_W, width: DAY_W }}
                        />
                    );
                })}
                {todayIdx != null && todayIdx >= 0 && todayIdx < days.length ? (
                    <div
                        className="absolute top-0 z-[1] h-full w-0.5 bg-status-critical"
                        style={{ left: todayIdx * DAY_W + DAY_W / 2 }}
                    />
                ) : null}
                {placed.map((b) => (
                    <div
                        key={b.id}
                        title={`${b.client} · ${b.ref}`}
                        className={cn(
                            'absolute z-[1] flex items-center overflow-hidden rounded-md px-2 text-[11.5px] font-semibold whitespace-nowrap text-white shadow-sm',
                            b.status === 'in_progress'
                                ? 'bg-status-success'
                                : 'bg-primary',
                        )}
                        style={{
                            left: b.s * DAY_W + 3,
                            top: b.lane * LANE_H + 8,
                            width: (b.e - b.s + 1) * DAY_W - 6,
                            height: LANE_H - 8,
                        }}
                    >
                        {b.client}
                    </div>
                ))}
            </div>
        </div>
    );
}

/** Fallback "homes" derived from the bookings when no respite-capable sites are configured. */
function inferHomes(bookings: RespiteBookingRow[]): RespiteHome[] {
    const names = Array.from(
        new Set(bookings.map((b) => b.site).filter((s): s is string => !!s)),
    );
    return names.map((name, i) => ({
        id: -(i + 1),
        name,
        capacity: 0,
        occupied: 0,
        available: null,
        full: false,
    }));
}
