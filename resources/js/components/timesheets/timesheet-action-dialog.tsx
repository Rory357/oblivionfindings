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
import { useState } from 'react';
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
export function TimesheetActionDialog(props: {
    open: boolean;
    action: TimesheetReviewAction;
    record: ViewTimesheetRow;
    canAct: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return props.open ? (
        <TimesheetActionBody
            key={`${props.record.id}:${props.action}`}
            {...props}
        />
    ) : null;
}
function TimesheetActionBody({
    action,
    record,
    canAct,
    onOpenChange,
}: Parameters<typeof TimesheetActionDialog>[0]) {
    const currentActor = Number(usePage<SharedData>().props.auth.user?.id ?? 0);
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
    const [reason, setReason] = useState(''),
        [error, setError] = useState(''),
        [discard, setDiscard] = useState(false);
    const command = useTimesheetCommand(
        `${currentActor}:${record.id}:${record.staff?.id ?? record.user_id}:${record.shift?.id ?? ''}:${currentClientId}:${action}`,
    );
    const allowed =
        canAct &&
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
    const close = () => {
        if (command.busy.current) return;
        if (
            !confirmed &&
            (reason !== '' || command.outcome?.status === 'unknown')
        )
            setDiscard(true);
        else onOpenChange(false);
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
    return (
        <>
            <Dialog open onOpenChange={(open) => !open && close()}>
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
                            <Button onClick={() => onOpenChange(false)}>
                                Done
                            </Button>
                        </div>
                    ) : (
                        <>
                            {!allowed && (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    This action is no longer available in the
                                    current records. Your reason is retained;
                                    close and refresh to check access.
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
                                        (command.outcome?.status === 'rejected'
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
                                                        className="mt-3 min-h-11"
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
                                    disabled={command.pending}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    onClick={save}
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
                onConfirm={() => onOpenChange(false)}
                title={
                    command.outcome?.status === 'unknown'
                        ? 'Close this unconfirmed attempt?'
                        : 'Discard this reason?'
                }
                description={
                    command.outcome?.status === 'unknown'
                        ? 'Closing does not undo an action already saved. Check the record first; your typed reason will be lost.'
                        : 'Your unsaved reason will be lost.'
                }
                confirmText="Close form"
                cancelText="Keep editing"
            />
        </>
    );
}
