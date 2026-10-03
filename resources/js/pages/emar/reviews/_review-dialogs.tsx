import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, toDatetimeLocal } from '@/lib/datetime';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    Check,
    FileText,
    Home,
    Phone,
    Pill,
    Stethoscope,
    UserRound,
    Users,
    Video,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { ReviewFormShell } from './_form-shell';
import {
    ChoiceTiles,
    ConcealedMedicine,
    Field,
    Notice,
    OutcomeFields,
    ReviewPicker,
    SourceUpload,
    useReviewCommand,
    validateOutcome,
} from './_ui';
import {
    addCalendarMonths,
    cadenceLabel,
    completionItems,
    LOCATION_LABELS,
    OUTCOME_LABELS,
    recommendationOutcome,
    TRIGGER_LABELS,
} from './model';
import type {
    ClinicianRole,
    OutcomeDraft,
    PickerOption,
    Review,
    ReviewAction,
    ReviewKind,
    ReviewLocation,
    ReviewPermissions,
} from './types';

export const LOCATION_CHOICES = [
    {
        value: 'house' as const,
        label: 'At the house',
        description: 'The clinician came to the house',
        icon: Home,
    },
    {
        value: 'practice' as const,
        label: 'At the practice',
        description: 'The person went to the clinic',
        icon: Building2,
    },
    {
        value: 'phone' as const,
        label: 'By phone',
        description: 'A phone consultation',
        icon: Phone,
    },
    {
        value: 'video' as const,
        label: 'By video',
        description: 'A video consultation',
        icon: Video,
    },
];
export function ClinicianFields({
    prefix,
    value,
    onChange,
    role,
    setRole,
    practice,
    setPractice,
    clientId,
    errors,
    optional = false,
}: {
    prefix: string;
    value: PickerOption | null;
    onChange: (value: PickerOption | null) => void;
    role: ClinicianRole | '';
    setRole: (role: ClinicianRole) => void;
    practice: string;
    setPractice: (practice: string) => void;
    clientId?: number;
    errors: Record<string, string>;
    optional?: boolean;
}) {
    return (
        <>
            <Field
                id={`${prefix}-clinician`}
                label="Clinician"
                required={!optional}
                error={errors[`${prefix}-clinician`]}
                hint="Record who did the review. You are recording their findings."
            >
                <ReviewPicker
                    id={`${prefix}-clinician`}
                    kind="clinician"
                    value={value}
                    onChange={onChange}
                    clientId={clientId}
                    placeholder={
                        optional
                            ? 'Not booked with a clinician yet'
                            : 'Choose who did the review'
                    }
                    optional={optional}
                    allowCustom
                    error={errors[`${prefix}-clinician`]}
                />
            </Field>
            {value && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        id={`${prefix}-role`}
                        label="Role"
                        required
                        error={errors[`${prefix}-role`]}
                    >
                        <Select
                            value={role || undefined}
                            onValueChange={(next) =>
                                setRole(next as ClinicianRole)
                            }
                        >
                            <SelectTrigger
                                id={`${prefix}-role`}
                                aria-invalid={!!errors[`${prefix}-role`]}
                                className="w-full"
                            >
                                <SelectValue placeholder="Choose their role" />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    [
                                        'GP',
                                        'Pharmacist',
                                        'Nurse practitioner',
                                        'Specialist',
                                    ] as const
                                ).map((next) => (
                                    <SelectItem key={next} value={next}>
                                        {next}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        id={`${prefix}-practice`}
                        label="Practice or service (optional)"
                    >
                        <Input
                            id={`${prefix}-practice`}
                            value={practice}
                            onChange={(event) =>
                                setPractice(event.target.value)
                            }
                        />
                    </Field>
                </div>
            )}
        </>
    );
}
export function BookReviewDialog({
    action,
    today,
    onClose,
}: {
    action: Extract<ReviewAction, { type: 'book' }>;
    today: string;
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const [requestUuid] = useState(() => crypto.randomUUID());
    const [person, setPerson] = useState<PickerOption | null>(
        action.clientId
            ? {
                  value: String(action.clientId),
                  label: action.clientName ?? 'Selected person',
              }
            : null,
    );
    const [kind, setKind] = useState<ReviewKind | ''>('');
    const [trigger, setTrigger] = useState('');
    const [reason, setReason] = useState('');
    const [due, setDue] = useState('');
    const [owner, setOwner] = useState<PickerOption | null>(null);
    const [clinician, setClinician] = useState<PickerOption | null>(null);
    const [role, setRole] = useState<ClinicianRole | ''>('');
    const [practice, setPractice] = useState('');
    const [appointment, setAppointment] = useState('');
    const [location, setLocation] = useState<ReviewLocation | ''>('');
    const errors = command.errors;
    const steps = [
        {
            key: 'who',
            label: 'Who and why',
            blurb: 'The person, regular or triggered',
            icon: UserRound,
        },
        {
            key: 'when',
            label: 'When and with whom',
            blurb: 'Due date, appointment, owner',
            icon: CalendarClock,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then book',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        const next: Record<string, string> = {};
        if (step === 0) {
            if (!person) next['book-person'] = 'Choose the person.';
            if (!kind) next['book-kind'] = 'Choose regular or triggered.';
            if (kind === 'triggered' && !trigger)
                next['book-trigger'] = 'Choose what started it.';
            if (kind === 'triggered' && trigger === 'other' && !reason.trim())
                next['book-reason'] = 'Say what happened.';
        }
        if (step === 1) {
            if (!formatDateOnly(due, ''))
                next['book-due'] = 'Choose a valid due date.';
            else if (due < today)
                next['book-due'] = 'The due date cannot be in the past.';
            if (!owner) next['book-owner'] = 'Choose who owns it.';
            if (clinician && !role)
                next['book-role'] = 'Choose the clinician’s role.';
            if (clinician && !validLocalDateTime(appointment))
                next['book-appointment'] =
                    'Choose the appointment date and time, or clear the clinician.';
            if (clinician && !location)
                next['book-location'] = 'Choose where it happens.';
        }
        return next;
    };
    const personId = person?.value ?? '';
    const ownerId = owner?.value ?? '';
    const save = () => {
        if (!personId || !ownerId) return;
        const [date, time] = appointment.split('T');
        command.submit('/emar/reviews', {
            request_uuid: requestUuid,
            client_id: Number(personId),
            review_type: kind,
            scheduled_date: due,
            owner_id: Number(ownerId),
            trigger_code: kind === 'triggered' ? trigger : null,
            trigger_reason: kind === 'triggered' ? reason.trim() : null,
            appointment_date: clinician ? date : null,
            appointment_time: clinician ? time : null,
            appointment_location: clinician ? location : null,
            reviewer_name: clinician?.label ?? null,
            reviewer_role: clinician ? role : null,
            clinician_practice: clinician ? practice.trim() : null,
        });
    };
    return (
        <ReviewFormShell
            title={
                person
                    ? `Book a review — ${person.label}`
                    : 'Book a medication review'
            }
            description="Who and why, when and with whom, then review."
            steps={steps}
            command={command}
            dirty={
                !!(
                    kind ||
                    trigger ||
                    reason ||
                    due ||
                    owner ||
                    clinician ||
                    (person && !action.clientId)
                )
            }
            validate={validate}
            onSave={save}
            onClose={onClose}
            saveLabel="Book the review"
            successTitle="Review booked"
            successBlurb={`Due ${formatDateOnly(due)}. ${owner?.label ?? 'The owner'} owns it.`}
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        {action.clientId ? (
                            <Card className="gap-1 p-4">
                                <p className="text-caption">Person</p>
                                <p className="text-sm font-semibold">
                                    {person?.label}
                                </p>
                            </Card>
                        ) : (
                            <Field
                                id="book-person"
                                label="Person"
                                required
                                error={errors['book-person']}
                            >
                                <ReviewPicker
                                    id="book-person"
                                    kind="person"
                                    value={person}
                                    onChange={(next) => {
                                        setPerson(next);
                                        setOwner(null);
                                        setClinician(null);
                                    }}
                                    placeholder="Choose someone at your houses"
                                    error={errors['book-person']}
                                />
                            </Field>
                        )}
                        <ChoiceTiles
                            id="book-kind"
                            label="Regular or triggered"
                            value={kind}
                            onChange={setKind}
                            error={errors['book-kind']}
                            choices={[
                                {
                                    value: 'regular',
                                    label: 'Regular',
                                    description:
                                        'Starts or continues the person’s regular review cycle',
                                    icon: CalendarClock,
                                },
                                {
                                    value: 'triggered',
                                    label: 'Triggered',
                                    description:
                                        'Something happened that needs a review',
                                    icon: AlertTriangle,
                                },
                            ]}
                        />
                        {kind === 'triggered' && (
                            <>
                                <Field
                                    id="book-trigger"
                                    label="What started it"
                                    required
                                    error={errors['book-trigger']}
                                >
                                    <Select
                                        value={trigger || undefined}
                                        onValueChange={setTrigger}
                                    >
                                        <SelectTrigger
                                            id="book-trigger"
                                            className="w-full"
                                            aria-invalid={
                                                !!errors['book-trigger']
                                            }
                                        >
                                            <SelectValue placeholder="Choose the trigger" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(TRIGGER_LABELS).map(
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
                                    id="book-reason"
                                    label="What happened"
                                    required={trigger === 'other'}
                                    error={errors['book-reason']}
                                >
                                    <Textarea
                                        id="book-reason"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        rows={3}
                                    />
                                </Field>
                            </>
                        )}
                    </>
                ) : step === 1 ? (
                    <>
                        <Field
                            id="book-due"
                            label="Due by"
                            required
                            error={errors['book-due']}
                            hint="It becomes overdue the day after, in NZ time."
                        >
                            <DatePicker
                                id="book-due"
                                label="Due by"
                                value={due}
                                onChange={setDue}
                                invalid={!!errors['book-due']}
                            />
                        </Field>
                        <ClinicianFields
                            prefix="book"
                            value={clinician}
                            onChange={setClinician}
                            role={role}
                            setRole={setRole}
                            practice={practice}
                            setPractice={setPractice}
                            clientId={person ? Number(person.value) : undefined}
                            errors={errors}
                            optional
                        />
                        {clinician && (
                            <>
                                <DateTimeField
                                    id="book-appointment"
                                    label="Appointment"
                                    value={appointment}
                                    onChange={setAppointment}
                                    error={errors['book-appointment']}
                                />
                                <ChoiceTiles
                                    id="book-location"
                                    label="Where"
                                    value={location}
                                    onChange={setLocation}
                                    choices={LOCATION_CHOICES}
                                    error={errors['book-location']}
                                />
                            </>
                        )}
                        <Field
                            id="book-owner"
                            label="Owner"
                            required
                            error={errors['book-owner']}
                        >
                            <ReviewPicker
                                id="book-owner"
                                kind="owner"
                                value={owner}
                                onChange={setOwner}
                                clientId={
                                    person ? Number(person.value) : undefined
                                }
                                placeholder="Who books it and records the outcome"
                                error={errors['book-owner']}
                            />
                        </Field>
                    </>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            title="Who and why"
                            icon={UserRound}
                            onEdit={() => edit(0)}
                        >
                            <ReviewRow label="Person" value={person?.label} />
                            <ReviewRow
                                label="Kind"
                                value={
                                    kind === 'triggered'
                                        ? `Triggered — ${TRIGGER_LABELS[trigger]}`
                                        : 'Regular'
                                }
                            />
                            {reason && (
                                <ReviewRow
                                    label="What happened"
                                    value={reason}
                                />
                            )}
                        </ReviewCard>
                        <ReviewCard
                            title="When and with whom"
                            icon={CalendarClock}
                            onEdit={() => edit(1)}
                        >
                            <ReviewRow
                                label="Due by"
                                value={formatDateOnly(due)}
                            />
                            <ReviewRow label="Owner" value={owner?.label} />
                            <ReviewRow
                                label="Clinician"
                                value={
                                    clinician
                                        ? `${clinician.label} · ${role}`
                                        : 'Not booked with a clinician yet'
                                }
                            />
                            {clinician && (
                                <>
                                    <ReviewRow
                                        label="Appointment"
                                        value={localDateTimeLabel(appointment)}
                                    />
                                    <ReviewRow
                                        label="Where"
                                        value={
                                            location
                                                ? LOCATION_LABELS[location]
                                                : '—'
                                        }
                                    />
                                </>
                            )}
                        </ReviewCard>
                        <div className="sm:col-span-2">
                            <Notice title="When you save">
                                This review appears on the person’s medication
                                record and review task. Recording a regular
                                review books the next one. A triggered review
                                leaves the regular cycle in place.
                            </Notice>
                        </div>
                    </div>
                )
            }
        />
    );
}

export function RecordReviewDialog({
    review,
    can,
    today,
    asAt,
    defaultInterval,
    onClose,
}: {
    review: Review;
    can: ReviewPermissions;
    today: string;
    asAt: string;
    defaultInterval: { months: number; reviewed: boolean };
    onClose: () => void;
}) {
    const command = useReviewCommand();
    const orders = review.current_orders ?? [];
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
    const [registration, setRegistration] = useState('');
    const [when, setWhen] = useState(toDatetimeLocal(asAt));
    const [location, setLocation] = useState<ReviewLocation | ''>(
        review.appointment_location ?? '',
    );
    const [tookPart, setTookPart] = useState<'took' | 'not' | ''>('');
    const [personReason, setPersonReason] = useState('');
    const [whanau, setWhanau] = useState<'took' | 'told' | 'none' | ''>('');
    const [whanauDetail, setWhanauDetail] = useState('');
    const [initialRevision] = useState(review.revision);
    const [initialClinician] = useState({
        name: review.reviewer_name ?? '',
        role: review.reviewer_role ?? '',
        practice: review.clinician_practice ?? '',
        when: toDatetimeLocal(asAt),
        location: review.appointment_location ?? '',
    });
    const [itemDrafts, setItemDrafts] = useState<OutcomeDraft[]>(
        orders.map((order) => ({
            client_medication_id: order.id,
            outcome: order.controlled_hidden ? 'pending_controlled' : '',
            recommendation: '',
            watch_text: '',
            watch_until: '',
        })),
    );
    const items = orders.map(
        (order) =>
            itemDrafts.find(
                (item) => item.client_medication_id === order.id,
            ) ?? {
                client_medication_id: order.id,
                outcome: order.controlled_hidden
                    ? ('pending_controlled' as const)
                    : ('' as const),
                recommendation: '',
                watch_text: '',
                watch_until: '',
            },
    );
    const [newMedicine, setNewMedicine] = useState(false);
    const [newName, setNewName] = useState('');
    const [newRecommendation, setNewRecommendation] = useState('');
    const [summary, setSummary] = useState('');
    const [source, setSource] = useState<File | null>(null);
    const [dbi, setDbi] = useState('');
    const [falls, setFalls] = useState('');
    const [earlier, setEarlier] = useState(false);
    const [earlierDate, setEarlierDate] = useState('');
    const cadence = review.cadence ?? { ...defaultInterval, own: false };
    const regularNext = addCalendarMonths(
        when.split('T')[0] || today,
        cadence.months,
    );
    const nextRegular =
        review.review_type === 'regular'
            ? regularNext
            : (review.next_regular_review_date ?? '');
    const nextDate = earlier ? earlierDate : nextRegular;
    const nextLabel = formatDateOnly(
        nextDate,
        'Not booked yet — regular cycle unchanged',
    );
    const errors = command.errors;
    const hidden = orders.filter((order) => order.controlled_hidden).length;
    const steps = [
        {
            key: 'clinician',
            label: 'Who did it',
            blurb: 'The clinician, when and how',
            icon: Stethoscope,
        },
        {
            key: 'participants',
            label: 'Who took part',
            blurb: 'The person and whānau',
            icon: Users,
        },
        {
            key: 'medicines',
            label: 'Each medicine',
            blurb: `${orders.length} current medicines`,
            icon: Pill,
        },
        {
            key: 'summary',
            label: 'Summary & letter',
            blurb: 'The clinician’s own words',
            icon: FileText,
        },
        {
            key: 'next',
            label: 'Next review',
            blurb: 'Keep the regular cycle',
            icon: CalendarClock,
        },
        {
            key: 'review',
            label: 'Review & save',
            blurb: 'Check, then record',
            icon: Check,
        },
    ];
    const validate = (step: number) => {
        let next: Record<string, string> = {};
        if (step === 0) {
            if (!clinician)
                next['record-clinician'] = 'Choose who did the review.';
            if (!role) next['record-role'] = 'Choose the clinician’s role.';
            if (
                !validLocalDateTime(when) ||
                !formatDateOnly(when.split('T')[0], '')
            )
                next['record-when'] = 'Choose when it happened.';
            else if (when > toDatetimeLocal(asAt))
                next['record-when'] =
                    'It cannot be in the future. Record it after the review.';
            if (!location) next['record-location'] = 'Choose how it happened.';
        }
        if (step === 1) {
            if (!tookPart)
                next['record-person'] = 'Say whether the person took part.';
            if (tookPart === 'not' && !personReason.trim())
                next['record-person-reason'] =
                    'Say why the person did not take part.';
            if (!whanau)
                next['record-whanau'] =
                    'Say whether whānau, a welfare guardian or an EPOA was involved.';
            if (whanau && !whanauDetail.trim())
                next['record-whanau-detail'] =
                    whanau === 'none'
                        ? 'Say why they were not involved.'
                        : 'Say who was involved.';
        }
        if (step === 2) {
            for (const item of items) {
                if (
                    orders.find(
                        (order) => order.id === item.client_medication_id,
                    )?.controlled_hidden
                )
                    continue;
                next = {
                    ...next,
                    ...validateOutcome(
                        `medicine-${item.client_medication_id}`,
                        item,
                    ),
                };
            }
            if (newMedicine && !newName.trim())
                next['record-new-name'] = 'Enter the medicine name.';
            if (newMedicine && !newRecommendation.trim())
                next['record-new-recommendation'] =
                    'Say what the clinician recommends.';
        }
        if (step === 3) {
            if (!summary.trim())
                next['record-summary'] =
                    'Write what the clinician said. Their letter can go with it.';
            if (dbi && (!Number.isFinite(Number(dbi)) || Number(dbi) < 0))
                next['record-dbi'] =
                    'Use a number of zero or more, or leave it blank.';
            if (
                falls &&
                (!Number.isInteger(Number(falls)) || Number(falls) < 0)
            )
                next['record-falls'] =
                    'Use a whole number of zero or more, or leave it blank.';
        }
        if (step === 4 && earlier) {
            if (!formatDateOnly(earlierDate, ''))
                next['record-earlier'] = 'Choose an earlier review date.';
            else if (
                earlierDate <= today ||
                (nextRegular && earlierDate >= nextRegular)
            )
                next['record-earlier'] = nextRegular
                    ? 'Choose a date after today and before the next regular review.'
                    : 'Choose a date after today.';
        }
        return next;
    };
    const clinicianName = clinician?.label ?? '';
    const save = () => {
        if (!clinicianName) return;
        const [date, time] = when.split('T');
        command.submit(`/emar/reviews/${review.id}/complete`, {
            revision: review.revision,
            completed_date: date,
            completed_time: time,
            reviewer_name: clinicianName,
            reviewer_role: role,
            reviewer_registration_number: registration.trim(),
            clinician_practice: practice.trim(),
            review_location: location,
            participants: JSON.stringify({
                person: tookPart,
                person_reason: tookPart === 'not' ? personReason.trim() : null,
                whanau,
                whanau_detail: whanauDetail.trim(),
            }),
            clinical_summary: summary.trim(),
            drug_burden_index: dbi || null,
            falls_last_quarter: falls || null,
            items: JSON.stringify(completionItems(items, orders)),
            new_medicine: newMedicine
                ? JSON.stringify({
                      name: newName.trim(),
                      recommendation: newRecommendation.trim(),
                  })
                : null,
            source,
            earlier_review_date: earlier ? earlierDate : null,
        });
    };
    const updateItem = (id: number, patch: Partial<OutcomeDraft>) =>
        setItemDrafts((current) =>
            current.some((item) => item.client_medication_id === id)
                ? current.map((item) =>
                      item.client_medication_id === id
                          ? { ...item, ...patch }
                          : item,
                  )
                : [
                      ...current,
                      {
                          client_medication_id: id,
                          outcome: '',
                          recommendation: '',
                          watch_text: '',
                          watch_until: '',
                          ...patch,
                      },
                  ],
        );
    const changes =
        items.filter((item) => recommendationOutcome(item.outcome)).length +
        (newMedicine ? 1 : 0);
    return (
        <ReviewFormShell
            title={`Record the outcome — ${review.client_name}`}
            description={`Review ${review.id} · each medicine gets its own outcome.`}
            steps={steps}
            command={command}
            dirty={
                !!(
                    (clinician?.label ?? '') !== initialClinician.name ||
                    role !== initialClinician.role ||
                    practice !== initialClinician.practice ||
                    when !== initialClinician.when ||
                    location !== initialClinician.location ||
                    registration ||
                    dbi ||
                    falls ||
                    personReason ||
                    whanauDetail ||
                    newName ||
                    newRecommendation ||
                    earlierDate ||
                    tookPart ||
                    whanau ||
                    summary ||
                    source ||
                    items.some(
                        (item) =>
                            item.outcome &&
                            item.outcome !== 'pending_controlled',
                    ) ||
                    newMedicine ||
                    earlier
                )
            }
            validate={validate}
            onSave={save}
            onClose={onClose}
            saveLabel="Record the review"
            successTitle="Review recorded"
            successBlurb={
                <>
                    {changes
                        ? `${changes} ${changes === 1 ? 'recommendation waits' : 'recommendations wait'} for the prescriber and Orders. `
                        : ''}
                    {hidden
                        ? `${hidden} controlled ${hidden === 1 ? 'outcome remains' : 'outcomes remain'} to add. `
                        : ''}
                    Next regular review: {nextLabel}.
                </>
            }
            body={(step, edit) =>
                step === 0 ? (
                    <>
                        {review.revision !== initialRevision && (
                            <Notice
                                warning
                                title="This review was refreshed while you were editing"
                            >
                                Your entries are retained. Check the clinician,
                                current medicines and outcomes before saving.
                            </Notice>
                        )}
                        <ClinicianFields
                            prefix="record"
                            value={clinician}
                            onChange={setClinician}
                            role={role}
                            setRole={setRole}
                            practice={practice}
                            setPractice={setPractice}
                            clientId={review.client_id}
                            errors={errors}
                        />
                        <Field
                            id="record-registration"
                            label="Registration number (optional)"
                        >
                            <Input
                                id="record-registration"
                                value={registration}
                                onChange={(event) =>
                                    setRegistration(event.target.value)
                                }
                            />
                        </Field>
                        <DateTimeField
                            id="record-when"
                            label="When it happened"
                            value={when}
                            onChange={setWhen}
                            error={errors['record-when']}
                            clearable={false}
                        />
                        <ChoiceTiles
                            id="record-location"
                            label="How"
                            value={location}
                            onChange={setLocation}
                            choices={LOCATION_CHOICES}
                            error={errors['record-location']}
                        />
                    </>
                ) : step === 1 ? (
                    <>
                        <ChoiceTiles
                            id="record-person"
                            label={review.client_name}
                            value={tookPart}
                            onChange={setTookPart}
                            error={errors['record-person']}
                            choices={[
                                {
                                    value: 'took',
                                    label: 'Took part',
                                    description:
                                        'The person was there and had a say',
                                    icon: UserRound,
                                },
                                {
                                    value: 'not',
                                    label: 'Did not take part',
                                    description: 'Record why',
                                    icon: XCircle,
                                },
                            ]}
                        />
                        {tookPart === 'not' && (
                            <Field
                                id="record-person-reason"
                                label="Why not"
                                required
                                error={errors['record-person-reason']}
                            >
                                <Textarea
                                    id="record-person-reason"
                                    value={personReason}
                                    onChange={(event) =>
                                        setPersonReason(event.target.value)
                                    }
                                    rows={2}
                                />
                            </Field>
                        )}
                        <ChoiceTiles
                            id="record-whanau"
                            label="Whānau, welfare guardian or EPOA"
                            value={whanau}
                            onChange={setWhanau}
                            error={errors['record-whanau']}
                            choices={[
                                {
                                    value: 'took',
                                    label: 'Took part',
                                    description: 'In person, by phone or video',
                                    icon: Users,
                                },
                                {
                                    value: 'told',
                                    label: 'Told afterwards',
                                    description: 'Record who and how',
                                    icon: Phone,
                                },
                                {
                                    value: 'none',
                                    label: 'Not involved',
                                    description: 'Record why',
                                    icon: XCircle,
                                },
                            ]}
                        />
                        {whanau && (
                            <Field
                                id="record-whanau-detail"
                                label={
                                    whanau === 'none'
                                        ? 'Why not'
                                        : 'Who was involved, and how'
                                }
                                required
                                error={errors['record-whanau-detail']}
                            >
                                <Textarea
                                    id="record-whanau-detail"
                                    value={whanauDetail}
                                    onChange={(event) =>
                                        setWhanauDetail(event.target.value)
                                    }
                                    rows={2}
                                />
                            </Field>
                        )}
                    </>
                ) : step === 2 ? (
                    <>
                        {hidden > 0 && (
                            <Notice
                                warning
                                title="Controlled outcomes stay pending"
                            >
                                You can record the permitted medicines. A lead
                                with controlled-medicine access adds the
                                remaining outcomes. They are not marked
                                Continue.
                            </Notice>
                        )}
                        <div className="space-y-4">
                            {orders.map((order) => (
                                <Card key={order.id} className="gap-3 p-4">
                                    {order.controlled_hidden ? (
                                        <>
                                            <ConcealedMedicine />
                                            <StatusBadge
                                                variant="warning"
                                                className="w-fit"
                                            >
                                                Outcome to add
                                            </StatusBadge>
                                        </>
                                    ) : (
                                        <>
                                            <div>
                                                <h3 className="text-sm font-semibold">
                                                    {order.name}
                                                </h3>
                                                <p className="text-caption">
                                                    {[
                                                        order.dosage,
                                                        order.frequency,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <OutcomeFields
                                                prefix={`medicine-${order.id}`}
                                                value={
                                                    items.find(
                                                        (item) =>
                                                            item.client_medication_id ===
                                                            order.id,
                                                    )!
                                                }
                                                onChange={(patch) =>
                                                    updateItem(order.id, patch)
                                                }
                                                errors={errors}
                                            />
                                        </>
                                    )}
                                </Card>
                            ))}
                        </div>
                        {!orders.length && (
                            <Notice title="No current medicines">
                                You can still record the review. Add a newly
                                recommended medicine below if the clinician
                                recommended one.
                            </Notice>
                        )}
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="record-new"
                                checked={newMedicine}
                                onCheckedChange={(checked) =>
                                    setNewMedicine(checked === true)
                                }
                            />
                            <Label htmlFor="record-new">
                                The clinician recommended a new medicine
                            </Label>
                        </div>
                        {newMedicine && (
                            <>
                                <Field
                                    id="record-new-name"
                                    label="Medicine"
                                    required
                                    error={errors['record-new-name']}
                                >
                                    <Input
                                        id="record-new-name"
                                        value={newName}
                                        onChange={(event) =>
                                            setNewName(event.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="record-new-recommendation"
                                    label="What the clinician recommends"
                                    required
                                    error={errors['record-new-recommendation']}
                                >
                                    <Textarea
                                        id="record-new-recommendation"
                                        value={newRecommendation}
                                        onChange={(event) =>
                                            setNewRecommendation(
                                                event.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </Field>
                            </>
                        )}
                        <Notice title="Recommendations go through Orders">
                            The prescriber’s decision is recorded separately. An
                            agreed change must be entered and independently
                            checked in Orders before it affects the chart.
                        </Notice>
                    </>
                ) : step === 3 ? (
                    <>
                        <Field
                            id="record-summary"
                            label="In the clinician’s words"
                            required
                            error={errors['record-summary']}
                        >
                            <Textarea
                                id="record-summary"
                                value={summary}
                                onChange={(event) =>
                                    setSummary(event.target.value)
                                }
                                rows={5}
                            />
                        </Field>
                        <Field
                            id="record-source"
                            label={
                                <span id="record-source-label">
                                    Written review or letter (optional)
                                </span>
                            }
                            error={errors.source}
                        >
                            <SourceUpload
                                id="record-source"
                                file={source}
                                onChange={setSource}
                                error={errors.source}
                                processing={command.processing}
                            />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="record-dbi"
                                label="Drug burden index — the clinician’s (optional)"
                                error={errors['record-dbi']}
                            >
                                <Input
                                    id="record-dbi"
                                    inputMode="decimal"
                                    value={dbi}
                                    onChange={(event) =>
                                        setDbi(event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                id="record-falls"
                                label="Falls in the last quarter — their note (optional)"
                                error={errors['record-falls']}
                            >
                                <Input
                                    id="record-falls"
                                    inputMode="numeric"
                                    value={falls}
                                    onChange={(event) =>
                                        setFalls(event.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <Notice title="Private clinical source">
                            Summary, letter and participation free text follow
                            the review’s access rules. The structured permitted
                            medicine outcomes remain available on the person’s
                            record.
                        </Notice>
                    </>
                ) : step === 4 ? (
                    <>
                        <Card className="gap-2 p-4">
                            <h3 className="text-section-title">
                                {review.review_type === 'regular'
                                    ? 'The next regular review is booked automatically'
                                    : 'The regular cycle carries on'}
                            </h3>
                            <p className="text-sm">
                                {cadenceLabel(cadence.months)} ·{' '}
                                {cadence.own
                                    ? 'Set for this person'
                                    : 'Organisation default'}
                            </p>
                            <p className="text-sm font-semibold">
                                Next: {nextLabel}
                            </p>
                            {!cadence.reviewed && (
                                <p className="text-caption">
                                    This interval is not reviewed. It is the
                                    existing default awaiting clinical-lead
                                    review.
                                </p>
                            )}
                        </Card>
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="record-earlier-check"
                                checked={earlier}
                                onCheckedChange={(checked) =>
                                    setEarlier(checked === true)
                                }
                            />
                            <Label htmlFor="record-earlier-check">
                                The clinician asked for an earlier review
                            </Label>
                        </div>
                        {earlier && (
                            <Field
                                id="record-earlier"
                                label="Earlier review date"
                                required
                                error={errors['record-earlier']}
                            >
                                <DatePicker
                                    id="record-earlier"
                                    label="Earlier review date"
                                    value={earlierDate}
                                    onChange={setEarlierDate}
                                    invalid={!!errors['record-earlier']}
                                />
                            </Field>
                        )}
                    </>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={Stethoscope}
                            title="Who did it"
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
                                label="How"
                                value={
                                    location ? LOCATION_LABELS[location] : '—'
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Users}
                            title="Who took part"
                            onEdit={() => edit(1)}
                        >
                            <ReviewRow
                                label="The person"
                                value={
                                    tookPart === 'took'
                                        ? 'Took part'
                                        : `Did not take part — ${personReason}`
                                }
                            />
                            <ReviewRow
                                label="Whānau"
                                value={`${whanau === 'took' ? 'Took part' : whanau === 'told' ? 'Told afterwards' : 'Not involved'} — ${whanauDetail}`}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Pill}
                            title="Medicines"
                            onEdit={() => edit(2)}
                        >
                            {items.map((item) => (
                                <ReviewRow
                                    key={item.client_medication_id}
                                    label={
                                        orders.find(
                                            (order) =>
                                                order.id ===
                                                item.client_medication_id,
                                        )?.name ?? 'Medicine'
                                    }
                                    value={
                                        item.outcome
                                            ? OUTCOME_LABELS[item.outcome]
                                            : 'Not chosen'
                                    }
                                />
                            ))}
                            {newMedicine && (
                                <ReviewRow
                                    label={newName}
                                    value={newRecommendation}
                                />
                            )}
                        </ReviewCard>
                        <ReviewCard
                            icon={FileText}
                            title="Summary & letter"
                            onEdit={() => edit(3)}
                        >
                            <ReviewRow label="Summary" value={summary} />
                            <ReviewRow
                                label="Source"
                                value={source?.name ?? 'No source attached'}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Next review"
                            span
                            onEdit={() => edit(4)}
                        >
                            <ReviewRow label="Due by" value={nextLabel} />
                            <ReviewRow
                                label="How often"
                                value={cadenceLabel(cadence.months)}
                            />
                        </ReviewCard>
                        <div className="sm:col-span-2">
                            <Notice title="When you save">
                                The review and its outcomes are kept with who
                                recorded them.{' '}
                                {changes
                                    ? `${changes} recommendations await the prescriber and checked Orders. `
                                    : ''}
                                {hidden
                                    ? `${hidden} controlled outcomes remain pending. `
                                    : ''}
                                {review.review_type === 'regular' || earlier
                                    ? 'The next regular review is booked or moved in the same save.'
                                    : 'The existing regular review cycle carries on.'}
                            </Notice>
                        </div>
                    </div>
                )
            }
        />
    );
}
