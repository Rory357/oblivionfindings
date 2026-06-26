/* eslint-disable no-restricted-syntax -- Inline month calendar: the day cells are
 * styled native buttons (a grid of 42 controls; <Button> would add chrome we don't
 * want) and the in-range band / endpoint pills use color-mix() on the --primary
 * token via inline style, which is the design-token system, not a raw colour. */
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

/** Monday-first weekday header labels. */
const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as const;
const MONTHS_FULL = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
] as const;
const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

/** Local-safe `YYYY-MM-DD` for a y/m(0-based)/d triple — never touches UTC. */
function toIso(y: number, m: number, d: number): string {
    return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/** Parse an ISO date string into a local Date (midnight, no TZ shift). */
function parseIso(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** "Wed 8" short label for the duration summary / range line. */
export function shortDay(iso: string): string {
    const d = parseIso(iso);
    return `${WEEKDAY_SHORT[d.getDay()]} ${d.getDate()}`;
}

/**
 * Controlled inline month calendar with single-range selection (handover §"new
 * component"). Monday-first; clicking days drives `onChange(start, end)` with ISO
 * strings. Public holidays in `holidays` get a warm highlight (decorative — the
 * server `/preview` engine is the source of truth for which days are paid).
 */
export function LeaveCalendarRange({
    start,
    end,
    onChange,
    holidays = {},
    month,
}: {
    start: string | null;
    end: string | null;
    onChange: (start: string | null, end: string | null) => void;
    holidays?: Record<string, string>;
    month?: Date;
}) {
    // Local calendar view state — seeds from the current selection / `month` prop.
    const [viewMonth, setViewMonth] = useState<Date>(() => {
        if (start) {
            const d = parseIso(start);
            return new Date(d.getFullYear(), d.getMonth(), 1);
        }
        const base = month ?? new Date();
        return new Date(base.getFullYear(), base.getMonth(), 1);
    });

    const viewY = viewMonth.getFullYear();
    const viewM = viewMonth.getMonth();

    const prevMonth = () => setViewMonth(new Date(viewY, viewM - 1, 1));
    const nextMonth = () => setViewMonth(new Date(viewY, viewM + 1, 1));

    const pickDay = (iso: string) => {
        if (!start || (start && end)) {
            onChange(iso, null); // begin a new range
        } else if (iso < start) {
            onChange(iso, start); // clicked before start → swap
        } else {
            onChange(start, iso);
        }
    };

    // 6×7 grid of day numbers, leading/trailing blanks as null.
    const cells = useMemo(() => {
        const first = new Date(viewY, viewM, 1);
        const offset = (first.getDay() + 6) % 7; // Monday-first
        const daysInMonth = new Date(viewY, viewM + 1, 0).getDate();
        const out: (number | null)[] = [];
        for (let i = 0; i < offset; i++) out.push(null);
        for (let d = 1; d <= daysInMonth; d++) out.push(d);
        while (out.length % 7 !== 0) out.push(null);
        return out;
    }, [viewY, viewM]);

    return (
        <div>
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[13px] font-semibold">
                    Dates <span className="text-status-critical">*</span>
                </span>
                <div className="flex items-center gap-0.5">
                    <button
                        type="button"
                        onClick={prevMonth}
                        aria-label="Previous month"
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </button>
                    <span className="min-w-[104px] text-center text-[13px] font-bold">
                        {MONTHS_FULL[viewM]} {viewY}
                    </span>
                    <button
                        type="button"
                        onClick={nextMonth}
                        aria-label="Next month"
                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </button>
                </div>
            </div>

            <div className="rounded-[13px] border border-border bg-card p-2.5">
                <div className="mb-0.5 grid grid-cols-7">
                    {WEEKDAYS.map((wd) => (
                        <div
                            key={wd}
                            className="py-1 text-center text-[10.5px] font-bold tracking-wide text-muted-foreground"
                        >
                            {wd}
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-7">
                    {cells.map((d, i) => {
                        if (d == null) {
                            return <div key={`b${i}`} className="h-10" />;
                        }
                        const iso = toIso(viewY, viewM, d);
                        const dow = new Date(viewY, viewM, d).getDay();
                        const weekend = dow === 0 || dow === 6;
                        const isHoliday = !!holidays[iso];
                        const inRange =
                            !!start && !!end && iso >= start && iso <= end;
                        const isStart = iso === start;
                        const isEnd = iso === end;
                        const endpoint = isStart || isEnd;

                        // In-range band — square cells form a continuous strip, only
                        // the start/end corners round off. Endpoints carry their own
                        // pill (inner span), so the band fill skips them.
                        const bandStyle: CSSProperties | undefined = inRange
                            ? {
                                  background:
                                      endpoint
                                          ? undefined
                                          : 'color-mix(in oklch, var(--primary) 11%, transparent)',
                                  borderRadius:
                                      isStart && isEnd
                                          ? '11px'
                                          : isStart
                                            ? '11px 0 0 11px'
                                            : isEnd
                                              ? '0 11px 11px 0'
                                              : '0',
                              }
                            : undefined;

                        return (
                            <button
                                key={iso}
                                type="button"
                                onClick={() => pickDay(iso)}
                                aria-label={`${WEEKDAY_SHORT[dow]} ${d} ${MONTHS_FULL[viewM]} ${viewY}${isHoliday ? ` — ${holidays[iso]} (public holiday)` : ''}`}
                                aria-pressed={endpoint}
                                style={bandStyle}
                                className="relative grid h-10 place-items-center focus-visible:rounded-[11px] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <span
                                    className={cn(
                                        'grid h-[34px] w-[34px] place-items-center rounded-[10px] text-[13px] transition-colors',
                                        endpoint
                                            ? 'bg-primary font-bold text-primary-foreground shadow-[0_3px_8px_-2px_var(--primary)]'
                                            : isHoliday
                                              ? 'font-bold text-status-warning'
                                              : weekend
                                                ? 'font-medium text-muted-foreground'
                                                : 'font-medium text-foreground',
                                    )}
                                >
                                    {d}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export default LeaveCalendarRange;
