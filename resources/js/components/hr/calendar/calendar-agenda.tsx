/* eslint-disable no-restricted-syntax -- Agenda cards ported 1:1 from the design
 * prototype: colour-railed list rows with time / meta / badge are styled native
 * <button> surfaces, not shadcn primitives. Colours are token / color-mix. */
import { type CalendarLayerFeed } from '@/lib/calendar/layer-feed';
import {
    addDays,
    colorVar,
    dayStart,
    deepName,
    fmtDayMon,
    fmtLong,
    fmtTime,
    isoKey,
    layerLabel,
    sameDay,
    secondaryFor,
} from './calendar-render';

export interface AgendaHandlers {
    onEntryClick: (e: CalendarLayerFeed, x: number, y: number) => void;
    onEntryCtx: (e: CalendarLayerFeed, x: number, y: number) => void;
    onDeepLink: (href: string) => void;
}

function badgeText(e: CalendarLayerFeed): string {
    if (e.layer === 'event') {
        const cat = (e.extendedProps.category as string) || 'Event';
        return cat.charAt(0).toUpperCase() + cat.slice(1);
    }
    return layerLabel(e);
}

export function CalendarAgenda({
    events,
    today,
    handlers,
}: {
    events: CalendarLayerFeed[];
    today: Date;
    handlers: AgendaHandlers;
}) {
    const t0 = dayStart(today);
    const upcoming = events
        .filter(
            (e) =>
                dayStart(new Date(e.end || e.start)).getTime() >= t0.getTime(),
        )
        .sort(
            (a, b) => new Date(a.start).getTime() - new Date(b.start).getTime(),
        );

    const byDay = new Map<string, CalendarLayerFeed[]>();
    for (const e of upcoming) {
        const s = new Date(e.start);
        const key = isoKey(s.getTime() < t0.getTime() ? today : s);
        const arr = byDay.get(key) ?? [];
        arr.push(e);
        byDay.set(key, arr);
    }

    const groups = [...byDay.keys()]
        .sort()
        .slice(0, 8)
        .map((k) => {
            const [y, m, dd] = k.split('-').map(Number);
            const d = new Date(y, m - 1, dd);
            const label = sameDay(d, today)
                ? 'Today'
                : sameDay(d, addDays(today, 1))
                  ? 'Tomorrow'
                  : fmtLong(d);
            return { d, label, items: byDay.get(k)! };
        });

    if (groups.length === 0) {
        return (
            <div
                style={{
                    borderRadius: 16,
                    border: '1px dashed var(--border)',
                    background: 'var(--card)',
                    padding: 46,
                    textAlign: 'center',
                }}
            >
                <div style={{ fontSize: 30 }}>☕</div>
                <div style={{ marginTop: 8, fontSize: 15, fontWeight: 600 }}>
                    Nothing on — enjoy the quiet
                </div>
                <div style={{ fontSize: 13, color: 'var(--muted-foreground)' }}>
                    No events match your filters in this range.
                </div>
            </div>
        );
    }

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            {groups.map((grp) => (
                <div key={grp.label}>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'baseline',
                            gap: 10,
                            marginBottom: 9,
                        }}
                    >
                        <h3
                            style={{ margin: 0, fontSize: 14, fontWeight: 700 }}
                        >
                            {grp.label}
                        </h3>
                        <span
                            style={{
                                fontSize: 12,
                                color: 'var(--muted-foreground)',
                            }}
                        >
                            {fmtDayMon(grp.d)}
                        </span>
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 7,
                        }}
                    >
                        {grp.items.map((e) => {
                            const c = colorVar(e);
                            const ro = e.layer !== 'event';
                            const start = new Date(e.start);
                            const end = e.end ? new Date(e.end) : start;
                            const dur = e.allDay
                                ? ''
                                : `${Math.round((end.getTime() - start.getTime()) / 60000)} min`;
                            const sub = secondaryFor(e);
                            const meta =
                                layerLabel(e) + (sub ? ` · ${sub}` : '');
                            return (
                                <button
                                    key={e.id}
                                    type="button"
                                    onClick={(ev) =>
                                        handlers.onEntryClick(
                                            e,
                                            ev.clientX,
                                            ev.clientY,
                                        )
                                    }
                                    onContextMenu={(ev) => {
                                        ev.preventDefault();
                                        handlers.onEntryCtx(
                                            e,
                                            ev.clientX,
                                            ev.clientY,
                                        );
                                    }}
                                    className="hrcal-agenda-row"
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 14,
                                        borderRadius: 13,
                                        border: '1px solid var(--border)',
                                        background: 'var(--card)',
                                        padding: '12px 15px',
                                        textAlign: 'left',
                                        cursor: 'pointer',
                                        boxShadow: '0 1px 2px rgba(0,0,0,.03)',
                                    }}
                                >
                                    <span
                                        style={{
                                            height: 38,
                                            width: 4,
                                            flex: 'none',
                                            borderRadius: 9999,
                                            background: c,
                                        }}
                                    />
                                    <span style={{ width: 64, flex: 'none' }}>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 13,
                                                fontWeight: 700,
                                                fontVariantNumeric:
                                                    'tabular-nums',
                                            }}
                                        >
                                            {e.allDay
                                                ? 'All day'
                                                : fmtTime(start)}
                                        </span>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 11,
                                                color: 'var(--muted-foreground)',
                                            }}
                                        >
                                            {dur}
                                        </span>
                                    </span>
                                    <span style={{ minWidth: 0, flex: 1 }}>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 14,
                                                fontWeight: 600,
                                                lineHeight: 1.3,
                                            }}
                                        >
                                            {e.title}
                                        </span>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 12,
                                                color: 'var(--muted-foreground)',
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {meta}
                                        </span>
                                    </span>
                                    <span
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            borderRadius: 9999,
                                            padding: '3px 10px',
                                            fontSize: 11,
                                            fontWeight: 700,
                                            background: `color-mix(in oklch, ${c} 14%, var(--card))`,
                                            color: c,
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {badgeText(e)}
                                    </span>
                                    {ro && e.deepLink ? (
                                        <span
                                            role="link"
                                            tabIndex={-1}
                                            onClick={(ev) => {
                                                ev.stopPropagation();
                                                handlers.onDeepLink(
                                                    e.deepLink!,
                                                );
                                            }}
                                            style={{
                                                display: 'inline-flex',
                                                alignItems: 'center',
                                                gap: 5,
                                                fontSize: 11.5,
                                                fontWeight: 700,
                                                color: 'var(--primary)',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            Open in {deepName(e)}
                                            <svg
                                                width="13"
                                                height="13"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                            </svg>
                                        </span>
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

export default CalendarAgenda;
