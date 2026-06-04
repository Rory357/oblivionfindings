/**
 * Site Calendar — shared helpers, primitives and the five views.
 * Ported from the prototype's cal-views.jsx; icons swapped for lucide-react and
 * shared UI state threaded via context to keep view signatures small.
 */
import { Fragment, createContext, useContext, useEffect, useRef, useState, type CSSProperties, type ReactNode } from 'react';
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    CheckSquare,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Dot,
    Hammer,
    KeyRound,
    MapPin,
    Plus,
    Repeat,
    ShieldCheck,
    Siren,
    Truck,
    Utensils,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { colorVars, parseDT, type CalendarItem, type ColorBy } from '@/lib/calendar/recur';

export type Density = 'comfortable' | 'compact';

export interface SourceDef {
    key: string;
    label: string;
    short: string;
    group: string;
    icon: string;
    origin: string;
}

export type Decorated = CalendarItem & {
    _start: Date;
    _end: Date | null;
    typeLabel?: string | null;
};

/* ---- icons -------------------------------------------------------------- */

const ICONS: Record<string, LucideIcon> = {
    CalendarDays,
    ClipboardList,
    ShieldCheck,
    KeyRound,
    CheckSquare,
    AlertTriangle,
    Wrench,
    Truck,
    Utensils,
    Hammer,
    Siren,
    Repeat,
    MapPin,
    Plus,
    Dot,
};

export function Icon({ name, className = 'h-4 w-4' }: { name: string; className?: string }) {
    const C = ICONS[name] ?? Dot;
    return <C className={className} />;
}

/* ---- date helpers ------------------------------------------------------- */

export const pad = (n: number): string => String(n).padStart(2, '0');
export const sameDay = (a: Date, b: Date): boolean =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
export const addDays = (d: Date, n: number): Date => {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
};
export const startOfWeek = (d: Date): Date => {
    const r = new Date(d);
    r.setHours(0, 0, 0, 0);
    r.setDate(r.getDate() - r.getDay());
    return r;
};
export const startOfMonth = (d: Date): Date => new Date(d.getFullYear(), d.getMonth(), 1);
export const endOfMonth = (d: Date): Date => new Date(d.getFullYear(), d.getMonth() + 1, 0);
export const minutes = (d: Date): number => d.getHours() * 60 + d.getMinutes();
export function fmtTime(d: Date): string {
    let h = d.getHours();
    const m = d.getMinutes();
    const ap = h >= 12 ? 'pm' : 'am';
    h = h % 12 || 12;
    return m ? `${h}:${pad(m)}${ap}` : `${h}${ap}`;
}
export const fmtTimeRange = (s: Date, e: Date | null): string => (e ? `${fmtTime(s)} – ${fmtTime(e)}` : fmtTime(s));

export const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const WD_FULL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
export const MO = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/**
 * Compact month grid for the "jump to date" picker in the banner/toolbar — lets the
 * user navigate to any day directly instead of stepping period-by-period. Pure grid;
 * the Popover wrapper lives in SiteCalendar (it owns periodLabel + the trigger style).
 */
export function MiniMonth({ selected, onSelect }: { selected: Date; onSelect: (d: Date) => void }) {
    const [cursor, setCursor] = useState(() => startOfMonth(selected));
    const today = new Date();
    const gridStart = startOfWeek(startOfMonth(cursor));
    const days = Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
    const shiftMonth = (delta: number) => setCursor((c) => new Date(c.getFullYear(), c.getMonth() + delta, 1));

    return (
        <div className="w-60 p-1">
            <div className="mb-1 flex items-center justify-between px-1">
                <button
                    type="button"
                    onClick={() => shiftMonth(-1)}
                    aria-label="Previous month"
                    className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
                >
                    <ChevronLeft className="h-4 w-4" />
                </button>
                <span className="text-sm font-semibold">
                    {MO[cursor.getMonth()]} {cursor.getFullYear()}
                </span>
                <button
                    type="button"
                    onClick={() => shiftMonth(1)}
                    aria-label="Next month"
                    className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
                >
                    <ChevronRight className="h-4 w-4" />
                </button>
            </div>
            <div className="grid grid-cols-7 gap-0.5 px-1 text-center text-[10px] font-medium uppercase text-muted-foreground">
                {WD.map((d) => (
                    <div key={d}>{d.slice(0, 2)}</div>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-0.5 p-1">
                {days.map((d) => {
                    const inMonth = d.getMonth() === cursor.getMonth();
                    const isToday = sameDay(d, today);
                    const isSelected = sameDay(d, selected);
                    const tone = isSelected
                        ? 'bg-primary font-semibold text-primary-foreground'
                        : isToday
                          ? 'font-semibold text-primary ring-1 ring-primary/40 hover:bg-muted'
                          : inMonth
                            ? 'text-foreground hover:bg-muted'
                            : 'text-muted-foreground/40 hover:bg-muted';
                    return (
                        <button
                            key={d.toISOString()}
                            type="button"
                            onClick={() => onSelect(d)}
                            aria-current={isToday ? 'date' : undefined}
                            aria-label={`${WD_FULL[d.getDay()]} ${d.getDate()} ${MO[d.getMonth()]} ${d.getFullYear()}`}
                            className={`inline-flex h-8 items-center justify-center rounded-md text-xs transition-colors ${tone}`}
                        >
                            {d.getDate()}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function decorate(item: CalendarItem): Decorated {
    const start = parseDT(item.start) ?? new Date();
    return { ...item, _start: start, _end: item.end ? parseDT(item.end) : null };
}

/* ---- shared UI context -------------------------------------------------- */

interface CalendarUI {
    colorBy: ColorBy;
    density: Density;
    srcByKey: Record<string, SourceDef>;
    onSelect: (ev: Decorated) => void;
    onPreview?: (ev: Decorated, el: HTMLElement) => void;
    onPreviewEnd?: () => void;
    onCreateAt?: (d: Date, hour?: number) => void;
    onContext?: (e: React.MouseEvent, d: Date, hour?: number) => void;
    onMove?: (ev: Decorated, start: Date, end?: Date) => void;
}

const CalendarUICtx = createContext<CalendarUI>({
    colorBy: 'source',
    density: 'comfortable',
    srcByKey: {},
    onSelect: () => {},
});

export function CalendarUIProvider({ value, children }: { value: CalendarUI; children: ReactNode }) {
    return <CalendarUICtx.Provider value={value}>{children}</CalendarUICtx.Provider>;
}
const useCalUI = () => useContext(CalendarUICtx);

const cv = (ev: Decorated, colorBy: ColorBy): CSSProperties => colorVars(ev, colorBy);

/* ---- status taxonomy + primitives -------------------------------------- */

export const STATUSES: Record<string, { label: string; tone: string }> = {
    scheduled: { label: 'Scheduled', tone: 'info' },
    overdue: { label: 'Overdue', tone: 'critical' },
    pending: { label: 'Pending approval', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    completed: { label: 'Completed', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

const TONE: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-status-neutral-bg text-status-neutral',
};

export function StatusBadge({ status, className = '' }: { status: string; className?: string }) {
    const s = STATUSES[status] ?? STATUSES.scheduled;
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium leading-none ${TONE[s.tone]} ${className}`}
        >
            {status === 'overdue' && <AlertTriangle className="h-3 w-3" />}
            {s.label}
        </span>
    );
}

function initialsOf(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');
}

export function Avatar({ person, size = 'h-6 w-6' }: { person: { id: number; name: string }; size?: string }) {
    const hue = (person.id * 57) % 360;
    return (
        <span
            className={`inline-flex ${size} shrink-0 items-center justify-center rounded-full text-[10px] font-semibold ring-2 ring-card`}
            style={{ background: `oklch(0.92 0.05 ${hue})`, color: `oklch(0.42 0.13 ${hue})` }}
            title={person.name}
        >
            {initialsOf(person.name)}
        </span>
    );
}

export function SourceDot({ k, className = 'h-2 w-2' }: { k: string; className?: string }) {
    return <span className={`inline-block shrink-0 rounded-full ${className}`} style={{ background: `var(--src-${k})` }} />;
}

function SourceIcon({ k, className = 'h-3.5 w-3.5' }: { k: string; className?: string }) {
    const { srcByKey } = useCalUI();
    const m = srcByKey[k];
    return <Icon name={m ? m.icon : 'Dot'} className={className} />;
}

function RecurGlyph({ ev, className = 'h-2.5 w-2.5' }: { ev: Decorated; className?: string }) {
    return ev.isOccurrence || ev.recurrence ? <Repeat className={className} /> : null;
}

/* ---- chips + time blocks ----------------------------------------------- */

function MiniChip({ ev, onDragStart }: { ev: Decorated; onDragStart?: (e: React.DragEvent, ev: Decorated) => void }) {
    const { colorBy, onSelect, onPreview, onPreviewEnd } = useCalUI();
    const overdue = ev.status === 'overdue';
    return (
        <button
            onClick={(e) => {
                e.stopPropagation();
                onPreviewEnd?.();
                onSelect(ev);
            }}
            draggable={!!onDragStart}
            onDragStart={onDragStart ? (e) => { onPreviewEnd?.(); onDragStart(e, ev); } : undefined}
            style={cv(ev, colorBy)}
            className={`group flex w-full items-center gap-1.5 rounded-[6px] border px-1.5 py-[3px] text-left text-[11px] leading-tight transition-all hover:shadow-sm ${onDragStart ? 'cursor-grab active:cursor-grabbing' : ''}`}
            onMouseEnter={(e) => {
                e.currentTarget.style.background = 'var(--cb)';
                onPreview?.(ev, e.currentTarget);
            }}
            onMouseLeave={(e) => {
                e.currentTarget.style.background = '';
                onPreviewEnd?.();
            }}
        >
            <span className="h-3 w-1 shrink-0 rounded-full" style={{ background: 'var(--c)' }} />
            {!ev.allDay && (
                <span className="tnum shrink-0 font-medium" style={{ color: 'var(--c)' }}>
                    {fmtTime(ev._start)}
                </span>
            )}
            <span className={`truncate ${overdue ? 'font-semibold text-status-critical' : 'text-foreground/90'}`}>{ev.title}</span>
            <RecurGlyph ev={ev} className="ml-auto h-2.5 w-2.5 shrink-0 opacity-45" />
        </button>
    );
}

const GRID_START = 0;
const GRID_END = 24;
const HOUR_H = 54;
const HOURS = Array.from({ length: GRID_END - GRID_START }, (_, i) => GRID_START + i);
const topFor = (min: number): number => ((min - GRID_START * 60) / 60) * HOUR_H;

/** Scroll a time-grid to ~6am on mount / period change so the working day is in
 *  view even though the grid now spans the full 24 hours (midnight → midnight). */
function useGridAutoScroll(navDate: Date) {
    const ref = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        if (ref.current) ref.current.scrollTop = topFor(6 * 60);
    }, [navDate]);
    return ref;
}

/** Width (px) the vertical scrollbar steals from a scroll container, so the
 *  fixed day-header / all-day rows can reserve the same gutter and keep their
 *  columns aligned with the scrolling time grid below. 0 with overlay scrollbars. */
function useScrollbarWidth(ref: { current: HTMLDivElement | null }): number {
    const [w, setW] = useState(0);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const measure = () => setW(el.offsetWidth - el.clientWidth);
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, [ref]);
    return w;
}

type Packed = Decorated & { _s: number; _e: number; _col: number; _cols: number };

function packDay(list: Decorated[]): Packed[] {
    const evs = list
        .filter((e) => !e.allDay)
        .map((e) => ({ ...e, _s: 0, _e: 0, _col: 0, _cols: 1 }) as Packed)
        .sort((a, b) => a._start.getTime() - b._start.getTime());
    const out: Packed[] = [];
    let cluster: Packed[] = [];
    let clusterEnd = -1;
    const flush = () => {
        const cols: number[] = [];
        cluster.forEach((e) => {
            let placed = false;
            for (let c = 0; c < cols.length; c++) {
                if (cols[c] <= e._s) {
                    e._col = c;
                    cols[c] = e._e;
                    placed = true;
                    break;
                }
            }
            if (!placed) {
                e._col = cols.length;
                cols.push(e._e);
            }
        });
        cluster.forEach((e) => {
            e._cols = cols.length;
            out.push(e);
        });
        cluster = [];
        clusterEnd = -1;
    };
    evs.forEach((e) => {
        const s = minutes(e._start);
        const en = e._end ? minutes(e._end) : s + 45;
        e._s = s;
        e._e = en;
        if (cluster.length && s >= clusterEnd) flush();
        cluster.push(e);
        clusterEnd = Math.max(clusterEnd, en);
    });
    flush();
    return out;
}

function TimeBlock({ ev, compact }: { ev: Packed; compact?: boolean }) {
    const { colorBy, onSelect, onMove, onPreview, onPreviewEnd } = useCalUI();
    const top = topFor(ev._s);
    const baseH = Math.max(((ev._e - ev._s) / 60) * HOUR_H - 3, 22);
    const [drag, setDrag] = useState<{ mode: 'move' | 'resize'; dy: number } | null>(null);
    const overdue = ev.status === 'overdue';

    const startDrag = (mode: 'move' | 'resize') => (e: React.PointerEvent) => {
        if (e.button !== 0) return;
        onPreviewEnd?.();
        if (!onMove || ev.allDay) {
            if (mode === 'move') onSelect(ev);
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        const startY = e.clientY;
        let dy = 0;
        let moved = false;
        const move = (me: PointerEvent) => {
            dy = me.clientY - startY;
            if (Math.abs(dy) > 3) moved = true;
            setDrag({ mode, dy });
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            setDrag(null);
            const snap = Math.round((dy / HOUR_H) * 60 / 15) * 15;
            if (moved && snap !== 0) {
                const s = new Date(ev._start);
                const e2 = ev._end ? new Date(ev._end) : new Date(s.getTime() + 45 * 60000);
                if (mode === 'move') {
                    s.setMinutes(s.getMinutes() + snap);
                    e2.setMinutes(e2.getMinutes() + snap);
                } else {
                    e2.setMinutes(e2.getMinutes() + snap);
                    if (e2 <= s) e2.setTime(s.getTime() + 15 * 60000);
                }
                onMove(ev, s, e2);
            } else if (!moved) {
                onSelect(ev);
            }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    };

    const dy = drag ? drag.dy : 0;
    const liveTop = top + (drag && drag.mode === 'move' ? dy : 0);
    const liveH = Math.max(baseH + (drag && drag.mode === 'resize' ? dy : 0), 22);
    const w = 100 / ev._cols;
    return (
        <div
            onPointerDown={startDrag('move')}
            onMouseEnter={(e) => {
                if (!drag) onPreview?.(ev, e.currentTarget);
            }}
            onMouseLeave={() => onPreviewEnd?.()}
            style={{
                ...cv(ev, colorBy),
                top: liveTop,
                height: liveH,
                left: `calc(${ev._col * w}% + 2px)`,
                width: `calc(${w}% - 4px)`,
                background: 'var(--cb)',
                borderColor: 'var(--cl)',
                boxShadow: 'inset 3px 0 0 var(--c)',
                zIndex: drag ? 30 : undefined,
            }}
            className={`absolute select-none overflow-hidden rounded-md border px-2 py-1 text-left transition-shadow hover:z-10 hover:shadow-md ${onMove ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer'} ${drag ? 'shadow-lg ring-1 ring-primary/40' : ''}`}
        >
            <div className="flex items-center gap-1" style={{ color: 'var(--c)' }}>
                <SourceIcon k={ev.source} className="h-3 w-3 shrink-0" />
                <span className="tnum truncate text-[10px] font-semibold">{fmtTime(ev._start)}</span>
                <RecurGlyph ev={ev} className="ml-auto h-2.5 w-2.5 shrink-0 opacity-60" />
            </div>
            <div className={`truncate text-[12px] font-medium leading-tight text-foreground ${compact ? '' : 'mt-0.5'}`}>{ev.title}</div>
            {!compact && liveH > 52 && ev.room && (
                <div className="mt-0.5 flex items-center gap-1 text-[10.5px] text-muted-foreground">
                    <MapPin className="h-2.5 w-2.5" />
                    <span className="truncate">{ev.room}</span>
                </div>
            )}
            {overdue && <span className="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-status-critical" />}
            {onMove && <div onPointerDown={startDrag('resize')} className="absolute inset-x-0 bottom-0 h-2 cursor-ns-resize" title="Drag to resize" />}
        </div>
    );
}

function AllDayRow({ days, events, padRight = 0 }: { days: Date[]; events: Decorated[]; padRight?: number }) {
    const { colorBy, onSelect, onPreview, onPreviewEnd } = useCalUI();
    if (!events.some((e) => e.allDay)) return null;
    return (
        <div className="flex border-b bg-muted/30" style={{ paddingRight: padRight }}>
            <div className="flex w-14 shrink-0 items-center justify-end pr-2 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">All-day</div>
            <div className="grid flex-1" style={{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }}>
                {days.map((d, i) => (
                    <div key={i} className="min-h-[34px] space-y-1 border-l p-1">
                        {events
                            .filter((e) => e.allDay && sameDay(e._start, d))
                            .map((e) => (
                                <button
                                    key={e.id}
                                    onClick={() => { onPreviewEnd?.(); onSelect(e); }}
                                    style={cv(e, colorBy)}
                                    className="flex w-full items-center gap-1 rounded border px-1.5 py-1 text-left text-[11px] font-medium"
                                    onMouseEnter={(ev) => { ev.currentTarget.style.background = 'var(--cb)'; onPreview?.(e, ev.currentTarget); }}
                                    onMouseLeave={(ev) => { ev.currentTarget.style.background = ''; onPreviewEnd?.(); }}
                                >
                                    <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: 'var(--c)' }} />
                                    <span className={`truncate ${e.status === 'overdue' ? 'text-status-critical' : 'text-foreground/90'}`}>{e.title}</span>
                                    <RecurGlyph ev={e} className="ml-auto h-2.5 w-2.5 shrink-0 opacity-45" />
                                </button>
                            ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

/** A Date that re-renders on an interval (default 60s) so live "now" markers
 *  (the week/day NowLine, the rail's NOW) advance without a manual reload. */
export function useNow(intervalMs = 60_000): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), intervalMs);
        return () => clearInterval(id);
    }, [intervalMs]);
    return now;
}

function NowLine({ day }: { day: Date }) {
    const now = useNow();
    if (!sameDay(day, now)) return null;
    const m = minutes(now);
    if (m < GRID_START * 60 || m > GRID_END * 60) return null;
    return (
        <div className="pointer-events-none absolute left-0 right-0 z-20" style={{ top: topFor(m) }}>
            <div className="flex items-center">
                <span className="-ml-1 h-2.5 w-2.5 rounded-full bg-status-critical" />
                <span className="h-px flex-1 bg-status-critical" />
            </div>
        </div>
    );
}

/* ---- views -------------------------------------------------------------- */

export function MonthView({ events, navDate }: { events: Decorated[]; navDate: Date }) {
    const { density, onCreateAt, onContext, onMove, onSelect } = useCalUI();
    const TODAY = new Date();
    const first = startOfMonth(navDate);
    const gridStart = startOfWeek(first);
    const lastDay = endOfMonth(navDate);
    const rows = Math.ceil((first.getDay() + lastDay.getDate()) / 7);
    const shown = Array.from({ length: rows * 7 }, (_, i) => addDays(gridStart, i));
    const cap = density === 'compact' ? 5 : 3;
    const dragRef = useRef<Decorated | null>(null);
    const [over, setOver] = useState<number | null>(null);
    const onDragStart = onMove
        ? (e: React.DragEvent, ev: Decorated) => {
              dragRef.current = ev;
              e.dataTransfer.effectAllowed = 'move';
              try {
                  e.dataTransfer.setData('text/plain', String(ev.id));
              } catch {
                  /* noop */
              }
          }
        : undefined;

    return (
        <div className="flex h-full flex-col overflow-hidden rounded-xl border bg-card">
            <div className="grid grid-cols-7 border-b bg-muted/40">
                {WD.map((d) => (
                    <div key={d} className="px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{d}</div>
                ))}
            </div>
            <div className="scroll-pretty grid flex-1 grid-cols-7 overflow-y-auto" style={{ gridTemplateRows: `repeat(${rows}, minmax(118px, 1fr))` }}>
                {shown.map((d, i) => {
                    const inMonth = d.getMonth() === navDate.getMonth();
                    const isToday = sameDay(d, TODAY);
                    const isWeekend = d.getDay() === 0 || d.getDay() === 6;
                    const dayEvents = events
                        .filter((e) => sameDay(e._start, d))
                        .sort((a, b) => (a.allDay ? -1 : 0) - (b.allDay ? -1 : 0) || a._start.getTime() - b._start.getTime());
                    const isOver = over === i;
                    const cellBg = isOver
                        ? 'bg-primary/10 ring-1 ring-inset ring-primary/40'
                        : isToday
                          ? 'bg-primary/[0.06] hover:bg-primary/[0.09]'
                          : !inMonth
                            ? 'bg-muted/20'
                            : isWeekend
                              ? 'bg-muted/[0.35] hover:bg-accent/40'
                              : 'bg-card hover:bg-accent/40';
                    return (
                        <div
                            key={i}
                            onClick={() => onCreateAt?.(d)}
                            onContextMenu={onContext ? (e) => onContext(e, d) : undefined}
                            onDragOver={onMove ? (e) => { e.preventDefault(); if (over !== i) setOver(i); } : undefined}
                            onDragLeave={onMove ? () => setOver((o) => (o === i ? null : o)) : undefined}
                            onDrop={onMove ? (e) => { e.preventDefault(); setOver(null); const ev = dragRef.current; dragRef.current = null; if (ev) onMove(ev, d); } : undefined}
                            className={`group relative flex min-h-0 flex-col overflow-hidden border-b border-r p-1 transition-colors last:border-r-0 ${cellBg} ${(i + 1) % 7 === 0 ? 'border-r-0' : ''}`}
                        >
                            {isToday && <span className="pointer-events-none absolute inset-x-0 top-0 h-0.5 bg-primary" />}
                            <div className="mb-1 flex items-center justify-between px-0.5">
                                <span className={`tnum inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-[12px] font-semibold ${isToday ? 'bg-primary text-primary-foreground shadow-sm' : inMonth ? 'text-foreground' : 'text-muted-foreground/60'}`}>{d.getDate()}</span>
                                {onCreateAt && (
                                    <span className="opacity-0 transition-opacity group-hover:opacity-100">
                                        <Plus className="h-3.5 w-3.5 text-muted-foreground" />
                                    </span>
                                )}
                            </div>
                            <div className="space-y-[3px]">
                                {dayEvents.slice(0, cap).map((e) => (
                                    <MiniChip key={e.id} ev={e} onDragStart={onDragStart} />
                                ))}
                                {dayEvents.length > cap && (
                                    <button
                                        onClick={(ev) => { ev.stopPropagation(); onSelect(dayEvents[cap]); }}
                                        className="px-1 text-[11px] font-medium text-muted-foreground hover:text-foreground"
                                    >
                                        +{dayEvents.length - cap} more
                                    </button>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export function WeekView({ events, navDate }: { events: Decorated[]; navDate: Date }) {
    const { onContext } = useCalUI();
    const scrollRef = useGridAutoScroll(navDate);
    const sbw = useScrollbarWidth(scrollRef);
    const TODAY = new Date();
    const ws = startOfWeek(navDate);
    const days = Array.from({ length: 7 }, (_, i) => addDays(ws, i));
    const ctxAt = (e: React.MouseEvent, d: Date) => {
        if (!onContext) return;
        const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const h = Math.min(Math.max(GRID_START + Math.floor((e.clientY - r.top) / HOUR_H), GRID_START), GRID_END - 1);
        onContext(e, d, h);
    };
    return (
        <div className="flex h-full flex-col overflow-hidden rounded-xl border bg-card">
            <div className="flex border-b" style={{ paddingRight: sbw }}>
                <div className="w-14 shrink-0" />
                {days.map((d, i) => {
                    const isToday = sameDay(d, TODAY);
                    return (
                        <div key={i} className="flex flex-1 flex-col items-center gap-0.5 border-l py-2">
                            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{WD[d.getDay()]}</span>
                            <span className={`tnum flex h-7 w-7 items-center justify-center rounded-full text-[13px] font-semibold ${isToday ? 'bg-primary text-primary-foreground' : 'text-foreground'}`}>{d.getDate()}</span>
                        </div>
                    );
                })}
            </div>
            <AllDayRow days={days} events={events} padRight={sbw} />
            <div ref={scrollRef} data-view-scroll className="scroll-pretty relative flex-1 overflow-y-auto pt-3">
                <div className="flex" style={{ height: HOURS.length * HOUR_H }}>
                    <div className="w-14 shrink-0">
                        {HOURS.map((h) => (
                            <div key={h} className="relative" style={{ height: HOUR_H }}>
                                <span className="tnum absolute -top-2 right-2 text-[10.5px] font-medium text-muted-foreground">{(h % 12 || 12)}{h >= 12 ? 'pm' : 'am'}</span>
                            </div>
                        ))}
                    </div>
                    {days.map((d, i) => {
                        const packed = packDay(events.filter((e) => sameDay(e._start, d)));
                        return (
                            <div key={i} className="relative flex-1 border-l" onContextMenu={(e) => ctxAt(e, d)}>
                                {HOURS.map((h) => <div key={h} className="border-b border-border/60" style={{ height: HOUR_H }} />)}
                                <NowLine day={d} />
                                {packed.map((e) => <TimeBlock key={e.id} ev={e} compact />)}
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export function DayView({ events, navDate }: { events: Decorated[]; navDate: Date }) {
    const { onContext } = useCalUI();
    const scrollRef = useGridAutoScroll(navDate);
    const sbw = useScrollbarWidth(scrollRef);
    const TODAY = new Date();
    const day = navDate;
    const dayEvents = events.filter((e) => sameDay(e._start, day));
    const packed = packDay(dayEvents);
    const ctxAt = (e: React.MouseEvent) => {
        if (!onContext) return;
        const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const h = Math.min(Math.max(GRID_START + Math.floor((e.clientY - r.top) / HOUR_H), GRID_START), GRID_END - 1);
        onContext(e, day, h);
    };
    return (
        <div className="flex h-full flex-col overflow-hidden rounded-xl border bg-card">
            <div className="flex items-center gap-3 border-b px-4 py-3">
                <span className={`tnum flex h-11 w-11 flex-col items-center justify-center rounded-xl ${sameDay(day, TODAY) ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'}`}>
                    <span className="text-[9px] font-semibold uppercase leading-none">{WD[day.getDay()]}</span>
                    <span className="text-lg font-bold leading-tight">{day.getDate()}</span>
                </span>
                <div>
                    <div className="text-sm font-semibold">{WD_FULL[day.getDay()]}</div>
                    <div className="text-xs text-muted-foreground">{dayEvents.length} {dayEvents.length === 1 ? 'entry' : 'entries'} scheduled</div>
                </div>
            </div>
            <AllDayRow days={[day]} events={events} padRight={sbw} />
            <div ref={scrollRef} data-view-scroll className="scroll-pretty relative flex-1 overflow-y-auto pt-3">
                <div className="flex" style={{ height: HOURS.length * HOUR_H }}>
                    <div className="w-14 shrink-0">
                        {HOURS.map((h) => (
                            <div key={h} className="relative" style={{ height: HOUR_H }}>
                                <span className="tnum absolute -top-2 right-2 text-[10.5px] font-medium text-muted-foreground">{(h % 12 || 12)}{h >= 12 ? 'pm' : 'am'}</span>
                            </div>
                        ))}
                    </div>
                    <div className="relative flex-1 border-l" onContextMenu={ctxAt}>
                        {HOURS.map((h) => <div key={h} className="border-b border-border/60" style={{ height: HOUR_H }} />)}
                        <NowLine day={day} />
                        {packed.map((e) => <TimeBlock key={e.id} ev={e} />)}
                    </div>
                </div>
            </div>
        </div>
    );
}

export function AgendaView({ events, navDate }: { events: Decorated[]; navDate: Date }) {
    const { colorBy, srcByKey, onSelect, onContext } = useCalUI();
    const TODAY = new Date();
    const inMonth = events
        .filter((e) => e._start.getMonth() === navDate.getMonth() && e._start.getFullYear() === navDate.getFullYear())
        .sort((a, b) => a._start.getTime() - b._start.getTime());
    const groups: { key: string; date: Date; items: Decorated[] }[] = [];
    inMonth.forEach((e) => {
        const key = e._start.toDateString();
        let g = groups.find((x) => x.key === key);
        if (!g) {
            g = { key, date: e._start, items: [] };
            groups.push(g);
        }
        g.items.push(e);
    });
    return (
        <div className="scroll-pretty h-full overflow-y-auto rounded-xl border bg-card">
            {groups.length === 0 && (
                <div className="flex h-full flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
                    <CalendarDays className="h-10 w-10 opacity-40" />
                    <p className="text-sm">No entries match your filters this month.</p>
                </div>
            )}
            {groups.map((g) => {
                const isToday = sameDay(g.date, TODAY);
                return (
                    <div key={g.key} className="flex border-b last:border-b-0" onContextMenu={onContext ? (e) => onContext(e, g.date) : undefined}>
                        <div className={`sticky top-0 flex w-24 shrink-0 flex-col items-center gap-0.5 border-r py-4 ${isToday ? 'bg-primary/5' : 'bg-muted/30'}`}>
                            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{WD[g.date.getDay()]}</span>
                            <span className={`tnum text-2xl font-bold ${isToday ? 'text-primary' : 'text-foreground'}`}>{g.date.getDate()}</span>
                            <span className="text-[11px] text-muted-foreground">{MO[g.date.getMonth()].slice(0, 3)}</span>
                        </div>
                        <div className="flex-1 divide-y">
                            {g.items.map((e) => (
                                <button key={e.id} onClick={() => onSelect(e)} className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/40">
                                    <span className="tnum w-20 shrink-0 text-[12px] font-medium text-muted-foreground">{e.allDay ? 'All day' : fmtTime(e._start)}</span>
                                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" style={{ color: 'var(--c)', background: 'var(--cb)', ...cv(e, colorBy) }}>
                                        <SourceIcon k={e.source} className="h-4 w-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className={`flex items-center gap-1.5 truncate text-sm font-medium ${e.status === 'overdue' ? 'text-status-critical' : 'text-foreground'}`}>
                                            {e.title}
                                            <RecurGlyph ev={e} className="h-3 w-3 shrink-0 text-muted-foreground" />
                                        </span>
                                        <span className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                                            <span style={{ color: `var(--src-${e.source})` }}>{e.typeLabel || srcByKey[e.source]?.label || e.source}</span>
                                            {e.room && <><span>·</span><span>{e.room}</span></>}
                                            {e.ref && <><span>·</span><span className="tnum">{e.ref}</span></>}
                                        </span>
                                    </span>
                                    {e.owner && <Avatar person={e.owner} />}
                                    <StatusBadge status={e.status} className="hidden sm:inline-flex" />
                                </button>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export function TimelineView({ events, navDate, sources }: { events: Decorated[]; navDate: Date; sources: SourceDef[] }) {
    const { colorBy, onSelect, onContext, onPreview, onPreviewEnd } = useCalUI();
    const TODAY = new Date();
    const first = startOfMonth(navDate);
    const last = endOfMonth(navDate);
    const days = Array.from({ length: last.getDate() }, (_, i) => addDays(first, i));
    const COLW = 40;
    const used = sources.filter((s) => events.some((e) => e.source === s.key));
    const lanes = used.length ? used : sources;
    return (
        <div className="scroll-pretty h-full overflow-auto rounded-xl border bg-card">
            <div style={{ minWidth: 160 + days.length * COLW }}>
                <div className="sticky top-0 z-20 flex border-b bg-card">
                    <div className="sticky left-0 z-10 flex w-40 shrink-0 items-center border-r bg-card px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Source</div>
                    <div className="flex">
                        {days.map((d, i) => {
                            const isToday = sameDay(d, TODAY);
                            const wknd = d.getDay() === 0 || d.getDay() === 6;
                            return (
                                <div key={i} style={{ width: COLW }} className={`flex flex-col items-center justify-center border-l py-1 ${wknd ? 'bg-muted/40' : ''}`}>
                                    <span className="text-[9px] uppercase text-muted-foreground">{WD[d.getDay()][0]}</span>
                                    <span className={`tnum flex h-5 w-5 items-center justify-center rounded-full text-[11px] font-semibold ${isToday ? 'bg-primary text-primary-foreground' : 'text-foreground'}`}>{d.getDate()}</span>
                                </div>
                            );
                        })}
                    </div>
                </div>
                {lanes.map((s) => {
                    const laneEvents = events.filter((e) => e.source === s.key);
                    return (
                        <div key={s.key} className="flex border-b last:border-b-0">
                            <div className="sticky left-0 z-10 flex w-40 shrink-0 items-center gap-2 border-r bg-card px-3 py-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-md" style={{ background: `var(--src-${s.key}-bg)`, color: `var(--src-${s.key})` }}>
                                    <SourceIcon k={s.key} className="h-3.5 w-3.5" />
                                </span>
                                <span className="truncate text-[12px] font-medium">{s.label}</span>
                            </div>
                            <div className="relative flex">
                                {days.map((d, i) => {
                                    const wknd = d.getDay() === 0 || d.getDay() === 6;
                                    const dayEvents = laneEvents.filter((e) => sameDay(e._start, d));
                                    return (
                                        <div key={i} style={{ width: COLW }} onContextMenu={onContext ? (e) => onContext(e, d) : undefined} className={`relative min-h-[44px] border-l p-0.5 ${wknd ? 'bg-muted/30' : ''} ${sameDay(d, TODAY) ? 'bg-primary/[0.04]' : ''}`}>
                                            <div className="flex flex-col items-center gap-0.5 pt-1">
                                                {dayEvents.slice(0, 2).map((e) => {
                                                    const vars = cv(e, colorBy) as Record<string, string>;
                                                    return (
                                                        <button
                                                            key={e.id}
                                                            onClick={() => { onPreviewEnd?.(); onSelect(e); }}
                                                            onMouseEnter={(me) => onPreview?.(e, me.currentTarget)}
                                                            onMouseLeave={() => onPreviewEnd?.()}
                                                            className="h-3 w-7 rounded-full ring-2 ring-card transition-transform hover:scale-125"
                                                            style={{ background: vars['--c'], opacity: e.status === 'completed' || e.status === 'cancelled' ? 0.4 : 1 }}
                                                        />
                                                    );
                                                })}
                                                {dayEvents.length > 2 && <span className="text-[9px] font-semibold leading-none text-muted-foreground">+{dayEvents.length - 2}</span>}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/* ---- today rail --------------------------------------------------------- */

function srcLabel(key: string): string {
    return key.charAt(0).toUpperCase() + key.slice(1);
}

function RailRow({ ev, onSelect, showDay = false }: { ev: Decorated; onSelect: (ev: Decorated) => void; showDay?: boolean }) {
    return (
        <button
            onClick={() => onSelect(ev)}
            className="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors hover:bg-accent/40"
        >
            <span className="tnum w-16 shrink-0 text-[11.5px] font-medium text-muted-foreground">
                {showDay ? `${WD[ev._start.getDay()]} ` : ''}
                {ev.allDay ? 'All day' : fmtTime(ev._start)}
            </span>
            <span className="h-7 w-1 shrink-0 rounded-full" style={{ background: `var(--src-${ev.source})` }} />
            <span className="min-w-0 flex-1">
                <span className={`block truncate text-[12.5px] font-medium ${ev.status === 'overdue' ? 'text-status-critical' : 'text-foreground'}`}>{ev.title}</span>
                <span className="block truncate text-[11px]" style={{ color: `var(--src-${ev.source})` }}>
                    {ev.typeLabel || srcLabel(ev.source)}
                </span>
            </span>
            {ev.owner && <Avatar person={ev.owner} size="h-6 w-6" />}
        </button>
    );
}

/**
 * Right-hand context rail (xl+): today focus / happening-now-or-up-next, a
 * "Needs attention" card (overdue + awaiting approval), and today's schedule
 * with a live NOW marker plus an "Up next" (1–14 day) list. Driven by a
 * today-anchored event window, independent of the browsed period.
 */
export function TodayRail({
    events,
    today,
    onSelect,
    onApprovals,
    onJumpToday,
    viewingToday,
}: {
    events: Decorated[];
    today: Date;
    onSelect: (ev: Decorated) => void;
    onApprovals: () => void;
    onJumpToday: () => void;
    viewingToday: boolean;
}) {
    const now = today;
    const dayStart = (d: Date) => {
        const r = new Date(d);
        r.setHours(0, 0, 0, 0);
        return r;
    };
    const dayDiff = (d: Date) => Math.round((dayStart(d).getTime() - dayStart(now).getTime()) / 86_400_000);
    const todayItems = events
        .filter((e) => sameDay(e._start, now))
        .sort((a, b) => (b.allDay ? 0 : 1) - (a.allDay ? 0 : 1) || a._start.getTime() - b._start.getTime());
    const happening = todayItems.find((e) => !e.allDay && e._end && e._start <= now && e._end > now);
    const nextToday = todayItems.find((e) => !e.allDay && e._start > now);
    const focus = happening || nextToday;
    const overdue = events.filter((e) => e.status === 'overdue').sort((a, b) => a._start.getTime() - b._start.getTime());
    const pending = (() => {
        const seen = new Set<string | number>();
        return events
            .filter((e) => e.group === 'manual' && e.approvalStatus === 'pending')
            .filter((e) => {
                const k = e.seriesId ?? e.id;
                if (seen.has(k)) return false;
                seen.add(k);
                return true;
            });
    })();
    const attention = overdue.length + pending.length;
    const upcoming = events
        .filter((e) => {
            const dd = dayDiff(e._start);
            return dd >= 1 && dd <= 14 && e.status !== 'completed' && e.status !== 'cancelled';
        })
        .sort((a, b) => a._start.getTime() - b._start.getTime())
        .slice(0, 6);
    const nowMin = now.getHours() * 60 + now.getMinutes();

    return (
        <aside className="hidden w-[330px] shrink-0 flex-col gap-3 xl:flex">
            {/* Today header + focus */}
            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div className="flex items-start justify-between gap-2 border-b bg-gradient-to-br from-primary/[0.07] to-transparent p-4">
                    <div>
                        <div className="text-[11px] font-semibold uppercase tracking-wider text-primary">{WD_FULL[now.getDay()]}</div>
                        <div className="mt-0.5 flex items-baseline gap-2">
                            <span className="tnum text-[34px] font-bold leading-none tracking-tight">{now.getDate()}</span>
                            <span className="text-[15px] font-semibold text-muted-foreground">
                                {MO[now.getMonth()]} {now.getFullYear()}
                            </span>
                        </div>
                    </div>
                    {!viewingToday && (
                        <button
                            onClick={onJumpToday}
                            className="inline-flex items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-[11px] font-semibold text-primary transition-colors hover:bg-primary/15"
                        >
                            <CalendarDays className="h-3.5 w-3.5" /> Jump
                        </button>
                    )}
                </div>
                <div className="p-3">
                    {focus ? (
                        <button
                            onClick={() => onSelect(focus)}
                            className="flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-all hover:shadow-md"
                            style={{ borderColor: `var(--src-${focus.source}-ln)`, background: `var(--src-${focus.source}-bg)` }}
                        >
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-card" style={{ color: `var(--src-${focus.source})` }}>
                                <SourceIcon k={focus.source} className="h-5 w-5" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide" style={{ color: `var(--src-${focus.source})` }}>
                                    {happening ? (
                                        <>
                                            <span className="relative inline-flex h-1.5 w-1.5">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-70" />
                                                <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-current" />
                                            </span>
                                            Happening now
                                        </>
                                    ) : (
                                        <>
                                            <Clock className="h-3 w-3" /> Up next · {fmtTime(focus._start)}
                                        </>
                                    )}
                                </span>
                                <span className="mt-0.5 block truncate text-[13.5px] font-semibold text-foreground">{focus.title}</span>
                                {focus.room && <span className="block truncate text-[11.5px] text-muted-foreground">{focus.room}</span>}
                            </span>
                        </button>
                    ) : (
                        <div className="flex items-center gap-2.5 rounded-xl border border-dashed p-3 text-[12.5px] text-muted-foreground">
                            <CheckCircle2 className="h-4 w-4 text-status-success" /> No more entries scheduled today.
                        </div>
                    )}
                </div>
            </div>

            {/* Needs attention */}
            {attention > 0 && (
                <div className="overflow-hidden rounded-2xl border border-status-warning/30 bg-card shadow-sm">
                    <div className="flex items-center gap-2 border-b border-status-warning/20 bg-status-warning-bg/50 px-4 py-2.5">
                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                        <span className="text-[12px] font-semibold text-status-warning">Needs attention</span>
                        <span className="tnum ml-auto rounded-full bg-status-warning px-1.5 py-0.5 text-[10px] font-bold text-white">{attention}</span>
                    </div>
                    <div className="divide-y">
                        {overdue.slice(0, 3).map((e) => (
                            <button
                                key={`${e.id}-${e.start}`}
                                onClick={() => onSelect(e)}
                                className="flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-accent/40"
                            >
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-status-critical-bg text-status-critical">
                                    <SourceIcon k={e.source} className="h-3.5 w-3.5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12.5px] font-medium">{e.title}</span>
                                    <span className="block text-[11px] font-medium text-status-critical">Overdue · {Math.abs(dayDiff(e._start))}d</span>
                                </span>
                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                            </button>
                        ))}
                        {pending.length > 0 && (
                            <button onClick={onApprovals} className="flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors hover:bg-accent/40">
                                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                                    <ClipboardCheck className="h-3.5 w-3.5" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[12.5px] font-medium">{pending.length} awaiting approval</span>
                                    <span className="block text-[11px] text-muted-foreground">Review &amp; approve</span>
                                </span>
                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Today's schedule + up next */}
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div className="flex items-center justify-between border-b px-4 py-2.5">
                    <span className="text-[12px] font-semibold">Today&apos;s schedule</span>
                    <span className="tnum text-[11px] text-muted-foreground">
                        {todayItems.length} {todayItems.length === 1 ? 'entry' : 'entries'}
                    </span>
                </div>
                <div className="scroll-pretty min-h-0 flex-1 overflow-y-auto p-1.5">
                    {todayItems.length === 0 && (
                        <div className="flex flex-col items-center gap-2 px-3 py-8 text-center text-muted-foreground">
                            <CalendarDays className="h-8 w-8 opacity-40" />
                            <span className="text-[12.5px]">Nothing on today.</span>
                        </div>
                    )}
                    {todayItems.map((e, i) => {
                        const prev = todayItems[i - 1];
                        const eMin = e._start.getHours() * 60 + e._start.getMinutes();
                        const prevMin = prev ? prev._start.getHours() * 60 + prev._start.getMinutes() : 0;
                        const showNow = !e.allDay && eMin > nowMin && (!prev || prev.allDay || prevMin <= nowMin);
                        return (
                            <Fragment key={`${e.id}-${e.start}`}>
                                {showNow && (
                                    <div className="my-0.5 flex items-center gap-1.5 px-2">
                                        <span className="h-2 w-2 rounded-full bg-status-critical" />
                                        <span className="text-[10px] font-bold uppercase tracking-wide text-status-critical">Now · {fmtTime(now)}</span>
                                        <span className="h-px flex-1 bg-status-critical/40" />
                                    </div>
                                )}
                                <RailRow ev={e} onSelect={onSelect} />
                            </Fragment>
                        );
                    })}
                    {upcoming.length > 0 && (
                        <>
                            <div className="mt-1 px-3 pb-1 pt-3 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Up next</div>
                            {upcoming.map((e) => (
                                <RailRow key={`up-${e.id}-${e.start}`} ev={e} onSelect={onSelect} showDay />
                            ))}
                        </>
                    )}
                </div>
            </div>
        </aside>
    );
}
