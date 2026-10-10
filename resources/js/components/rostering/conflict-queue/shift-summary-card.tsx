import { Link } from '@inertiajs/react';
import { Clock, MapPin, Users } from 'lucide-react';

import { cn } from '@/lib/utils';

import { formatWindow, shiftTypeLabel } from './build-queue';
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
    return formatWindow(shift.startsAt, shift.endsAt, shift.workerTimezone);
}

/** Compact shift card used inside the detail panel (one card, or two for overlaps). */
export function ShiftSummaryCard({ shift }: { shift: QueueShift }) {
    const contextLine = [shift.serviceContext, shiftTypeLabel(shift.shiftType)]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="rounded-xl border p-3">
            <div className="flex items-start justify-between gap-2">
                <span className="min-w-0 text-sm font-semibold break-words">
                    {shift.client ?? 'No client recorded'}
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
                    {shift.staff || shift.userId != null ? (
                        <span className="min-w-0 break-words">
                            {shift.staff || 'Staff member'}
                        </span>
                    ) : (
                        <em className="text-status-warning not-italic">
                            Open — no staff
                        </em>
                    )}
                </span>
                {shift.location ? (
                    <span className="flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5 shrink-0" />
                        <span className="min-w-0 break-words">
                            {shift.location}
                        </span>
                    </span>
                ) : null}
                <span className="flex items-center gap-1.5">
                    <Clock className="h-3.5 w-3.5 shrink-0" />
                    <span className="min-w-0 break-words">
                        {windowLabel(shift)}
                    </span>
                </span>
                {contextLine ? (
                    <span className="block pl-5 break-words capitalize">
                        {contextLine}
                    </span>
                ) : null}
            </div>
            {shift.can?.view_shift && shift.urls?.shift ? (
                <Link
                    href={shift.urls.shift}
                    className="frontline-tap mt-3 inline-flex items-center text-xs font-semibold text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                >
                    View shift
                </Link>
            ) : (
                <p className="text-caption mt-3">
                    Shift details unavailable for this view.
                </p>
            )}
        </div>
    );
}

export default ShiftSummaryCard;
