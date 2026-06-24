import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';

export type LeaveCalendarFeed = {
    month: string;
    month_label: string;
    start: string;
    end: string;
    entries: Array<{
        id: number;
        user_id: number;
        user_name: string;
        site: string | null;
        leave_type: string;
        period: string;
        status: string;
        start: string;
        end: string;
    }>;
    people: Array<{ user_id: number; name: string; site: string | null }>;
    public_holidays: Record<
        string,
        { name: string; is_national: boolean; region: string | null }
    >;
};

const TYPE_COLOR: Record<string, string> = {
    annual: 'bg-status-info text-white',
    sick: 'bg-status-critical text-white',
    bereavement: 'bg-primary text-primary-foreground',
    parental: 'bg-status-warning text-white',
    alternative: 'bg-status-success text-white',
    public_holiday: 'bg-status-success text-white',
    unpaid: 'bg-muted-foreground text-white',
};

const typeColor = (t: string) => TYPE_COLOR[t] ?? 'bg-muted-foreground text-white';
const typeLabel = (t: string) =>
    t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const pad = (n: number) => String(n).padStart(2, '0');

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
}

export function LeaveCalendarPane({
    calendar,
    currentMonth,
}: {
    calendar: LeaveCalendarFeed | null | undefined;
    /** Server "now" month (Y-m) for the Today button; falls back to feed month. */
    currentMonth?: string;
}) {
    if (!calendar) {
        return (
            <Card>
                <CardContent className="py-12 text-center text-sm text-muted-foreground">
                    Loading calendar…
                </CardContent>
            </Card>
        );
    }

    const go = (month: string) =>
        router.get(
            '/hr/leave',
            { tab: 'calendar', month },
            { preserveState: true, preserveScroll: true },
        );

    const [year, monthNum] = calendar.month.split('-').map(Number);
    const daysInMonth = new Date(year, monthNum, 0).getDate();
    const firstWeekday = (new Date(year, monthNum - 1, 1).getDay() + 6) % 7; // Mon=0

    const cells: Array<{ day: number; date: string } | null> = [];
    for (let i = 0; i < firstWeekday; i++) cells.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push({ day: d, date: `${year}-${pad(monthNum)}-${pad(d)}` });
    }
    while (cells.length % 7 !== 0) cells.push(null);

    const entriesForDay = (date: string) =>
        calendar.entries.filter((e) => e.start <= date && e.end >= date);

    const typesPresent = Array.from(
        new Set(calendar.entries.map((e) => e.leave_type)),
    );

    const todayMonth = currentMonth ?? calendar.month;

    return (
        <div className="space-y-4">
            {/* Toolbar */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-1.5">
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => go(shiftMonth(calendar.month, -1))}
                        aria-label="Previous month"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <div className="min-w-[160px] text-center text-base font-bold">
                        {calendar.month_label}
                    </div>
                    <Button
                        variant="outline"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => go(shiftMonth(calendar.month, 1))}
                        aria-label="Next month"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-2"
                        onClick={() => go(todayMonth)}
                    >
                        Today
                    </Button>
                </div>
                {/* Legend */}
                <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    {typesPresent.map((t) => (
                        <span key={t} className="inline-flex items-center gap-1.5">
                            <span
                                className={`h-2.5 w-2.5 rounded-sm ${typeColor(t).split(' ')[0]}`}
                            />
                            {typeLabel(t)}
                        </span>
                    ))}
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-2.5 w-2.5 rounded-sm bg-status-success/30" />
                        Public holiday
                    </span>
                </div>
            </div>

            <Card>
                <CardContent className="p-3">
                    {/* Weekday header */}
                    <div className="grid grid-cols-7 gap-1.5 pb-1.5">
                        {WEEKDAYS.map((w) => (
                            <div
                                key={w}
                                className="px-1 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                            >
                                {w}
                            </div>
                        ))}
                    </div>
                    {/* Day grid */}
                    <div className="grid grid-cols-7 gap-1.5">
                        {cells.map((cell, idx) => {
                            if (!cell)
                                return (
                                    <div
                                        key={`pad-${idx}`}
                                        className="min-h-[92px] rounded-lg bg-muted/30"
                                    />
                                );
                            const holiday = calendar.public_holidays[cell.date];
                            const dayEntries = entriesForDay(cell.date);
                            return (
                                <div
                                    key={cell.date}
                                    className={`min-h-[92px] rounded-lg border p-1.5 ${
                                        holiday
                                            ? 'border-status-success/40 bg-status-success/10'
                                            : 'border-border bg-card'
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold">
                                            {cell.day}
                                        </span>
                                        {holiday && (
                                            <span
                                                className="truncate text-[9px] font-medium text-status-success"
                                                title={holiday.name}
                                            >
                                                {holiday.name}
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-1 space-y-1">
                                        {dayEntries.slice(0, 3).map((e) => (
                                            <div
                                                key={`${e.id}-${cell.date}`}
                                                className={`truncate rounded px-1.5 py-0.5 text-[10px] font-medium ${typeColor(e.leave_type)} ${
                                                    e.status === 'pending'
                                                        ? 'opacity-60'
                                                        : ''
                                                }`}
                                                title={`${e.user_name} · ${typeLabel(e.leave_type)}${e.status === 'pending' ? ' (pending)' : ''}`}
                                            >
                                                {e.user_name}
                                            </div>
                                        ))}
                                        {dayEntries.length > 3 && (
                                            <div className="px-1 text-[10px] text-muted-foreground">
                                                +{dayEntries.length - 3} more
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>

            {calendar.entries.length === 0 && (
                <Card>
                    <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
                        <CalendarDays className="h-7 w-7 text-muted-foreground" />
                        <div className="text-sm font-semibold">
                            No leave booked this month
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Approved and pending leave appears here as soon as it's
                            scheduled.
                        </div>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

export default LeaveCalendarPane;
