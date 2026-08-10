import { Link } from '@inertiajs/react';
import { Clock, MapPin, Users } from 'lucide-react';

import { cn } from '@/lib/utils';

import { dayLabel, shiftTypeLabel, timeLabel } from './build-queue';
import type { QueueShift } from './types';

const STATUS_PILL: Record<string, string> = {
    open: 'bg-status-warning-bg text-status-warning',
    pending: 'bg-status-info-bg text-status-info',
};

function statusPillClass(status: string) {
    return STATUS_PILL[status] ?? 'bg-muted text-muted-foreground';
}

function windowLabel(shift: QueueShift) {
    if (!shift.startsAt) return 'Time not set';
    const start = timeLabel(shift.startsAt);
    const end = shift.endsAt ? timeLabel(shift.endsAt) : '';
    return `${dayLabel(shift.startsAt)} · ${start}${end ? `–${end}` : ''}`;
}

/** Compact shift card used inside the detail panel (one card, or two for overlaps). */
export function ShiftSummaryCard({ shift }: { shift: QueueShift }) {
    const contextLine = [shift.serviceContext, shiftTypeLabel(shift.shiftType)]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="rounded-xl border p-3">
            <div className="flex items-start justify-between gap-2">
                <span className="truncate text-sm font-semibold">
                    {shift.client ?? 'Unassigned client'}
                </span>
                <span
                    className={cn(
                        'shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold capitalize',
                        statusPillClass(shift.status),
                    )}
                >
                    {shift.status}
                </span>
            </div>
            <div className="mt-2 space-y-1 text-[12.5px] text-muted-foreground">
                <span className="flex items-center gap-1.5">
                    <Users className="h-3.5 w-3.5 shrink-0" />
                    {shift.staff ? (
                        <span className="truncate">{shift.staff}</span>
                    ) : (
                        <em className="text-status-warning not-italic">
                            Open — no staff
                        </em>
                    )}
                </span>
                {shift.location ? (
                    <span className="flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5 shrink-0" />
                        <span className="truncate">{shift.location}</span>
                    </span>
                ) : null}
                <span className="flex items-center gap-1.5">
                    <Clock className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{windowLabel(shift)}</span>
                </span>
                {contextLine ? (
                    <span className="block truncate pl-5 capitalize">
                        {contextLine}
                    </span>
                ) : null}
            </div>
            <Link
                href={`/operations/shifts/${shift.id}`}
                className="mt-3 inline-flex text-xs font-semibold text-primary underline-offset-4 hover:underline"
            >
                View shift
            </Link>
        </div>
    );
}

export default ShiftSummaryCard;
