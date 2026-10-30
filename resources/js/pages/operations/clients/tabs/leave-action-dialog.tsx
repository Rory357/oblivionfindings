import {
    ActualEventTimeField,
    actualEventInstant,
} from '@/components/clinical/actual-event-time-field';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { CalendarRange, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import type { LeaveItem } from './leave-excursions';

export type LeaveAction =
    | 'approve'
    | 'decline'
    | 'depart'
    | 'return'
    | 'withdraw';
export const LEAVE_ACTION_LABEL: Record<LeaveAction, string> = {
    approve: 'Approve leave',
    decline: 'Decline leave',
    depart: 'Record departure',
    return: 'Record return',
    withdraw: 'Withdraw leave',
};
const EFFECT: Record<LeaveAction, string> = {
    approve:
        'Approves the plan. Medication stays due until an actual departure is recorded.',
    decline: 'Declines this planned leave. Medication remains due as normal.',
    depart: 'Records when the person actually left. Doses due during this absence show Away; earlier overdue doses still need an outcome.',
    return: 'Records the actual return, including an early return. Doses due from this time are assessed normally. Other active absences still apply.',
    withdraw:
        'Withdraws the leave plan and keeps its history. If the person has already left, record their actual return first.',
};
const STEPS = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Action and actual time',
        icon: CalendarRange,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the change',
        icon: ClipboardCheck,
    },
];
export function LeaveActionDialog({
    clientId,
    item,
    action,
    onClose,
}: {
    clientId: number;
    item: LeaveItem;
    action: LeaveAction;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState(() => toDatetimeLocal(new Date()));
    const [offset, setOffset] = useState('');
    const [alreadyReturned, setAlreadyReturned] = useState(false);
    const [returnLocal, setReturnLocal] = useState('');
    const [returnOffset, setReturnOffset] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [discard, setDiscard] = useState(false);
    const closedLeave = action === 'depart' && alreadyReturned;
    const actionLabel = closedLeave
        ? 'Record departure and return'
        : LEAVE_ACTION_LABEL[action];
    const effect = closedLeave
        ? item.medication_away_enabled === true
            ? 'Records the actual departure and return together. Only doses due during that absence show Away; recorded outcomes stay unchanged.'
            : 'Records the actual departure and return together and keeps their history. The medication schedule connection is off; medication reminders are unchanged.'
        : item.medication_away_enabled === true
          ? EFFECT[action]
          : {
                approve:
                    'Approves the leave plan and keeps its history. The medication schedule connection is off; this action does not change medication reminders.',
                decline:
                    'Declines the leave plan and keeps its history. Medication reminders are unchanged.',
                depart: 'Records when the person actually left and keeps the history. The medication schedule connection is off; doses will not automatically show Away.',
                return: 'Records the actual return and keeps the history. The medication schedule connection is off; medication reminders are unchanged.',
                withdraw:
                    'Withdraws the planned leave and keeps its history. Medication reminders are unchanged. Actual departed leave must be ended by recording a return.',
            }[action];
    const timed = action === 'depart' || action === 'return';
    const instant = actualEventInstant(local, offset);
    const returnInstant = actualEventInstant(returnLocal, returnOffset);
    function validate() {
        const next: Record<string, string> = {};
        if (timed && (!instant || Date.parse(instant) > Date.now()))
            next.occurred_at =
                'Choose the actual time, including which clock reading if repeated. It cannot be in the future.';
        if (
            closedLeave &&
            (!returnInstant || Date.parse(returnInstant) > Date.now())
        )
            next.returned_at =
                'Choose the actual return time, including which clock reading if repeated. It cannot be in the future.';
        else if (
            closedLeave &&
            instant &&
            Date.parse(returnInstant) < Date.parse(instant)
        )
            next.returned_at =
                'The actual return must be at or after the departure.';
        if (action === 'withdraw' && !reason.trim())
            next.reason = 'Explain why the leave is being withdrawn.';
        if (!item.version || !item.allowed_actions?.includes(action))
            next.action =
                'This action is no longer available. Close and refresh the person’s record.';
        setErrors(next);
        return Object.keys(next).length === 0;
    }
    function save() {
        if (saving || !validate()) return;
        setSaving(true);
        router.put(
            '/operations/clients/' + clientId + '/leave/' + item.id,
            {
                action,
                version: item.version!,
                ...(timed ? { occurred_at: instant } : {}),
                ...(closedLeave ? { returned_at: returnInstant } : {}),
                ...(action === 'withdraw'
                    ? { reason: reason.trim() }
                    : { approval_notes: reason.trim() || null }),
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (next) => {
                    setErrors(next);
                    setConfirm(false);
                },
                onFinish: () => setSaving(false),
            },
        );
    }
    return (
        <>
            <WizardShell
                open
                onClose={() => {
                    if (!saving) setDiscard(true);
                }}
                title={actionLabel}
                description={effect}
                railIcon={CalendarRange}
                railTitle={actionLabel}
                railSub={item.destination || 'Planned leave'}
                steps={STEPS}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!saving && (next === 0 || validate())) setStep(next);
                }}
                pct={step === 1 ? 100 : 50}
                maxWidth="min(94vw, 900px)"
                maxHeight="min(88vh, 640px)"
                footerStart={
                    <Button
                        variant="outline"
                        disabled={saving}
                        onClick={() =>
                            step === 1 ? setStep(0) : setDiscard(true)
                        }
                    >
                        {step === 1 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={saving}
                        onClick={() => {
                            if (validate()) {
                                if (step === 0) setStep(1);
                                else setConfirm(true);
                            }
                        }}
                    >
                        {saving
                            ? 'Saving…'
                            : step === 0
                              ? 'Continue'
                              : actionLabel}
                    </Button>
                }
            >
                <WizardStepPane>
                    <p className="text-subtle">{effect}</p>
                    {Object.values(errors).length > 0 && (
                        <div role="alert" className="my-3">
                            <InputError
                                message={Object.values(errors).join(' ')}
                            />
                        </div>
                    )}
                    {step === 0 ? (
                        <div className="mt-5 space-y-5">
                            {timed && (
                                <ActualEventTimeField
                                    id="leave-actual-time"
                                    label={
                                        action === 'depart'
                                            ? 'Actually left'
                                            : 'Actually returned'
                                    }
                                    value={local}
                                    offset={offset}
                                    onChange={(v, o) => {
                                        setLocal(v);
                                        setOffset(o);
                                    }}
                                    error={errors.occurred_at}
                                />
                            )}
                            {action === 'depart' && (
                                <div className="space-y-4 rounded-lg border p-3">
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="leave-already-returned"
                                            checked={alreadyReturned}
                                            onCheckedChange={(checked) =>
                                                setAlreadyReturned(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <div className="space-y-1">
                                            <Label htmlFor="leave-already-returned">
                                                This leave has already ended
                                            </Label>
                                            <p className="text-subtle">
                                                Add the actual return to record
                                                both times together.
                                            </p>
                                        </div>
                                    </div>
                                    {alreadyReturned && (
                                        <ActualEventTimeField
                                            id="leave-historical-return"
                                            label="Actually returned"
                                            value={returnLocal}
                                            offset={returnOffset}
                                            onChange={(v, o) => {
                                                setReturnLocal(v);
                                                setReturnOffset(o);
                                            }}
                                            error={errors.returned_at}
                                        />
                                    )}
                                </div>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="leave-action-notes">
                                    {action === 'withdraw'
                                        ? 'Reason for withdrawal'
                                        : 'Notes (optional)'}
                                </Label>
                                <Textarea
                                    id="leave-action-notes"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                            </div>
                        </div>
                    ) : (
                        <ReviewCard
                            icon={ClipboardCheck}
                            title="Change to the leave record"
                        >
                            <ReviewRow label="Action" value={actionLabel} />
                            <ReviewRow
                                label="Destination"
                                value={item.destination}
                            />
                            {timed && (
                                <ReviewRow
                                    label={
                                        closedLeave
                                            ? 'Actually left · Pacific/Auckland'
                                            : 'Actual time · Pacific/Auckland'
                                    }
                                    value={
                                        formatDateTime(instant) +
                                        ' · UTC' +
                                        instant.slice(-6)
                                    }
                                />
                            )}
                            {closedLeave && (
                                <ReviewRow
                                    label="Actually returned · Pacific/Auckland"
                                    value={
                                        formatDateTime(returnInstant) +
                                        ' · UTC' +
                                        returnInstant.slice(-6)
                                    }
                                />
                            )}
                            <ReviewRow label="Notes" value={reason} />
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={confirm}
                onClose={() => {
                    if (!saving) setConfirm(false);
                }}
                onConfirm={save}
                title={actionLabel + '?'}
                description={effect}
                confirmText={actionLabel}
                variant="default"
                processing={saving}
            />
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this change?"
                description="Nothing will be saved to the leave record."
                confirmText="Discard change"
            />
        </>
    );
}
