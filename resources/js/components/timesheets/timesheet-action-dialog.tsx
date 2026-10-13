import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard } from '@/components/wizard/primitives';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Loader2,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { timesheetText, useTimesheetCommand } from './use-timesheet-command';
import type { ViewTimesheetRow } from './view-timesheet-dialog';
export type TimesheetReviewAction = 'submit' | 'approve' | 'reject' | 'return';
const labels = {
    submit: 'Submit for approval',
    approve: 'Approve timesheet',
    reject: 'Reject timesheet',
    return: 'Return to staff',
};
const statuses = {
    submit: 'submitted',
    approve: 'approved',
    reject: 'rejected',
    return: 'returned',
};
export type TimesheetActionDialogProps = {
    open: boolean;
    action: TimesheetReviewAction | null;
    record: ViewTimesheetRow | null;
    canAct: boolean;
    writeCoordination?: Parameters<typeof useTimesheetCommand>[1];
    onOpenChange: (open: boolean) => void;
};
/** Keep this component mounted in the owning page, outside transient viewers. */
export function TimesheetActionDialog(props: TimesheetActionDialogProps) {
    const requestedKey = props.record && props.action
        ? `${props.record.id}:${props.action}` : null;
    const serial = useRef(0), wasOpen = useRef(props.open);
    const protectedForm = useRef<() => boolean>(() => true);
    const [session, setSession] = useState<{
        key: string; serial: number; record: ViewTimesheetRow; action: TimesheetReviewAction;
    } | null>(() => props.open && requestedKey && props.record && props.action
        ? { key: requestedKey, serial: 0, record: props.record, action: props.action } : null);
    useLayoutEffect(() => {
        const reopening = props.open && !wasOpen.current;
        wasOpen.current = props.open;
        if (!props.open || !requestedKey || !props.record || !props.action) return;
        if (!session || (!protectedForm.current() &&
            (requestedKey !== session.key || reopening))) {
            protectedForm.current = () => true;
            serial.current += 1;
            setSession({ key: requestedKey, serial: serial.current,
                record: props.record, action: props.action });
        }
    }, [props.open, props.record, props.action, requestedKey, session]);
    if (!session) return null;
    const targetAvailable = requestedKey === session.key;
    return <TimesheetActionBody {...props} key={`${session.key}:${session.serial}`}
        action={session.action} record={targetAvailable && props.record ? props.record : session.record}
        canAct={targetAvailable && props.canAct} targetAvailable={targetAvailable}
        protectedForm={protectedForm} releaseDraft={() => setSession(null)} />;
}
function TimesheetActionBody(props: Omit<TimesheetActionDialogProps, 'action' | 'record'> & {
    action: TimesheetReviewAction; record: ViewTimesheetRow; targetAvailable: boolean;
    protectedForm: { current: () => boolean }; releaseDraft: () => void;
}) {
    const { action, record, canAct, writeCoordination, onOpenChange } = props;
    const rawActor = usePage<SharedData>().props.auth.user?.id;
    const currentActor = typeof rawActor === 'number' && Number.isSafeInteger(rawActor) && rawActor > 0
        ? rawActor : 0;
    const currentClientId =
        record.client_id !== undefined
            ? record.client_id
            : (record.client?.id ?? null);
    const [initial] = useState({
        actor: currentActor,
        recordId: record.id,
        ownerId: record.staff?.id ?? record.user_id,
        clientId: currentClientId,
        shiftId: record.shift?.id ?? null,
    });
    const mutationPending = useRef(false);
    const [reason, setReasonState] = useState('');
    const setReason = (value: string) => {
        mutationPending.current = true;
        setReasonState(value);
    };
    useLayoutEffect(() => { mutationPending.current = false; }, [reason]);
    const [error, setError] = useState(''), [discard, setDiscard] = useState(false);
    const command = useTimesheetCommand(
        `${currentActor}:${record.id}:${record.staff?.id ?? record.user_id}:${record.shift?.id ?? ''}:${currentClientId}:${action}:${canAct}`,
        writeCoordination,
    );
    const allowed =
        canAct && props.targetAvailable &&
        currentActor === initial.actor &&
        initial.actor > 0 &&
        record.id === initial.recordId &&
        currentClientId === initial.clientId &&
        (record.staff?.id ?? record.user_id) === initial.ownerId &&
        (record.shift?.id ?? null) === initial.shiftId;
    const blocked = !allowed || command.pending || command.held.current;
    const confirmed =
        command.outcome?.status === 'confirmed'
            ? command.outcome.receipt
            : null;
    useEffect(() => { if (!props.open) setDiscard(false); }, [props.open]);
    useLayoutEffect(() => {
        props.protectedForm.current = () => mutationPending.current || reason !== '' ||
            command.busy.current || command.held.current || command.pending ||
            command.outcome !== null || discard || currentActor !== initial.actor;
        return () => { props.protectedForm.current = () => true; };
    }, [props.protectedForm, reason, command.pending, command.outcome, discard,
        currentActor, initial.actor, command.busy, command.held]);
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (!confirmed && (mutationPending.current || reason !== '' || command.busy.current ||
                command.held.current || command.outcome?.status === 'unknown')) {
                event.preventDefault(); event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [reason, confirmed, command.outcome, command.busy, command.held]);
    const releaseAndClose = () => { props.releaseDraft(); onOpenChange(false); };
    const close = () => {
        if (command.busy.current) return;
        if (
            !confirmed &&
            (reason !== '' || command.outcome?.status === 'unknown')
        )
            setDiscard(true);
        else releaseAndClose();
    };
    function save() {
        if (blocked || command.busy.current) return;
        const value = timesheetText(reason);
        if ((action === 'reject' || action === 'return') && !value) {
            setError('Explain the decision for the staff member.');
            return;
        }
        if (Array.from(value ?? '').length > 5000) {
            setError('Keep the reason to 5,000 characters or fewer.');
            return;
        }
        setError('');
        const payload =
            action === 'submit'
                ? {}
                : action === 'return'
                  ? { returned_notes: value }
                  : { decision_notes: value };
        void command.submit(
            'post',
            `/operations/timesheets/${initial.recordId}/${action}`,
            payload,
            {
                action,
                actorId: initial.actor,
                timesheetId: initial.recordId,
                ownerId: initial.ownerId,
                clientId: initial.clientId,
                shiftId: initial.shiftId,
                status: statuses[action],
                values:
                    action === 'submit'
                        ? { timesheet_id: initial.recordId, action }
                        : {
                              timesheet_id: initial.recordId,
                              action,
                              reason: value,
                          },
            },
        );
    }
    if (!props.targetAvailable || currentActor !== initial.actor || initial.actor <= 0)
        return <Dialog open={props.open} onOpenChange={(open) => !open && onOpenChange(false)}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Timesheet review unavailable</DialogTitle>
                    <DialogDescription>This view cannot send this review. Any entries are kept in this page.</DialogDescription>
                </DialogHeader>
                <Button variant="outline" className="frontline-tap" onClick={() => onOpenChange(false)}>Close</Button>
            </DialogContent>
        </Dialog>;
    return (
        <>
            <Dialog open={props.open} onOpenChange={(open) => !open && close()}>
                <DialogContent
                    style={{
                        width: 'min(92vw, 600px)',
                        maxWidth: 'min(92vw, 600px)',
                    }}
                    className="max-h-[90vh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {labels[action]} · #{initial.recordId}
                        </DialogTitle>
                        <DialogDescription>
                            {action === 'submit'
                                ? 'Submit the saved hours and details for approval.'
                                : action === 'approve'
                                  ? 'Confirm the recorded work is ready for the next payroll and billing steps. Approval does not mark it paid.'
                                  : 'The staff member will see this decision and your reason.'}
                        </DialogDescription>
                    </DialogHeader>
                    {confirmed ? (
                        <div role="status" className="space-y-4">
                            <CheckCircle2 className="size-7 text-status-success" />
                            <p>
                                {confirmed.changed
                                    ? `Timesheet #${confirmed.timesheet_id} is confirmed ${confirmed.status}.`
                                    : `Timesheet #${confirmed.timesheet_id} was already ${confirmed.status}. Your new reason was not added.`}
                            </p>
                            <Button className="frontline-tap" onClick={releaseAndClose}>
                                Done
                            </Button>
                        </div>
                    ) : (
                        <>
                            {!allowed && (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This action is no longer available in the
                                    current records. Your reason is retained;
                                    any entries are kept in this form. Check the
                                    current record in a new tab to check access.
                                    <Button asChild variant="outline" className="mt-3 frontline-tap">
                                        <a href={`/operations/timesheets?view=${initial.recordId}`}
                                            target="_blank" rel="noopener noreferrer">
                                            Check current timesheet <ExternalLink className="size-4" />
                                        </a>
                                    </Button>
                                </InfoCard>
                            )}
                            {action !== 'submit' && (
                                <Field
                                    label={
                                        action === 'approve'
                                            ? 'Decision notes (optional)'
                                            : action === 'return'
                                              ? 'What needs changing?'
                                              : 'Reason for rejection'
                                    }
                                    htmlFor="timesheet-decision-reason"
                                    error={
                                        error ||
                                        (command.outcome && command.outcome.status !== 'confirmed'
                                            ? Object.values(
                                                  command.outcome.errors ?? {},
                                              )[0]
                                            : undefined)
                                    }
                                >
                                    <Textarea
                                        id="timesheet-decision-reason"
                                        rows={5}
                                        value={reason}
                                        disabled={blocked}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            {command.outcome &&
                                command.outcome.status !== 'confirmed' && (
                                    <div role="alert">
                                        <InfoCard
                                            icon={AlertTriangle}
                                            tone={
                                                command.outcome.status ===
                                                'unknown'
                                                    ? 'warn'
                                                    : 'crit'
                                            }
                                        >
                                            <p>{command.outcome.message}</p>
                                            {command.outcome.status ===
                                                'unknown' && (
                                                <>
                                                    <p className="mt-2">
                                                        Check timesheet #
                                                        {initial.recordId}. A
                                                        missing or inaccessible
                                                        record does not prove
                                                        that the action failed.
                                                    </p>
                                                    <Button
                                                        asChild
                                                        variant="outline"
                                                        className="mt-3 frontline-tap"
                                                    >
                                                        <a
                                                            href={`/operations/timesheets?view=${initial.recordId}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            Check current
                                                            timesheet
                                                            <ExternalLink className="size-4" />
                                                        </a>
                                                    </Button>
                                                </>
                                            )}
                                        </InfoCard>
                                    </div>
                                )}
                            <div className="flex flex-wrap justify-end gap-2">
                                <Button
                                    variant="outline"
                                    onClick={close}
                                    className="frontline-tap"
                                    disabled={command.pending}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    onClick={save}
                                    className="frontline-tap"
                                    disabled={blocked}
                                    variant={
                                        action === 'reject'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                >
                                    {command.pending && (
                                        <Loader2 className="size-4 animate-spin" />
                                    )}
                                    {labels[action]}
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    if (command.outcome?.status === 'unknown') onOpenChange(false);
                    else releaseAndClose();
                }}
                title={
                    command.outcome?.status === 'unknown'
                        ? 'Close this unconfirmed attempt?'
                        : 'Discard this reason?'
                }
                description={
                    command.outcome?.status === 'unknown'
                        ? 'Closing keeps the original reason and attempt in this page. It does not undo a saved action. Reopen the same review to check it.'
                        : 'Closing removes the reason from this form. It does not undo an action already saved.'
                }
                confirmText="Close form"
                cancelText="Keep editing"
            />
        </>
    );
}
