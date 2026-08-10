import { Link } from '@inertiajs/react';
import { ArrowRight, ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

interface DatePopoverProps {
    /** Date to anchor the calendar to (controls the month shown + today highlight). */
    anchor: Date;
    /** ISO dates ('YYYY-MM-DD') that should show a brand dot under the day number. */
    shiftDates?: string[];
    /** Selected date — if provided, gets a brand ring. */
    selected?: Date | null;
    /** Called when a day cell is clicked. */
    onSelect?: (date: Date) => void;
    /** Called when the user dismisses (outside click or Escape). */
    onClose: () => void;
    /** Link target for the footer "Open full calendar" CTA. */
    calendarHref?: string;
}

const WEEK_HEADERS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

/**
 * 7×6 month grid anchored below the StaffHeader title. Today is highlighted
 * in --primary; days the worker has shifts on get a small --primary dot under
 * the number. Closes on outside click or Escape.
 */
export function DatePopover({
    anchor,
    shiftDates = [],
    selected,
    onSelect,
    onClose,
    calendarHref = '/my-calendar',
}: DatePopoverProps) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [monthOffset, setMonthOffset] = useState(0);

    useEffect(() => {
        const onMouseDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('mousedown', onMouseDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose]);

    const month = useMemo(() => {
        const d = new Date(
            anchor.getFullYear(),
            anchor.getMonth() + monthOffset,
            1,
        );
        return d;
    }, [anchor, monthOffset]);

    const cells = useMemo(() => buildMonthCells(month), [month]);
    const todayKey = isoKey(new Date());
    const selectedKey = selected ? isoKey(selected) : null;
    const shiftKeys = useMemo(() => new Set(shiftDates), [shiftDates]);
    const monthLabel = month.toLocaleString(undefined, {
        month: 'long',
        year: 'numeric',
    });

    return (
        <div
            ref={ref}
            data-test="my-day-date-popover"
            className={cn(
                'absolute top-[calc(100%+8px)] left-0 z-50 w-[296px]',
                'rounded-xl border border-border bg-popover p-3.5 text-popover-foreground',
                'shadow-[0_18px_50px_-12px_rgba(0,0,0,0.30),0_4px_12px_-4px_rgba(0,0,0,0.18)]',
                'animate-in duration-150 fade-in-0 slide-in-from-top-2',
            )}
        >
            {/* arrow */}
            <div
                aria-hidden="true"
                className="absolute -top-[7px] left-5 h-3 w-3 rotate-45 border-t border-l border-border bg-popover"
            />

            <div className="mb-2.5 flex items-center gap-1.5">
                {/* eslint-disable-next-line no-restricted-syntax -- 24px calendar nav chevron, not a shadcn Button. */}
                <button
                    type="button"
                    onClick={() => setMonthOffset((n) => n - 1)}
                    className="inline-flex h-6 w-6 items-center justify-center rounded-md border border-border bg-background text-muted-foreground hover:bg-muted"
                    aria-label="Previous month"
                >
                    <ChevronLeft className="h-3 w-3" />
                </button>
                <div className="flex-1 text-center text-[13.5px] font-semibold">
                    {monthLabel}
                </div>
                {/* eslint-disable-next-line no-restricted-syntax -- 24px calendar nav chevron, not a shadcn Button. */}
                <button
                    type="button"
                    onClick={() => setMonthOffset((n) => n + 1)}
                    className="inline-flex h-6 w-6 items-center justify-center rounded-md border border-border bg-background text-muted-foreground hover:bg-muted"
                    aria-label="Next month"
                >
                    <ChevronRight className="h-3 w-3" />
                </button>
            </div>

            <div className="mb-1 grid grid-cols-7 gap-0.5">
                {WEEK_HEADERS.map((d, i) => (
                    <div
                        key={i}
                        className="text-center text-[10.5px] font-semibold tracking-wider text-text-faint uppercase"
                    >
                        {d}
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-7 gap-0.5">
                {cells.map((cell, i) => {
                    const key = isoKey(cell.date);
                    const isToday = key === todayKey;
                    const isSelected = key === selectedKey;
                    const hasShift = shiftKeys.has(key);
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- calendar day cell with absolutely-positioned shift dot; not a shadcn Button.
                        <button
                            key={i}
                            type="button"
                            disabled={cell.muted}
                            onClick={() => !cell.muted && onSelect?.(cell.date)}
                            className={cn(
                                'relative h-[34px] rounded-md text-[12.5px] tabular-nums transition-colors',
                                cell.muted && 'cursor-default text-text-faint',
                                !cell.muted &&
                                    !isToday &&
                                    'text-foreground hover:bg-muted',
                                isToday &&
                                    'bg-primary font-semibold text-primary-foreground',
                                isSelected &&
                                    !isToday &&
                                    'ring-2 ring-primary/40',
                            )}
                        >
                            {cell.date.getDate()}
                            {hasShift ? (
                                <span
                                    className={cn(
                                        'absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full',
                                        isToday
                                            ? 'bg-primary-foreground'
                                            : 'bg-primary',
                                    )}
                                />
                            ) : null}
                        </button>
                    );
                })}
            </div>

            <div className="mt-2.5 flex items-center gap-2 border-t border-border pt-2.5">
                <span className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
                    <span className="h-1 w-1 rounded-full bg-primary" />
                    Shift scheduled
                </span>
                <Link
                    href={calendarHref}
                    className="ml-auto inline-flex items-center gap-1 text-[12px] font-medium text-primary"
                >
                    Open full calendar
                    <ArrowRight className="h-2.5 w-2.5" />
                </Link>
            </div>
        </div>
    );
}

/** Build 42 cells (6 weeks) starting on the Sunday before the 1st of `month`. */
function buildMonthCells(month: Date): { date: Date; muted: boolean }[] {
    const year = month.getFullYear();
    const monthIdx = month.getMonth();
    const firstDay = new Date(year, monthIdx, 1);
    const offset = firstDay.getDay(); // 0=Sunday
    const start = new Date(year, monthIdx, 1 - offset);
    const cells: { date: Date; muted: boolean }[] = [];
    for (let i = 0; i < 42; i++) {
        const d = new Date(
            start.getFullYear(),
            start.getMonth(),
            start.getDate() + i,
        );
        cells.push({ date: d, muted: d.getMonth() !== monthIdx });
    }
    return cells;
}

function isoKey(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default DatePopover;
