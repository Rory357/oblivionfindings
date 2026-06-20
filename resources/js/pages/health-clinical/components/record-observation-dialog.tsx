/* eslint-disable no-restricted-syntax -- Record wizard mirrors the Add-Client modal
 * chrome: styled native controls (type tiles, toggles) on semantic design tokens. */
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { ACVPU_OPTIONS, NEWS2_BAND_LABEL, scoreNews2, type News2Band } from '@/lib/news2';
import { cn } from '@/lib/utils';
import {
    ClientPicker,
    ClinicalCardRail,
    type ClientResult,
} from '@/pages/health-clinical/components/record-wizard-shared';
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import {
    Activity,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock,
    Droplet,
    Flag,
    HeartPulse,
    Loader2,
    Moon,
    Scale,
    Stethoscope,
    Toilet,
    Zap,
} from 'lucide-react';
import { useMemo, useState, type ComponentType } from 'react';

type ObsTypeKey = 'vitals' | 'weight' | 'pain' | 'fluid_intake' | 'bowel' | 'sleep' | 'general';

type DataMap = Record<string, string | boolean>;

type ObsForm = {
    client_id: string;
    observation_type: ObsTypeKey | '';
    data: DataMap;
    recorded_at: string;
    notes: string;
    is_flagged: boolean;
    flagged_reason: string;
    protocol_schedule_id: string;
};

const TYPES: { key: ObsTypeKey; label: string; description: string; icon: ComponentType<{ className?: string }>; clinical?: boolean }[] = [
    { key: 'vitals', label: 'Vital signs', description: 'BP, pulse, temp, SpO₂ → NEWS2', icon: HeartPulse, clinical: true },
    { key: 'weight', label: 'Weight', description: 'Body weight in kg', icon: Scale },
    { key: 'pain', label: 'Pain', description: 'Score & location', icon: Zap, clinical: true },
    { key: 'fluid_intake', label: 'Fluid intake', description: 'Amount & fluid type', icon: Droplet },
    { key: 'bowel', label: 'Bowel', description: 'Bristol stool type', icon: Toilet },
    { key: 'sleep', label: 'Sleep', description: 'Bed/wake & quality', icon: Moon },
    { key: 'general', label: 'General', description: 'Notes-only observation', icon: ClipboardList },
];

const REQUIRED: Record<ObsTypeKey, string[]> = {
    vitals: ['systolic', 'diastolic', 'pulse'],
    weight: ['weight_kg'],
    pain: ['score', 'location'],
    fluid_intake: ['amount_ml', 'fluid_type'],
    bowel: ['bristol_type'],
    sleep: ['bed_time', 'wake_time', 'quality'],
    general: [],
};

const STEPS: readonly WizardStep[] = [
    { key: 'client', label: 'Client & type', blurb: 'Who & what to record', icon: Stethoscope },
    { key: 'measure', label: 'Measurements', blurb: 'The clinical readings', icon: Activity },
    { key: 'context', label: 'Context', blurb: 'When, notes & flags', icon: Clock },
    { key: 'review', label: 'Review', blurb: 'Confirm & record', icon: Check },
];

const BAND_PILL: Record<News2Band, string> = {
    low: 'bg-status-success-bg text-status-success',
    low_medium: 'bg-primary/10 text-primary',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-critical-bg text-status-critical',
};

const BAND_BORDER: Record<News2Band, string> = {
    low: 'border-status-success/40',
    low_medium: 'border-primary/40',
    medium: 'border-status-warning/40',
    high: 'border-status-critical/40',
};

export type RecordObservationDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Profile entry point (§8): locks step 1 to this client. */
    client?: ClientResult | null;
    /** Pre-bind an overdue protocol schedule so it auto-completes on save. */
    protocolScheduleId?: number | null;
    /** Whether the user may record clinical types (vitals/pain). */
    canRecordClinical?: boolean;
    onSaved?: () => void;
};

export function RecordObservationDialog(props: RecordObservationDialogProps) {
    const { open, onClose } = props;
    return open ? <Body {...props} onClose={onClose} /> : null;
}

function Body({ onClose, client, protocolScheduleId, canRecordClinical = true, onSaved }: RecordObservationDialogProps) {
    const [picked, setPicked] = useState<ClientResult | null>(client ?? null);
    const lockedClient = client != null;

    const form = useForm<ObsForm>({
        client_id: client ? String(client.id) : '',
        observation_type: '',
        data: {},
        recorded_at: '',
        notes: '',
        is_flagged: false,
        flagged_reason: '',
        protocol_schedule_id: protocolScheduleId ? String(protocolScheduleId) : '',
    });
    const { data, setData, processing, errors } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [done, setDone] = useState(false);
    const [stepError, setStepError] = useState<string | null>(null);

    const setD = (key: string, value: string | boolean) => setData('data', { ...data.data, [key]: value });

    const news2 = useMemo(
        () => (data.observation_type === 'vitals' ? scoreNews2(data.data) : null),
        [data.observation_type, data.data],
    );

    const availableTypes = TYPES.filter((t) => canRecordClinical || !t.clinical);

    const chooseType = (key: string) => {
        // Reset measurements when the type changes so stale keys never persist.
        form.setData((prev) => ({ ...prev, observation_type: key as ObsTypeKey, data: {} }));
    };

    const choosePatient = (c: ClientResult | null) => {
        setPicked(c);
        setData('client_id', c ? String(c.id) : '');
    };

    const stepValid = (i: number): boolean => {
        if (i === 0) return !!data.client_id && !!data.observation_type;
        if (i === 1) {
            const req = data.observation_type ? REQUIRED[data.observation_type] : [];
            return req.every((k) => {
                const v = data.data[k];
                return v !== undefined && v !== '' && v !== false;
            });
        }
        return true;
    };

    const next = () => {
        if (!stepValid(stepIndex)) {
            setStepError('Please complete the required fields before continuing.');
            return;
        }
        setStepError(null);
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => {
        setStepError(null);
        setStepIndex((i) => Math.max(i - 1, 0));
    };

    const submit = () => {
        form.post('/health-clinical/observations', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDone(true);
                onSaved?.();
            },
            onError: () => setStepIndex(1),
        });
    };

    if (done) {
        return (
            <WizardShell
                open
                onClose={onClose}
                title="Observation recorded"
                description="The observation was recorded."
                railIcon={Activity}
                railTitle="Record observation"
                railSub="Clinical"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Observation recorded"
                        blurb={
                            news2 ? (
                                <>
                                    NEWS2 <strong>{news2.score}</strong> · {news2.bandLabel}. {news2.advice}
                                </>
                            ) : (
                                'The observation has been saved to the client record and timeline.'
                            )
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
            title="Record observation"
            description="A guided wizard to record a clinical observation."
            railIcon={Activity}
            railTitle="Record observation"
            railSub="Clinical"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => i <= stepIndex && setStepIndex(i)}
            railExtra={<ClinicalCardRail clientId={data.client_id ? Number(data.client_id) : null} />}
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
                        <Button type="button" onClick={submit} disabled={processing}>
                            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                            Record observation
                        </Button>
                    ) : (
                        <Button type="button" onClick={next} disabled={!stepValid(stepIndex)}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {STEPS[stepIndex].key === 'client' ? (
                <WizardStepPane>
                    <StepHead icon={Stethoscope} title="Who & what are we recording?" blurb="Pick the client, then the observation type." />
                    <div className="grid gap-5">
                        <Field label="Client" required>
                            {lockedClient && picked ? (
                                <ClientPicker value={picked} onChange={() => {}} />
                            ) : (
                                <ClientPicker value={picked} onChange={choosePatient} />
                            )}
                        </Field>
                        <Field label="Observation type" required>
                            <TilePicker
                                value={data.observation_type}
                                onChange={chooseType}
                                cols={3}
                                options={availableTypes.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    description: t.description,
                                    icon: t.icon,
                                }))}
                            />
                        </Field>
                        {!canRecordClinical ? (
                            <InfoCard icon={Stethoscope}>
                                Vitals &amp; pain need the clinical recording permission, so they're hidden here.
                            </InfoCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'measure' ? (
                <WizardStepPane>
                    <StepHead icon={Activity} title="Measurements" blurb="Enter the readings for this observation." />
                    <MeasureFields type={data.observation_type as ObsTypeKey} data={data.data} setD={setD} news2={news2} />
                    {stepError ? <p className="mt-3 text-xs text-status-critical">{stepError}</p> : null}
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'context' ? (
                <WizardStepPane>
                    <StepHead icon={Clock} title="Context" blurb="When it was taken, any notes, and whether it needs review." />
                    <div className="grid gap-4">
                        <Field label="Recorded at" hint="leave blank for now (back-date for retrospective entry)">
                            <Input type="datetime-local" value={data.recorded_at} onChange={(e) => setData('recorded_at', e.target.value)} />
                        </Field>
                        <Field label="Clinical notes">
                            <Textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Anything the care team should know about this reading." />
                        </Field>
                        <div className="rounded-lg border border-border bg-muted/30 p-3">
                            <label className="flex items-start gap-3">
                                <Switch checked={data.is_flagged} onCheckedChange={(v) => setData('is_flagged', v)} />
                                <span>
                                    <span className="flex items-center gap-1.5 text-sm font-semibold">
                                        <Flag className="h-3.5 w-3.5 text-status-warning" /> Flag for clinical review
                                    </span>
                                    <span className="mt-0.5 block text-[13px] text-muted-foreground">
                                        Pushes this record to the registered-nurse sign-off queue.
                                    </span>
                                </span>
                            </label>
                            {data.is_flagged ? (
                                <div className="mt-2.5">
                                    <Input value={data.flagged_reason} onChange={(e) => setData('flagged_reason', e.target.value)} placeholder="Reason for the flag (optional)" />
                                </div>
                            ) : null}
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'review' ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Review & record" blurb="Confirm the details below, then record." />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Stethoscope} title="Observation" onEdit={() => setStepIndex(0)}>
                            <ReviewRow label="Client" value={picked?.name} />
                            <ReviewRow label="Type" value={TYPES.find((t) => t.key === data.observation_type)?.label} />
                            <ReviewRow label="Recorded at" value={data.recorded_at ? new Date(data.recorded_at).toLocaleString('en-NZ') : 'Now'} />
                        </ReviewCard>
                        <ReviewCard icon={Activity} title="Measurements" onEdit={() => setStepIndex(1)}>
                            {Object.entries(data.data).filter(([, v]) => v !== '' && v !== false).map(([k, v]) => (
                                <ReviewRow key={k} label={k.replace(/_/g, ' ')} value={String(v)} />
                            ))}
                            {news2 ? (
                                <ReviewRow
                                    label="NEWS2"
                                    value={
                                        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', BAND_PILL[news2.band])}>
                                            {news2.score} · {news2.bandLabel}
                                        </span>
                                    }
                                />
                            ) : null}
                        </ReviewCard>
                        {(data.notes || data.is_flagged) && (
                            <ReviewCard icon={Clock} title="Context" span onEdit={() => setStepIndex(2)}>
                                {data.notes ? <ReviewRow label="Notes" value={data.notes} /> : null}
                                {data.is_flagged ? <ReviewRow label="Flagged for review" value={data.flagged_reason || 'Yes'} /> : null}
                            </ReviewCard>
                        )}
                    </div>
                    {Object.keys(errors).length ? (
                        <p className="mt-3 text-xs text-status-critical">Some fields need attention — check the measurements step.</p>
                    ) : null}
                    <p className="mt-4 text-[11px] text-muted-foreground">
                        Recorded against your account and added to the client timeline. Vitals are scored with NEWS2 on save.
                    </p>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Type-aware measurement fields                                      */
/* ------------------------------------------------------------------ */

function MeasureFields({
    type,
    data,
    setD,
    news2,
}: {
    type: ObsTypeKey;
    data: DataMap;
    setD: (key: string, value: string | boolean) => void;
    news2: ReturnType<typeof scoreNews2>;
}) {
    const numField = (key: string, label: string, opts: { required?: boolean; hint?: string } = {}) => (
        <Field label={label} required={opts.required} hint={opts.hint}>
            <Input type="number" inputMode="decimal" value={(data[key] as string) ?? ''} onChange={(e) => setD(key, e.target.value)} />
        </Field>
    );

    if (type === 'vitals') {
        return (
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={HeartPulse}>Blood pressure & pulse</SubHead>
                    {numField('systolic', 'Systolic (mmHg)', { required: true })}
                    {numField('diastolic', 'Diastolic (mmHg)', { required: true })}
                    {numField('pulse', 'Pulse (bpm)', { required: true })}
                    {numField('temperature', 'Temperature (°C)')}
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Activity}>Respiratory & consciousness</SubHead>
                    {numField('respiration_rate', 'Respiratory rate', { hint: 'breaths/min' })}
                    {numField('o2_saturation', 'SpO₂ (%)')}
                    <Field label="Consciousness (ACVPU)">
                        <SelectInput
                            value={(data.consciousness as string) ?? 'A'}
                            onChange={(v) => setD('consciousness', v)}
                            placeholder="Select"
                            options={ACVPU_OPTIONS.map((o) => ({ value: o.value, label: o.label }))}
                        />
                    </Field>
                    <Field label="Supplemental oxygen">
                        <div className="flex h-10 items-center gap-2.5">
                            <Switch checked={!!data.on_oxygen} onCheckedChange={(v) => setD('on_oxygen', v)} />
                            <span className="text-[13px] text-muted-foreground">{data.on_oxygen ? 'On oxygen' : 'On air'}</span>
                        </div>
                    </Field>
                    <Field label="SpO₂ Scale 2" hint="hypercapnic / COPD target 88–92%">
                        <div className="flex h-10 items-center gap-2.5">
                            <Switch checked={data.spo2_scale === '2'} onCheckedChange={(v) => setD('spo2_scale', v ? '2' : '1')} />
                            <span className="text-[13px] text-muted-foreground">{data.spo2_scale === '2' ? 'Scale 2' : 'Scale 1'}</span>
                        </div>
                    </Field>
                </div>
                <Live news2={news2} />
            </div>
        );
    }

    if (type === 'weight') {
        return <div className="grid gap-4 sm:grid-cols-2">{numField('weight_kg', 'Weight (kg)', { required: true })}</div>;
    }

    if (type === 'pain') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                {numField('score', 'Pain score (0–10)', { required: true })}
                <Field label="Location" required>
                    <Input value={(data.location as string) ?? ''} onChange={(e) => setD('location', e.target.value)} placeholder="e.g. lower back" />
                </Field>
            </div>
        );
    }

    if (type === 'fluid_intake') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                {numField('amount_ml', 'Amount (ml)', { required: true })}
                <Field label="Fluid type" required>
                    <SelectInput
                        value={(data.fluid_type as string) ?? ''}
                        onChange={(v) => setD('fluid_type', v)}
                        placeholder="Select fluid"
                        options={['water', 'tea', 'coffee', 'juice', 'milk', 'other'].map((f) => ({ value: f, label: f[0].toUpperCase() + f.slice(1) }))}
                    />
                </Field>
            </div>
        );
    }

    if (type === 'bowel') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Bristol stool type" required hint="1 (hard) – 7 (liquid)">
                    <SelectInput
                        value={(data.bristol_type as string) ?? ''}
                        onChange={(v) => setD('bristol_type', v)}
                        placeholder="Select type"
                        options={[1, 2, 3, 4, 5, 6, 7].map((n) => ({ value: String(n), label: `Type ${n}` }))}
                    />
                </Field>
            </div>
        );
    }

    if (type === 'sleep') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Bed time" required>
                    <Input type="time" value={(data.bed_time as string) ?? ''} onChange={(e) => setD('bed_time', e.target.value)} />
                </Field>
                <Field label="Wake time" required>
                    <Input type="time" value={(data.wake_time as string) ?? ''} onChange={(e) => setD('wake_time', e.target.value)} />
                </Field>
                <Field label="Quality" required>
                    <SelectInput
                        value={(data.quality as string) ?? ''}
                        onChange={(v) => setD('quality', v)}
                        placeholder="Select"
                        options={['good', 'fair', 'poor'].map((q) => ({ value: q, label: q[0].toUpperCase() + q.slice(1) }))}
                    />
                </Field>
                {numField('interruptions', 'Interruptions', { hint: 'count' })}
            </div>
        );
    }

    return (
        <InfoCard icon={ClipboardList}>
            A general observation captures a free-text note only — add it on the next step.
        </InfoCard>
    );
}

function Live({ news2 }: { news2: ReturnType<typeof scoreNews2> }) {
    if (!news2) {
        return (
            <InfoCard icon={Activity}>
                Enter respiratory rate, SpO₂, BP, pulse and temperature to see the live NEWS2 score.
            </InfoCard>
        );
    }
    return (
        <div className={cn('flex items-center gap-3 rounded-xl border bg-card p-3.5', BAND_BORDER[news2.band])}>
            <div className={cn('grid h-12 w-12 shrink-0 place-items-center rounded-xl text-xl font-bold', BAND_PILL[news2.band])}>
                {news2.score}
            </div>
            <div>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-bold">NEWS2 {news2.bandLabel}</span>
                    {news2.redFlag ? <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">Red score</span> : null}
                </div>
                <p className="mt-0.5 text-[13px] text-muted-foreground">{news2.advice}</p>
            </div>
        </div>
    );
}

export default RecordObservationDialog;
