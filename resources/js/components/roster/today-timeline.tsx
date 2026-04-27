import { CalendarDays, Clock, MapPin } from 'lucide-react';

import StaffStatus from '@/components/staff-status';
import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';

import type { RosterShift } from './types';

function shiftRange(shift: RosterShift): string {
    return `${formatTime(shift.starts_at)} - ${formatTime(shift.ends_at)}`;
}

export default function TodayTimeline({
    shifts,
    onSelect,
    compact = false,
}: {
    shifts: RosterShift[];
    onSelect: (shift: RosterShift) => void;
    compact?: boolean;
}) {
    if (shifts.length === 0) {
        return (
            <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-6 text-center">
                <CalendarDays className="mx-auto h-7 w-7 text-muted-foreground" />
                <p className="mt-2 text-sm font-medium">No shift today</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Your next shifts are listed below.
                </p>
            </div>
        );
    }

    return (
        <div
            className={cn(
                'grid gap-3',
                !compact && 'lg:grid-cols-[repeat(auto-fit,minmax(220px,1fr))]',
            )}
        >
            {shifts.map((shift) => (
                <Button
                    key={shift.id}
                    type="button"
                    variant="outline"
                    onClick={() => onSelect(shift)}
                    className="h-auto min-h-24 justify-start rounded-lg p-0 text-left"
                    id={`shift-${shift.id}`}
                >
                    <span className="flex w-full gap-3 p-3">
                        <span className="mt-1 h-12 w-1 rounded-full bg-primary" />
                        <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-semibold">
                                    {shiftRange(shift)}
                                </span>
                                <StaffStatus
                                    kind="shift"
                                    state={shift.status_state}
                                    size="sm"
                                />
                            </span>
                            <span className="mt-1 block truncate text-sm text-foreground">
                                {shift.client?.name ?? 'Unassigned person'}
                            </span>
                            <span className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                {shift.location ? (
                                    <span className="inline-flex items-center gap-1">
                                        <MapPin className="h-3 w-3" />
                                        {shift.location}
                                    </span>
                                ) : null}
                                <span className="inline-flex items-center gap-1">
                                    <Clock className="h-3 w-3" />
                                    Tasks{' '}
                                    {
                                        shift.tasks.filter(
                                            (task) => task.is_completed,
                                        ).length
                                    }
                                    /{shift.tasks.length}
                                </span>
                            </span>
                        </span>
                    </span>
                </Button>
            ))}
        </div>
    );
}
