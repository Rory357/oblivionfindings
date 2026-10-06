import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { ClipboardCheck, Stethoscope } from 'lucide-react';
import {
    ActualEventTimeField,
    actualEventInstant,
} from './actual-event-time-field';

import { Textarea } from '@/components/ui/textarea';
import { store as storeShiftClinicalEvent } from '@/routes/shifts/clinical/events';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

const EVENT_TYPES = [
    { value: 'fall', label: 'Fall' },
    { value: 'seizure', label: 'Seizure' },
    { value: 'choking', label: 'Choking Incident' },
    { value: 'deterioration', label: 'Health Deterioration' },
    { value: 'allergic_reaction', label: 'Allergic Reaction' },
    { value: 'skin_integrity', label: 'Skin Integrity Issue' },
    { value: 'infection_sign', label: 'Sign of Infection' },
    { value: 'behavioural_crisis', label: 'Behavioural Crisis' },
    { value: 'mental_health_episode', label: 'Mental Health Episode' },
    { value: 'hospital_admission', label: 'Hospital admission' },
    { value: 'hospital_discharge', label: 'Hospital discharge' },
    { value: 'other', label: 'Other Clinical Event' },
] as const;

const SEVERITIES = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
] as const;

type EventType = (typeof EVENT_TYPES)[number]['value'];
type Severity = (typeof SEVERITIES)[number]['value'];
const HS_LINKED_EVENT_TYPES: ReadonlySet<EventType> = new Set([
    'fall',
    'seizure',
    'choking',
]);

interface Props {
    clientId?: number;
    shiftId?: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onRecorded?: () => void;
}

const STEPS = [
    {
        key: 'details',
        label: 'What happened',
        blurb: 'Event and actual time',
        icon: Stethoscope,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before recording',
        icon: ClipboardCheck,
    },
];
type Admission = { id: number; occurred_at: string; reported_at: string };

export default function EventRecordSheet(props: Props) {
    return (
        <EventRecordForm
            key={
                props.shiftId
                    ? 'shift-' + props.shiftId
                    : 'client-' + props.clientId
            }
            {...props}
        />
    );
}

function EventRecordForm({
    clientId,
    shiftId,
    open,
    onOpenChange,
    onRecorded,
}: Props) {
    const [eventType, setEventType] = useState<EventType>('other');
    const [severity, setSeverity] = useState<Severity>('medium');
    const [occurredAt, setOccurredAt] = useState(toDatetimeLocal(new Date()));
    const [offset, setOffset] = useState('');
    const [step, setStep] = useState(0);
    const [confirm, setConfirm] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [admissionId, setAdmissionId] = useState('');
    const [admissions, setAdmissions] = useState<Admission[]>([]);
    const [admissionState, setAdmissionState] = useState<
        'loading' | 'ready' | 'error'
    >('loading');
    const [admissionAttempt, setAdmissionAttempt] = useState(0);
    const [description, setDescription] = useState('');
    const [immediateActionTaken, setImmediateActionTaken] = useState('');
    const [outcome, setOutcome] = useState('');
    const [requiresFollowup, setRequiresFollowup] = useState(false);
    const [followupNotes, setFollowupNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const immediateActionRequired = HS_LINKED_EVENT_TYPES.has(eventType);
    const hospital =
        eventType === 'hospital_admission' ||
        eventType === 'hospital_discharge';
    const eventInstant = actualEventInstant(occurredAt, offset);
    const admissionsUrl = shiftId
        ? '/shifts/' + shiftId + '/clinical/hospital-admissions'
        : clientId
          ? '/clients/' + clientId + '/clinical/hospital-admissions'
          : null;
    useEffect(() => {
        setAdmissions([]);
        setAdmissionId('');
        if (!open || eventType !== 'hospital_discharge' || !admissionsUrl)
            return;
        const abort = new AbortController();
        setAdmissionState('loading');
        fetch(admissionsUrl, {
            headers: { Accept: 'application/json' },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (
                    !response.ok ||
                    !response.headers
                        .get('content-type')
                        ?.includes('application/json')
                )
                    throw new Error('Unavailable');
                const data: unknown = await response.json();
                const rows = (data as { admissions?: unknown })?.admissions;
                if (
                    !Array.isArray(rows) ||
                    !rows.every(
                        (item) =>
                            Number.isInteger(item?.id) &&
                            item.id > 0 &&
                            typeof item.occurred_at === 'string' &&
                            Number.isFinite(Date.parse(item.occurred_at)),
                    )
                )
                    throw new Error('Invalid admission response');
                if (!abort.signal.aborted) {
                    setAdmissions(rows);
                    setAdmissionState('ready');
                }
            })
            .catch(() => {
                if (!abort.signal.aborted) {
                    setAdmissions([]);
                    setAdmissionState('error');
                }
            });
        return () => abort.abort();
    }, [admissionsUrl, eventType, open, admissionAttempt]);
    const validate = () => {
        const next: Record<string, string> = {};
        if (!eventInstant || Date.parse(eventInstant) > Date.now())
            next.occurred_at =
                'Choose the actual event time in New Zealand. It cannot be in the future.';
        if (!description.trim()) next.description = 'Describe what happened.';
        if (immediateActionRequired && !immediateActionTaken.trim())
            next.immediate_action_taken = 'Record what was done straight away.';
        if (!clientId && !shiftId)
            next.client = 'Choose a person or shift before recording.';
        if (
            eventType === 'hospital_discharge' &&
            (admissionState !== 'ready' ||
                !admissions.some((item) => String(item.id) === admissionId))
        )
            next.hospital_admission_id =
                'Choose the hospital admission this discharge ends.';
        setErrors(next);
        return Object.keys(next).length === 0;
    };
    const requestClose = () => {
        if (!submitting) setDiscard(true);
    };

    const resetForm = useCallback(() => {
        setEventType('other');
        setOffset('');
        setStep(0);
        setConfirm(false);
        setDiscard(false);
        setAdmissionId('');
        setAdmissions([]);
        setSeverity('medium');
        setOccurredAt(toDatetimeLocal(new Date()));
        setDescription('');
        setImmediateActionTaken('');
        setOutcome('');
        setRequiresFollowup(false);
        setFollowupNotes('');
        setErrors({});
    }, []);

    useEffect(() => {
        if (!open) {
            resetForm();
        }
    }, [open, resetForm]);

    const handleSubmit = () => {
        if (submitting || !validate()) return;
        if (immediateActionRequired && !immediateActionTaken.trim()) {
            setErrors({
                immediate_action_taken:
                    'Record the immediate action taken before saving this Health & Safety-linked event.',
            });

            return;
        }

        const url = shiftId
            ? storeShiftClinicalEvent.url(shiftId)
            : `/clients/${clientId}/clinical/events`;

        setSubmitting(true);
        setErrors({});

        router.post(
            url,
            {
                event_type: eventType,
                severity,
                occurred_at: eventInstant,
                ...(eventType === 'hospital_discharge'
                    ? { hospital_admission_id: Number(admissionId) }
                    : {}),
                description,
                immediate_action_taken: immediateActionTaken || undefined,
                outcome: outcome || undefined,
                requires_followup: requiresFollowup,
                followup_notes:
                    requiresFollowup && followupNotes
                        ? followupNotes
                        : undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onRecorded?.();
                },
                onError: (formErrors) => {
                    setErrors(formErrors as Record<string, string>);
                    setConfirm(false);
                    setStep(0);
                },
                onFinish: () => {
                    setSubmitting(false);
                },
            },
        );
    };

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                title="Record clinical event"
                description="Record actual events and their effect on care."
                railIcon={Stethoscope}
                railTitle="Clinical event"
                railSub={
                    shiftId
                        ? 'From the current shift'
                        : 'Person’s clinical record'
                }
                steps={STEPS}
                stepIndex={step}
                onStepClick={(next) => {
                    if (!submitting && (next === 0 || validate()))
                        setStep(next);
                }}
                pct={step === 1 ? 100 : 50}
                maxWidth="min(94vw, 980px)"
                maxHeight="min(88vh, 760px)"
                footerStart={
                    <Button
                        variant="outline"
                        onClick={() =>
                            step === 1 ? setStep(0) : requestClose()
                        }
                        disabled={submitting}
                    >
                        {step === 1 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={submitting}
                        onClick={() => {
                            if (validate()) {
                                if (step === 0) setStep(1);
                                else setConfirm(true);
                            }
                        }}
                    >
                        {submitting
                            ? 'Saving…'
                            : step === 0
                              ? 'Continue'
                              : 'Record event'}
                    </Button>
                }
            >
                <WizardStepPane>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="clinical-event-type">
                                        Event type
                                    </Label>
                                    <Select
                                        value={eventType}
                                        onValueChange={(value) =>
                                            setEventType(value as EventType)
                                        }
                                    >
                                        <SelectTrigger id="clinical-event-type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {EVENT_TYPES.map((type) => (
                                                <SelectItem
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="clinical-event-severity">
                                        Severity
                                    </Label>
                                    <Select
                                        value={severity}
                                        onValueChange={(value) =>
                                            setSeverity(value as Severity)
                                        }
                                    >
                                        <SelectTrigger id="clinical-event-severity">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {SEVERITIES.map((item) => (
                                                <SelectItem
                                                    key={item.value}
                                                    value={item.value}
                                                >
                                                    {item.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <ActualEventTimeField
                                id="clinical-event-time"
                                label="When it happened"
                                value={occurredAt}
                                offset={offset}
                                onChange={(v, o) => {
                                    setOccurredAt(v);
                                    setOffset(o);
                                }}
                                error={errors.occurred_at}
                            />
                            {hospital && (
                                <p className="text-subtle">
                                    {eventType === 'hospital_admission'
                                        ? 'Record an actual hospital admission, not a planned appointment. Medication due during the stay shows Away. Earlier doses still need an outcome.'
                                        : 'Choose the recorded admission and actual discharge time. Medication due after discharge is assessed normally; other active absences still apply.'}
                                </p>
                            )}
                            {eventType === 'hospital_discharge' && (
                                <div className="space-y-2">
                                    <Label htmlFor="hospital-admission">
                                        Admission being ended
                                    </Label>
                                    {admissionState === 'loading' ? (
                                        <p role="status">
                                            Loading open admissions…
                                        </p>
                                    ) : admissionState === 'error' ? (
                                        <div role="alert">
                                            <p>
                                                Open admissions could not be
                                                loaded. Your entry has been
                                                kept.
                                            </p>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setAdmissionAttempt(
                                                        (v) => v + 1,
                                                    )
                                                }
                                            >
                                                Try again
                                            </Button>
                                        </div>
                                    ) : admissions.length === 0 ? (
                                        <p role="status">
                                            No open hospital admission is
                                            recorded. Check the person’s
                                            clinical history before recording a
                                            discharge.
                                        </p>
                                    ) : (
                                        <Select
                                            value={admissionId}
                                            onValueChange={setAdmissionId}
                                        >
                                            <SelectTrigger id="hospital-admission">
                                                <SelectValue placeholder="Choose the actual admission" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {admissions.map((item) => (
                                                    <SelectItem
                                                        key={item.id}
                                                        value={String(item.id)}
                                                    >
                                                        Admitted{' '}
                                                        {formatDateTime(
                                                            item.occurred_at,
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="clinical-event-description">
                                    Description
                                </Label>
                                <Textarea
                                    id="clinical-event-description"
                                    placeholder="Describe what happened."
                                    rows={4}
                                    value={description}
                                    onChange={(event) =>
                                        setDescription(event.target.value)
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="clinical-immediate-action">
                                    Immediate Action Taken
                                    {immediateActionRequired ? ' *' : ''}
                                </Label>
                                <Textarea
                                    id="clinical-immediate-action"
                                    aria-invalid={Boolean(
                                        errors.immediate_action_taken,
                                    )}
                                    placeholder={
                                        immediateActionRequired
                                            ? 'Required: document exactly what was done straight away.'
                                            : 'Document immediate actions taken.'
                                    }
                                    rows={3}
                                    value={immediateActionTaken}
                                    onChange={(event) =>
                                        setImmediateActionTaken(
                                            event.target.value,
                                        )
                                    }
                                />
                                {immediateActionRequired ? (
                                    <p className="text-xs text-muted-foreground">
                                        Required because this event is linked to
                                        Health &amp; Safety.
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="clinical-outcome">
                                    Outcome
                                </Label>
                                <Textarea
                                    id="clinical-outcome"
                                    placeholder="Record the current outcome or condition."
                                    rows={3}
                                    value={outcome}
                                    onChange={(event) =>
                                        setOutcome(event.target.value)
                                    }
                                />
                            </div>

                            <div className="rounded-lg border p-3">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id={`requires-followup-${shiftId ?? clientId ?? 'event'}`}
                                        checked={requiresFollowup}
                                        onCheckedChange={(checked) => {
                                            const nextValue = Boolean(checked);
                                            setRequiresFollowup(nextValue);
                                            if (!nextValue) {
                                                setFollowupNotes('');
                                            }
                                        }}
                                    />
                                    <div className="space-y-1">
                                        <Label
                                            htmlFor={`requires-followup-${shiftId ?? clientId ?? 'event'}`}
                                        >
                                            Requires follow-up
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Flag this if a coordinator or
                                            clinical lead should review what
                                            happens next.
                                        </p>
                                    </div>
                                </div>

                                {requiresFollowup ? (
                                    <div className="mt-3 space-y-2">
                                        <Label htmlFor="clinical-followup-notes">
                                            Follow-up Notes
                                        </Label>
                                        <Textarea
                                            id="clinical-followup-notes"
                                            placeholder="Add any follow-up or review notes."
                                            rows={3}
                                            value={followupNotes}
                                            onChange={(event) =>
                                                setFollowupNotes(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                ) : null}
                            </div>

                            {Object.keys(errors).length > 0 ? (
                                <div className="rounded-md border border-status-critical/30 bg-status-critical-bg p-3">
                                    {Object.entries(errors).map(
                                        ([field, message]) => (
                                            <p
                                                key={field}
                                                className="text-xs text-status-critical"
                                            >
                                                {message}
                                            </p>
                                        ),
                                    )}
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <ReviewCard
                            icon={ClipboardCheck}
                            title="Event to record"
                        >
                            <ReviewRow
                                label="Event"
                                value={
                                    EVENT_TYPES.find(
                                        (item) => item.value === eventType,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Actual time · Pacific/Auckland"
                                value={
                                    formatDateTime(eventInstant) +
                                    ' · UTC' +
                                    eventInstant.slice(-6)
                                }
                            />
                            {eventType === 'hospital_discharge' && (
                                <ReviewRow
                                    label="Admission"
                                    value={formatDateTime(
                                        admissions.find(
                                            (item) =>
                                                String(item.id) === admissionId,
                                        )?.occurred_at,
                                    )}
                                />
                            )}
                            <ReviewRow label="Severity" value={severity} />
                            <ReviewRow
                                label="What happened"
                                value={description}
                            />
                            <ReviewRow
                                label="Immediate action"
                                value={immediateActionTaken}
                            />
                            <ReviewRow label="Outcome" value={outcome} />
                            <ReviewRow
                                label="Follow-up needed"
                                value={requiresFollowup ? 'Yes' : 'No'}
                            />
                            {requiresFollowup && (
                                <ReviewRow
                                    label="Follow-up notes"
                                    value={followupNotes}
                                />
                            )}
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={confirm}
                onClose={() => {
                    if (!submitting) setConfirm(false);
                }}
                onConfirm={handleSubmit}
                processing={submitting}
                title="Record this clinical event?"
                description={
                    hospital
                        ? 'The actual admission or discharge changes the person’s medication Away period. The event and who recorded it remain in their clinical history.'
                        : 'The event will be saved to the clinical record and relevant follow-ups.'
                }
                confirmText="Record event"
                variant="default"
            />
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => onOpenChange(false)}
                title="Discard this event?"
                description="Your unsaved event details will be discarded."
                confirmText="Discard event"
            />
        </>
    );
}
