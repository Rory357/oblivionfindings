/* eslint-disable no-restricted-syntax -- A 12-mini-month year grid built from raw
 * <button>/<div> cells (a bespoke date-picker surface, not a shadcn primitive);
 * colours are token-based throughout. */
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const WEEKDAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

function iso(y: number, m: number, d: number): string {
    return `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

/** Monday-first day cells for a month, with leading blanks. */
function monthCells(year: number, month: number): (number | null)[] {
    const first = new Date(year, month, 1).getDay(); // 0=Sun
    const lead = (first + 6) % 7; // shift so Monday=0
    const days = new Date(year, month + 1, 0).getDate();
    const cells: (number | null)[] = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= days; d++) cells.push(d);
    return cells;
}

/**
 * Year overview — a 12-mini-month grid (Monday-first, en-NZ). Click a month
 * header to jump the calendar to that month; click a day to open it in Day view.
 * Today and the active date are highlighted.
 */
export function CalendarYearPicker({
    open,
    initialYear,
    activeDate,
    onClose,
    onPickMonth,
    onPickDay,
}: {
    open: boolean;
    initialYear: number;
    activeDate: string | null;
    onClose: () => void;
    onPickMonth: (date: string) => void;
    onPickDay: (date: string) => void;
}) {
    const [year, setYear] = useState(initialYear);
    const todayIso = new Date().toISOString().slice(0, 10);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center justify-center gap-4">
                        <button
                            type="button"
                            aria-label="Previous year"
                            onClick={() => setYear((y) => y - 1)}
                            className="grid h-8 w-8 place-items-center rounded-lg border border-border hover:bg-muted"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <span className="text-xl font-bold tabular-nums">
                            {year}
                        </span>
                        <button
                            type="button"
                            aria-label="Next year"
                            onClick={() => setYear((y) => y + 1)}
                            className="grid h-8 w-8 place-items-center rounded-lg border border-border hover:bg-muted"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Pick a month or day to jump to.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {MONTHS.map((label, m) => (
                        <div key={label}>
                            <button
                                type="button"
                                onClick={() => onPickMonth(iso(year, m, 1))}
                                className="mb-1 w-full rounded-md px-1 py-0.5 text-left text-[12px] font-bold hover:bg-accent"
                            >
                                {label}
                            </button>
                            <div className="grid grid-cols-7 gap-0.5 text-center">
                                {WEEKDAYS.map((w, i) => (
                                    <span
                                        key={i}
                                        className="text-[9px] font-semibold text-muted-foreground"
                                    >
                                        {w}
                                    </span>
                                ))}
                                {monthCells(year, m).map((d, i) => {
                                    if (d === null) return <span key={i} />;
                                    const cellIso = iso(year, m, d);
                                    const isToday = cellIso === todayIso;
                                    const isActive = cellIso === activeDate;
                                    return (
                                        <button
                                            key={i}
                                            type="button"
                                            onClick={() => onPickDay(cellIso)}
                                            className={
                                                'grid h-5 w-5 place-items-center rounded text-[10px] tabular-nums transition-colors ' +
                                                (isToday
                                                    ? 'bg-primary font-bold text-primary-foreground'
                                                    : isActive
                                                      ? 'bg-accent font-semibold text-foreground'
                                                      : 'hover:bg-muted')
                                            }
                                        >
                                            {d}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default CalendarYearPicker;
