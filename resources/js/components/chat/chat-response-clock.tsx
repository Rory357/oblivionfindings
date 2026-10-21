import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeInZone, formatDurationMinutes } from '@/lib/datetime';

export type ResponseClock = {
    state:
        | 'unconfigured'
        | 'not_pending'
        | 'history_restricted'
        | 'due'
        | 'overdue';
    due_at: string | null;
    timezone: string | null;
    working_minutes: number | null;
};

/**
 * Staff see the reply deadline as a status (Due / Overdue). Families see a
 * plain-language promise instead: their own thread never shows "Overdue"
 * (approved mockup M11) or configuration wording meant for administrators.
 */
export function ChatResponseClock({
    clock,
    wording,
    audience = 'staff',
}: {
    clock?: ResponseClock;
    wording?: string;
    audience?: 'staff' | 'family';
}) {
    const due =
        clock?.due_at && ['due', 'overdue'].includes(clock.state)
            ? formatDateTimeInZone(clock.due_at, clock.timezone ?? undefined)
            : null;
    const workingMinutes = clock?.working_minutes;
    if (audience === 'family')
        return (
            <span className="flex flex-col gap-1">
                <span>
                    {due && clock?.state === 'due'
                        ? `Your care team aims to reply by ${due}.`
                        : 'Your care team will reply here.'}
                </span>
                {workingMinutes != null && (
                    <span>
                        Replies usually within{' '}
                        {formatDurationMinutes(workingMinutes)} during service
                        hours.
                    </span>
                )}
                {wording && <span>{wording}</span>}
            </span>
        );
    return (
        <span className="flex flex-col gap-1">
            {due ? (
                <span className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge
                        size="sm"
                        variant={
                            clock?.state === 'overdue' ? 'critical' : 'warning'
                        }
                    >
                        {clock?.state === 'overdue' ? 'Overdue' : 'Due'}
                    </StatusBadge>
                    <span>{due}</span>
                </span>
            ) : (
                <span>
                    {clock?.state === 'history_restricted'
                        ? 'Response timing is outside your available history'
                        : clock?.state === 'not_pending'
                          ? 'No reply deadline outstanding'
                          : 'No response expectation configured'}
                </span>
            )}
            {workingMinutes != null && (
                <span>
                    {formatDurationMinutes(workingMinutes)} working time
                    {clock?.timezone ? ` · ${clock.timezone}` : ''}
                </span>
            )}
            {wording && <span>{wording}</span>}
        </span>
    );
}
