import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface CalendarEvent {
    id: string;
    kind: 'meeting' | 'compliance' | 'policy' | string;
    date: string;
    title: string;
    href: string;
}

interface GovernanceCalendarProps {
    events: CalendarEvent[];
}

const KIND_DOT: Record<string, string> = {
    meeting: 'bg-primary',
    compliance: 'bg-status-warning',
    policy: 'bg-status-info',
};

const KIND_LEGEND: Array<{ kind: string; label: string }> = [
    { kind: 'meeting', label: 'Board Meeting' },
    { kind: 'compliance', label: 'Compliance Due' },
    { kind: 'policy', label: 'Policy Review' },
];

function monthDays(year: number, month: number): Array<{ day: number; date: string; inMonth: boolean }> {
    const first = new Date(year, month, 1);
    const startWeekday = (first.getDay() + 6) % 7; // make Monday = 0
    const lastDay = new Date(year, month + 1, 0).getDate();
    const cells: Array<{ day: number; date: string; inMonth: boolean }> = [];

    // Prefix days from previous month
    const prevLast = new Date(year, month, 0).getDate();
    for (let i = startWeekday - 1; i >= 0; i--) {
        const d = prevLast - i;
        const prevMonth = month === 0 ? 11 : month - 1;
        const prevYear = month === 0 ? year - 1 : year;
        cells.push({ day: d, date: dateString(prevYear, prevMonth, d), inMonth: false });
    }
    // Current month
    for (let d = 1; d <= lastDay; d++) {
        cells.push({ day: d, date: dateString(year, month, d), inMonth: true });
    }
    // Trailing days
    while (cells.length % 7 !== 0) {
        const d = cells.length - (startWeekday + lastDay) + 1;
        const nextMonth = month === 11 ? 0 : month + 1;
        const nextYear = month === 11 ? year + 1 : year;
        cells.push({ day: d, date: dateString(nextYear, nextMonth, d), inMonth: false });
    }
    return cells;
}

function dateString(year: number, month: number, day: number): string {
    const m = String(month + 1).padStart(2, '0');
    const d = String(day).padStart(2, '0');
    return `${year}-${m}-${d}`;
}

function monthLabel(year: number, month: number): string {
    return new Date(year, month).toLocaleString('default', { month: 'long', year: 'numeric' });
}

/**
 * Compact calendar for the right rail. Shows the current month with coloured
 * dots for meetings, compliance due dates, and policy reviews, plus a list of
 * the next 5 upcoming events.
 */
export function GovernanceCalendar({ events }: GovernanceCalendarProps) {
    const today = new Date();
    const [cursor, setCursor] = useState(() => ({
        year: today.getFullYear(),
        month: today.getMonth(),
    }));

    const cells = useMemo(() => monthDays(cursor.year, cursor.month), [cursor]);

    const eventsByDate = useMemo(() => {
        const map = new Map<string, CalendarEvent[]>();
        for (const e of events) {
            const list = map.get(e.date) ?? [];
            list.push(e);
            map.set(e.date, list);
        }
        return map;
    }, [events]);

    const upcoming = useMemo(() => {
        const todayStr = dateString(today.getFullYear(), today.getMonth(), today.getDate());
        return events
            .filter((e) => e.date >= todayStr)
            .sort((a, b) => a.date.localeCompare(b.date))
            .slice(0, 5);
    }, [events, today]);

    const todayStr = dateString(today.getFullYear(), today.getMonth(), today.getDate());

    const goToPrev = () => {
        setCursor((c) => ({
            year: c.month === 0 ? c.year - 1 : c.year,
            month: c.month === 0 ? 11 : c.month - 1,
        }));
    };
    const goToNext = () => {
        setCursor((c) => ({
            year: c.month === 11 ? c.year + 1 : c.year,
            month: c.month === 11 ? 0 : c.month + 1,
        }));
    };

    return (
        <Card data-dusk="cockpit-calendar">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">Governance Calendar</CardTitle>
                        <CardDescription>Meetings, compliance due dates and policy reviews.</CardDescription>
                    </div>
                    <Link
                        href="/governance/meetings/calendar"
                        className="text-xs font-medium text-primary hover:underline"
                    >
                        Full calendar
                    </Link>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                    <Button variant="ghost" size="icon" aria-label="Previous month" onClick={goToPrev}>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <p className="text-sm font-medium text-foreground">
                        {monthLabel(cursor.year, cursor.month)}
                    </p>
                    <Button variant="ghost" size="icon" aria-label="Next month" onClick={goToNext}>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>

                <div className="grid grid-cols-7 gap-1 text-center text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                    {['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].map((d) => (
                        <div key={d}>{d}</div>
                    ))}
                </div>

                <div className="grid grid-cols-7 gap-1">
                    {cells.map((cell) => {
                        const dayEvents = eventsByDate.get(cell.date) ?? [];
                        const isToday = cell.date === todayStr;

                        return (
                            <div
                                key={cell.date}
                                className={cn(
                                    'flex aspect-square flex-col items-center justify-center rounded-md text-xs transition-colors',
                                    cell.inMonth ? 'text-foreground' : 'text-muted-foreground/50',
                                    isToday && 'bg-primary text-primary-foreground font-semibold',
                                    !isToday && cell.inMonth && dayEvents.length > 0 && 'bg-muted',
                                )}
                                title={dayEvents.map((e) => e.title).join('\n')}
                            >
                                <span>{cell.day}</span>
                                {dayEvents.length > 0 && (
                                    <div className="mt-0.5 flex items-center gap-0.5">
                                        {dayEvents.slice(0, 3).map((e) => (
                                            <span
                                                key={e.id}
                                                className={cn('h-1 w-1 rounded-full', KIND_DOT[e.kind] ?? 'bg-foreground/60')}
                                                aria-hidden="true"
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-3 text-[10px] uppercase tracking-wide text-muted-foreground">
                    {KIND_LEGEND.map((entry) => (
                        <span key={entry.kind} className="inline-flex items-center gap-1">
                            <span className={cn('h-1.5 w-1.5 rounded-full', KIND_DOT[entry.kind])} />
                            {entry.label}
                        </span>
                    ))}
                </div>

                <div className="space-y-1.5 border-t border-border pt-3">
                    <p className="text-xs font-medium text-muted-foreground">Upcoming</p>
                    {upcoming.length === 0 ? (
                        <p className="text-xs italic text-muted-foreground">No events scheduled.</p>
                    ) : (
                        upcoming.map((event) => (
                            <Link
                                key={event.id}
                                href={event.href}
                                className="flex items-center gap-2 rounded-md p-1.5 text-xs transition hover:bg-muted"
                            >
                                <span
                                    className={cn('h-2 w-2 shrink-0 rounded-full', KIND_DOT[event.kind] ?? 'bg-foreground/60')}
                                    aria-hidden="true"
                                />
                                <span className="min-w-0 flex-1 truncate">{event.title}</span>
                                <span className="shrink-0 text-muted-foreground">{event.date.slice(5)}</span>
                            </Link>
                        ))
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export default GovernanceCalendar;
