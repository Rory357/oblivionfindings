import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { formatDate, formatTime } from '@/lib/datetime';

import type { RosterShift } from './types';

function groupByDay(shifts: RosterShift[]): Array<[string, RosterShift[]]> {
    const grouped = shifts.reduce<Record<string, RosterShift[]>>(
        (days, shift) => {
            const key = shift.day_key ?? 'unknown';
            days[key] = days[key] ?? [];
            days[key].push(shift);
            return days;
        },
        {},
    );

    return Object.entries(grouped);
}

export default function UpcomingList({
    shifts,
    onSelect,
}: {
    shifts: RosterShift[];
    onSelect: (shift: RosterShift) => void;
}) {
    if (shifts.length === 0) {
        return (
            <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-5 text-sm text-muted-foreground">
                No upcoming shifts in the next 14 days.
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {groupByDay(shifts).map(([day, dayShifts]) => (
                <section key={day} className="space-y-2">
                    <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {formatDate(day)}
                    </h3>
                    <div className="divide-y rounded-lg border bg-card">
                        {dayShifts.map((shift) => (
                            <Button
                                key={shift.id}
                                type="button"
                                variant="ghost"
                                onClick={() => onSelect(shift)}
                                className="h-auto min-h-16 w-full justify-start rounded-none px-3 py-3 text-left first:rounded-t-lg last:rounded-b-lg"
                            >
                                <span className="flex w-full items-center justify-between gap-3">
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">
                                            {formatTime(shift.starts_at)} -{' '}
                                            {formatTime(shift.ends_at)}
                                        </span>
                                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                            {shift.client?.name ??
                                                'Unassigned person'}
                                            {shift.location
                                                ? ` - ${shift.location}`
                                                : ''}
                                        </span>
                                    </span>
                                    <StaffStatus
                                        kind="shift"
                                        state={shift.status_state}
                                        size="sm"
                                        className="shrink-0"
                                    />
                                </span>
                            </Button>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
