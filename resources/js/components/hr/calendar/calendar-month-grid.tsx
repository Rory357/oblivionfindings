/* eslint-disable no-restricted-syntax -- A bespoke month-grid calendar (mirrors
 * the Rostering Calendar pane for cross-module consistency): the toolbar, day
 * cells and event chips are styled native <button>/<div> surfaces with status
 * accent bars, not shadcn primitives. Colours are token-based throughout. */
import { ChevronLeft, ChevronRight, Repeat } from 'lucide-react';
import { Plus } from 'lucide-react';

import {
    CAL_WEEKDAYS,
    monthMatrix,
    ymdKey,
} from '@/components/rostering/calendar-shared';
import { cn } from '@/lib/utils';
import { LAYER_META, type CalendarLayer, type CalendarLayerFeed } from '@/lib/calendar/layer-feed';

const CHIP_CAP = 3;

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/** Local YMD of an ISO/date string. */
function dayKeyOf(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value.slice(0, 10);
    return ymdKey(d);
}

function fmtTime(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit', hour12: false });
}

type ChipMeta = { accent: string; dashed: boolean; muted: boolean };
function chipMeta(e: CalendarLayerFeed): ChipMeta {
    if (e.extendedProps.gap) return { accent: 'var(--status-critical)', dashed: true, muted: false };
    if (e.extendedProps.pending) return { accent: 'var(--status-neutral)', dashed: true, muted: false };
    return { accent: `var(--${e.color})`, dashed: false, muted: false };
}

function secondaryText(e: CalendarLayerFeed): string {
    const p = e.extendedProps;
    const who = (p.person as string) || (p.site as string) || LAYER_META[e.layer].label;
    const time = e.allDay ? 'All day' : fmtTime(e.start);
    return `${time} · ${who}`;
}

export interface MonthGridHandlers {
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onTitleClick: () => void;
    onChipClick: (e: CalendarLayerFeed, x: number, y: number) => void;
    onChipCtx: (e: CalendarLayerFeed, x: number, y: number) => void;
    onQuickAdd: (dateKey: string, x: number, y: number) => void;
    onMoreClick: (dateKey: string, x: number, y: number) => void;
    onMoveEvent?: (eventId: number, dateKey: string) => void;
}

/**
 * Month-grid calendar for `/hr/calendar`, styled to match the Rostering Calendar
 * pane (day cells + status-accent chips + "+N more" overflow) so the two
 * surfaces feel identical. Renders the unified layer feed; holidays show as a
 * day-header badge, everything else as a chip.
 */
export function CalendarMonthGrid({
    events,
    cursor,
    canManage,
    loading,
    handlers,
}: {
    events: CalendarLayerFeed[];
    cursor: Date;
    canManage: boolean;
    loading: boolean;
    handlers: MonthGridHandlers;
}) {
    const weeks = monthMatrix(cursor.getFullYear(), cursor.getMonth());
    const todayKey = ymdKey(new Date());

    // Group the feed by local day, splitting holidays (day-header badges) out.
    const chipsByDay = new Map<string, CalendarLayerFeed[]>();
    const holidaysByDay = new Map<string, string[]>();
    for (const e of events) {
        if (!e.start) continue;
        const key = dayKeyOf(e.start);
        if (e.layer === 'holiday') {
            const arr = holidaysByDay.get(key) ?? [];
            arr.push(e.title);
            holidaysByDay.set(key, arr);
            continue;
        }
        const arr = chipsByDay.get(key) ?? [];
        arr.push(e);
        chipsByDay.set(key, arr);
    }
    // Stable order within a day: timed first by time, all-day after.
    for (const arr of chipsByDay.values()) {
        arr.sort((a, b) => Number(a.allDay) - Number(b.allDay) || a.start.localeCompare(b.start));
    }

    const title = `${MONTHS[cursor.getMonth()]} ${cursor.getFullYear()}`;

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            {/* ── toolbar ── */}
            <div className="flex flex-wrap items-center gap-2 border-b border-border px-3.5 py-2.5">
                <button
                    type="button"
                    onClick={handlers.onToday}
                    className="rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-semibold hover:bg-muted"
                >
                    Today
                </button>
                <div className="flex items-center">
                    <button
                        type="button"
                        aria-label="Previous month"
                        onClick={handlers.onPrev}
                        className="grid h-8 w-8 place-items-center rounded-lg hover:bg-muted"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        aria-label="Next month"
                        onClick={handlers.onNext}
                        className="grid h-8 w-8 place-items-center rounded-lg hover:bg-muted"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </button>
                </div>
                <button
                    type="button"
                    onClick={handlers.onTitleClick}
                    title="Jump to month / day"
                    className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-[17px] font-bold tracking-tight hover:bg-muted"
                >
                    {title}
                    <ChevronRight className="h-4 w-4 rotate-90 text-muted-foreground" />
                </button>
            </div>

            {/* ── grid ── */}
            <div className="p-3.5" aria-busy={loading}>
                <div className="mb-2 grid grid-cols-7">
                    {CAL_WEEKDAYS.map((d) => (
                        <div
                            key={d}
                            className={cn(
                                'px-2 pb-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground',
                                (d === 'Sat' || d === 'Sun') && 'text-muted-foreground/65',
                            )}
                        >
                            {d}
                        </div>
                    ))}
                </div>
                <div
                    className={cn(
                        'flex flex-col divide-y divide-border overflow-hidden rounded-[13px] border border-border transition-opacity',
                        loading && 'opacity-60',
                    )}
                >
                    {weeks.map((row, wi) => (
                        <div key={wi} className="grid grid-cols-7 divide-x divide-border">
                            {row.map((date) => {
                                const key = ymdKey(date);
                                return (
                                    <DayCell
                                        key={key}
                                        date={date}
                                        inMonth={date.getMonth() === cursor.getMonth()}
                                        isToday={key === todayKey}
                                        chips={chipsByDay.get(key) ?? []}
                                        holidays={holidaysByDay.get(key) ?? []}
                                        canManage={canManage}
                                        handlers={handlers}
                                    />
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

function DayCell({
    date,
    inMonth,
    isToday,
    chips,
    holidays,
    canManage,
    handlers,
}: {
    date: Date;
    inMonth: boolean;
    isToday: boolean;
    chips: CalendarLayerFeed[];
    holidays: string[];
    canManage: boolean;
    handlers: MonthGridHandlers;
}) {
    const key = ymdKey(date);
    const dow = date.getDay();
    const weekend = dow === 0 || dow === 6;
    const shown = chips.slice(0, CHIP_CAP);
    const more = chips.length - shown.length;

    return (
        <div
            className={cn(
                'group/cell relative flex min-h-[132px] flex-col gap-1 bg-card p-1.5 transition-colors',
                weekend && 'bg-secondary/30',
                !inMonth && 'bg-muted/40',
                isToday && 'bg-primary/[0.04]',
                holidays.length > 0 && 'bg-status-warning-bg/40',
            )}
            onDragOver={canManage && handlers.onMoveEvent ? (e) => e.preventDefault() : undefined}
            onDrop={
                canManage && handlers.onMoveEvent
                    ? (e) => {
                          e.preventDefault();
                          const id = Number(e.dataTransfer.getData('text/hr-event'));
                          if (id) handlers.onMoveEvent?.(id, key);
                      }
                    : undefined
            }
        >
            <div className="flex min-h-[22px] items-center justify-between px-0.5">
                <span
                    className={cn(
                        'inline-flex h-6 w-6 items-center justify-center rounded-lg text-[13px] font-semibold',
                        isToday && 'bg-primary font-bold text-primary-foreground',
                        !inMonth && !isToday && 'text-muted-foreground/50',
                    )}
                >
                    {date.getDate()}
                </span>
                <span className="flex items-center gap-1">
                    {holidays.length > 0 ? (
                        <span
                            className="max-w-[88px] truncate rounded-md bg-status-warning-bg px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide text-status-warning"
                            title={holidays.join(', ')}
                        >
                            {holidays[0]}
                        </span>
                    ) : null}
                    {canManage ? (
                        <button
                            type="button"
                            aria-label={`New event on ${date.toLocaleDateString()}`}
                            title="New event"
                            onClick={(e) => {
                                e.stopPropagation();
                                handlers.onQuickAdd(key, e.clientX, e.clientY);
                            }}
                            className="inline-flex h-[22px] w-[22px] items-center justify-center rounded-[7px] bg-accent text-primary opacity-0 transition-all hover:bg-primary hover:text-primary-foreground focus-visible:opacity-100 group-hover/cell:opacity-100"
                        >
                            <Plus className="h-[13px] w-[13px]" />
                        </button>
                    ) : null}
                </span>
            </div>

            <div className="flex flex-1 flex-col gap-1">
                {shown.map((e) => (
                    <EventChip
                        key={e.id}
                        e={e}
                        canManage={canManage}
                        onClick={handlers.onChipClick}
                        onCtx={handlers.onChipCtx}
                    />
                ))}
                {more > 0 ? (
                    <button
                        type="button"
                        onClick={(ev) => {
                            ev.stopPropagation();
                            handlers.onMoreClick(key, ev.clientX, ev.clientY);
                        }}
                        className="mt-0.5 self-start rounded-md px-1.5 py-0.5 text-[11px] font-semibold text-primary hover:bg-primary/10"
                    >
                        +{more} more
                    </button>
                ) : null}
            </div>
        </div>
    );
}

function EventChip({
    e,
    canManage,
    onClick,
    onCtx,
}: {
    e: CalendarLayerFeed;
    canManage: boolean;
    onClick: (e: CalendarLayerFeed, x: number, y: number) => void;
    onCtx: (e: CalendarLayerFeed, x: number, y: number) => void;
}) {
    const m = chipMeta(e);
    const draggable = canManage && e.layer === 'event' && !e.extendedProps.recurring;
    const layer = e.layer as CalendarLayer;

    return (
        <button
            type="button"
            draggable={draggable}
            onDragStart={
                draggable
                    ? (ev) => ev.dataTransfer.setData('text/hr-event', String(e.extendedProps.eventId))
                    : undefined
            }
            onClick={(ev) => {
                ev.stopPropagation();
                onClick(e, ev.clientX, ev.clientY);
            }}
            onContextMenu={(ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                onCtx(e, ev.clientX, ev.clientY);
            }}
            className="group/chip relative flex w-full cursor-pointer flex-col gap-0.5 rounded-lg border border-border bg-card px-1.5 py-1 pl-2 text-left shadow-xs transition-all hover:z-[2] hover:-translate-y-px hover:shadow-md"
            style={{
                borderLeftWidth: 3,
                borderLeftStyle: m.dashed ? 'dashed' : 'solid',
                borderLeftColor: m.accent,
                opacity: m.muted ? 0.62 : 1,
            }}
        >
            <span className="flex min-w-0 items-center gap-1.5">
                <span className="h-[7px] w-[7px] shrink-0 rounded-full" style={{ background: m.accent }} />
                <span className="min-w-0 flex-1 truncate text-xs font-semibold text-foreground">
                    {e.title}
                </span>
                {e.extendedProps.recurring ? (
                    <Repeat className="h-[11px] w-[11px] shrink-0 text-muted-foreground" />
                ) : null}
            </span>
            <span className="truncate pl-3 text-[10.5px] text-muted-foreground">
                {secondaryText(e)}
            </span>
            {e.extendedProps.gap || e.extendedProps.pending || e.extendedProps.redacted ? (
                <span className="flex flex-wrap gap-1 pl-3">
                    {e.extendedProps.gap ? (
                        <span className="rounded bg-status-critical-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-critical">
                            GAP
                        </span>
                    ) : null}
                    {e.extendedProps.pending ? (
                        <span className="rounded bg-status-warning-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-warning">
                            PENDING
                        </span>
                    ) : null}
                    {e.extendedProps.redacted ? (
                        <span className="rounded bg-muted px-1 text-[8.5px] font-extrabold tracking-wider text-muted-foreground">
                            PRIVATE
                        </span>
                    ) : null}
                </span>
            ) : null}
            <span className="sr-only">{LAYER_META[layer].label}</span>
        </button>
    );
}

export default CalendarMonthGrid;
