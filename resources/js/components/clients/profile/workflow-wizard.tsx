/* Generic config-driven workflow wizard for the client profile redesign.
 * One declarative config per workflow (see flows.tsx) rendered through the
 * shared WizardShell so every create/record flow matches the Add Client UX:
 * stepper rail + blurbs, "Step x of y" header, scroll body, per-step required
 * validation and an auto-generated Review & save step. */
import {
    ChipMulti,
    Field,
    InfoCard,
    Ring,
    SelectInput,
    StepHead,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertCircle,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Loader2,
    Plus,
    UploadCloud,
    User,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

/* ------------------------------------------------------------------ types */

export type WizardFieldOption = string | { value: string; label: string };

export type WizardField = {
    key: string;
    label: string;
    type?:
        | 'text'
        | 'textarea'
        | 'select'
        | 'date'
        | 'time'
        | 'datetime-local'
        | 'number'
        | 'checkbox'
        | 'chips'
        | 'file';
    options?: WizardFieldOption[];
    required?: boolean;
    full?: boolean;
    placeholder?: string;
    rows?: number;
    hint?: string;
    /** Checkbox helper text. */
    desc?: string;
    accept?: string;
    /** Hide the field unless this returns true for the current values. */
    when?: (values: WizardValues) => boolean;
};

export type WizardPickerOption = {
    key: string;
    label: string;
    icon?: IconType;
    desc?: string;
};

export type WizardPicker = {
    key: string;
    label: string;
    options: WizardPickerOption[];
    cols?: 2 | 3;
};

export type WizardStepConfig = {
    key: string;
    label: string;
    icon: IconType;
    blurb: string;
    heading?: string;
    desc?: string;
    picker?: WizardPicker;
    fields?: WizardField[];
    info?: ReactNode;
    infoTone?: 'info' | 'warn' | 'crit';
    infoIcon?: IconType;
};

export type WizardValues = Record<string, unknown>;

export type WizardSubmitHelpers = {
    /** Submission accepted — wizard shows the toast and closes (or resets). */
    onDone: () => void;
    /** Submission failed — re-enable the form, optionally mark fields. */
    onError: (errors?: Record<string, string>) => void;
};

export type WorkflowConfig = {
    key: string;
    icon: IconType;
    title: string;
    sub?: string;
    width?: number;
    submitLabel?: string;
    /** "Save & add another" secondary submit. */
    again?: boolean;
    reviewTitle?: string;
    reviewBlurb?: string;
    steps: WizardStepConfig[];
    initialValues?: WizardValues;
    submit: (values: WizardValues, helpers: WizardSubmitHelpers) => void;
};

/* ------------------------------------------------------------- utilities */

function isFilled(value: unknown): boolean {
    if (Array.isArray(value)) return value.length > 0;
    if (typeof value === 'boolean') return value;
    if (value instanceof File) return true;
    return String(value ?? '').trim() !== '';
}

function displayValue(field: WizardField, value: unknown): string {
    if (field.type === 'checkbox') return value ? 'Yes' : 'No';
    if (value instanceof File) return value.name;
    if (Array.isArray(value)) return value.length ? value.join(', ') : '—';
    if (field.type === 'select' && field.options) {
        const match = field.options.find(
            (o) => (typeof o === 'string' ? o : o.value) === value,
        );
        if (match && typeof match !== 'string') return match.label;
    }
    return isFilled(value) ? String(value) : '—';
}

function buildInitialValues(config: WorkflowConfig): WizardValues {
    const values: WizardValues = {};
    for (const step of config.steps) {
        if (step.picker) {
            values[step.picker.key] =
                config.initialValues?.[step.picker.key] ??
                step.picker.options[0]?.key ??
                '';
        }
        for (const field of step.fields ?? []) {
            values[field.key] =
                config.initialValues?.[field.key] ??
                (field.type === 'checkbox'
                    ? false
                    : field.type === 'chips'
                      ? []
                      : field.type === 'file'
                        ? null
                        : '');
        }
    }
    return values;
}

/* ------------------------------------------------------------ field input */

function WizardFieldInput({
    field,
    value,
    onChange,
    error,
}: {
    field: WizardField;
    value: unknown;
    onChange: (value: unknown) => void;
    error?: string;
}) {
    if (field.type === 'checkbox') {
        return (
            <label
                className={`flex cursor-pointer items-start gap-3 rounded-lg border bg-card/50 p-3 transition-colors hover:border-primary/40 ${
                    error ? 'border-status-critical' : 'border-border'
                } ${field.full ? 'sm:col-span-2' : ''}`}
            >
                <Checkbox
                    checked={Boolean(value)}
                    onCheckedChange={(checked) => onChange(checked === true)}
                    className="mt-0.5"
                />
                <span>
                    <span className="block text-sm font-medium">
                        {field.label}
                        {field.required ? (
                            <span className="text-status-critical"> *</span>
                        ) : null}
                    </span>
                    {field.desc ? (
                        <span className="block text-xs text-muted-foreground">
                            {field.desc}
                        </span>
                    ) : null}
                </span>
            </label>
        );
    }

    if (field.type === 'chips') {
        return (
            <Field
                label={field.label}
                required={field.required}
                hint={field.hint}
                error={error}
                span={field.full}
            >
                <ChipMulti
                    values={Array.isArray(value) ? (value as string[]) : []}
                    onChange={onChange}
                    options={(field.options ?? []).map((o) =>
                        typeof o === 'string' ? o : o.label,
                    )}
                />
            </Field>
        );
    }

    if (field.type === 'file') {
        const file = value instanceof File ? value : null;
        return (
            <Field
                label={field.label}
                required={field.required}
                hint={field.hint}
                error={error}
                span={field.full}
            >
                {/* eslint-disable-next-line no-restricted-syntax -- styled file drop target per the design handoff */}
                <label
                    className={`flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed bg-card/40 px-4 py-6 text-center transition-colors hover:border-primary/50 hover:bg-accent/50 ${
                        error ? 'border-status-critical' : 'border-border'
                    }`}
                >
                    <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-accent text-primary">
                        <UploadCloud className="h-[18px] w-[18px]" />
                    </span>
                    <span className="text-sm font-medium">
                        {file ? file.name : 'Choose a file or drag it here'}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                        {field.placeholder ?? 'PDF, DOCX, JPG · up to 10 MB'}
                    </span>
                    <input
                        type="file"
                        accept={field.accept}
                        className="hidden"
                        onChange={(e) => onChange(e.target.files?.[0] ?? null)}
                    />
                </label>
            </Field>
        );
    }

    if (field.type === 'select') {
        return (
            <Field
                label={field.label}
                required={field.required}
                hint={field.hint}
                error={error}
                span={field.full}
            >
                <SelectInput
                    value={String(value ?? '')}
                    onChange={onChange}
                    placeholder={field.placeholder ?? 'Select…'}
                    options={(field.options ?? []).map((o) =>
                        typeof o === 'string' ? { value: o, label: o } : o,
                    )}
                />
            </Field>
        );
    }

    if (field.type === 'textarea') {
        return (
            <Field
                label={field.label}
                required={field.required}
                hint={field.hint}
                error={error}
                span={field.full ?? true}
            >
                <Textarea
                    value={String(value ?? '')}
                    rows={field.rows ?? 3}
                    placeholder={field.placeholder}
                    aria-invalid={error ? true : undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
            </Field>
        );
    }

    return (
        <Field
            label={field.label}
            required={field.required}
            hint={field.hint}
            error={error}
            span={field.full}
        >
            <Input
                type={field.type ?? 'text'}
                value={String(value ?? '')}
                placeholder={field.placeholder}
                aria-invalid={error ? true : undefined}
                onChange={(e) => onChange(e.target.value)}
            />
        </Field>
    );
}

/* ----------------------------------------------------------------- dialog */

export function WorkflowWizardDialog({
    config,
    open,
    onClose,
    clientLabel,
}: {
    config: WorkflowConfig;
    open: boolean;
    onClose: () => void;
    /** "Tane Wineera · Tūī House" locked-context chip on step one. */
    clientLabel?: string;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [values, setValues] = useState<WizardValues>(() =>
        buildInitialValues(config),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    // Re-seed whenever the dialog (re)opens or the flow changes.
    useEffect(() => {
        if (open) {
            setValues(buildInitialValues(config));
            setStepIndex(0);
            setErrors({});
            setBusy(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reseed on open/flow change only
    }, [open, config.key]);

    const reviewIndex = config.steps.length;
    const isReview = stepIndex === reviewIndex;
    const current = isReview ? null : config.steps[stepIndex];

    const railSteps: WizardStep[] = useMemo(
        () => [
            ...config.steps.map((s) => ({
                key: s.key,
                label: s.label,
                blurb: s.blurb,
                icon: s.icon,
            })),
            {
                key: '__review',
                label: config.reviewTitle ?? 'Review & save',
                blurb: 'Confirm and save',
                icon: CheckCircle2,
            },
        ],
        [config],
    );

    const visibleFields = useCallback(
        (step: WizardStepConfig) =>
            (step.fields ?? []).filter((f) => !f.when || f.when(values)),
        [values],
    );

    const allFields = useMemo(
        () => config.steps.flatMap((s) => visibleFields(s)),
        [config, visibleFields],
    );
    const filled = allFields.filter((f) => isFilled(values[f.key])).length;
    const pct = allFields.length
        ? Math.round((filled / allFields.length) * 100)
        : 100;

    const setValue = (key: string, value: unknown) => {
        setValues((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => {
            if (!prev[key]) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
    };

    const validateStep = (step: WizardStepConfig): boolean => {
        const stepErrors: Record<string, string> = {};
        for (const field of visibleFields(step)) {
            if (field.required && !isFilled(values[field.key])) {
                stepErrors[field.key] = `${field.label} is required.`;
            }
        }
        if (Object.keys(stepErrors).length) {
            setErrors(stepErrors);
            return false;
        }
        setErrors({});
        return true;
    };

    const next = () => {
        if (!current || !validateStep(current)) return;
        setStepIndex((i) => i + 1);
    };

    const jumpTo = (index: number) => {
        if (index <= stepIndex) {
            setStepIndex(index);
            setErrors({});
        } else if (current && validateStep(current)) {
            setStepIndex(Math.min(index, reviewIndex));
        }
    };

    const submit = (again: boolean) => {
        setBusy(true);
        config.submit(values, {
            onDone: () => {
                if (again) {
                    setValues(buildInitialValues(config));
                    setStepIndex(0);
                    setErrors({});
                    setBusy(false);
                } else {
                    onClose();
                }
            },
            onError: (serverErrors) => {
                setBusy(false);
                if (serverErrors && Object.keys(serverErrors).length) {
                    setErrors(serverErrors);
                    // Jump back to the first step containing an offending field.
                    const errorKeys = new Set(Object.keys(serverErrors));
                    const ownerIndex = config.steps.findIndex((s) =>
                        (s.fields ?? []).some((f) => errorKeys.has(f.key)),
                    );
                    if (ownerIndex >= 0) setStepIndex(ownerIndex);
                }
            },
        });
    };

    const errorCount = Object.keys(errors).length;

    return (
        <WizardShell
            open={open}
            onClose={() => !busy && onClose()}
            title={config.title}
            description={config.sub ?? 'Guided workflow'}
            railIcon={config.icon}
            railTitle={config.title}
            railSub={config.sub ?? 'Guided workflow'}
            steps={railSteps}
            stepIndex={stepIndex}
            onStepClick={jumpTo}
            pct={pct}
            footerStart={
                <div className="flex items-center gap-3">
                    {stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => i - 1)}
                            disabled={busy}
                        >
                            <ChevronLeft className="mr-1 h-4 w-4" /> Back
                        </Button>
                    ) : null}
                    {errorCount ? (
                        <span className="flex items-center gap-1.5 text-xs font-medium text-status-critical">
                            <AlertCircle className="h-3.5 w-3.5" />
                            Fill in the required field{errorCount > 1 ? 's' : ''}.
                        </span>
                    ) : null}
                </div>
            }
            footerEnd={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={busy}
                    >
                        Cancel
                    </Button>
                    {isReview ? (
                        <>
                            {config.again ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => submit(true)}
                                    disabled={busy}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Save & add another
                                </Button>
                            ) : null}
                            <Button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={busy}
                                data-test={`wizard-submit-${config.key}`}
                            >
                                {busy ? (
                                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="mr-1.5 h-4 w-4" />
                                )}
                                {busy
                                    ? 'Saving…'
                                    : (config.submitLabel ?? 'Save')}
                            </Button>
                        </>
                    ) : (
                        <Button
                            type="button"
                            onClick={next}
                            data-test={`wizard-continue-${config.key}`}
                        >
                            Continue
                            <ChevronRight className="ml-1 h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {isReview ? (
                <WizardStepPane key="__review">
                    <StepHead
                        icon={CheckCircle2}
                        title={config.reviewTitle ?? 'Review & save'}
                        blurb={
                            config.reviewBlurb ??
                            'Check the details below — you can jump back to any step to edit.'
                        }
                    />
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={pct} size={64} />
                        <div>
                            <div className="text-sm font-semibold">
                                {pct >= 80
                                    ? 'Ready to save'
                                    : 'Ready when you are'}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {pct >= 80
                                    ? 'All key information is filled in.'
                                    : 'Optional fields can be completed later — required fields are done.'}
                            </p>
                        </div>
                    </div>
                    <div className="space-y-3">
                        {config.steps.map((step, index) => (
                            <ReviewCard
                                key={step.key}
                                icon={step.icon}
                                title={step.label}
                                onEdit={() => setStepIndex(index)}
                            >
                                {step.picker ? (
                                    <ReviewRow
                                        label={step.picker.label}
                                        value={
                                            step.picker.options.find(
                                                (o) =>
                                                    o.key ===
                                                    values[step.picker!.key],
                                            )?.label
                                        }
                                    />
                                ) : null}
                                {visibleFields(step).map((field) => (
                                    <ReviewRow
                                        key={field.key}
                                        label={field.label}
                                        value={
                                            isFilled(values[field.key])
                                                ? displayValue(
                                                      field,
                                                      values[field.key],
                                                  )
                                                : undefined
                                        }
                                    />
                                ))}
                            </ReviewCard>
                        ))}
                    </div>
                </WizardStepPane>
            ) : current ? (
                <WizardStepPane key={current.key}>
                    <StepHead
                        icon={current.icon}
                        title={current.heading ?? current.label}
                        blurb={current.desc ?? current.blurb}
                    />
                    {clientLabel && stepIndex === 0 ? (
                        <div className="mb-4 flex items-center gap-3 rounded-xl border border-primary/40 bg-accent px-3 py-2.5">
                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-card text-primary">
                                <User className="h-[15px] w-[15px]" />
                            </span>
                            <div className="min-w-0">
                                <div className="truncate text-sm font-medium">
                                    {clientLabel}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    Locked to the client you opened.
                                </div>
                            </div>
                        </div>
                    ) : null}
                    {current.picker ? (
                        <div className="mb-4">
                            <p className="mb-1.5 text-sm font-medium">
                                {current.picker.label}
                            </p>
                            <TilePicker
                                value={String(
                                    values[current.picker.key] ?? '',
                                )}
                                onChange={(v) =>
                                    setValue(current.picker!.key, v)
                                }
                                options={current.picker.options.map((o) => ({
                                    key: o.key,
                                    label: o.label,
                                    description: o.desc,
                                    icon: o.icon,
                                }))}
                                cols={current.picker.cols ?? 3}
                            />
                        </div>
                    ) : null}
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        {visibleFields(current).map((field) => (
                            <WizardFieldInput
                                key={field.key}
                                field={field}
                                value={values[field.key]}
                                onChange={(v) => setValue(field.key, v)}
                                error={errors[field.key]}
                            />
                        ))}
                    </div>
                    {current.info ? (
                        <div className="mt-4">
                            <InfoCard
                                icon={current.infoIcon ?? AlertCircle}
                                tone={current.infoTone ?? 'info'}
                            >
                                {current.info}
                            </InfoCard>
                        </div>
                    ) : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
