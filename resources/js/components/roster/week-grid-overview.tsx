import { useMemo } from 'react';

import StaffStatus from '@/components/staff-status';
import { cn } from '@/lib/utils';

import type { RosterShift } from './types';

const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function startOfMondayWeek(reference: Date) {
    const d = new Date(reference);
    d.setHours(0, 0, 0, 0);
    const day = d.getDay(); // Sun=0..Sat=6
    const offsetToMonday = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + offsetToMonday);
    return d;
}

function dayKey(d: Date) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function formatTime(iso: string | null) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${hh}:${mm}`;
}

export type WeekGridOverviewProps = {
    todayShifts: RosterShift[];
    upcomingShifts: RosterShift[];
    recentShifts: RosterShift[];
    onSelect: (shift: RosterShift) => void;
    today: string; // 'YYYY-MM-DD' from window.today
};

/**
 * Mon–Sun overview of the current week, visible on desktop (`lg+`) above the
 * detailed today/upcoming/recent lists. Each column shows that day's date,
 * highlights "today", and stacks every assigned shift as a tappable block.
 *
 * The component is pure presentational: it groups whatever shifts it is given
 * by their `starts_at` into the seven days of the current week and renders
 * blocks. The parent owns the detail sheet and click handling.
 */
export default function WeekGridOverview({
    todayShifts,
    upcomingShifts,
    recentShifts,
    onSelect,
    today,
}: WeekGridOverviewProps) {
    const todayDate = useMemo(() => new Date(`${today}T00:00:00`), [today]);
    const weekStart = useMemo(
        () => startOfMondayWeek(todayDate),
        [todayDate],
    );
    const days = useMemo(
        () =>
            Array.from({ length: 7 }, (_, i) => {
                const d = new Date(weekStart);
                d.setDate(weekStart.getDate() + i);
                return d;
            }),
        [weekStart],
    );

    const shiftsByDay = useMemo(() => {
        const map: Record<string, RosterShift[]> = {};
        for (const day of days) {
            map[dayKey(day)] = [];
        }
        const all = [
            ...recentShifts,
            ...todayShifts,
            ...upcomingShifts,
        ];
        for (const shift of all) {
            if (!shift.starts_at) continue;
            const start = new Date(shift.starts_at);
            if (Number.isNaN(start.getTime())) continue;
            const key = dayKey(start);
            if (key in map) {
                map[key].push(shift);
            }
        }
        for (const key of Object.keys(map)) {
            map[key].sort((a, b) => {
                const aStart = a.starts_at
                    ? new Date(a.starts_at).getTime()
                    : 0;
                const bStart = b.starts_at
                    ? new Date(b.starts_at).getTime()
                    : 0;
                return aStart - bStart;
            });
        }
        return map;
    }, [days, todayShifts, upcomingShifts, recentShifts]);

    const todayKey = dayKey(todayDate);

    return (
        <section
            aria-label="Week overview"
            className="hidden rounded-lg border bg-card p-3 lg:block"
        >
            <div className="grid grid-cols-7 gap-2">
                {days.map((day, index) => {
                    const key = dayKey(day);
                    const isToday = key === todayKey;
                    const isPast = day < todayDate && !isToday;
                    const shifts = shiftsByDay[key] ?? [];

                    return (
                        <div
                            key={key}
                            className={cn(
                                'flex min-h-32 flex-col gap-2 rounded-md border bg-background/60 p-2',
                                isToday &&
                                    'border-primary/60 bg-primary/5 ring-1 ring-primary/30',
                                isPast && 'opacity-70',
                            )}
                        >
                            <div className="flex items-baseline justify-between">
                                <span
                                    className={cn(
                                        'text-xs font-semibold uppercase tracking-wide',
                                        isToday
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {WEEKDAY_LABELS[index]}
                                </span>
                                <span className="text-sm font-semibold">
                                    {day.getDate()}
                                </span>
                            </div>

                            {shifts.length === 0 ? (
                                <p className="mt-auto text-[11px] text-muted-foreground">
                                    {isPast || isToday ? '—' : 'Free'}
                                </p>
                            ) : (
                                <div className="flex flex-col gap-1.5">
                                    {shifts.map((shift) => (
                                        <button
                                            key={shift.id}
                                            type="button"
                                            onClick={() => onSelect(shift)}
                                            className="group flex w-full flex-col items-start gap-1 rounded-md border bg-card p-1.5 text-left text-xs transition hover:border-primary/40 hover:bg-primary/5"
                                        >
                                            <div className="flex w-full items-center justify-between gap-1">
                                                <span className="font-semibold tabular-nums">
                                                    {formatTime(shift.starts_at)}
                                                </span>
                                                <StaffStatus
                                                    kind="shift"
                                                    state={shift.status_state}
                                                    size="sm"
                                                />
                                            </div>
                                            <span className="line-clamp-1 text-[11px] text-muted-foreground group-hover:text-foreground">
                                                {shift.client?.name ??
                                                    'Unassigned'}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
