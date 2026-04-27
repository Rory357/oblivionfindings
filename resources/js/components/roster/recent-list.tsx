import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { formatDate, formatTime } from '@/lib/datetime';

import type { RosterShift } from './types';

export default function RecentList({
    shifts,
    onSelect,
}: {
    shifts: RosterShift[];
    onSelect: (shift: RosterShift) => void;
}) {
    if (shifts.length === 0) {
        return (
            <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-5 text-sm text-muted-foreground">
                No completed shifts in the last 7 days.
            </div>
        );
    }

    return (
        <details className="group rounded-lg border bg-card" open={false}>
            <summary className="cursor-pointer list-none px-4 py-3 text-sm font-semibold">
                Recently completed
                <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                    {shifts.length}
                </span>
            </summary>
            <div className="divide-y border-t">
                {shifts.map((shift) => (
                    <Button
                        key={shift.id}
                        type="button"
                        variant="ghost"
                        onClick={() => onSelect(shift)}
                        className="h-auto min-h-16 w-full justify-start rounded-none px-4 py-3 text-left"
                    >
                        <span className="flex w-full items-center justify-between gap-3">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    {formatDate(shift.starts_at)} -{' '}
                                    {formatTime(shift.starts_at)}
                                </span>
                                <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                    {shift.client?.name ?? 'Unassigned person'}
                                    {shift.timesheet
                                        ? ` - timesheet ${shift.timesheet.status}`
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
        </details>
    );
}
