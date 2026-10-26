import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTimeInZone, WORKER_TIMEZONE } from '@/lib/datetime';
import { Loader2 } from 'lucide-react';
import {
    useId,
    useLayoutEffect,
    useRef,
    useState,
    type MutableRefObject,
} from 'react';
import {
    AttendanceCommandFeedback,
    useAttendanceClose,
} from './attendance-command-ui';
import type { OnClockRow } from './shared';
import {
    attendanceTimesheetMessage,
    useAttendanceCommand,
} from './use-attendance-command';

type EndSessionDialogProps = {
    open: boolean;
    session: OnClockRow | null;
    currentSession: OnClockRow | null;
    viewerId: number | null;
    canManage: boolean;
    timezone?: string;
    onClose: () => void;
};

export function EndSessionDialog(props: EndSessionDialogProps) {
    const requestClose = useRef<(() => void) | null>(null);
    return (
        <Dialog
            open={props.open}
            onOpenChange={(open) => {
                if (!open) requestClose.current?.();
            }}
        >
            <DialogContent
                className="max-h-[85vh] overflow-x-hidden overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
            >
                {props.open && props.session && (
                    <EndSessionBody
                        {...props}
                        session={props.session}
                        requestClose={requestClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Keep this one command owner mounted until the manager explicitly closes it. */
function EndSessionBody({
    session,
    currentSession,
    viewerId,
    canManage,
    timezone = WORKER_TIMEZONE,
    onClose,
    requestClose,
}: Omit<EndSessionDialogProps, 'session'> & {
    session: OnClockRow;
    requestClose: MutableRefObject<(() => void) | null>;
}) {
    const [original] = useState(() => ({
        sessionId: session.id,
        staffId: session.user_id,
        shiftId: session.shift_id,
        viewerId,
    }));
    const [retired, setRetired] = useState(false);
    const [sourceWithdrawn, setSourceWithdrawn] = useState(false);
    const changed =
        !canManage ||
        viewerId === null ||
        viewerId !== original.viewerId ||
        session.id !== original.sessionId ||
        session.user_id !== original.staffId ||
        session.shift_id !== original.shiftId ||
        (currentSession !== null &&
            (currentSession.id !== original.sessionId ||
                currentSession.user_id !== original.staffId ||
                currentSession.shift_id !== original.shiftId));
    const command = useAttendanceCommand(
        changed || retired
            ? 'end_session:unavailable'
            : `end_session:${original.sessionId}:${original.viewerId}`,
    );
    // The board is capped, so absence means unavailable in this view, not an
    // authorisation verdict. A normal successful close also removes its row:
    // keep that command owner until its response has been checked.
    useLayoutEffect(() => {
        if (currentSession === null) setSourceWithdrawn(true);
        if (
            changed ||
            ((sourceWithdrawn || currentSession === null) &&
                !command.pending &&
                !command.receipt)
        ) {
            setRetired(true);
        }
    }, [changed, currentSession, sourceWithdrawn, command.pending, command.receipt]);
    const unavailable =
        changed ||
        retired ||
        ((sourceWithdrawn || currentSession === null) && !command.receipt);
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const fieldId = useId();
    const close = useAttendanceClose({
        onClose,
        dirty: reason !== '',
        pending: command.pending,
        busy: command.busy,
        outcome: command.outcome,
    });
    useLayoutEffect(() => {
        requestClose.current = close.requestClose;
        return () => {
            requestClose.current = null;
        };
    }, [requestClose, close.requestClose]);
    const receipt = unavailable ? null : command.receipt;
    const submit = () => {
        if (unavailable || command.blocked || command.busy.current) return;
        const trimmedReason = reason.trim();
        if (!trimmedReason || trimmedReason.length > 1000) {
            setError(
                !trimmedReason
                    ? 'Enter a reason for ending this session.'
                    : 'Keep the reason to 1,000 characters or fewer.',
            );
            document.getElementById(fieldId)?.focus();
            return;
        }
        setError('');
        command.submit(
            `/attendance/sessions/${original.sessionId}/end`,
            { reason: trimmedReason },
            {
                action: 'end_session',
                sessionId: original.sessionId,
                explicitShiftId: original.shiftId,
            },
        );
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle>
                    {unavailable
                        ? 'Session unavailable'
                        : receipt
                          ? 'Session closed'
                          : session.user_name
                            ? `End ${session.user_name}'s session?`
                            : 'End this session?'}
                </DialogTitle>
                <DialogDescription>
                    {unavailable
                        ? 'Reopen attendance to check the current session.'
                        : receipt
                          ? 'Review the recorded end and any payroll follow-up before closing.'
                          : 'Ends this session and any running break. To record a specific finish time, use Fix clock-out instead.'}
                </DialogDescription>
            </DialogHeader>
            {unavailable ? (
                <p role="alert" className="text-subtle">
                    This session is no longer available in this view, or
                    your access or selection has changed. Check attendance
                    before starting again. A pending action may already
                    have saved; its result will still be checked here.
                </p>
            ) : receipt ? (
                <div role="status" className="space-y-2 text-subtle">
                    <p>
                        Recorded end:{' '}
                        {formatDateTimeInZone(
                            receipt.clock_out_at,
                            timezone,
                        )}
                    </p>
                    <p>{attendanceTimesheetMessage(receipt)}</p>
                </div>
            ) : (
                <>
                    <p className="text-subtle text-muted-foreground">
                        The reason is required for the audit history.
                        Timesheet changes depend on the record’s current
                        status; any payroll follow-up will be shown here.
                    </p>
                    <AttendanceCommandFeedback
                        outcome={command.outcome}
                    />
                    <div className="grid gap-1.5">
                        <Label htmlFor={fieldId}>Reason</Label>
                        <Textarea
                            id={fieldId}
                            value={reason}
                            onChange={(event) => {
                                setReason(event.target.value);
                                setError('');
                                command.clearRejected();
                            }}
                            disabled={command.blocked}
                            aria-invalid={!!error}
                            aria-describedby={
                                error ? `${fieldId}-error` : undefined
                            }
                            placeholder="Why does this session need to end now?"
                            rows={3}
                            autoFocus
                        />
                        <InputError
                            id={`${fieldId}-error`}
                            message={error}
                        />
                    </div>
                </>
            )}
            <DialogFooter className="flex-wrap gap-2">
                <Button
                    variant="outline"
                    onClick={close.requestClose}
                    disabled={command.pending}
                >
                    {receipt || unavailable ? 'Close' : 'Cancel'}
                </Button>
                {!receipt && !unavailable && (
                    <Button
                        variant="destructive"
                        onClick={submit}
                        disabled={command.blocked}
                    >
                        {command.pending && (
                            <Loader2
                                className="size-4 animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        {command.pending
                            ? 'Ending session…'
                            : 'End session'}
                    </Button>
                )}
            </DialogFooter>
            {close.dialog}
        </>
    );
}
