/* eslint-disable no-restricted-syntax -- A bespoke week/day time-grid ported
 * 1:1 from the design prototype: hour rails, absolutely-positioned event /
 * shift blocks and the now-line are styled native <button>/<div> surfaces, not
 * shadcn primitives. Colours are token / color-mix throughout. */
import { type CSSProperties } from 'react';

import { type CalendarLayerFeed } from '@/lib/calendar/layer-feed';
import {
    barStyle,
    colorVar,
    dotStyle,
    fmtTime,
    sameDay,
} from './calendar-render';

const H0 = 6; // 6am
const H1 = 21; // 9pm
const HOUR_H = 48;

export interface TimeGridHandlers {
    onEntryClick: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryCtx: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryHover?: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryHoverEnd?: () => void;
    onCreate: (date: Date, hour: number, x: number, y: number) => void;
}

function onSameLocalDay(e: CalendarLayerFeed, d: Date): boolean {
    const s = new Date(e.start);
    return sameDay(s, d);
}

export function CalendarTimeGrid({
    days,
    events,
    today,
    handlers,
}: {
    days: Date[];
    events: CalendarLayerFeed[];
    today: Date;
    handlers: TimeGridHandlers;
}) {
    const cols = `repeat(${days.length},1fr)`;
    const hours: number[] = [];
    for (let h = H0; h <= H1; h++) hours.push(h);

    const dotBorder =
        '1px dotted color-mix(in oklch, var(--primary) 12%, transparent)';

    const nowTop = (today.getHours() + today.getMinutes() / 60 - H0) * HOUR_H;

    return (
        <div
            style={{
                borderRadius: 16,
                border: '1px solid var(--border)',
                background: 'var(--card)',
                overflow: 'hidden',
                boxShadow: '0 1px 3px rgba(0,0,0,.04)',
            }}
        >
            {/* all-day row */}
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '56px 1fr',
                    borderBottom: '1px solid var(--border)',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'flex-end',
                        padding: '6px 8px',
                        fontSize: 10,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '.05em',
                        color: 'var(--muted-foreground)',
                    }}
                >
                    All-day
                </div>
                <div style={{ display: 'grid', gridTemplateColumns: cols }}>
                    {days.map((d, i) => {
                        const items = events.filter(
                            (e) => e.allDay && onSameLocalDay(e, d),
                        );
                        return (
                            <div
                                key={i}
                                style={{
                                    borderLeft: dotBorder,
                                    padding: '4px 5px',
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 3,
                                    minHeight: 30,
                                }}
                            >
                                {items.map((e) => {
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
                                            style={barStyle(
                                                c,
                                                !!e.extendedProps.pending,
                                            )}
                                        >
                                            <span style={dotStyle(c)} />
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
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* day headers */}
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: '56px 1fr',
                    borderBottom: '1px solid var(--border)',
                }}
            >
                <div />
                <div style={{ display: 'grid', gridTemplateColumns: cols }}>
                    {days.map((d, i) => {
                        const isToday = sameDay(d, today);
                        return (
                            <div
                                key={i}
                                style={{
                                    borderLeft: dotBorder,
                                    padding: '7px 4px',
                                    textAlign: 'center',
                                }}
                            >
                                <div
                                    style={{
                                        fontSize: 10,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '.08em',
                                        color: 'var(--muted-foreground)',
                                    }}
                                >
                                    {d.toLocaleDateString('en-NZ', {
                                        weekday: 'short',
                                    })}
                                </div>
                                <div
                                    style={
                                        isToday
                                            ? {
                                                  display: 'inline-grid',
                                                  placeItems: 'center',
                                                  height: 28,
                                                  width: 28,
                                                  margin: '3px auto 0',
                                                  borderRadius: 9999,
                                                  background: 'var(--primary)',
                                                  color: 'var(--primary-foreground)',
                                                  fontSize: 14,
                                                  fontWeight: 700,
                                              }
                                            : {
                                                  fontSize: 16,
                                                  fontWeight: 700,
                                                  marginTop: 2,
                                                  color: 'var(--foreground)',
                                              }
                                    }
                                >
                                    {d.getDate()}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* scroll body */}
            <div style={{ maxHeight: 560, overflowY: 'auto' }}>
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: '56px 1fr',
                        position: 'relative',
                    }}
                >
                    {/* hour labels */}
                    <div>
                        {hours.map((h) => {
                            const ap = h < 12 ? 'am' : 'pm';
                            const h12 = h % 12 === 0 ? 12 : h % 12;
                            return (
                                <div
                                    key={h}
                                    style={{
                                        height: HOUR_H,
                                        textAlign: 'right',
                                        paddingRight: 8,
                                        fontSize: 10.5,
                                        fontWeight: 500,
                                        color: 'color-mix(in oklch, var(--muted-foreground) 70%, transparent)',
                                        transform: 'translateY(-7px)',
                                    }}
                                >
                                    {h12} {ap}
                                </div>
                            );
                        })}
                    </div>

                    {/* day columns */}
                    <div
                        style={{
                            display: 'grid',
                            gridTemplateColumns: cols,
                            position: 'relative',
                        }}
                    >
                        {days.map((d, i) => {
                            const isToday = sameDay(d, today);
                            const colStyle: CSSProperties = {
                                position: 'relative',
                                borderLeft: dotBorder,
                                cursor: 'copy',
                            };
                            if (isToday)
                                colStyle.background =
                                    'color-mix(in oklch, var(--primary) 2.5%, transparent)';
                            const timed = events.filter(
                                (e) => !e.allDay && onSameLocalDay(e, d),
                            );
                            return (
                                <div
                                    key={i}
                                    style={colStyle}
                                    onClick={(ev) => {
                                        const rect = (
                                            ev.currentTarget as HTMLElement
                                        ).getBoundingClientRect();
                                        const y = ev.clientY - rect.top;
                                        const hour = Math.max(
                                            H0,
                                            Math.min(
                                                20,
                                                H0 + Math.floor(y / HOUR_H),
                                            ),
                                        );
                                        handlers.onCreate(
                                            d,
                                            hour,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                >
                                    {hours.map((h) => (
                                        <div
                                            key={h}
                                            style={{
                                                height: HOUR_H,
                                                borderTop:
                                                    '1px solid color-mix(in oklch, var(--border) 35%, transparent)',
                                            }}
                                        />
                                    ))}

                                    {isToday ? (
                                        <div
                                            style={{
                                                position: 'absolute',
                                                left: 0,
                                                right: 0,
                                                top: nowTop,
                                                height: 2,
                                                background:
                                                    'var(--status-critical)',
                                                zIndex: 5,
                                                boxShadow:
                                                    '0 0 0 1px color-mix(in oklch, var(--status-critical) 30%, transparent)',
                                            }}
                                        />
                                    ) : null}

                                    {timed.map((e) => {
                                        const isShift = e.layer === 'shift';
                                        const isGap = !!e.extendedProps.gap;
                                        const c = isGap
                                            ? 'var(--status-critical)'
                                            : isShift
                                              ? 'var(--live)'
                                              : colorVar(e);
                                        const s = new Date(e.start);
                                        const en = e.end ? new Date(e.end) : s;
                                        const top = Math.max(
                                            0,
                                            (s.getHours() +
                                                s.getMinutes() / 60 -
                                                H0) *
                                                HOUR_H,
                                        );
                                        const h = Math.max(
                                            22,
                                            ((en.getTime() - s.getTime()) /
                                                3_600_000) *
                                                HOUR_H,
                                        );
                                        const block: CSSProperties = isShift
                                            ? {
                                                  position: 'absolute',
                                                  right: 3,
                                                  width: '30%',
                                                  top,
                                                  height: h - 3,
                                                  borderRadius: 7,
                                                  border: `1px ${isGap ? 'dashed' : 'solid'} color-mix(in oklch, ${c} 38%, transparent)`,
                                                  background: `color-mix(in oklch, ${c} ${isGap ? 10 : 13}%, var(--card))`,
                                                  color: 'var(--foreground)',
                                                  padding: '3px 5px',
                                                  textAlign: 'left',
                                                  cursor: 'pointer',
                                                  overflow: 'hidden',
                                                  zIndex: 1,
                                              }
                                            : {
                                                  position: 'absolute',
                                                  left: 3,
                                                  right: 3,
                                                  top,
                                                  height: h - 3,
                                                  borderRadius: 8,
                                                  border: `1px solid color-mix(in oklch, ${c} 30%, transparent)`,
                                                  borderLeft: `3px solid ${c}`,
                                                  background: `color-mix(in oklch, ${c} 14%, var(--card))`,
                                                  color: 'var(--foreground)',
                                                  padding: '3px 7px',
                                                  textAlign: 'left',
                                                  cursor: 'pointer',
                                                  overflow: 'hidden',
                                                  zIndex: 2,
                                              };
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
                                                style={block}
                                            >
                                                <span
                                                    style={{
                                                        display: 'block',
                                                        fontSize: 11,
                                                        fontWeight: 700,
                                                        lineHeight: 1.2,
                                                        overflow: 'hidden',
                                                        textOverflow:
                                                            'ellipsis',
                                                        whiteSpace: 'nowrap',
                                                    }}
                                                >
                                                    {isGap
                                                        ? '⚠ Unfilled'
                                                        : e.title}
                                                </span>
                                                <span
                                                    style={{
                                                        display: 'block',
                                                        fontSize: 10,
                                                        opacity: 0.8,
                                                    }}
                                                >
                                                    {fmtTime(s)}–{fmtTime(en)}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
}

export default CalendarTimeGrid;
