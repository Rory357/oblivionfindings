import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import type { useAttendanceBreak } from '@/hooks/use-attendance-break';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

export function AttendanceBreakFeedback({
    command,
    checkHref,
}: {
    command: ReturnType<typeof useAttendanceBreak>;
    checkHref: '/attendance' | '/my-day' | '/hr/my';
}) {
    if (command.pending) {
        return (
            <Alert variant="info" role="status">
                <Loader2 className="animate-spin" aria-hidden="true" />
                <AlertTitle>Saving your break…</AlertTitle>
                <AlertDescription>Wait for the result before taking another clock action.</AlertDescription>
            </Alert>
        );
    }
    const outcome = command.outcome;
    if (!outcome) return null;
    const saved = outcome.status === 'confirmed';
    return (
        <Alert variant={saved ? 'success' : 'warning'} role={saved ? 'status' : 'alert'}>
            {saved ? <CheckCircle2 aria-hidden="true" /> : <AlertCircle aria-hidden="true" />}
            <AlertTitle>
                {saved
                    ? outcome.receipt.action === 'start_break' ? 'Break started' : 'Break ended'
                    : outcome.status === 'rejected' ? 'Break not changed' : 'Check your attendance'}
            </AlertTitle>
            <AlertDescription className="gap-2">
                {saved ? (
                    <>
                        <p>
                            {outcome.refreshed
                                ? 'Your current attendance has been refreshed.'
                                : 'Your break was saved. Refresh your attendance before another clock action.'}
                        </p>
                        {!outcome.refreshed && (
                            <Button variant="outline" className="frontline-tap self-start"
                                disabled={command.refreshing} onClick={command.refresh}>
                                {command.refreshing ? 'Refreshing…' : 'Refresh attendance'}
                            </Button>
                        )}
                    </>
                ) : (
                    <>
                        <p>{outcome.message}</p>
                        {outcome.status !== 'rejected' && (
                            <Button variant="outline" className="frontline-tap self-start" asChild>
                                <a href={checkHref}>Check current attendance</a>
                            </Button>
                        )}
                    </>
                )}
            </AlertDescription>
        </Alert>
    );
}
