import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    CalendarClock,
    Check,
    MoveRight,
    Repeat,
    UserRound,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { ReviewFormShell } from './_form-shell';
import { ClinicianFields, LOCATION_CHOICES } from './_review-dialogs';
import { ChoiceTiles, Field, Notice, useReviewCommand } from './_ui';
import { cadenceLabel, LOCATION_LABELS, MOVE_LABELS } from './model';
import type {
    ClinicianRole,
    PickerOption,
    Review,
    ReviewAction,
    ReviewLocation,
} from './types';

export function MoveReviewDialog({
    review,
    today,
    onClose,
}: {
    review: Review;
    today: string;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [date, setDate] = useState('');
    const [reasonCode, setReasonCode] = useState('');
    const [reason, setReason] = useState('');
    const steps = [
        {
            key: 'date',
            label: 'New date',
            blurb: 'Keep the earlier date',
            icon: CalendarClock,
        },
        {
            key: 'reason',
            label: 'Why it is moving',
            blurb: 'A reason is kept with the change',
            icon: MoveRight,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then move',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        const errors: Record<string, string> = {};
        if (step === 0) {
            if (!formatDateOnly(date, ''))
                errors['move-date'] = 'Choose a valid new date.';
            else if (date < today)
                errors['move-date'] = 'The new date cannot be in the past.';
            else if (date === review.scheduled_date)
                errors['move-date'] = 'Choose a different date.';
        }
        if (step === 1) {
            if (!reasonCode)
                errors['move-reason-code'] = 'Choose why it is moving.';
            if (reason.trim().length < 3)
                errors['move-reason'] = 'Say why it is moving.';
        }
        return errors;
    };
    return (
        <ReviewFormShell
            title={`Move the review — ${review.client_name}`}
            description={`Review ${review.id} · currently due ${formatDateOnly(review.scheduled_date)}.`}
            steps={steps}
            command={command}
            dirty={!!(date || reasonCode || reason)}
            validate={validate}
            onSave={() =>
                command.submit(
                    `/emar/reviews/${review.id}`,
                    {
                        revision: review.revision,
                        scheduled_date: date,
                        reason_code: reasonCode,
                        reason: reason.trim(),
                    },
                    'put',
                )
            }
            onClose={onClose}
            saveLabel="Move the review"
            successTitle="Review moved"
            successBlurb={`Now due ${formatDateOnly(date)}. The previous date and reason are kept.`}
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        <Notice title="Earlier dates are kept">
                            This changes the due date. An appointment is changed
                            separately, and being away does not pause a review.
                        </Notice>
                        <Field
                            id="move-date"
                            label="New due date"
                            required
                            error={command.errors['move-date']}
                        >
                            <DatePicker
                                id="move-date"
                                label="New due date"
                                value={date}
                                onChange={setDate}
                                invalid={!!command.errors['move-date']}
                            />
                        </Field>
                    </>
                ) : step === 1 ? (
                    <>
                        <Field
                            id="move-reason-code"
                            label="Why move it"
                            required
                            error={command.errors['move-reason-code']}
                        >
                            <Select
                                value={reasonCode || undefined}
                                onValueChange={setReasonCode}
                            >
                                <SelectTrigger
                                    id="move-reason-code"
                                    className="w-full"
                                    aria-invalid={
                                        !!command.errors['move-reason-code']
                                    }
                                >
                                    <SelectValue placeholder="Choose the reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(MOVE_LABELS).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            id="move-reason"
                            label="Reason"
                            required
                            error={command.errors['move-reason']}
                        >
                            <Textarea
                                id="move-reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                rows={3}
                            />
                        </Field>
                    </>
                ) : (
                    <ReviewCard
                        icon={MoveRight}
                        title="Move this review"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow
                            label="From"
                            value={formatDateOnly(review.scheduled_date)}
                        />
                        <ReviewRow label="To" value={formatDateOnly(date)} />
                        <ReviewRow
                            label="Why"
                            value={`${MOVE_LABELS[reasonCode]}${reason ? ` — ${reason}` : ''}`}
                        />
                    </ReviewCard>
                )
            }
        />
    );
}
export function CancelReviewDialog({
    review,
    onClose,
}: {
    review: Review;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [reason, setReason] = useState('');
    const steps = [
        {
            key: 'reason',
            label: 'Why cancel it',
            blurb: 'Triggered review only',
            icon: XCircle,
        },
        {
            key: 'review',
            label: 'Review & cancel',
            blurb: 'The reason is kept',
            icon: Check,
        },
    ];
    return (
        <ReviewFormShell
            title={`Cancel the triggered review — ${review.client_name}`}
            description={`Review ${review.id} · due ${formatDateOnly(review.scheduled_date)}.`}
            steps={steps}
            command={command}
            dirty={!!reason}
            validate={(step) =>
                step === 0 && !reason.trim()
                    ? {
                          'cancel-reason':
                              'Say why this review is no longer needed.',
                      }
                    : {}
            }
            onSave={() => {
                if (review.review_type !== 'triggered') {
                    command.setErrors({
                        _request:
                            'Regular reviews are moved with a reason. They cannot be cancelled.',
                    });
                    return;
                }
                command.submit(
                    `/emar/reviews/${review.id}`,
                    { revision: review.revision, reason: reason.trim() },
                    'delete',
                );
            }}
            onClose={onClose}
            saveLabel="Cancel the review"
            successTitle="Triggered review cancelled"
            successBlurb="The review and reason are kept. The regular review cycle continues."
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        <Notice
                            warning
                            title="Only this triggered review is cancelled"
                        >
                            It remains in the history with your reason. The
                            person’s regular review is still due.
                        </Notice>
                        <Field
                            id="cancel-reason"
                            label="Why it is no longer needed"
                            required
                            error={command.errors['cancel-reason']}
                        >
                            <Textarea
                                id="cancel-reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                rows={4}
                            />
                        </Field>
                    </>
                ) : (
                    <ReviewCard
                        icon={XCircle}
                        title="Cancel this triggered review"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow label="Person" value={review.client_name} />
                        <ReviewRow
                            label="Due"
                            value={formatDateOnly(review.scheduled_date)}
                        />
                        <ReviewRow label="Why" value={reason} />
                    </ReviewCard>
                )
            }
        />
    );
}
export function AppointmentDialog({
    review,
    onClose,
}: {
    review: Review;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [clinician, setClinician] = useState<PickerOption | null>(
        review.reviewer_name
            ? {
                  value: `existing:${review.reviewer_name}`,
                  label: review.reviewer_name,
              }
            : null,
    );
    const [role, setRole] = useState<ClinicianRole | ''>(
        review.reviewer_role ?? '',
    );
    const [practice, setPractice] = useState(review.clinician_practice ?? '');
    const [when, setWhen] = useState(
        review.appointment_date
            ? `${review.appointment_date}T${review.appointment_time ?? ''}`
            : '',
    );
    const [location, setLocation] = useState<ReviewLocation | ''>(
        review.appointment_location ?? '',
    );
    const steps = [
        {
            key: 'clinician',
            label: 'With whom',
            blurb: 'The clinician’s name and role',
            icon: UserRound,
        },
        {
            key: 'appointment',
            label: 'When and where',
            blurb: 'Exact NZ date and time',
            icon: CalendarClock,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then save',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        const errors: Record<string, string> = {};
        if (step === 0) {
            if (!clinician)
                errors['appointment-clinician'] = 'Choose the clinician.';
            if (!role) errors['appointment-role'] = 'Choose their role.';
        }
        if (step === 1) {
            if (!validLocalDateTime(when))
                errors['appointment-when'] =
                    'Choose the appointment date and time.';
            if (!location)
                errors['appointment-location'] = 'Choose where it happens.';
        }
        return errors;
    };
    return (
        <ReviewFormShell
            title={`Appointment — ${review.client_name}`}
            description={`Review ${review.id} · due date stays ${formatDateOnly(review.scheduled_date)}.`}
            steps={steps}
            command={command}
            dirty={
                !!(
                    clinician?.label !== review.reviewer_name ||
                    role !== review.reviewer_role ||
                    practice !== (review.clinician_practice ?? '') ||
                    when !==
                        (review.appointment_date
                            ? `${review.appointment_date}T${review.appointment_time ?? ''}`
                            : '') ||
                    location !== (review.appointment_location ?? '')
                )
            }
            validate={validate}
            onSave={() => {
                const [date, time] = when.split('T');
                command.submit(
                    `/emar/reviews/${review.id}/appointment`,
                    {
                        revision: review.revision,
                        appointment_date: date,
                        appointment_time: time,
                        appointment_location: location,
                        reviewer_name: clinician!.label,
                        reviewer_role: role,
                        clinician_practice: practice.trim(),
                    },
                    'put',
                );
            }}
            onClose={onClose}
            saveLabel="Save the appointment"
            successTitle="Appointment saved"
            successBlurb={`${clinician?.label} · ${localDateTimeLabel(when)}.`}
            body={(step, edit) =>
                step === 0 ? (
                    <ClinicianFields
                        prefix="appointment"
                        value={clinician}
                        onChange={setClinician}
                        role={role}
                        setRole={setRole}
                        practice={practice}
                        setPractice={setPractice}
                        clientId={review.client_id}
                        errors={command.errors}
                    />
                ) : step === 1 ? (
                    <>
                        <DateTimeField
                            id="appointment-when"
                            label="Appointment"
                            value={when}
                            onChange={setWhen}
                            error={command.errors['appointment-when']}
                            clearable={false}
                        />
                        <ChoiceTiles
                            id="appointment-location"
                            label="Where"
                            value={location}
                            onChange={setLocation}
                            choices={LOCATION_CHOICES}
                            error={command.errors['appointment-location']}
                        />
                        <Notice title="The review due date stays the same">
                            If the review also needs a new due date, move the
                            review with a reason.
                        </Notice>
                    </>
                ) : (
                    <ReviewCard
                        icon={CalendarClock}
                        title="Appointment"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow
                            label="Clinician"
                            value={`${clinician?.label} · ${role}`}
                        />
                        <ReviewRow
                            label="When"
                            value={localDateTimeLabel(when)}
                        />
                        <ReviewRow
                            label="Where"
                            value={location ? LOCATION_LABELS[location] : '—'}
                        />
                        <ReviewRow
                            label="Review due"
                            value={formatDateOnly(review.scheduled_date)}
                        />
                    </ReviewCard>
                )
            }
        />
    );
}
export function IntervalDialog({
    action,
    defaultInterval,
    onClose,
}: {
    action: Extract<ReviewAction, { type: 'interval' }>;
    defaultInterval: { months: number; reviewed: boolean };
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [mode, setMode] = useState<'default' | 'own'>(
        action.cadence.own ? 'own' : 'default',
    );
    const [months, setMonths] = useState(String(action.cadence.months));
    const [reason, setReason] = useState('');
    const effective =
        mode === 'default' ? defaultInterval.months : Number(months);
    const steps = [
        {
            key: 'interval',
            label: 'How often',
            blurb: 'Default or this person’s interval',
            icon: Repeat,
        },
        {
            key: 'reason',
            label: 'Why change it',
            blurb: 'A reason is kept',
            icon: CalendarClock,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then save',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        const errors: Record<string, string> = {};
        if (
            step === 0 &&
            mode === 'own' &&
            (!Number.isInteger(effective) || effective < 1 || effective > 12)
        )
            errors['interval-months'] = 'Choose 1 to 12 calendar months.';
        if (step === 1 && !reason.trim())
            errors['interval-reason'] = 'Say why this interval is appropriate.';
        return errors;
    };
    return (
        <ReviewFormShell
            title={`How often — ${action.clientName}`}
            description="Calendar months · keeps the person’s review history."
            steps={steps}
            command={command}
            dirty={
                !!(
                    mode !== (action.cadence.own ? 'own' : 'default') ||
                    months !== String(action.cadence.months) ||
                    reason
                )
            }
            validate={validate}
            onSave={() =>
                command.submit(
                    `/emar/clients/${action.clientId}/review-interval`,
                    {
                        months: mode === 'default' ? null : effective,
                        reason: reason.trim(),
                    },
                    'put',
                )
            }
            onClose={onClose}
            saveLabel="Save the interval"
            successTitle="Review interval saved"
            successBlurb={`${cadenceLabel(effective)} · ${mode === 'default' ? 'Organisation default' : 'Set for this person'}.`}
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        <ChoiceTiles
                            id="interval-mode"
                            label="Which interval"
                            value={mode}
                            onChange={setMode}
                            choices={[
                                {
                                    value: 'default',
                                    label: 'Organisation default',
                                    description: `${cadenceLabel(defaultInterval.months)}${defaultInterval.reviewed ? '' : ' · not reviewed'}`,
                                    icon: Repeat,
                                },
                                {
                                    value: 'own',
                                    label: 'Set for this person',
                                    description: 'Record why it is appropriate',
                                    icon: UserRound,
                                },
                            ]}
                        />
                        {mode === 'own' && (
                            <Field
                                id="interval-months"
                                label="Calendar months"
                                required
                                error={command.errors['interval-months']}
                            >
                                <Select
                                    value={months}
                                    onValueChange={setMonths}
                                >
                                    <SelectTrigger
                                        id="interval-months"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Array.from(
                                            { length: 12 },
                                            (_, index) => index + 1,
                                        ).map((value) => (
                                            <SelectItem
                                                key={value}
                                                value={String(value)}
                                            >
                                                {cadenceLabel(value)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        <Notice title="The booked date is kept">
                            Changing the interval sets the next cycle. Move an
                            existing booked review with a reason if its current
                            date needs to change.
                        </Notice>
                    </>
                ) : step === 1 ? (
                    <Field
                        id="interval-reason"
                        label="Why this interval"
                        required
                        error={command.errors['interval-reason']}
                    >
                        <Textarea
                            id="interval-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                        />
                    </Field>
                ) : (
                    <ReviewCard
                        icon={Repeat}
                        title="Review interval"
                        onEdit={() => edit(0)}
                    >
                        <ReviewRow label="Person" value={action.clientName} />
                        <ReviewRow
                            label="How often"
                            value={cadenceLabel(effective)}
                        />
                        <ReviewRow
                            label="Source"
                            value={
                                mode === 'default'
                                    ? 'Organisation default'
                                    : 'Set for this person'
                            }
                        />
                        <ReviewRow label="Why" value={reason} />
                    </ReviewCard>
                )
            }
        />
    );
}
