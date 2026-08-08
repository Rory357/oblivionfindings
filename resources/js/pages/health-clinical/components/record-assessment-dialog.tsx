/* eslint-disable no-restricted-syntax -- Record wizard mirrors the Add-Client modal
 * chrome: styled native controls (tool tiles, option selects, score panel) on
 * semantic design tokens. */
import { Button } from '@/components/ui/button';
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
import {
    computeAssessment,
    type AssessmentInputs,
    type AssessmentTypeValue,
} from '@/lib/assessment-scoring';
import { cn } from '@/lib/utils';
import {
    ClientPicker,
    ClinicalCardRail,
    type ClientResult,
} from '@/pages/health-clinical/components/record-wizard-shared';
import { useForm } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Droplets,
    Loader2,
    Paperclip,
    Scale,
    ShieldAlert,
    Stethoscope,
    TrendingDown,
} from 'lucide-react';
import { useMemo, useState, type ComponentType } from 'react';

type AssessmentForm = {
    client_id: string;
    assessment_type: string;
    inputs: AssessmentInputs;
    notes: string;
    attachments: File[];
};

const TYPES: {
    key: AssessmentTypeValue;
    label: string;
    blurb: string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    {
        key: 'falls_frat',
        label: 'Falls (FRAT)',
        blurb: 'Peninsula Health Falls Risk Assessment Tool',
        icon: TrendingDown,
    },
    {
        key: 'pressure_braden',
        label: 'Pressure (Braden)',
        blurb: 'Braden Scale for pressure-injury risk',
        icon: ShieldAlert,
    },
    {
        key: 'malnutrition_must',
        label: 'Malnutrition (MUST)',
        blurb: 'BAPEN Malnutrition Universal Screening Tool',
        icon: Scale,
    },
    {
        key: 'dysphagia_iddsi',
        label: 'Dysphagia (IDDSI)',
        blurb: 'IDDSI texture-level classification',
        icon: Droplets,
    },
];

const SELECT_OPTIONS: Record<string, { value: string; label: string }[]> = {
    recent_falls: [
        { value: 'none_12mo', label: 'No falls in the last 12 months' },
        {
            value: 'one_plus_3_12mo',
            label: 'One or more between 3 and 12 months ago',
        },
        { value: 'one_plus_3mo', label: 'One or more in the last 3 months' },
        {
            value: 'one_plus_3mo_resident',
            label: 'One or more in the last 3 months whilst a resident',
        },
    ],
    medications: [
        { value: 'none', label: 'Not taking any listed medications' },
        { value: 'one', label: 'Taking one' },
        { value: 'two', label: 'Taking two' },
        { value: 'more_than_two', label: 'Taking more than two' },
    ],
    psychological: [
        {
            value: 'none',
            label: 'No apparent anxiety, depression or agitation',
        },
        { value: 'mild', label: 'Mild' },
        { value: 'moderate', label: 'Moderate' },
        { value: 'severe', label: 'Severe' },
    ],
    cognitive: [
        { value: 'intact', label: 'Intact (AMTS 9–10)' },
        { value: 'mild', label: 'Mildly impaired (AMTS 7–8)' },
        { value: 'moderate', label: 'Moderately impaired (AMTS 5–6)' },
        { value: 'severe', label: 'Severely impaired (AMTS ≤4)' },
    ],
};

const BRADEN_SUBSCALES: {
    key: string;
    label: string;
    options: { value: string; label: string }[];
}[] = [
    {
        key: 'sensory_perception',
        label: 'Sensory perception',
        options: [
            { value: '1', label: '1 — Completely limited' },
            { value: '2', label: '2 — Very limited' },
            { value: '3', label: '3 — Slightly limited' },
            { value: '4', label: '4 — No impairment' },
        ],
    },
    {
        key: 'moisture',
        label: 'Moisture',
        options: [
            { value: '1', label: '1 — Constantly moist' },
            { value: '2', label: '2 — Very moist' },
            { value: '3', label: '3 — Occasionally moist' },
            { value: '4', label: '4 — Rarely moist' },
        ],
    },
    {
        key: 'activity',
        label: 'Activity',
        options: [
            { value: '1', label: '1 — Bedfast' },
            { value: '2', label: '2 — Chairfast' },
            { value: '3', label: '3 — Walks occasionally' },
            { value: '4', label: '4 — Walks frequently' },
        ],
    },
    {
        key: 'mobility',
        label: 'Mobility',
        options: [
            { value: '1', label: '1 — Completely immobile' },
            { value: '2', label: '2 — Very limited' },
            { value: '3', label: '3 — Slightly limited' },
            { value: '4', label: '4 — No limitations' },
        ],
    },
    {
        key: 'nutrition',
        label: 'Nutrition',
        options: [
            { value: '1', label: '1 — Very poor' },
            { value: '2', label: '2 — Probably inadequate' },
            { value: '3', label: '3 — Adequate' },
            { value: '4', label: '4 — Excellent' },
        ],
    },
    {
        key: 'friction_shear',
        label: 'Friction & shear',
        options: [
            { value: '1', label: '1 — Problem' },
            { value: '2', label: '2 — Potential problem' },
            { value: '3', label: '3 — No apparent problem' },
        ],
    },
];

const IDDSI_DRINKS = [
    { value: '0', label: 'Level 0 — Thin' },
    { value: '1', label: 'Level 1 — Slightly Thick' },
    { value: '2', label: 'Level 2 — Mildly Thick' },
    { value: '3', label: 'Level 3 — Moderately Thick' },
    { value: '4', label: 'Level 4 — Extremely Thick' },
];
const IDDSI_FOODS = [
    { value: '3', label: 'Level 3 — Liquidised' },
    { value: '4', label: 'Level 4 — Pureed' },
    { value: '5', label: 'Level 5 — Minced & Moist' },
    { value: '6', label: 'Level 6 — Soft & Bite-Sized' },
    { value: '7', label: 'Level 7 — Regular' },
];

const STEPS: readonly WizardStep[] = [
    {
        key: 'client',
        label: 'Client & tool',
        blurb: 'Who & which assessment',
        icon: Stethoscope,
    },
    {
        key: 'form',
        label: 'Assessment',
        blurb: 'Complete the tool',
        icon: ClipboardList,
    },
    {
        key: 'evidence',
        label: 'Notes & evidence',
        blurb: 'Context & files',
        icon: Paperclip,
    },
    { key: 'review', label: 'Review', blurb: 'Confirm the score', icon: Check },
];

const TONE_CLASS: Record<string, string> = {
    success:
        'border-status-success/40 bg-status-success-bg text-status-success',
    warning:
        'border-status-warning/40 bg-status-warning-bg text-status-warning',
    critical:
        'border-status-critical/40 bg-status-critical-bg text-status-critical',
    neutral: 'border-border bg-muted text-muted-foreground',
};

export type RecordAssessmentDialogProps = {
    open: boolean;
    onClose: () => void;
    /** Profile entry point (§8): locks step 1 to this client. */
    client?: ClientResult | null;
    onSaved?: () => void;
};

export function RecordAssessmentDialog(props: RecordAssessmentDialogProps) {
    return props.open ? <Body {...props} /> : null;
}

function Body({ onClose, client, onSaved }: RecordAssessmentDialogProps) {
    const [picked, setPicked] = useState<ClientResult | null>(client ?? null);
    const lockedClient = client != null;
    const [done, setDone] = useState(false);
    const [stepIndex, setStepIndex] = useState(0);

    const form = useForm<AssessmentForm>({
        client_id: client ? String(client.id) : '',
        assessment_type: '',
        inputs: {},
        notes: '',
        attachments: [],
    });
    const { data, setData, processing, errors } = form;
    const hasErrors = Object.keys(errors).length > 0;

    const type = data.assessment_type as AssessmentTypeValue | '';
    const result = useMemo(
        () => (type ? computeAssessment(type, data.inputs) : null),
        [type, data.inputs],
    );

    const choosePatient = (c: ClientResult | null) => {
        setPicked(c);
        setData('client_id', c ? String(c.id) : '');
    };
    const setInput = (key: string, value: string | number | boolean | null) =>
        setData('inputs', { ...data.inputs, [key]: value });
    const chooseType = (v: string) => {
        // Reset inputs when switching tool so a stale answer can't leak across forms.
        setData((d) => ({ ...d, assessment_type: v, inputs: {} }));
    };

    const addFiles = (files: File[]) =>
        setData('attachments', [...data.attachments, ...files]);
    const removeFile = (i: number) =>
        setData(
            'attachments',
            data.attachments.filter((_, idx) => idx !== i),
        );

    const formValid = (): boolean => {
        if (!type) return false;
        const i = data.inputs;
        const has = (v: string | number | boolean | null | undefined) =>
            v !== undefined && v !== null && v !== '';
        if (type === 'falls_frat')
            return [
                'recent_falls',
                'medications',
                'psychological',
                'cognitive',
            ].every((k) => !!i[k]);
        if (type === 'pressure_braden')
            return BRADEN_SUBSCALES.every((s) => !!i[s.key]);
        if (type === 'malnutrition_must') {
            // Mirror the server: weight loss + a BMI basis (direct BMI, or height+weight).
            const bmiBasis =
                has(i.bmi) || (has(i.height_cm) && has(i.weight_kg));
            return has(i.weight_loss_percent) && bmiBasis;
        }
        if (type === 'dysphagia_iddsi')
            return has(i.drink_level) || has(i.food_level);
        return false;
    };

    const stepValid = (idx: number): boolean => {
        if (idx === 0) return !!data.client_id && !!data.assessment_type;
        if (idx === 1) return formValid();
        return true;
    };

    const next = () =>
        stepValid(stepIndex) &&
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const submit = () => {
        form.post('/health-clinical/assessments', {
            forceFormData: true,
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
                title="Assessment recorded"
                description="The clinical risk assessment was recorded."
                railIcon={ShieldAlert}
                railTitle="Record assessment"
                railSub="Clinical"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Assessment recorded"
                        blurb={
                            result?.summary
                                ? `Recorded on the register and client timeline — ${result.summary}.`
                                : 'Recorded on the Assessments register and the client timeline.'
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
    const typeMeta = TYPES.find((t) => t.key === type);

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Record clinical risk assessment"
            description="A guided wizard to complete a standardised clinical assessment."
            railIcon={ShieldAlert}
            railTitle="Record assessment"
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
                            Record assessment
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
            {STEPS[stepIndex].key === 'client' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Stethoscope}
                        title="Who, and which assessment?"
                        blurb="Pick the client and the standardised tool to complete."
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
                        <Field label="Assessment tool" required>
                            <TilePicker
                                value={data.assessment_type}
                                onChange={chooseType}
                                cols={2}
                                options={TYPES.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    icon: t.icon,
                                }))}
                            />
                            {typeMeta ? (
                                <p className="mt-2 text-[13px] text-muted-foreground">
                                    {typeMeta.blurb}
                                </p>
                            ) : null}
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'form' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardList}
                        title={typeMeta?.label ?? 'Assessment'}
                        blurb="Complete each item — the score updates live and is verified on save."
                    />
                    {hasErrors ? (
                        <InfoCard icon={ShieldAlert} tone="crit">
                            Couldn’t record this assessment — please check the
                            values below and try again.
                        </InfoCard>
                    ) : null}
                    <AssessmentFields
                        type={type}
                        inputs={data.inputs}
                        setInput={setInput}
                    />
                    {result ? <LiveScore result={result} /> : null}
                </WizardStepPane>
            ) : null}

            {STEPS[stepIndex].key === 'evidence' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Paperclip}
                        title="Notes & evidence"
                        blurb="Optional clinical context and supporting documents."
                    />
                    <div className="grid gap-4">
                        <Field label="Notes" hint="optional">
                            <Textarea
                                rows={3}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                placeholder="Clinical context, who was consulted, planned actions…"
                            />
                        </Field>
                        <div>
                            <SubHead icon={Paperclip}>
                                Evidence &amp; attachments
                            </SubHead>
                            <p className="mb-2 text-[13px] text-muted-foreground">
                                Completed paper assessment, SLT report, body map
                                or photos. Image, PDF or Word, up to 10&nbsp;MB
                                each.
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
                        title="Review & record"
                        blurb="Confirm the computed result before recording."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={ShieldAlert}
                            title="Assessment"
                            onEdit={() => setStepIndex(0)}
                        >
                            <ReviewRow label="Client" value={picked?.name} />
                            <ReviewRow label="Tool" value={typeMeta?.label} />
                            <ReviewRow
                                label="Evidence"
                                value={
                                    data.attachments.length
                                        ? `${data.attachments.length} file${data.attachments.length === 1 ? '' : 's'}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Notes"
                                value={data.notes || undefined}
                            />
                        </ReviewCard>
                        {result ? (
                            <ReviewCard
                                icon={ClipboardList}
                                title="Result"
                                onEdit={() => setStepIndex(1)}
                            >
                                <ReviewRow
                                    label="Outcome"
                                    value={result.summary}
                                />
                                {result.advice ? (
                                    <ReviewRow
                                        label="Advice"
                                        value={result.advice}
                                    />
                                ) : null}
                            </ReviewCard>
                        ) : null}
                    </div>
                    {result ? <LiveScore result={result} /> : null}
                    <InfoCard icon={ShieldAlert} tone="info">
                        The score is computed transparently and verified
                        server-side. It supports — but does not replace — your
                        clinical judgement.
                    </InfoCard>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

function AssessmentFields({
    type,
    inputs,
    setInput,
}: {
    type: AssessmentTypeValue | '';
    inputs: AssessmentInputs;
    setInput: (k: string, v: string | number | boolean | null) => void;
}) {
    if (type === 'malnutrition_must') {
        return (
            <div className="grid gap-4">
                <SubHead icon={Scale}>Body mass index</SubHead>
                <p className="-mt-2 text-[13px] text-muted-foreground">
                    Enter a BMI, or a height and weight to derive it — a BMI
                    basis is required.
                </p>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="BMI" hint="or height + weight">
                        <Input
                            type="number"
                            step="0.1"
                            min={5}
                            max={120}
                            value={str(inputs.bmi)}
                            onChange={(e) => setInput('bmi', e.target.value)}
                            placeholder="e.g. 21.4"
                        />
                    </Field>
                    <Field label="Height (cm)" hint="with weight">
                        <Input
                            type="number"
                            min={30}
                            max={260}
                            value={str(inputs.height_cm)}
                            onChange={(e) =>
                                setInput('height_cm', e.target.value)
                            }
                            placeholder="170"
                        />
                    </Field>
                    <Field label="Weight (kg)" hint="with height">
                        <Input
                            type="number"
                            step="0.1"
                            min={1}
                            max={500}
                            value={str(inputs.weight_kg)}
                            onChange={(e) =>
                                setInput('weight_kg', e.target.value)
                            }
                            placeholder="68"
                        />
                    </Field>
                </div>
                <Field label="Unplanned weight loss (3–6 months, %)" required>
                    <Input
                        type="number"
                        step="0.1"
                        min={0}
                        max={100}
                        value={str(inputs.weight_loss_percent)}
                        onChange={(e) =>
                            setInput('weight_loss_percent', e.target.value)
                        }
                        placeholder="e.g. 7"
                    />
                </Field>
                <div className="rounded-lg border border-border bg-muted/30 p-3">
                    <label className="flex items-start gap-3">
                        <Switch
                            checked={!!inputs.acute_disease_effect}
                            onCheckedChange={(v) =>
                                setInput('acute_disease_effect', v ? 1 : 0)
                            }
                        />
                        <span>
                            <span className="text-sm font-semibold">
                                Acute disease effect
                            </span>
                            <span className="mt-0.5 block text-[13px] text-muted-foreground">
                                Acutely ill and there has been, or is likely to
                                be, no nutritional intake for more than 5 days.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        );
    }

    if (type === 'falls_frat') {
        return (
            <div className="grid gap-4">
                {(
                    [
                        'recent_falls',
                        'medications',
                        'psychological',
                        'cognitive',
                    ] as const
                ).map((key) => (
                    <SelectField
                        key={key}
                        label={FRAT_LABELS[key]}
                        value={str(inputs[key])}
                        onChange={(v) => setInput(key, v)}
                        options={SELECT_OPTIONS[key]}
                    />
                ))}
            </div>
        );
    }

    if (type === 'pressure_braden') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                {BRADEN_SUBSCALES.map((s) => (
                    <SelectField
                        key={s.key}
                        label={s.label}
                        value={str(inputs[s.key])}
                        onChange={(v) => setInput(s.key, v)}
                        options={s.options}
                    />
                ))}
            </div>
        );
    }

    if (type === 'dysphagia_iddsi') {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                <SelectField
                    label="Recommended drink level"
                    value={str(inputs.drink_level)}
                    onChange={(v) => setInput('drink_level', v)}
                    options={IDDSI_DRINKS}
                    placeholder="Select a drink level"
                />
                <SelectField
                    label="Recommended food level"
                    value={str(inputs.food_level)}
                    onChange={(v) => setInput('food_level', v)}
                    options={IDDSI_FOODS}
                    placeholder="Select a food level"
                />
            </div>
        );
    }

    return null;
}

const FRAT_LABELS: Record<string, string> = {
    recent_falls: 'Recent falls',
    medications:
        'Medications (sedatives, anti-Parkinson’s, antidepressants, antihypertensives, hypnotics)',
    psychological: 'Psychological status',
    cognitive: 'Cognitive status',
};

function SelectField({
    label,
    value,
    onChange,
    options,
    placeholder,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: { value: string; label: string }[];
    placeholder?: string;
}) {
    return (
        <div>
            <Label className="mb-1.5 block text-[13px] font-medium">
                {label}
            </Label>
            <Select value={value || undefined} onValueChange={onChange}>
                <SelectTrigger>
                    <SelectValue placeholder={placeholder ?? 'Select…'} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function LiveScore({
    result,
}: {
    result: NonNullable<ReturnType<typeof computeAssessment>>;
}) {
    return (
        <div
            className={cn(
                'mt-4 rounded-xl border p-4',
                TONE_CLASS[result.bandTone ?? 'neutral'],
            )}
        >
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-bold">{result.summary}</p>
                {result.score !== null ? (
                    <span className="text-2xl font-bold tabular-nums">
                        {result.score}
                    </span>
                ) : null}
            </div>
            <div className="mt-3 flex flex-col gap-1.5">
                {result.breakdown.map((row) => (
                    <div
                        key={row.key}
                        className="flex items-center justify-between gap-3 text-[13px]"
                    >
                        <span className="text-foreground/80">{row.label}</span>
                        <span className="flex items-center gap-2 text-right">
                            <span className="text-muted-foreground">
                                {row.detail}
                            </span>
                            {row.points !== null ? (
                                <span className="min-w-[20px] rounded bg-background/60 px-1 text-center font-semibold tabular-nums">
                                    {row.points}
                                </span>
                            ) : null}
                        </span>
                    </div>
                ))}
            </div>
            {result.advice ? (
                <p className="mt-3 border-t border-current/15 pt-2 text-[13px] font-medium">
                    {result.advice}
                </p>
            ) : null}
        </div>
    );
}

function str(v: string | number | boolean | null | undefined): string {
    if (v === null || v === undefined) return '';
    return String(v);
}

export default RecordAssessmentDialog;
