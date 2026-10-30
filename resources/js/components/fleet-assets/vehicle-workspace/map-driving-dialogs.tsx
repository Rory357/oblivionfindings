import {
    DateTimeField,
    localDateTimeLabel,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import {
    BarChart3,
    ClipboardCheck,
    FileCheck2,
    FileText,
    Gauge,
    MapPin,
    ShieldCheck,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { PersonPicker } from './choice-picker';
import { uploadSummary, type UploadOutcome } from './evidence-upload';
import {
    km,
    LIMIT_PRESETS,
    limitError,
    policyBadge,
    policyError,
    policyFormFrom,
    policyFormula,
    REVIEW_OUTCOMES,
    type LimitForm,
    type PolicyForm,
} from './map-driving-model';
import type {
    DrivingInsights,
    DrivingPolicy,
    SpeedLimit,
    TripReviewEvent,
} from './map-driving-types';
import { InsightModal, ModalRow, whenLabel } from './map-insights-kit';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import type { Person } from './types';
import {
    fieldProps,
    StagedFilesField,
    StudioNotice,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { todayInAuckland } from './workspace-model';

type VehicleLabel = { id: number; name: string; registration: string | null };

function ConfirmBox({
    id,
    checked,
    onChange,
    error,
    children,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1">
            <div className="flex items-start gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    aria-invalid={!!error}
                    aria-describedby={error ? `${id}-error` : undefined}
                    onCheckedChange={(value) => onChange(value === true)}
                />
                <Label htmlFor={id} className="leading-snug font-normal">
                    {children}
                </Label>
            </div>
            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="text-xs text-status-critical"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

/** "How the score is calculated" for the current selection and policy. */
export function ScoreRulesDialog({
    insights,
    onClose,
}: {
    insights: DrivingInsights;
    onClose: () => void;
}) {
    const { policy, score } = insights;
    const eligible = insights.trips.filter((trip) => trip.score !== null);
    return (
        <InsightModal
            title="How the score is calculated"
            description={`${policyBadge(policy)} · ${policy.source === 'published' ? `published ${formatDateTime(policy.published_at)}${policy.published_by ? ` by ${policy.published_by}` : ''}` : 'fleet settings until a version is published'}`}
            icon={BarChart3}
            onClose={onClose}
            footer={
                <Button variant="outline" onClick={onClose}>
                    Done
                </Button>
            }
        >
            <StudioNotice title="Vehicle insight, not a confirmed driver rating">
                Sensor events and driver identity need review. An alert still
                goes to Control Room when a score is withheld.
            </StudioNotice>
            <h3 className="vehicle-insight-heading">
                1. Calculate each eligible trip
            </h3>
            <p className="score-formula">{policyFormula(policy)}</p>
            <p className="vehicle-insight-text">
                Clamp at zero. One continuous overspeed episode counts once,
                including duplicate reports. Cornering and unclassified harsh
                events take the acceleration weight, and idle time counts only
                while the ignition is known to be on.
            </p>
            {eligible.map((trip) => (
                <ModalRow
                    key={trip.id}
                    label={`${trip.reference} · ${km(trip.distance_km)} · ${trip.coverage_pct ?? 0}% coverage`}
                    value={`Reviewed score: ${trip.score} · original ${trip.harsh.braking} braking / ${trip.harsh.acceleration} acceleration / ${trip.overspeed_episodes} overspeed`}
                />
            ))}
            {!eligible.length && (
                <p className="vehicle-insight-text">
                    No trip in this selection has enough coverage to score.
                </p>
            )}
            <h3 className="vehicle-insight-heading">2. Weight by distance</h3>
            <p className="vehicle-insight-text">
                Sum each eligible trip score × trip kilometres, then divide by
                eligible kilometres. Current selection: {score.value ?? '—'}/100
                over {km(score.eligible_km)}. Trips below{' '}
                {policy.min_score_coverage_pct}% coverage do not contribute.
            </p>
            <h3 className="vehicle-insight-heading">
                3. Attribute only with evidence
            </h3>
            <p className="vehicle-insight-text">
                A person needs confirmed driving identity and enough eligible
                journeys. Missing data, unassigned trips, vehicle faults and
                potential collisions do not silently become driving deductions.
                Reviewed false events need an auditable correction and
                recalculation. Use Reviews & coaching to record the decision.
            </p>
            <ModalRow
                label="Overspeed episodes"
                value={`At or above the ${policy.speed_threshold_kph} km/h fleet setting, measured between recorded samples`}
            />
            <ModalRow
                label="Road-limit overspeeding"
                value="Requires verified road, direction, limit source and effective time"
            />
            <p className="vehicle-insight-text text-caption">
                Severity weighting, exposure normalisation, minimum samples and
                weights must be validated before a driver score is used
                operationally.
            </p>
        </InsightModal>
    );
}

const REVIEW_STEPS = [
    {
        key: 'evidence',
        label: 'Event evidence',
        blurb: 'Outcome, owner and reason',
        icon: ClipboardCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the resulting record',
        icon: FileCheck2,
    },
];

/** Review one recorded driving event; the recorded telemetry stays intact. */
export function ReviewEventWizard({
    vehicle,
    trip,
    event,
    people,
    onClose,
    onSaved,
}: {
    vehicle: VehicleLabel;
    trip: { id: number; reference: string };
    event: TripReviewEvent;
    people: Person[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({
        outcome: event.review?.outcome ?? '',
        owner: null as number | null,
        reason: '',
        confirmed: false,
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const server = command.errors;
    const errors = {
        outcome: local.outcome ?? server.outcome ?? server.event_key,
        owner: local.owner ?? server.review_owner_user_id,
        reason: local.reason ?? server.reason,
        confirmed: local.confirmed ?? server.confirmed,
    };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocal((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(
            key === 'owner' ? 'review_owner_user_id' : (key as string),
        );
    };
    useEffect(() => {
        if (Object.keys(server).length) setStep(0);
    }, [server]);
    const validate = () => {
        const found: Record<string, string> = {};
        if (!form.outcome) found.outcome = 'Choose the review outcome.';
        if (!form.owner) found.owner = 'Choose the review owner.';
        if (!form.reason.trim())
            found.reason =
                'Record the evidence and the reason for this outcome.';
        if (!form.confirmed)
            found.confirmed =
                'Confirm that you reviewed the event evidence and scoring impact.';
        setLocal(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/driving/trips/${trip.id}/reviews`,
            {
                event_key: event.key,
                outcome: form.outcome,
                reason: form.reason.trim(),
                review_owner_user_id: form.owner,
                confirmed: form.confirmed,
                expected_version: event.version,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const outcome = REVIEW_OUTCOMES.find((item) => item.value === form.outcome);
    const owner = people.find((person) => person.id === form.owner);
    const context = `${trip.reference} · ${event.title} · ${whenLabel(event.at)}`;

    return (
        <WorkspaceWizard
            title="Review driving event"
            description={`${vehicle.name}: record the outcome of a recorded event. The original telemetry stays intact.`}
            railIcon={ClipboardCheck}
            railSub={vehicle.registration ?? ''}
            steps={REVIEW_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.outcome,
                    !!form.owner,
                    !!form.reason.trim(),
                    form.confirmed,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{ name: vehicle.name, detail: context }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Save review & recalculate"
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Event review recorded"
                    blurb="Dismissed events no longer deduct points. Disputed events withhold the trip score. Control Room alerts stay open until their separate response is resolved."
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {context}. {event.detail}
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="event-outcome"
                            label="Review outcome"
                            error={errors.outcome}
                            hint={outcome?.detail}
                        >
                            <VehicleSearchSelect
                                id="event-outcome"
                                label="Review outcome"
                                value={form.outcome}
                                options={REVIEW_OUTCOMES.map((item) => ({
                                    value: item.value,
                                    label: item.label,
                                    description: item.detail,
                                }))}
                                onChange={(value) =>
                                    update(
                                        'outcome',
                                        value as typeof form.outcome,
                                    )
                                }
                                invalid={!!errors.outcome}
                                describedBy={
                                    errors.outcome
                                        ? 'event-outcome-error'
                                        : undefined
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="event-owner"
                            label="Review owner"
                            error={errors.owner}
                            hint="Current staff at this vehicle's site."
                        >
                            <PersonPicker
                                id="event-owner"
                                label="Review owner"
                                value={form.owner}
                                people={people}
                                onChange={(value) => update('owner', value)}
                                invalid={!!errors.owner}
                                describedBy={
                                    errors.owner
                                        ? 'event-owner-error'
                                        : undefined
                                }
                            />
                        </WizardField>
                    </div>
                    <WizardField
                        id="event-reason"
                        label="Evidence and review reason"
                        error={errors.reason}
                    >
                        <Textarea
                            {...fieldProps('event-reason', errors.reason)}
                            rows={4}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(change) =>
                                update('reason', change.target.value)
                            }
                        />
                    </WizardField>
                    <ConfirmBox
                        id="event-confirmed"
                        checked={form.confirmed}
                        onChange={(value) => update('confirmed', value)}
                        error={errors.confirmed}
                    >
                        I reviewed the event evidence and scoring impact
                    </ConfirmBox>
                </div>
            )}
            {step === 1 && (
                <ReviewCard
                    icon={ClipboardCheck}
                    title="Event review"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow label="Event" value={context} />
                    <ReviewRow label="Outcome" value={outcome?.label} />
                    <ReviewRow label="Review owner" value={owner?.name} />
                    <ReviewRow
                        label="Reason"
                        value={form.reason.trim() || undefined}
                    />
                    <ReviewRow
                        label="Previous outcome"
                        value={
                            event.review
                                ? REVIEW_OUTCOMES.find(
                                      (item) =>
                                          item.value === event.review?.outcome,
                                  )?.label
                                : 'Unreviewed'
                        }
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

const POLICY_STEPS = [
    {
        key: 'weights',
        label: 'Weights',
        blurb: 'Points per trip event',
        icon: SlidersHorizontal,
    },
    {
        key: 'eligibility',
        label: 'Eligibility',
        blurb: 'Coverage and a person’s minimums',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Publish the new version',
        icon: FileCheck2,
    },
];

const WEIGHT_FIELDS: Array<[keyof PolicyForm, string]> = [
    ['braking', 'Braking points per event'],
    ['acceleration', 'Acceleration points per event'],
    ['overspeed', 'Overspeed points per episode'],
    ['idle', 'Points per idle minute'],
];

const ELIGIBILITY_FIELDS: Array<[keyof PolicyForm, string]> = [
    ['coverage', 'Minimum trip coverage %'],
    ['trips', 'Minimum confirmed trips'],
    ['distance', 'Minimum confirmed distance · km'],
];

/** Publish a new scoring policy version; the previous version stays in history. */
export function ScorePolicyWizard({
    vehicle,
    policy,
    onClose,
    onSaved,
}: {
    vehicle: VehicleLabel;
    policy: DrivingPolicy;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({
        ...policyFormFrom(policy),
        reason: '',
        confirmed: false,
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const server = command.errors;
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setError('');
        command.clearError(key as string);
    };
    useEffect(() => {
        const keys = Object.keys(server);
        if (keys.length)
            setStep(
                keys.some((key) =>
                    ['braking', 'acceleration', 'overspeed', 'idle'].includes(
                        key,
                    ),
                )
                    ? 0
                    : 1,
            );
    }, [server]);
    const validateStep = (at: number) => {
        if (at === 0) {
            const bad = WEIGHT_FIELDS.some(([key]) => {
                const value = String(form[key]).trim();
                return (
                    value === '' ||
                    !Number.isFinite(Number(value)) ||
                    Number(value) < 0 ||
                    Number(value) > 100
                );
            });
            setError(bad ? 'Use non-negative weights up to 100.' : '');
            return !bad;
        }
        if (at === 1) {
            const message =
                policyError(form) ||
                (!form.reason.trim()
                    ? 'Record why this version is needed.'
                    : '') ||
                (!form.confirmed
                    ? 'Confirm that you reviewed the scoring impact.'
                    : '');
            setError(message);
            return !message;
        }
        return true;
    };
    const submit = async () => {
        if (!command.uncertain && (!validateStep(0) || !validateStep(1))) {
            setStep(
                policyError(form) || !form.reason.trim() || !form.confirmed
                    ? 1
                    : 0,
            );
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/driving/policy`,
            {
                braking: Number(form.braking),
                acceleration: Number(form.acceleration),
                overspeed: Number(form.overspeed),
                idle: Number(form.idle),
                coverage: Number(form.coverage),
                trips: Number(form.trips),
                distance: Number(form.distance),
                reason: form.reason.trim(),
                confirmed: form.confirmed,
                expected_version: policy.version,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const fieldError = (key: string) => server[key];
    const numberField = ([key, label]: [keyof PolicyForm, string]) => (
        <WizardField
            key={key}
            id={`policy-${key}`}
            label={label}
            error={fieldError(key)}
        >
            <Input
                {...fieldProps(`policy-${key}`, fieldError(key))}
                type="number"
                inputMode="decimal"
                min={0}
                step="any"
                value={form[key]}
                onChange={(change) => update(key, change.target.value)}
            />
        </WizardField>
    );

    return (
        <WorkspaceWizard
            title="Review score policy"
            description={`${vehicle.name}: a new version rescores trips everywhere they are scored and keeps the previous policy in history.`}
            railIcon={SlidersHorizontal}
            railSub={policyBadge(policy)}
            steps={POLICY_STEPS}
            step={step}
            setStep={(next) => {
                setError('');
                setStep(next);
            }}
            pct={Math.round(
                ([
                    ...WEIGHT_FIELDS.map(
                        ([key]) => String(form[key]).trim() !== '',
                    ),
                    ...ELIGIBILITY_FIELDS.map(
                        ([key]) => String(form[key]).trim() !== '',
                    ),
                    !!form.reason.trim(),
                    form.confirmed,
                ].filter(Boolean).length /
                    9) *
                    100,
            )}
            context={{
                name: 'Trip scoring policy',
                detail: `${policyBadge(policy)} · applies to every vehicle`,
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Publish policy version"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify({ error, server })}
            success={
                <WizardSuccess
                    title="Scoring policy published"
                    blurb="The new version scores trips in Driving insights, Trip history and exports. Earlier versions and the reviews made under them stay in its history."
                    onClose={onClose}
                />
            }
        >
            {error && (
                <p role="alert" className="mb-4 text-sm text-status-critical">
                    {error}
                </p>
            )}
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Trip deductions. A new version recalculates scores and
                        retains the previous policy in history.
                    </p>
                    <div className="vehicle-wizard-fields">
                        {WEIGHT_FIELDS.map(numberField)}
                    </div>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        A person’s score needs confirmed whole-trip attribution,
                        reviewed events and enough comparable data.
                    </p>
                    <div className="vehicle-wizard-fields">
                        {ELIGIBILITY_FIELDS.map(numberField)}
                    </div>
                    <WizardField
                        id="policy-reason"
                        label="Reason for the new version"
                        error={server.reason}
                    >
                        <Textarea
                            {...fieldProps('policy-reason', server.reason)}
                            rows={3}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(change) =>
                                update('reason', change.target.value)
                            }
                        />
                    </WizardField>
                    <ConfirmBox
                        id="policy-confirmed"
                        checked={form.confirmed}
                        onChange={(value) => update('confirmed', value)}
                        error={server.confirmed}
                    >
                        I reviewed the scoring impact of this version
                    </ConfirmBox>
                </div>
            )}
            {step === 2 && (
                <ReviewCard
                    icon={SlidersHorizontal}
                    title="New policy version"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow label="Replaces" value={policyBadge(policy)} />
                    <ReviewRow
                        label="Deductions"
                        value={`braking ${form.braking} · acceleration ${form.acceleration} · overspeed ${form.overspeed} · idle ${form.idle}/min`}
                    />
                    <ReviewRow
                        label="Eligibility"
                        value={`${form.coverage}% coverage · ${form.trips} trips · ${form.distance} km`}
                    />
                    <ReviewRow
                        label="Reason"
                        value={form.reason.trim() || undefined}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

type SubmitForm = (
    url: string,
    data: FormData,
) => Promise<Record<string, unknown> | null>;

/** Upload evidence files for a manual speed limit into the vehicle's private store. */
async function uploadLimitEvidence(
    submit: SubmitForm,
    vehicleId: number,
    limitId: number,
    files: File[],
    reason: string,
): Promise<UploadOutcome | null> {
    if (!files.length) return { uploaded: 0, waiting: 0, blocked: 0 };
    const form = new FormData();
    form.append('category', 'Speed limit evidence');
    form.append('document_date', todayInAuckland());
    form.append('reason', reason);
    form.append('source_type', 'speed_limit');
    form.append('source_id', String(limitId));
    files.forEach((file) => form.append('files[]', file));
    const result = await submit(
        `/fleet-assets/vehicles/${vehicleId}/documents`,
        form,
    );
    if (!result || !Array.isArray(result.files)) return null;
    const states = result.files.map((file) =>
        isJsonObject(file) ? String(file.state) : '',
    );
    return {
        uploaded: states.filter((state) => state === 'available').length,
        waiting: states.filter((state) =>
            [
                'scan_unavailable',
                'publication_failed',
                'stored',
                'reserved',
            ].includes(state),
        ).length,
        blocked: states.filter((state) =>
            ['quarantined', 'storage_failed'].includes(state),
        ).length,
    };
}

const LIMIT_STEPS = [
    {
        key: 'road',
        label: 'Road and validity',
        blurb: 'Road, direction, limit and dates',
        icon: MapPin,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Authority and sign evidence',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Submit for approval',
        icon: FileCheck2,
    },
];

/** Propose a manual speed limit; it stays pending until someone else approves it. */
export function ManualLimitWizard({
    vehicle,
    directions,
    segments,
    canUpload,
    onClose,
    onSaved,
}: {
    vehicle: VehicleLabel;
    directions: string[];
    segments: string[];
    canUpload: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({
        road_segment: '',
        direction: '',
        limit_kph: '',
        effective_from_local: '',
        expires_at_local: '',
        reason: '',
    }));
    const [form, setForm] = useState<LimitForm & { reason: string }>(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const upload = useVehicleRecordCommand(isJsonObject);
    const server = command.errors;
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setError('');
        command.clearError(key as string);
    };
    useEffect(() => {
        const keys = Object.keys(server);
        if (keys.length) setStep(keys.includes('reason') ? 1 : 0);
    }, [server]);
    const validateStep = (at: number) => {
        const message =
            at === 0
                ? limitError(form)
                : at === 1
                  ? !form.reason.trim()
                      ? 'Record the authority reference and reason.'
                      : canUpload && !files.length
                        ? 'Add the sign photo or authority document.'
                        : ''
                  : '';
        setError(message);
        return !message;
    };
    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/driving/speed-limits`,
            {
                road_segment: form.road_segment.trim(),
                direction: form.direction,
                limit_kph: Number(form.limit_kph),
                effective_from_local: form.effective_from_local,
                expires_at_local: form.expires_at_local,
                reason: form.reason.trim(),
            },
        );
        if (!result || !isJsonObject(result.limit)) return;
        const outcome = await uploadLimitEvidence(
            upload.submit,
            vehicle.id,
            Number(result.limit.id),
            files,
            form.reason.trim(),
        );
        setSaved(uploadSummary(outcome, files.length));
        onSaved();
    };
    const segmentOptions = [
        ...segments,
        ...(form.road_segment && !segments.includes(form.road_segment)
            ? [form.road_segment]
            : []),
    ].map((segment) => ({ value: segment, label: segment }));
    const presets = [
        ...LIMIT_PRESETS,
        ...(form.limit_kph && !LIMIT_PRESETS.includes(form.limit_kph)
            ? [form.limit_kph]
            : []),
    ];

    return (
        <WorkspaceWizard
            title="Add manual speed limit"
            description={`${vehicle.name}: an evidence-backed road limit for this vehicle's evaluations. It does not apply until someone else approves it.`}
            railIcon={Gauge}
            railSub={vehicle.registration ?? ''}
            steps={LIMIT_STEPS}
            step={step}
            setStep={(next) => {
                setError('');
                setStep(next);
            }}
            pct={Math.round(
                ([
                    !!form.road_segment.trim(),
                    !!form.direction,
                    !!form.limit_kph,
                    !!form.effective_from_local,
                    !!form.expires_at_local,
                    !!form.reason.trim(),
                ].filter(Boolean).length /
                    6) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: 'Pacific/Auckland · pending until reviewed',
            }}
            command={command}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={saved !== null}
            submitLabel="Submit for review"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify({ error, server })}
            success={
                <WizardSuccess
                    title="Manual speed limit submitted"
                    blurb={`It is not active until someone other than you reviews and approves it.${saved ?? ''}`}
                    onClose={onClose}
                />
            }
        >
            {error && (
                <p role="alert" className="mb-4 text-sm text-status-critical">
                    {error}
                </p>
            )}
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Use an evidence-backed road or segment, direction and
                        effective window. The entry stays pending until
                        reviewed.
                    </p>
                    <WizardField
                        id="limit-segment"
                        label="Road or segment"
                        error={server.road_segment}
                        hint="For example the road name and the section the sign covers."
                    >
                        {segmentOptions.length ? (
                            <VehicleSearchSelect
                                id="limit-segment"
                                label="Road or segment"
                                value={form.road_segment}
                                options={segmentOptions}
                                onChange={(value) =>
                                    update('road_segment', value)
                                }
                                onAdd={(proposed) =>
                                    proposed && update('road_segment', proposed)
                                }
                                invalid={!!server.road_segment}
                            />
                        ) : (
                            <Input
                                {...fieldProps(
                                    'limit-segment',
                                    server.road_segment,
                                )}
                                maxLength={160}
                                value={form.road_segment}
                                onChange={(change) =>
                                    update('road_segment', change.target.value)
                                }
                            />
                        )}
                    </WizardField>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="limit-direction"
                            label="Direction"
                            error={server.direction}
                        >
                            <VehicleSearchSelect
                                id="limit-direction"
                                label="Direction"
                                value={form.direction}
                                options={directions.map((direction) => ({
                                    value: direction,
                                    label: direction,
                                }))}
                                onChange={(value) => update('direction', value)}
                                invalid={!!server.direction}
                            />
                        </WizardField>
                        <WizardField
                            id="limit-kph"
                            label="Posted or temporary limit · km/h"
                            error={server.limit_kph}
                            hint="Choose a listed limit or add another between 5 and 150."
                        >
                            <VehicleSearchSelect
                                id="limit-kph"
                                label="Speed limit"
                                value={form.limit_kph}
                                options={presets.map((value) => ({
                                    value,
                                    label: `${value} km/h`,
                                }))}
                                onChange={(value) => update('limit_kph', value)}
                                onAdd={(proposed) => {
                                    const value = proposed.replace(/\D/g, '');
                                    if (value) update('limit_kph', value);
                                }}
                                invalid={!!server.limit_kph}
                            />
                        </WizardField>
                    </div>
                    <DateTimeField
                        id="limit-from"
                        label="Effective from"
                        value={form.effective_from_local}
                        onChange={(value) =>
                            update('effective_from_local', value)
                        }
                        error={server.effective_from_local}
                    />
                    <DateTimeField
                        id="limit-until"
                        label="Expires at"
                        value={form.expires_at_local}
                        onChange={(value) => update('expires_at_local', value)}
                        error={server.expires_at_local}
                    />
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Record the sign, road authority notice or approved
                        source. An unsupported manual entry cannot be approved.
                    </p>
                    <WizardField
                        id="limit-reason"
                        label="Authority reference and reason"
                        error={server.reason}
                    >
                        <Textarea
                            {...fieldProps('limit-reason', server.reason)}
                            rows={4}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(change) =>
                                update('reason', change.target.value)
                            }
                        />
                    </WizardField>
                    {canUpload ? (
                        <StagedFilesField
                            label="Sign photo or authority document"
                            files={files}
                            onChange={setFiles}
                            optional={false}
                        />
                    ) : (
                        <StudioNotice title="Evidence needs document access">
                            Someone with vehicle document access must add the
                            sign photo or authority document before this limit
                            can be approved.
                        </StudioNotice>
                    )}
                </div>
            )}
            {step === 2 && (
                <ReviewCard
                    icon={Gauge}
                    title="Manual speed limit"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow
                        label="Limit"
                        value={
                            form.limit_kph
                                ? `${form.limit_kph} km/h · ${form.road_segment} · ${form.direction}`
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Applies"
                        value={`${localDateTimeLabel(form.effective_from_local)} → ${localDateTimeLabel(form.expires_at_local)}`}
                    />
                    <ReviewRow
                        label="Evidence"
                        value={form.reason.trim() || undefined}
                    />
                    <ReviewRow
                        label="Files"
                        value={
                            files.length
                                ? files.map((file) => file.name).join(', ')
                                : 'None yet'
                        }
                    />
                    <ReviewRow label="Status" value="Pending review" />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

const DECISION_STEPS = [
    {
        key: 'scope',
        label: 'Scope and source review',
        blurb: 'Authority, road, direction and dates',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the decision',
        icon: FileCheck2,
    },
];

/** Approve a pending manual limit, or retire an approved one. */
export function LimitDecisionWizard({
    vehicle,
    limit,
    action,
    onClose,
    onSaved,
}: {
    vehicle: VehicleLabel;
    limit: SpeedLimit;
    action: 'approve' | 'retire';
    onClose: () => void;
    onSaved: () => void;
}) {
    const [initial] = useState(() => ({ reason: '', confirmed: false }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [local, setLocal] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = {
        reason: local.reason ?? command.errors.reason,
        confirmed: local.confirmed ?? command.errors.confirmed,
    };
    const approve = action === 'approve';
    const scope = `${limit.reference} · ${limit.road_segment} ${limit.direction} · ${limit.limit_kph} km/h · ${formatDateTime(limit.effective_from)} to ${formatDateTime(limit.expires_at)}`;
    useEffect(() => {
        if (Object.keys(command.errors).length) setStep(0);
    }, [command.errors]);
    const validate = () => {
        const found: Record<string, string> = {};
        if (!form.reason.trim())
            found.reason = 'Record the review decision and reason.';
        if (!form.confirmed)
            found.confirmed = approve
                ? 'Confirm that you verified the authority, road, direction and dates.'
                : 'Confirm that this limit should no longer apply.';
        setLocal(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/driving/speed-limits/${limit.id}/${action}`,
            {
                reason: form.reason.trim(),
                confirmed: form.confirmed,
                expected_version: limit.lock_version,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };

    return (
        <WorkspaceWizard
            title={
                approve ? 'Review manual speed limit' : 'Retire manual limit'
            }
            description={`${vehicle.name}: ${approve ? 'approve this limit for evaluations after checking its evidence' : 'stop this limit applying to new evaluations'}.`}
            railIcon={ShieldCheck}
            railSub={limit.reference}
            steps={DECISION_STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([!!form.reason.trim(), form.confirmed].filter(Boolean).length /
                    2) *
                    100,
            )}
            context={{
                name: limit.reference,
                detail: `${limit.limit_kph} km/h · ${limit.road_segment} · ${limit.direction}`,
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel={approve ? 'Approve limit' : 'Retire limit'}
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={
                        approve
                            ? 'Manual limit approved'
                            : 'Manual limit retired'
                    }
                    blurb={
                        approve
                            ? 'Evaluations use this limit between its start and expiry. Earlier evidence keeps the rule it was assessed under.'
                            : 'New evaluations no longer use this limit. Evidence already recorded with it is kept.'
                    }
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        {scope}. Evidence: {limit.reason}
                    </p>
                    <p className="text-caption">
                        {limit.files.length
                            ? `${limit.files.length} evidence ${limit.files.length === 1 ? 'file' : 'files'}: ${limit.files.map((file) => file.name).join(', ')}.`
                            : 'No evidence file yet. Approval needs the sign photo or authority document.'}{' '}
                        Proposed by {limit.proposed_by ?? 'a former user'}. You
                        are recorded as the reviewer.
                    </p>
                    <WizardField
                        id="limit-decision-reason"
                        label="Review decision and reason"
                        error={errors.reason}
                    >
                        <Textarea
                            {...fieldProps(
                                'limit-decision-reason',
                                errors.reason,
                            )}
                            rows={4}
                            maxLength={2000}
                            value={form.reason}
                            onChange={(change) => {
                                setForm((old) => ({
                                    ...old,
                                    reason: change.target.value,
                                }));
                                setLocal((old) => ({ ...old, reason: '' }));
                                command.clearError('reason');
                            }}
                        />
                    </WizardField>
                    <ConfirmBox
                        id="limit-decision-confirmed"
                        checked={form.confirmed}
                        onChange={(value) => {
                            setForm((old) => ({ ...old, confirmed: value }));
                            setLocal((old) => ({ ...old, confirmed: '' }));
                        }}
                        error={errors.confirmed || undefined}
                    >
                        {approve
                            ? 'I verified the authority, road, direction and dates'
                            : 'This limit should no longer apply to evaluations'}
                    </ConfirmBox>
                </div>
            )}
            {step === 1 && (
                <ReviewCard
                    icon={ShieldCheck}
                    title={approve ? 'Approval' : 'Retirement'}
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow label="Limit" value={scope} />
                    <ReviewRow
                        label="Decision"
                        value={approve ? 'Approve' : 'Retire'}
                    />
                    <ReviewRow
                        label="Reason"
                        value={form.reason.trim() || undefined}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}

/** Add evidence files to a pending or approved manual limit. */
export function LimitEvidenceDialog({
    vehicle,
    limit,
    onClose,
    onSaved,
}: {
    vehicle: VehicleLabel;
    limit: SpeedLimit;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [reason, setReason] = useState('');
    const [error, setError] = useState('');
    const [done, setDone] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        if (!files.length) {
            setError('Add the sign photo or authority document.');
            return;
        }
        if (!reason.trim()) {
            setError('Record what the file shows.');
            return;
        }
        const outcome = await uploadLimitEvidence(
            command.submit,
            vehicle.id,
            limit.id,
            files,
            reason.trim(),
        );
        if (outcome) {
            setDone(uploadSummary(outcome, files.length));
            onSaved();
        }
    };

    return (
        <InsightModal
            title="Add speed limit evidence"
            description={`${limit.reference} · ${limit.limit_kph} km/h · ${limit.road_segment} · ${limit.direction}`}
            icon={FileText}
            size="detail"
            onClose={onClose}
            footer={
                done !== null ? (
                    <Button onClick={onClose}>Done</Button>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={command.processing}
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                command.processing || command.requiresReload
                            }
                            onClick={save}
                        >
                            {command.uncertain
                                ? 'Retry this upload'
                                : 'Upload evidence'}
                        </Button>
                    </>
                )
            }
        >
            {done !== null ? (
                <StudioNotice title="Evidence added">
                    {done.trim() || 'The files are kept with this limit.'}
                </StudioNotice>
            ) : (
                <div className="space-y-4">
                    {(error || command.message) && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error || command.message}
                        </p>
                    )}
                    <StagedFilesField
                        label="Sign photo or authority document"
                        files={files}
                        onChange={(next) => {
                            setFiles(next);
                            setError('');
                        }}
                        optional={false}
                    />
                    <WizardField
                        id="limit-evidence-reason"
                        label="What the file shows"
                    >
                        <Textarea
                            id="limit-evidence-reason"
                            rows={3}
                            maxLength={2000}
                            value={reason}
                            onChange={(change) => {
                                setReason(change.target.value);
                                setError('');
                            }}
                        />
                    </WizardField>
                </div>
            )}
        </InsightModal>
    );
}
