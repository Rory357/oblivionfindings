import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDateTimeInZone } from '@/lib/datetime';
import { attendanceTimesheetMessage } from '@/pages/attendance/components/use-attendance-command';
import type { useMyHrClock } from './use-my-hr-clock';

export function MyHrClockFeedback({
    command,
    timezone,
}: {
    command: ReturnType<typeof useMyHrClock>;
    timezone: string;
}) {
    if (command.pending) return (
        <Alert variant="info" role="status">
            <Loader2 className="animate-spin" aria-hidden="true" />
            <AlertTitle>Saving your clock action…</AlertTitle>
            <AlertDescription>Wait for the result before taking another clock or break action.</AlertDescription>
        </Alert>
    );
    const outcome = command.outcome;
    if (!outcome) return null;
    if (outcome.status !== 'confirmed') return (
        <Alert variant="warning" role="alert">
            <AlertCircle aria-hidden="true" />
            <AlertTitle>{outcome.status === 'rejected' ? 'Clock action not saved' : 'Check your clock record'}</AlertTitle>
            <AlertDescription className="gap-2">
                <p>{outcome.message}</p>
                {outcome.status !== 'rejected' && (
                    <Button variant="outline" className="frontline-tap self-start" asChild>
                        <a href="/hr/my">Check current clock record</a>
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
    const result = outcome.receipt;
    const recorded = result.target_kind === 'attendance_session'
        ? result.attendance_result : result.time_entry_result;
    return (
        <Alert variant="success" role="status">
            <CheckCircle2 aria-hidden="true" />
            <AlertTitle>
                {result.action === 'clock_in'
                    ? 'Clocked in'
                    : result.target_kind === 'orphan_time_entry' ? 'Time entry closed' : 'Clocked out'}
            </AlertTitle>
            <AlertDescription className="gap-2">
                <p>
                    Recorded {result.action === 'clock_in' ? 'start' : 'finish'}:{' '}
                    {formatDateTimeInZone(
                        result.action === 'clock_in' ? recorded.clock_in_at : recorded.clock_out_at,
                        timezone,
                    )} ({timezone}).
                </p>
                {result.action === 'clock_out' && <p>{attendanceTimesheetMessage(
                    recorded,
                    result.target_kind === 'orphan_time_entry' ? 'Time entry' : 'Attendance',
                )}</p>}
                {!outcome.refreshed && (
                    <>
                        <p>Refresh your clock record before another clock or break action.</p>
                        <Button variant="outline" className="frontline-tap self-start"
                            disabled={command.refreshing} onClick={command.refresh}>
                            {command.refreshing ? 'Refreshing…' : 'Refresh clock record'}
                        </Button>
                    </>
                )}
            </AlertDescription>
        </Alert>
    );
}
