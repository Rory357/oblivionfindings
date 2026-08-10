/* eslint-disable no-restricted-syntax -- The substance wizard mirrors the Add
 * Client modal: the GHS pictogram picker and Yes/No toggles are intentional
 * styled native controls on top of WizardShell. Semantic design tokens only. */
import type {
    ActionKey,
    SubstanceDetail,
} from '@/components/health-safety/substance-detail-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    EXPOSURE_LIMIT_TYPES,
    GHS_PICTOGRAMS,
    HAZARD_CLASSES,
    PHYSICAL_FORMS,
    type Tone,
} from '@/pages/health-safety/substances/constants';
import { useForm } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    FlaskConical,
    Loader2,
    MapPin,
    Plus,
    ShieldCheck,
    Upload,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Form contract — matches HazardousSubstanceController::substanceRules() */
/* ------------------------------------------------------------------ */

type SubstanceForm = {
    name: string;
    common_name: string;
    physical_form: string;
    un_number: string;
    hsno_approval: string;
    hsno_classification: string;
    hazard_classifications: string[];
    ghs_pictograms: string[];
    signal_word: string;
    hazard_statements: string;
    precautionary_statements: string;
    is_controlled_substance: boolean;
    requires_tracking: boolean;
    ppe_required: string;
    storage_requirements: string;
    handling_precautions: string;
    first_aid_measures: string;
    firefighting_measures: string;
    spill_procedures: string;
    exposure_limit_type: string;
    exposure_limit_value: string;
};

type StepKey = 'substance' | 'controls' | 'review';

const STEPS = [
    {
        key: 'substance' as const,
        label: 'Substance',
        blurb: 'Identity & classification',
        icon: FlaskConical,
    },
    {
        key: 'controls' as const,
        label: 'Controls',
        blurb: 'PPE, storage & first aid',
        icon: ShieldCheck,
    },
    {
        key: 'review' as const,
        label: 'Review',
        blurb: 'Confirm & register',
        icon: CheckCircle2,
    },
];

const STEP_FIELDS: Record<'substance' | 'controls', (keyof SubstanceForm)[]> = {
    substance: [
        'name',
        'common_name',
        'physical_form',
        'un_number',
        'hsno_approval',
        'hsno_classification',
        'hazard_classifications',
        'ghs_pictograms',
        'signal_word',
        'hazard_statements',
        'precautionary_statements',
        'is_controlled_substance',
        'requires_tracking',
    ],
    controls: [
        'ppe_required',
        'storage_requirements',
        'handling_precautions',
        'first_aid_measures',
        'firefighting_measures',
        'spill_procedures',
        'exposure_limit_type',
        'exposure_limit_value',
    ],
};

const stepForError = (field: string): StepKey =>
    (STEP_FIELDS.controls as string[]).includes(field)
        ? 'controls'
        : 'substance';

const COMPLETION_FIELDS: (keyof SubstanceForm)[] = [
    'name',
    'physical_form',
    'hsno_classification',
    'hazard_classifications',
    'ghs_pictograms',
    'un_number',
    'ppe_required',
    'storage_requirements',
    'handling_precautions',
    'first_aid_measures',
];

const isFilled = (v: unknown): boolean =>
    Array.isArray(v) ? v.length > 0 : v !== '' && v != null && v !== false;
const completionPct = (d: SubstanceForm): number =>
    Math.round(
        (COMPLETION_FIELDS.filter((k) => isFilled(d[k])).length /
            COMPLETION_FIELDS.length) *
            100,
    );

function validateStep(key: StepKey, d: SubstanceForm): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'substance') {
        if (!d.name.trim()) e.name = 'Name is required.';
        if (!d.physical_form) e.physical_form = 'Choose a physical form.';
    }
    return e;
}

function emptyForm(): SubstanceForm {
    return {
        name: '',
        common_name: '',
        physical_form: 'liquid',
        un_number: '',
        hsno_approval: '',
        hsno_classification: '',
        hazard_classifications: [],
        ghs_pictograms: [],
        signal_word: '',
        hazard_statements: '',
        precautionary_statements: '',
        is_controlled_substance: false,
        requires_tracking: false,
        ppe_required: '',
        storage_requirements: '',
        handling_precautions: '',
        first_aid_measures: '',
        firefighting_measures: '',
        spill_procedures: '',
        exposure_limit_type: '',
        exposure_limit_value: '',
    };
}

function formFromDetail(d: SubstanceDetail): SubstanceForm {
    return {
        name: d.name ?? '',
        common_name: d.common_name ?? '',
        physical_form: d.physical_form ?? 'liquid',
        un_number: d.un_number ?? '',
        hsno_approval: d.hsno_approval ?? '',
        hsno_classification: d.hsno_classification ?? '',
        hazard_classifications: d.hazard_classifications ?? [],
        ghs_pictograms: d.ghs_pictograms ?? [],
        signal_word: d.signal_word ?? '',
        hazard_statements: d.hazard_statements ?? '',
        precautionary_statements: d.precautionary_statements ?? '',
        is_controlled_substance: d.is_controlled_substance ?? false,
        requires_tracking: d.requires_tracking ?? false,
        ppe_required: d.ppe_required ?? '',
        storage_requirements: d.storage_requirements ?? '',
        handling_precautions: d.handling_precautions ?? '',
        first_aid_measures: d.first_aid_measures ?? '',
        firefighting_measures: d.firefighting_measures ?? '',
        spill_procedures: d.spill_procedures ?? '',
        exposure_limit_type: d.exposure_limit_type ?? '',
        exposure_limit_value: d.exposure_limit_value ?? '',
    };
}

/* ------------------------------------------------------------------ */
/*  Local controls                                                     */
/* ------------------------------------------------------------------ */

function BoolToggle({
    value,
    onChange,
}: {
    value: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <Segmented<'yes' | 'no'>
            value={value ? 'yes' : 'no'}
            onChange={(v) => onChange(v === 'yes')}
            options={[
                { value: 'yes', label: 'Yes' },
                { value: 'no', label: 'No' },
            ]}
        />
    );
}

const TONE_SOFT: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

function GhsPicker({
    value,
    onChange,
}: {
    value: string[];
    onChange: (v: string[]) => void;
}) {
    const toggle = (code: string) =>
        onChange(
            value.includes(code)
                ? value.filter((c) => c !== code)
                : [...value, code],
        );
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {GHS_PICTOGRAMS.map((p) => {
                const active = value.includes(p.code);
                const Icon = p.icon;
                return (
                    <button
                        key={p.code}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(p.code)}
                        className={`flex items-center gap-2 rounded-lg border p-2 text-left transition-colors ${active ? 'border-primary bg-primary/10' : 'border-border bg-card hover:border-primary/50'}`}
                    >
                        <span
                            className={`grid h-7 w-7 shrink-0 place-items-center rounded-md ${TONE_SOFT[p.tone]}`}
                        >
                            <Icon className="h-4 w-4" />
                        </span>
                        <span className="min-w-0 text-[12px] leading-tight font-medium">
                            {p.label}
                        </span>
                        {active ? (
                            <Check className="ml-auto h-3.5 w-3.5 shrink-0 text-primary" />
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Wizard                                                             */
/* ------------------------------------------------------------------ */

export function SubstanceWizardDialog({
    open,
    onClose,
    editSubstance = null,
    onOpenSubstance,
}: {
    open: boolean;
    onClose: () => void;
    editSubstance?: SubstanceDetail | null;
    onOpenSubstance?: (id: number, opts?: { action?: ActionKey }) => void;
}) {
    const isEdit = !!editSubstance;
    const form = useForm<SubstanceForm>(
        editSubstance ? formFromDetail(editSubstance) : emptyForm(),
    );
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [createdId, setCreatedId] = useState<number | null>(null);
    const [savedName, setSavedName] = useState('');

    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';
    const pct = useMemo(() => completionPct(data), [data]);

    const set = <K extends keyof SubstanceForm>(k: K, v: SubstanceForm[K]) =>
        setData(k, v as never);
    const err = (n: string) =>
        errors[n] ?? (form.errors as Record<string, string>)[n];
    const goToStep = (k: StepKey) => {
        const i = STEPS.findIndex((s) => s.key === k);
        if (i >= 0) setStepIndex(i);
    };
    const next = () => {
        const e = validateStep(cur.key, data);
        setErrors(e);
        if (!Object.keys(e).length)
            setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setErrors({});
        setStepIndex(0);
        setDone(false);
        setCreatedId(null);
    };

    const submit = (addAnother: boolean) => {
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length) {
            setErrors(all);
            goToStep(stepForError(Object.keys(all)[0]));
            return;
        }
        setErrors({});

        const opts = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const flash = (page.props.flash ?? {}) as {
                    error?: string;
                    created_substance_id?: number;
                };
                if (flash.error) return; // guardrail — stay open
                const newId =
                    flash.created_substance_id ?? editSubstance?.id ?? null;
                if (addAnother && !isEdit) {
                    resetAll();
                    return;
                }
                setSavedName(data.name);
                setCreatedId(newId);
                setDone(true);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.keys(errs)[0];
                if (first) goToStep(stepForError(first));
            },
        };

        form.transform((d) => ({ ...d, stay: true }));
        if (isEdit && editSubstance)
            form.put(`/health-safety/substances/${editSubstance.id}`, opts);
        else form.post('/health-safety/substances', opts);
    };

    // ── Success pane ──
    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? `${savedName} updated` : `${savedName} registered`}
            blurb={
                isEdit
                    ? 'The chemical register is up to date.'
                    : 'Add its SDS and storage locations from the record, or register another substance.'
            }
            actions={
                <>
                    {!isEdit && createdId && onOpenSubstance ? (
                        <>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    onOpenSubstance(createdId, {
                                        action: 'add_sds',
                                    })
                                }
                            >
                                <Upload className="mr-1.5 h-4 w-4" /> Add SDS
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    onOpenSubstance(createdId, {
                                        action: 'add_storage',
                                    })
                                }
                            >
                                <MapPin className="mr-1.5 h-4 w-4" /> Add
                                storage
                            </Button>
                        </>
                    ) : null}
                    {createdId && onOpenSubstance ? (
                        <Button onClick={() => onOpenSubstance(createdId)}>
                            View substance
                        </Button>
                    ) : (
                        <Button onClick={onClose}>Done</Button>
                    )}
                    {!isEdit ? (
                        <Button variant="ghost" onClick={resetAll}>
                            <Plus className="mr-1.5 h-4 w-4" /> Add another
                        </Button>
                    ) : null}
                </>
            }
        />
    ) : undefined;

    const primaryLabel = isEdit ? 'Save substance' : 'Create substance';

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={
                isEdit
                    ? `Edit ${editSubstance?.name}`
                    : 'Add hazardous substance'
            }
            description="Register a hazardous substance under the Hazardous Substances Regulations 2017."
            railIcon={FlaskConical}
            railTitle={isEdit ? 'Edit substance' : 'Add substance'}
            railSub="Chemical register"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Completeness"
            success={success}
            footerStart={
                stepIndex > 0 ? (
                    <Button variant="ghost" onClick={back}>
                        <ChevronLeft className="mr-1 h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isReview ? (
                        <>
                            {!isEdit ? (
                                <Button
                                    variant="secondary"
                                    onClick={() => submit(true)}
                                    disabled={processing}
                                >
                                    Save &amp; add another
                                </Button>
                            ) : null}
                            <Button
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="mr-1.5 h-4 w-4" />
                                )}
                                {processing ? 'Saving…' : primaryLabel}
                            </Button>
                        </>
                    ) : (
                        <Button onClick={next}>
                            Continue <ChevronRight className="ml-1 h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {cur.key === 'substance' ? (
                <WizardStepPane>
                    <StepHead
                        icon={FlaskConical}
                        title="Substance"
                        blurb="Identity, HSNO classification and GHS hazards."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" required error={err('name')}>
                            <Input
                                value={data.name}
                                onChange={(e) => set('name', e.target.value)}
                                placeholder="e.g. Sodium hypochlorite 12.5%"
                                aria-invalid={!!err('name')}
                            />
                        </Field>
                        <Field label="Common name" hint="Optional">
                            <Input
                                value={data.common_name}
                                onChange={(e) =>
                                    set('common_name', e.target.value)
                                }
                                placeholder="e.g. Sanitiser bleach"
                            />
                        </Field>
                        <Field
                            label="Physical form"
                            required
                            error={err('physical_form')}
                            span
                        >
                            <Segmented
                                value={data.physical_form}
                                onChange={(v) => set('physical_form', v)}
                                options={PHYSICAL_FORMS.map((o) => ({
                                    value: o.value,
                                    label: o.label,
                                }))}
                            />
                        </Field>
                        <Field label="HSNO / EPA classification">
                            <Input
                                value={data.hsno_classification}
                                onChange={(e) =>
                                    set('hsno_classification', e.target.value)
                                }
                                placeholder="e.g. 3.1A Flammable liquid"
                            />
                        </Field>
                        <Field label="UN number">
                            <Input
                                value={data.un_number}
                                onChange={(e) =>
                                    set('un_number', e.target.value)
                                }
                                placeholder="e.g. UN1789"
                            />
                        </Field>
                        <Field label="HSNO approval">
                            <Input
                                value={data.hsno_approval}
                                onChange={(e) =>
                                    set('hsno_approval', e.target.value)
                                }
                                placeholder="e.g. HSR002515"
                            />
                        </Field>
                        <Field label="Signal word">
                            <Segmented
                                value={data.signal_word}
                                onChange={(v) => set('signal_word', v)}
                                options={[
                                    { value: '', label: 'None' },
                                    { value: 'Warning', label: 'Warning' },
                                    { value: 'Danger', label: 'Danger' },
                                ]}
                            />
                        </Field>
                        <Field label="Hazard classes" span>
                            <ChipMulti
                                values={data.hazard_classifications}
                                onChange={(v) =>
                                    set('hazard_classifications', v)
                                }
                                options={HAZARD_CLASSES}
                            />
                        </Field>
                        <Field label="GHS pictograms" span>
                            <GhsPicker
                                value={data.ghs_pictograms}
                                onChange={(v) => set('ghs_pictograms', v)}
                            />
                        </Field>
                        <Field
                            label="Hazard statements"
                            hint="H-statements"
                            span
                        >
                            <Textarea
                                rows={2}
                                value={data.hazard_statements}
                                onChange={(e) =>
                                    set('hazard_statements', e.target.value)
                                }
                                placeholder="e.g. H314 Causes severe skin burns and eye damage."
                            />
                        </Field>
                        <Field
                            label="Precautionary statements"
                            hint="P-statements"
                            span
                        >
                            <Textarea
                                rows={2}
                                value={data.precautionary_statements}
                                onChange={(e) =>
                                    set(
                                        'precautionary_statements',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Controlled substance"
                            hint="HSNO controlled — requires tracking"
                        >
                            <BoolToggle
                                value={data.is_controlled_substance}
                                onChange={(v) =>
                                    set('is_controlled_substance', v)
                                }
                            />
                        </Field>
                        <Field label="Requires tracking">
                            <BoolToggle
                                value={data.requires_tracking}
                                onChange={(v) => set('requires_tracking', v)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {cur.key === 'controls' ? (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="Controls"
                        blurb="PPE, storage, handling, first aid and exposure limits."
                    />
                    <div className="grid gap-4">
                        <Field label="PPE required">
                            <Textarea
                                rows={2}
                                value={data.ppe_required}
                                onChange={(e) =>
                                    set('ppe_required', e.target.value)
                                }
                                placeholder="e.g. Nitrile gloves, splash goggles, apron."
                            />
                        </Field>
                        <Field label="Storage requirements">
                            <Textarea
                                rows={2}
                                value={data.storage_requirements}
                                onChange={(e) =>
                                    set('storage_requirements', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Handling precautions">
                            <Textarea
                                rows={2}
                                value={data.handling_precautions}
                                onChange={(e) =>
                                    set('handling_precautions', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="First-aid measures">
                            <Textarea
                                rows={2}
                                value={data.first_aid_measures}
                                onChange={(e) =>
                                    set('first_aid_measures', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Firefighting measures">
                            <Textarea
                                rows={2}
                                value={data.firefighting_measures}
                                onChange={(e) =>
                                    set('firefighting_measures', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Spill procedures">
                            <Textarea
                                rows={2}
                                value={data.spill_procedures}
                                onChange={(e) =>
                                    set('spill_procedures', e.target.value)
                                }
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="WES exposure limit type"
                                hint="Optional"
                            >
                                <SelectInput
                                    value={data.exposure_limit_type}
                                    onChange={(v) =>
                                        set('exposure_limit_type', v)
                                    }
                                    placeholder="Select limit type"
                                    options={EXPOSURE_LIMIT_TYPES}
                                />
                            </Field>
                            <Field label="WES exposure limit value">
                                <Input
                                    value={data.exposure_limit_value}
                                    onChange={(e) =>
                                        set(
                                            'exposure_limit_value',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. 0.5 ppm"
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {cur.key === 'review' ? (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review"
                        blurb="Confirm the details before registering."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <ReviewCard
                            icon={FlaskConical}
                            title="Substance"
                            onEdit={() => goToStep('substance')}
                        >
                            <ReviewRow label="Name" value={data.name} />
                            <ReviewRow
                                label="Common name"
                                value={data.common_name}
                            />
                            <ReviewRow
                                label="Physical form"
                                value={data.physical_form}
                            />
                            <ReviewRow
                                label="HSNO classification"
                                value={data.hsno_classification}
                            />
                            <ReviewRow
                                label="UN number"
                                value={data.un_number}
                            />
                            <ReviewRow
                                label="Hazard classes"
                                value={data.hazard_classifications.join(', ')}
                            />
                            <ReviewRow
                                label="GHS pictograms"
                                value={data.ghs_pictograms.join(', ')}
                            />
                            <ReviewRow
                                label="Signal word"
                                value={data.signal_word}
                            />
                            <ReviewRow
                                label="Controlled"
                                value={
                                    data.is_controlled_substance ? 'Yes' : 'No'
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Controls"
                            onEdit={() => goToStep('controls')}
                        >
                            <ReviewRow label="PPE" value={data.ppe_required} />
                            <ReviewRow
                                label="Storage"
                                value={data.storage_requirements}
                            />
                            <ReviewRow
                                label="Handling"
                                value={data.handling_precautions}
                            />
                            <ReviewRow
                                label="First aid"
                                value={data.first_aid_measures}
                            />
                            <ReviewRow
                                label="Spill"
                                value={data.spill_procedures}
                            />
                            <ReviewRow
                                label="WES limit"
                                value={
                                    data.exposure_limit_type
                                        ? `${data.exposure_limit_type} ${data.exposure_limit_value}`.trim()
                                        : ''
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
