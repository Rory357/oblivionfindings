/* Generic config-driven H&S wizard (WS7). One WizardShell-based engine that renders any of
 * the workflow wizards from a declarative config (steps → fields), validates required fields,
 * shows the completeness meter, and posts to the workflow's endpoint with `stay` so the
 * dashboard refreshes in place → WizardSuccessPane. Tokens only; reuses wizard/primitives. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    ChipMulti,
    Field,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import { ClipboardCheck, type LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

export type RefData = {
    sites: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
};

export type FieldType =
    | 'text'
    | 'textarea'
    | 'date'
    | 'time'
    | 'datetime'
    | 'number'
    | 'select'
    | 'segmented'
    | 'tiles'
    | 'chips'
    | 'toggle';

export type FieldSpec = {
    key: string;
    label: string;
    type: FieldType;
    required?: boolean;
    hint?: string;
    span?: boolean;
    placeholder?: string;
    options?: Array<{ value: string; label: string; description?: string }>;
    /** Pull options from reference data instead of a static list. */
    source?: keyof RefData;
};

export type WizardStepSpec = {
    key: string;
    label: string;
    blurb: string;
    icon: LucideIcon;
    fields: FieldSpec[];
};

export type WizardConfig = {
    key: string;
    title: string;
    description: string;
    railIcon: LucideIcon;
    railTitle: string;
    railSub: string;
    endpoint: string;
    successTitle: string;
    successBlurb: string;
    steps: WizardStepSpec[];
    /** Optional transform of collected values into the POST payload. */
    transform?: (values: Record<string, unknown>) => Record<string, unknown>;
};

function initDefaults(config: WizardConfig): Record<string, unknown> {
    const v: Record<string, unknown> = {};
    for (const s of config.steps) {
        for (const f of s.fields) {
            v[f.key] = f.type === 'chips' ? [] : f.type === 'toggle' ? false : '';
        }
    }
    return v;
}

function optionsFor(f: FieldSpec, ref: RefData): Array<{ value: string; label: string }> {
    if (f.source) return ref[f.source].map((o) => ({ value: String(o.id), label: o.name }));
    return (f.options ?? []).map((o) => ({ value: o.value, label: o.label }));
}

function fieldValid(f: FieldSpec, v: unknown): boolean {
    if (!f.required) return true;
    if (f.type === 'chips') return Array.isArray(v) && v.length > 0;
    if (f.type === 'toggle') return true;
    return v != null && v !== '';
}

function FieldInput({
    f,
    value,
    onChange,
    refData,
}: {
    f: FieldSpec;
    value: unknown;
    onChange: (v: unknown) => void;
    refData: RefData;
}) {
    const opts = optionsFor(f, refData);
    const str = (value as string) ?? '';

    switch (f.type) {
        case 'textarea':
            return <Textarea value={str} onChange={(e) => onChange(e.target.value)} placeholder={f.placeholder} rows={3} />;
        case 'date':
            return <Input type="date" value={str} onChange={(e) => onChange(e.target.value)} />;
        case 'time':
            return <Input type="time" value={str} onChange={(e) => onChange(e.target.value)} />;
        case 'datetime':
            return <Input type="datetime-local" value={str} onChange={(e) => onChange(e.target.value)} />;
        case 'number':
            return <Input type="number" value={str} onChange={(e) => onChange(e.target.value)} placeholder={f.placeholder} />;
        case 'select':
            return <SelectInput value={str} onChange={onChange} placeholder={f.placeholder ?? 'Select…'} options={opts} />;
        case 'segmented':
            return <Segmented value={str} onChange={onChange} options={opts} />;
        case 'tiles':
            return <TilePicker value={str} onChange={onChange} options={opts.map((o) => ({ key: o.value, label: o.label }))} cols={2} />;
        case 'chips':
            return <ChipMulti values={(value as string[]) ?? []} onChange={onChange} options={opts.map((o) => o.label)} />;
        case 'toggle':
            return (
                <Segmented
                    value={value ? 'yes' : 'no'}
                    onChange={(v) => onChange(v === 'yes')}
                    options={[
                        { value: 'yes', label: 'Yes' },
                        { value: 'no', label: 'No' },
                    ]}
                />
            );
        default:
            return <Input value={str} onChange={(e) => onChange(e.target.value)} placeholder={f.placeholder} />;
    }
}

export function HsFormWizard({
    config,
    refData,
    open,
    onClose,
}: {
    config: WizardConfig;
    refData: RefData;
    open: boolean;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [values, setValues] = useState<Record<string, unknown>>(() => initDefaults(config));

    const steps: WizardStep[] = config.steps.map((s) => ({ key: s.key, label: s.label, blurb: s.blurb, icon: s.icon }));
    const requiredFields = useMemo(
        () => config.steps.flatMap((s) => s.fields).filter((f) => f.required),
        [config],
    );
    const pct =
        requiredFields.length === 0
            ? 100
            : Math.round((requiredFields.filter((f) => fieldValid(f, values[f.key])).length / requiredFields.length) * 100);
    const stepValid = (i: number) => config.steps[i].fields.every((f) => fieldValid(f, values[f.key]));

    const set = (k: string, v: unknown) => setValues((prev) => ({ ...prev, [k]: v }));
    const reset = () => {
        setValues(initDefaults(config));
        setStep(0);
        setSubmitted(false);
    };
    const close = () => {
        reset();
        onClose();
    };

    const submit = () => {
        setProcessing(true);
        const payload = { ...(config.transform ? config.transform(values) : values), stay: true };
        router.post(config.endpoint, payload as Record<string, string | number | boolean | string[] | null>, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setSubmitted(true),
            onFinish: () => setProcessing(false),
        });
    };

    const last = step === config.steps.length - 1;
    const canContinue = stepValid(step);
    const current = config.steps[step];

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={config.title}
            description={config.description}
            railIcon={config.railIcon}
            railTitle={config.railTitle}
            railSub={config.railSub}
            steps={steps}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            pct={pct}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title={config.successTitle}
                        blurb={config.successBlurb}
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    Record another
                                </Button>
                                <Button onClick={close}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                step > 0 ? (
                    <Button variant="outline" onClick={() => setStep(step - 1)}>
                        Back
                    </Button>
                ) : (
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                !last ? (
                    <Button onClick={() => canContinue && setStep(step + 1)} disabled={!canContinue}>
                        Continue
                    </Button>
                ) : (
                    <Button onClick={submit} disabled={processing || !canContinue}>
                        Save &amp; submit
                    </Button>
                )
            }
        >
            <WizardStepPane>
                {current.key === 'review' ? (
                    <>
                        <StepHead icon={ClipboardCheck} title="Review & submit" blurb="Confirm the details before filing." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            {config.steps
                                .filter((s) => s.key !== 'review')
                                .map((s, i) => (
                                    <ReviewCard key={s.key} icon={s.icon} title={s.label} onEdit={() => setStep(i)}>
                                        {s.fields.map((f) => (
                                            <ReviewRow
                                                key={f.key}
                                                label={f.label}
                                                value={reviewValue(f, values[f.key], refData)}
                                            />
                                        ))}
                                    </ReviewCard>
                                ))}
                        </div>
                    </>
                ) : (
                    <>
                        <StepHead icon={current.icon} title={current.label} blurb={current.blurb} />
                        <div className="grid gap-4 sm:grid-cols-2">
                            {current.fields.map((f) => (
                                <Field
                                    key={f.key}
                                    label={f.label}
                                    required={f.required}
                                    hint={f.hint}
                                    span={f.span || f.type === 'textarea' || f.type === 'tiles' || f.type === 'chips'}
                                >
                                    <FieldInput f={f} value={values[f.key]} onChange={(v) => set(f.key, v)} refData={refData} />
                                </Field>
                            ))}
                        </div>
                    </>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

function reviewValue(f: FieldSpec, v: unknown, ref: RefData): string {
    if (v == null || v === '' || (Array.isArray(v) && v.length === 0)) return '';
    if (f.type === 'toggle') return v ? 'Yes' : 'No';
    if (f.type === 'chips') return (v as string[]).join(', ');
    if (f.source) {
        const found = ref[f.source].find((o) => String(o.id) === String(v));
        return found ? found.name : String(v);
    }
    if (f.options) {
        const found = f.options.find((o) => o.value === v);
        return found ? found.label : String(v);
    }
    return String(v);
}

export default HsFormWizard;
