/* eslint-disable no-restricted-syntax -- Record wizard mirrors the Add-Client modal
 * chrome: styled native controls (type tiles, severity segments, witness chips) on
 * semantic design tokens. */
import {
    ActualEventTimeField,
    actualEventInstant,
} from '@/components/clinical/actual-event-time-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    ClientPicker,
    ClinicalCardRail,
    type ClientResult,
} from '@/pages/health-clinical/components/record-wizard-shared';
import { useForm } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Brain,
    Bug,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    HeartPulse,
    Loader2,
    Paperclip,
    Plus,
    ShieldAlert,
    Stethoscope,
    TrendingDown,
    X,
    Zap,
} from 'lucide-react';
import { useEffect, useState, type ComponentType } from 'react';

type EventForm = {
    client_id: string;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    witnesses: string[];
    immediate_action_taken: string;
    outcome: string;
    requires_followup: boolean;
    followup_notes: string;
    attachments: File[];
    hospital_admission_id?: number;
    hospital_discharged_at?: string;
};

const TYPES: {
    key: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    { key: 'fall', label: 'Fall', icon: TrendingDown },
    { key: 'seizure', label: 'Seizure', icon: Zap },
    { key: 'choking', label: 'Choking', icon: AlertOctagon },
    { key: 'deterioration', label: 'Deterioration', icon: HeartPulse },
    {
        key: 'allergic_reaction',
        label: 'Allergic reaction',
        icon: AlertTriangle,
    },
    { key: 'skin_integrity', label: 'Skin integrity', icon: ShieldAlert },
    { key: 'infection_sign', label: 'Sign of infection', icon: Bug },
    { key: 'behavioural_crisis', label: 'Behavioural crisis', icon: Brain },
    { key: 'mental_health_episode', label: 'Mental health', icon: Brain },
    {
        key: 'hospital_admission',
        label: 'Hospital admission',
        icon: Stethoscope,
    },
    {
        key: 'hospital_discharge',
        label: 'Hospital discharge',
        icon: Stethoscope,
    },
    { key: 'other', label: 'Other', icon: ClipboardList },
];

const HS_LINKED = new Set(['fall', 'seizure', 'choking']);

const SEVERITY: { key: string; label: string; tone: string }[] = [
    { key: 'low', label: 'Low', tone: 'text-status-info' },
    { key: 'medium', label: 'Medium', tone: 'text-status-warning' },
    { key: 'high', label: 'High', tone: 'text-status-warning' },
    { key: 'critical', label: 'Critical', tone: 'text-status-critical' },
];

const STEPS: readonly WizardStep[] = [
    {
        key: 'client',
        label: 'Client & type',
        blurb: 'Who & what happened',
        icon: Stethoscope,
    },
    {
        key: 'what',
        label: 'What happened',
        blurb: 'Description & witnesses',
        icon: AlertTriangle,
    },
    {
        key: 'response',
        label: 'Response & evidence',
        blurb: 'Action, follow-up, files',
        icon: ShieldAlert,
    },
    { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: Check },
];

export type RecordEventDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Profile entry point (§8): locks step 1 to this client. */
    client?: ClientResult | null;
    onSaved?: () => void;
};

export function RecordEventDialog(props: RecordEventDialogProps) {
    return props.open ? (
        <Body key={props.client?.id ?? 'module'} {...props} />
    ) : null;
}

function Body({ onClose, client, onSaved }: RecordEventDialogProps) {
    const [picked, setPicked] = useState<ClientResult | null>(client ?? null);
    const lockedClient = client != null;
    const [done, setDone] = useState(false);
    const [stepIndex, setStepIndex] = useState(0);
    const [witnessDraft, setWitnessDraft] = useState('');
    const [offset, setOffset] = useState('');
    const [stayEnded, setStayEnded] = useState(false);
    const [dischargedAt, setDischargedAt] = useState('');
    const [dischargeOffset, setDischargeOffset] = useState('');
    const [admissionId, setAdmissionId] = useState('');
    const [admissions, setAdmissions] = useState<
        { id: number; occurred_at: string }[]
    >([]);
    const [admissionState, setAdmissionState] = useState<
        'loading' | 'ready' | 'error'
    >('loading');
    const [admissionAttempt, setAdmissionAttempt] = useState(0);

    const form = useForm<EventForm>({
        client_id: client ? String(client.id) : '',
        event_type: '',
        severity: 'medium',
        occurred_at: toDatetimeLocal(new Date()),
        description: '',
        witnesses: [],
        immediate_action_taken: '',
        outcome: '',
        requires_followup: false,
        followup_notes: '',
        attachments: [],
    });
    const { data, setData, processing } = form;
    const hsLinked = HS_LINKED.has(data.event_type);
    const closedStay = data.event_type === 'hospital_admission' && stayEnded;
    const eventInstant = actualEventInstant(data.occurred_at, offset);
    const dischargeInstant = actualEventInstant(dischargedAt, dischargeOffset);
    const selectedAdmission = admissions.find(
        (item) => String(item.id) === admissionId,
    );
    const timeError =
        !eventInstant || Date.parse(eventInstant) > Date.now()
            ? 'Choose the actual event time in New Zealand. It cannot be in the future.'
            : undefined;
    const dischargeError = !closedStay
        ? undefined
        : !dischargeInstant || Date.parse(dischargeInstant) > Date.now()
          ? 'Choose the actual discharge time in New Zealand. It cannot be in the future.'
          : eventInstant &&
              Date.parse(dischargeInstant) < Date.parse(eventInstant)
            ? 'Discharge must be at or after the actual admission.'
            : undefined;
    const admissionError =
        data.event_type !== 'hospital_discharge'
            ? undefined
            : admissionState !== 'ready' || !selectedAdmission
              ? 'Choose the hospital admission this discharge ends.'
              : eventInstant &&
                  Date.parse(eventInstant) <
                      Date.parse(selectedAdmission.occurred_at)
                ? 'Discharge must be at or after the actual admission.'
                : undefined;

    const resetHospital = () => {
        setStayEnded(false);
        setDischargedAt('');
        setDischargeOffset('');
        setAdmissionId('');
        setAdmissions([]);
        setAdmissionState('loading');
        form.clearErrors('hospital_admission_id', 'hospital_discharged_at');
    };

    useEffect(() => {
        if (data.event_type !== 'hospital_discharge' || !data.client_id) return;
        const abort = new AbortController();
        fetch('/clients/' + data.client_id + '/clinical/hospital-admissions', {
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
                const payload: unknown = await response.json();
                const rows = (payload as { admissions?: unknown })?.admissions;
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
    }, [data.client_id, data.event_type, admissionAttempt]);

    const choosePatient = (c: ClientResult | null) => {
        if (String(c?.id ?? '') !== data.client_id) resetHospital();
        setPicked(c);
        setData('client_id', c ? String(c.id) : '');
    };

    const addWitness = () => {
        const name = witnessDraft.trim();
        if (name && !data.witnesses.includes(name)) {
            setData('witnesses', [...data.witnesses, name]);
        }
        setWitnessDraft('');
    };
    const addFiles = (files: File[]) =>
        setData('attachments', [...data.attachments, ...files]);
    const removeFile = (i: number) =>
        setData(
            'attachments',
            data.attachments.filter((_, idx) => idx !== i),
        );

    const stepValid = (i: number): boolean => {
        if (i === 0)
            return !!data.client_id && !!data.event_type && !!data.severity;
        if (i === 1)
            return (
                data.description.trim().length > 0 &&
                !timeError &&
                !dischargeError &&
                !admissionError
            );
        if (i === 2)
            return !hsLinked || data.immediate_action_taken.trim().length > 0;
        return true;
    };

    const next = () =>
        stepValid(stepIndex) &&
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = () => {
        if (processing) return;
        const invalidStep = [0, 1, 2].find((i) => !stepValid(i));
        if (invalidStep !== undefined) {
            setStepIndex(invalidStep);
            return;
        }
        form.transform((draft) => ({
            ...draft,
            occurred_at: eventInstant,
            ...(closedStay ? { hospital_discharged_at: dischargeInstant } : {}),
            ...(data.event_type === 'hospital_discharge'
                ? { hospital_admission_id: Number(admissionId) }
                : {}),
        }));
        form.post('/health-clinical/events', {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDone(true);
                onSaved?.();
            },
            onError: (errors) =>
                setStepIndex(
                    errors.client_id || errors.event_type || errors.severity
                        ? 0
                        : Object.keys(errors).some((key) =>
                                /^(immediate_action_taken|outcome|requires_followup|followup_notes|attachments)/.test(
                                    key,
                                ),
                            )
                          ? 2
                          : 1,
                ),
        });
    };

    if (done) {
        return (
            <WizardShell
                open
                onClose={onClose}
                title="Clinical event logged"
                description="The clinical event was recorded."
                railIcon={AlertTriangle}
                railTitle="Log clinical event"
                railSub="Clinical"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Clinical event logged"
                        blurb={
                            hsLinked
                                ? 'Recorded on the client timeline. A Health & Safety event was raised automatically for this event type.'
                                : 'Recorded on the client timeline and the clinical event register.'
                        }
                        actions={
                            <Button type="button" onClick={onClose}>
                                Done
                            </Button>
                        }
                    />
                }
            />
        );
    }

    const isReview = STEPS[stepIndex].key === 'review';

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Log clinical event"
            description="A guided wizard to record a clinical event."
            railIcon={AlertTriangle}
            railTitle="Log clinical event"
            railSub="Clinical"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => i <= stepIndex && setStepIndex(i)}
            railExtra={
                <ClinicalCardRail
                    clientId={data.client_id ? Number(data.client_id) : null}
                />
            }
            footerStart={
                stepIndex > 0 ? (
                    <Button type="button" variant="ghost" onClick={back}>
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isReview ? (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing}
                        >
                            {processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : (
                                <Check className="h-4 w-4" />
                            )}
                            Log event
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={next}
                            disabled={!stepValid(stepIndex)}
                        >
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {Object.keys(form.errors).length > 0 && (
                <div
                    role="alert"
                    className="rounded-lg border border-status-critical/30 bg-status-critical/5 p-3 text-sm text-status-critical"
                >
                    <p className="font-semibold">
                        The event has not been saved. Check these details:
                    </p>
                    <ul className="mt-1 list-inside list-disc">
                        {Object.entries(form.errors).map(([key, message]) => (
                            <li key={key}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}
            {STEPS[stepIndex].key === 'client' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Stethoscope}
                        title="What happened, and to whom?"
                        blurb="Pick the client, the event type and how severe it was."
                    />
                    <div className="grid gap-5">
                        <Field label="Client" required>
                            <ClientPicker
                                value={picked}
                                onChange={
                                    lockedClient ? () => {} : choosePatient
                                }
                            />
                        </Field>
                        <Field label="Event type" required>
                            <TilePicker
                                value={data.event_type}
                                onChange={(v) => {
                                    if (v !== data.event_type) resetHospital();
                                    setData('event_type', v);
                                }}
                                cols={3}
                                options={TYPES.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    icon: t.icon,
                                }))}
                            />
                        </Field>
                        <Field label="Severity" required>
                            <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                                {SEVERITY.map((s) => {
                                    const active = data.severity === s.key;
                                    return (
                                        <button
                                            key={s.key}
                                            type="button"
                                            onClick={() =>
                                                setData('severity', s.key)
                                            }
                                            className={cn(
                                                'rounded-md px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                                                active
                                                    ? cn(
                                                          'bg-card shadow-sm',
                                                          s.tone,
                                                      )
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {s.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                        {hsLinked ? (
                            <InfoCard icon={ShieldAlert} tone="warn">
                                Falls, seizures and choking automatically raise
                                a linked{' '}
                                <strong>Health &amp; Safety event</strong> (with
                                a WorkSafe-notifiable check) when you log this.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'what' ? (
                <WizardStepPane>
                    <StepHead
                        icon={AlertTriangle}
                        title="What happened"
                        blurb="Describe the event, when it occurred and who witnessed it."
                    />
                    <div className="grid gap-4">
                        <Field label="Description" required>
                            <Textarea
                                aria-label="Description"
                                rows={4}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                placeholder="Describe what happened, in clinical detail."
                            />
                        </Field>
                        <ActualEventTimeField
                            id="clinical-event-occurred-at"
                            label={
                                data.event_type === 'hospital_admission'
                                    ? 'Actually admitted'
                                    : data.event_type === 'hospital_discharge'
                                      ? 'Actually discharged'
                                      : 'When it happened'
                            }
                            value={data.occurred_at}
                            offset={offset}
                            onChange={(value, nextOffset) => {
                                setData('occurred_at', value);
                                setOffset(nextOffset);
                                form.clearErrors('occurred_at');
                            }}
                            error={form.errors.occurred_at || timeError}
                        />
                        {data.event_type === 'hospital_admission' && (
                            <div className="space-y-3 rounded-lg border border-border p-4">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="clinical-event-stay-ended"
                                        checked={stayEnded}
                                        onCheckedChange={(value) => {
                                            setStayEnded(value === true);
                                            form.clearErrors(
                                                'hospital_discharged_at',
                                            );
                                        }}
                                    />
                                    <Label htmlFor="clinical-event-stay-ended">
                                        This hospital stay has already ended
                                    </Label>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Record the actual admission and discharge
                                    together for a past stay. Only that interval
                                    is marked away.
                                </p>
                                {stayEnded && (
                                    <ActualEventTimeField
                                        id="clinical-event-discharged-at"
                                        label="Actually discharged"
                                        value={dischargedAt}
                                        offset={dischargeOffset}
                                        onChange={(value, nextOffset) => {
                                            setDischargedAt(value);
                                            setDischargeOffset(nextOffset);
                                            form.clearErrors(
                                                'hospital_discharged_at',
                                            );
                                        }}
                                        error={
                                            form.errors
                                                .hospital_discharged_at ||
                                            dischargeError
                                        }
                                    />
                                )}
                            </div>
                        )}
                        {data.event_type === 'hospital_discharge' && (
                            <div className="space-y-2 rounded-lg border border-border p-4">
                                <Label htmlFor="clinical-event-admission">
                                    Admission being ended
                                </Label>
                                {admissionState === 'loading' ? (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Loading current hospital admissions…
                                    </p>
                                ) : admissionState === 'error' ? (
                                    <div className="space-y-2">
                                        <p
                                            role="alert"
                                            className="text-sm text-status-critical"
                                        >
                                            Hospital admissions could not be
                                            loaded. Your draft is kept.
                                        </p>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => {
                                                setAdmissionState('loading');
                                                setAdmissionAttempt(
                                                    (n) => n + 1,
                                                );
                                            }}
                                        >
                                            Try again
                                        </Button>
                                    </div>
                                ) : admissions.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No open hospital admission is recorded
                                        for this person. Record the actual
                                        admission before its discharge.
                                    </p>
                                ) : (
                                    <Select
                                        value={admissionId}
                                        onValueChange={(value) => {
                                            setAdmissionId(value);
                                            form.clearErrors(
                                                'hospital_admission_id',
                                            );
                                        }}
                                    >
                                        <SelectTrigger id="clinical-event-admission">
                                            <SelectValue placeholder="Choose the admission" />
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
                                                    )}{' '}
                                                    · record {item.id}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {(form.errors.hospital_admission_id ||
                                    admissionError) && (
                                    <p
                                        role="alert"
                                        className="text-sm text-status-critical"
                                    >
                                        {form.errors.hospital_admission_id ||
                                            admissionError}
                                    </p>
                                )}
                            </div>
                        )}
                        <Field label="Witnesses" hint="staff or others present">
                            <div className="flex flex-col gap-2">
                                <div className="flex gap-2">
                                    <Input
                                        value={witnessDraft}
                                        onChange={(e) =>
                                            setWitnessDraft(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                                addWitness();
                                            }
                                        }}
                                        placeholder="Add a witness name…"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={addWitness}
                                    >
                                        <Plus className="h-4 w-4" /> Add
                                    </Button>
                                </div>
                                {data.witnesses.length ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {data.witnesses.map((w, i) => (
                                            <span
                                                key={i}
                                                className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2.5 py-1 text-[13px]"
                                            >
                                                {w}
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setData(
                                                            'witnesses',
                                                            data.witnesses.filter(
                                                                (_, idx) =>
                                                                    idx !== i,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`Remove ${w}`}
                                                >
                                                    <X className="h-3 w-3 text-muted-foreground hover:text-status-critical" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'response' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldAlert}
                        title="Response & evidence"
                        blurb="What was done, any follow-up, and supporting evidence."
                    />
                    <div className="grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <SubHead icon={ShieldAlert}>
                                Immediate response
                            </SubHead>
                            <Field
                                label="Immediate action taken"
                                required={hsLinked}
                                error={form.errors.immediate_action_taken}
                                hint={
                                    hsLinked
                                        ? 'Required because this event is linked to Health & Safety.'
                                        : undefined
                                }
                                span
                            >
                                <Textarea
                                    aria-invalid={Boolean(
                                        form.errors.immediate_action_taken,
                                    )}
                                    rows={2}
                                    value={data.immediate_action_taken}
                                    onChange={(e) =>
                                        setData(
                                            'immediate_action_taken',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={
                                        hsLinked
                                            ? 'Required: document exactly what was done straight away.'
                                            : 'First aid given, repositioning, observations started…'
                                    }
                                />
                            </Field>
                            <Field label="Outcome" span>
                                <Textarea
                                    rows={2}
                                    value={data.outcome}
                                    onChange={(e) =>
                                        setData('outcome', e.target.value)
                                    }
                                    placeholder="Current condition / how it resolved."
                                />
                            </Field>
                        </div>

                        <div className="rounded-lg border border-border bg-muted/30 p-3">
                            <label className="flex items-start gap-3">
                                <Switch
                                    checked={data.requires_followup}
                                    onCheckedChange={(v) =>
                                        setData('requires_followup', v)
                                    }
                                />
                                <span>
                                    <span className="text-sm font-semibold">
                                        Requires follow-up
                                    </span>
                                    <span className="mt-0.5 block text-[13px] text-muted-foreground">
                                        Track an action that still needs
                                        completing.
                                    </span>
                                </span>
                            </label>
                            {data.requires_followup ? (
                                <div className="mt-2.5">
                                    <Textarea
                                        rows={2}
                                        value={data.followup_notes}
                                        onChange={(e) =>
                                            setData(
                                                'followup_notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="What needs to happen, and by when."
                                    />
                                </div>
                            ) : null}
                        </div>

                        <div>
                            <SubHead icon={Paperclip}>
                                Evidence &amp; attachments
                            </SubHead>
                            <p className="mb-2 text-[13px] text-muted-foreground">
                                Injury / wound photos, a body map, observation
                                charts or the WorkSafe PDF. Image, PDF or Word,
                                up to 10&nbsp;MB each.
                            </p>
                            <FileDropzone
                                onFiles={addFiles}
                                accept="image/*,.pdf,.doc,.docx"
                                hint="Images, PDF or Word — up to 10 MB"
                            />
                            {data.attachments.length ? (
                                <div className="mt-2.5 flex flex-col gap-2">
                                    {data.attachments.map((f, i) => (
                                        <StagedFileCard
                                            key={i}
                                            file={f}
                                            onRemove={() => removeFile(i)}
                                        />
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'review' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Check}
                        title="Review & log"
                        blurb="Confirm the event details before logging."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={AlertTriangle}
                            title="Event"
                            onEdit={() => setStepIndex(0)}
                        >
                            <ReviewRow label="Client" value={picked?.name} />
                            <ReviewRow
                                label="Type"
                                value={
                                    TYPES.find((t) => t.key === data.event_type)
                                        ?.label
                                }
                            />
                            <ReviewRow
                                label="Severity"
                                value={
                                    SEVERITY.find(
                                        (s) => s.key === data.severity,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label={
                                    data.event_type === 'hospital_admission'
                                        ? 'Actually admitted · Pacific/Auckland'
                                        : data.event_type ===
                                            'hospital_discharge'
                                          ? 'Actually discharged · Pacific/Auckland'
                                          : 'When it happened · Pacific/Auckland'
                                }
                                value={
                                    eventInstant
                                        ? formatDateTime(eventInstant) +
                                          ' · UTC' +
                                          eventInstant.slice(-6)
                                        : '—'
                                }
                            />
                            {closedStay && (
                                <ReviewRow
                                    label="Actually discharged · Pacific/Auckland"
                                    value={
                                        formatDateTime(dischargeInstant) +
                                        ' · UTC' +
                                        dischargeInstant.slice(-6)
                                    }
                                />
                            )}
                            {data.event_type === 'hospital_discharge' &&
                                selectedAdmission && (
                                    <ReviewRow
                                        label="Admission being ended"
                                        value={
                                            formatDateTime(
                                                selectedAdmission.occurred_at,
                                            ) +
                                            ' · record ' +
                                            selectedAdmission.id
                                        }
                                    />
                                )}
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldAlert}
                            title="Response"
                            onEdit={() => setStepIndex(2)}
                        >
                            <ReviewRow
                                label="Action"
                                value={data.immediate_action_taken}
                            />
                            <ReviewRow label="Outcome" value={data.outcome} />
                            <ReviewRow
                                label="Follow-up"
                                value={
                                    data.requires_followup ? 'Required' : 'None'
                                }
                            />
                            <ReviewRow
                                label="Witnesses"
                                value={
                                    data.witnesses.length
                                        ? data.witnesses.join(', ')
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Evidence"
                                value={
                                    data.attachments.length
                                        ? `${data.attachments.length} file${data.attachments.length === 1 ? '' : 's'}`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={AlertTriangle}
                            title="Description"
                            span
                            onEdit={() => setStepIndex(1)}
                        >
                            <p className="text-[13px] whitespace-pre-wrap">
                                {data.description || '—'}
                            </p>
                        </ReviewCard>
                    </div>
                    {hsLinked ? (
                        <InfoCard icon={ShieldAlert} tone="warn">
                            A linked Health &amp; Safety event will be raised
                            automatically.
                        </InfoCard>
                    ) : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default RecordEventDialog;
