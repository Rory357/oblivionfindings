/* eslint-disable no-restricted-syntax -- A bespoke month-grid calendar ported
 * 1:1 from the design prototype: spanning all-day bars (lane-packed), timed
 * chips, per-cell tints and coverage strips are styled native <button>/<div>
 * surfaces, not shadcn primitives. Colours are token / color-mix throughout. */
import { type CSSProperties } from 'react';

import { type CalendarLayerFeed } from '@/lib/calendar/layer-feed';
import {
    addDays,
    barStyle,
    colorVar,
    dayStart,
    dotStyle,
    fmtTime,
    isoKey,
    sameDay,
    startOfWeek,
} from './calendar-render';

const DAY_MS = 86_400_000;
const CHIP_CAP = 3;
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export interface MonthGridHandlers {
    onDayNum: (date: Date) => void;
    onDayMenu: (date: Date, x: number, y: number) => void;
    onAdd: (date: Date, x: number, y: number) => void;
    onEntryClick: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryCtx: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryHover?: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryHoverEnd?: () => void;
    onMore: (date: Date) => void;
}

type Bar = {
    e: CalendarLayerFeed;
    col: number;
    span: number;
    lane: number;
    dashed: boolean;
    locked: boolean;
};

/** Last calendar day an entry covers (all-day ends are exclusive in the feed). */
function lastDay(e: CalendarLayerFeed): Date {
    const s = dayStart(new Date(e.start));
    if (!e.allDay) return s;
    const end = e.end ? dayStart(new Date(e.end)) : s;
    const last = addDays(end, -1);
    return last.getTime() < s.getTime() ? s : last;
}

function coversDay(e: CalendarLayerFeed, d: Date): boolean {
    const ds = dayStart(d).getTime();
    return (
        ds >= dayStart(new Date(e.start)).getTime() &&
        ds <= lastDay(e).getTime()
    );
}

export function CalendarMonthGrid({
    events,
    cursor,
    today,
    showCoverage,
    loading,
    handlers,
}: {
    events: CalendarLayerFeed[];
    cursor: Date;
    today: Date;
    /** Shift layer is active → paint worked / gap coverage strips. */
    showCoverage: boolean;
    loading: boolean;
    handlers: MonthGridHandlers;
}) {
    // Partition the feed: shifts feed the coverage strips only; everything else
    // renders as a spanning bar (all-day) or a timed chip.
    const gapDays = new Set<string>();
    const workedDays = new Set<string>();
    const bars: CalendarLayerFeed[] = [];
    const timed: CalendarLayerFeed[] = [];
    for (const e of events) {
        if (!e.start) continue;
        if (e.layer === 'shift') {
            const k = isoKey(new Date(e.start));
            if (e.extendedProps.gap) {
                gapDays.add(k);
                bars.push(e); // surface the gap as an all-day "Coverage gap" bar
            } else {
                workedDays.add(k);
            }
            continue;
        }
        if (e.allDay) bars.push(e);
        else timed.push(e);
    }

    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const gridStart = startOfWeek(first);
    const weeks: Date[][] = [];
    for (let w = 0; w < 6; w++) {
        const wStart = addDays(gridStart, w * 7);
        if (
            w === 5 &&
            wStart.getMonth() !== cursor.getMonth() &&
            addDays(wStart, -1).getMonth() !== cursor.getMonth()
        )
            break;
        const days: Date[] = [];
        for (let i = 0; i < 7; i++) days.push(addDays(wStart, i));
        weeks.push(days);
    }

    return (
        <div
            style={{
                borderRadius: 16,
                border: '1px solid var(--border)',
                background: 'var(--card)',
                overflow: 'hidden',
                boxShadow: '0 1px 3px rgba(0,0,0,.04)',
                opacity: loading ? 0.6 : 1,
                transition: 'opacity .15s ease',
            }}
            aria-busy={loading}
        >
            {/* weekday header */}
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(7,1fr)',
                    borderBottom: '1px solid var(--border)',
                }}
            >
                {WEEKDAYS.map((w, i) => (
                    <div
                        key={w}
                        style={{
                            padding: '9px 0',
                            textAlign: 'center',
                            fontSize: 10.5,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '.09em',
                            color:
                                i >= 5
                                    ? 'color-mix(in oklch, var(--muted-foreground) 70%, transparent)'
                                    : 'var(--muted-foreground)',
                        }}
                    >
                        {w}
                    </div>
                ))}
            </div>

            {weeks.map((days, wi) => (
                <WeekRow
                    key={wi}
                    days={days}
                    cursor={cursor}
                    today={today}
                    bars={bars}
                    timed={timed}
                    gapDays={gapDays}
                    workedDays={workedDays}
                    showCoverage={showCoverage}
                    handlers={handlers}
                />
            ))}
        </div>
    );
}

function WeekRow({
    days,
    cursor,
    today,
    bars,
    timed,
    gapDays,
    workedDays,
    showCoverage,
    handlers,
}: {
    days: Date[];
    cursor: Date;
    today: Date;
    bars: CalendarLayerFeed[];
    timed: CalendarLayerFeed[];
    gapDays: Set<string>;
    workedDays: Set<string>;
    showCoverage: boolean;
    handlers: MonthGridHandlers;
}) {
    const wStart = days[0];
    const wEnd = days[6];

    // ── background cells ──
    const cells = days.map((d) => {
        const inMonth = d.getMonth() === cursor.getMonth();
        const isToday = sameDay(d, today);
        const weekend = d.getDay() === 0 || d.getDay() === 6;
        const key = isoKey(d);
        const holiday = bars.some(
            (e) => e.layer === 'holiday' && coversDay(e, d),
        );
        const gap = showCoverage && gapDays.has(key);
        const worked =
            showCoverage && !gap && workedDays.has(key) && !weekend && inMonth;
        let bg = inMonth
            ? 'transparent'
            : 'color-mix(in oklch, var(--muted) 35%, transparent)';
        if (holiday)
            bg = 'color-mix(in oklch, var(--status-warning) 9%, transparent)';
        if (isToday) bg = 'color-mix(in oklch, var(--primary) 5%, transparent)';
        let strip = '';
        if (gap) strip = 'inset 0 -3px 0 0 var(--status-critical)';
        else if (worked)
            strip =
                'inset 0 -3px 0 0 color-mix(in oklch, var(--live) 55%, transparent)';
        const style: CSSProperties = {
            borderRight:
                '1px dotted color-mix(in oklch, var(--primary) 9%, transparent)',
            background: bg,
        };
        if (strip) style.boxShadow = strip;
        return { d, inMonth, isToday, holiday, style };
    });

    // ── spanning all-day bars (lane-packed) ──
    const spanning = bars
        .filter(
            (e) =>
                lastDay(e).getTime() >= dayStart(wStart).getTime() &&
                dayStart(new Date(e.start)).getTime() <=
                    dayStart(wEnd).getTime(),
        )
        .sort(
            (a, b) =>
                new Date(a.start).getTime() - new Date(b.start).getTime() ||
                lastDay(b).getTime() -
                    dayStart(new Date(b.start)).getTime() -
                    (lastDay(a).getTime() -
                        dayStart(new Date(a.start)).getTime()),
        );
    const lanes: [number, number][][] = [];
    const placed: Bar[] = [];
    for (const e of spanning) {
        const startCol = Math.max(
            0,
            Math.round(
                (dayStart(new Date(e.start)).getTime() -
                    dayStart(wStart).getTime()) /
                    DAY_MS,
            ),
        );
        const endCol = Math.min(
            6,
            Math.round(
                (lastDay(e).getTime() - dayStart(wStart).getTime()) / DAY_MS,
            ),
        );
        const col = Math.max(0, startCol);
        const span = Math.min(6, endCol) - col + 1;
        if (span <= 0) continue;
        let lane = 0;
        while (
            lanes[lane] &&
            lanes[lane].some((r) => !(endCol < r[0] || col > r[1]))
        )
            lane++;
        if (!lanes[lane]) lanes[lane] = [];
        lanes[lane].push([col, Math.min(6, endCol)]);
        placed.push({
            e,
            col,
            span,
            lane,
            dashed: !!e.extendedProps.pending || !!e.extendedProps.gap,
            locked: e.layer !== 'event',
        });
    }
    const laneCount = lanes.length;

    return (
        <div
            style={{
                position: 'relative',
                borderTop:
                    '1px solid color-mix(in oklch, var(--border) 70%, transparent)',
            }}
        >
            {/* bg cells */}
            <div
                style={{
                    position: 'absolute',
                    inset: 0,
                    display: 'grid',
                    gridTemplateColumns: 'repeat(7,1fr)',
                }}
            >
                {cells.map((c, i) => (
                    <div key={i} style={c.style} />
                ))}
            </div>

            {/* content */}
            <div
                style={{
                    position: 'relative',
                    display: 'flex',
                    flexDirection: 'column',
                    minHeight: 124,
                    padding: '5px 0 7px',
                }}
            >
                {/* numbers */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(7,1fr)',
                    }}
                >
                    {cells.map((c, i) => (
                        <div
                            key={i}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '1px 7px 3px',
                            }}
                        >
                            <button
                                type="button"
                                onClick={() => handlers.onDayNum(c.d)}
                                onContextMenu={(ev) => {
                                    ev.preventDefault();
                                    handlers.onDayMenu(
                                        c.d,
                                        ev.clientX,
                                        ev.clientY,
                                    );
                                }}
                                style={
                                    c.isToday
                                        ? {
                                              display: 'inline-grid',
                                              placeItems: 'center',
                                              height: 26,
                                              minWidth: 26,
                                              padding: '0 6px',
                                              borderRadius: 9999,
                                              background: 'var(--primary)',
                                              color: 'var(--primary-foreground)',
                                              fontSize: 13,
                                              fontWeight: 700,
                                              border: 'none',
                                              cursor: 'pointer',
                                          }
                                        : {
                                              display: 'inline-grid',
                                              placeItems: 'center',
                                              height: 26,
                                              minWidth: 26,
                                              borderRadius: 9999,
                                              background: 'transparent',
                                              border: 'none',
                                              cursor: 'pointer',
                                              fontSize: 13,
                                              fontWeight: 700,
                                              color: c.inMonth
                                                  ? 'var(--foreground)'
                                                  : 'color-mix(in oklch, var(--muted-foreground) 60%, transparent)',
                                          }
                                }
                            >
                                {c.d.getDate()}
                            </button>
                            <span
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 4,
                                }}
                            >
                                {c.holiday ? (
                                    <span
                                        style={{ fontSize: 14, lineHeight: 1 }}
                                    >
                                        🎉
                                    </span>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={(ev) => {
                                        ev.stopPropagation();
                                        handlers.onAdd(
                                            c.d,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                    onContextMenu={(ev) => {
                                        ev.preventDefault();
                                        handlers.onDayMenu(
                                            c.d,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                    aria-label="New event"
                                    className="hrcal-add"
                                    style={{
                                        display: 'grid',
                                        height: 20,
                                        width: 20,
                                        placeItems: 'center',
                                        borderRadius: 6,
                                        border: 'none',
                                        background: 'transparent',
                                        color: 'var(--muted-foreground)',
                                        cursor: 'pointer',
                                    }}
                                >
                                    <svg
                                        width="13"
                                        height="13"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2.2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="M5 12h14M12 5v14" />
                                    </svg>
                                </button>
                            </span>
                        </div>
                    ))}
                </div>

                {/* spanning bars */}
                {laneCount > 0 ? (
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(7,1fr)',
                            gridTemplateRows: `repeat(${laneCount},auto)`,
                            margin: '2px 0',
                        }}
                    >
                        {placed.map((b, i) => {
                            const c = colorVar(b.e);
                            return (
                                <button
                                    key={`${b.e.id}-${i}`}
                                    type="button"
                                    onClick={(ev) => {
                                        ev.stopPropagation();
                                        handlers.onEntryClick(
                                            b.e,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                    onContextMenu={(ev) => {
                                        ev.preventDefault();
                                        ev.stopPropagation();
                                        handlers.onEntryCtx(
                                            b.e,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                    onMouseEnter={(ev) =>
                                        handlers.onEntryHover?.(
                                            b.e,
                                            ev.clientX,
                                            ev.clientY,
                                        )
                                    }
                                    onMouseLeave={() =>
                                        handlers.onEntryHoverEnd?.()
                                    }
                                    title={b.e.title}
                                    style={barStyle(c, b.dashed, {
                                        gridColumn: `${b.col + 1} / span ${b.span}`,
                                        gridRow: b.lane + 1,
                                        margin: '1px 4px',
                                    })}
                                >
                                    <span style={dotStyle(c)} />
                                    <span
                                        style={{
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {b.e.title}
                                    </span>
                                    {b.e.extendedProps.recurring ? (
                                        <svg
                                            width="11"
                                            height="11"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            style={{
                                                flex: 'none',
                                                opacity: 0.7,
                                            }}
                                        >
                                            <path d="m17 2 4 4-4 4M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 13v1a4 4 0 0 1-4 4H3" />
                                        </svg>
                                    ) : null}
                                    {b.locked ? (
                                        <svg
                                            width="10"
                                            height="10"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.4"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            style={{
                                                flex: 'none',
                                                opacity: 0.55,
                                                marginLeft: 'auto',
                                            }}
                                        >
                                            <rect
                                                width="14"
                                                height="10"
                                                x="5"
                                                y="11"
                                                rx="2"
                                            />
                                            <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                                        </svg>
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>
                ) : null}

                {/* timed chips */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(7,1fr)',
                        marginTop: 2,
                    }}
                >
                    {days.map((d, i) => {
                        const dayChips = timed
                            .filter((e) => coversDay(e, d))
                            .sort(
                                (a, b) =>
                                    new Date(a.start).getTime() -
                                    new Date(b.start).getTime(),
                            );
                        const shown = dayChips.slice(0, CHIP_CAP);
                        const more = dayChips.length - shown.length;
                        return (
                            <div
                                key={i}
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 2,
                                    padding: '0 5px',
                                    minWidth: 0,
                                }}
                            >
                                {shown.map((e) => {
                                    const c = colorVar(e);
                                    return (
                                        <button
                                            key={e.id}
                                            type="button"
                                            onClick={(ev) => {
                                                ev.stopPropagation();
                                                handlers.onEntryClick(
                                                    e,
                                                    ev.clientX,
                                                    ev.clientY,
                                                );
                                            }}
                                            onContextMenu={(ev) => {
                                                ev.preventDefault();
                                                ev.stopPropagation();
                                                handlers.onEntryCtx(
                                                    e,
                                                    ev.clientX,
                                                    ev.clientY,
                                                );
                                            }}
                                            onMouseEnter={(ev) =>
                                                handlers.onEntryHover?.(
                                                    e,
                                                    ev.clientX,
                                                    ev.clientY,
                                                )
                                            }
                                            onMouseLeave={() =>
                                                handlers.onEntryHoverEnd?.()
                                            }
                                            title={e.title}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 5,
                                                width: '100%',
                                                borderRadius: 6,
                                                border: `1px solid color-mix(in oklch, ${c} 28%, transparent)`,
                                                background: `color-mix(in oklch, ${c} 12%, var(--card))`,
                                                color: 'var(--foreground)',
                                                padding: '1px 6px',
                                                fontSize: 11,
                                                cursor: 'pointer',
                                                textAlign: 'left',
                                                lineHeight: 1.55,
                                            }}
                                        >
                                            <span style={dotStyle(c)} />
                                            <span
                                                style={{
                                                    fontWeight: 700,
                                                    fontVariantNumeric:
                                                        'tabular-nums',
                                                    flex: 'none',
                                                    opacity: 0.85,
                                                }}
                                            >
                                                {fmtTime(new Date(e.start))}
                                            </span>
                                            <span
                                                style={{
                                                    overflow: 'hidden',
                                                    textOverflow: 'ellipsis',
                                                    whiteSpace: 'nowrap',
                                                }}
                                            >
                                                {e.title}
                                            </span>
                                        </button>
                                    );
                                })}
                                {more > 0 ? (
                                    <button
                                        type="button"
                                        onClick={(ev) => {
                                            ev.stopPropagation();
                                            handlers.onMore(d);
                                        }}
                                        style={{
                                            textAlign: 'left',
                                            fontSize: 11,
                                            fontWeight: 700,
                                            color: 'var(--primary)',
                                            background: 'none',
                                            border: 'none',
                                            padding: '1px 4px',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        +{more} more
                                    </button>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default CalendarMonthGrid;
