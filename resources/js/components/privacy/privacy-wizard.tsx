/* eslint-disable no-restricted-syntax -- Config-driven privacy wizard built on
 * the shared WizardShell chrome (the Add-client contract). Styled native
 * controls for toggles; every colour is a semantic design token. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { type TileOption } from '@/pages/privacy/privacy-shared';
import { useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Loader2, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Config types                                                       */
/* ------------------------------------------------------------------ */

export type WizardFieldType =
    | 'tiles'
    | 'select'
    | 'chips'
    | 'text'
    | 'email'
    | 'number'
    | 'date'
    | 'textarea'
    | 'toggle'
    | 'staff'
    | 'client'
    | 'subhead'
    | 'info';

export type WizardField = {
    type: WizardFieldType;
    name?: string;
    label?: string;
    required?: boolean;
    hint?: string;
    placeholder?: string;
    span?: boolean;
    cols?: 2 | 3;
    tiles?: TileOption[];
    options?: string[];
    icon?: IconType;
    tone?: 'info' | 'warn' | 'crit';
    text?: string;
    reviewLabel?: string;
    reviewWhen?: (data: Record<string, unknown>) => boolean;
};

export type PrivacyWizardStep = {
    key: string;
    label: string;
    blurb: string;
    icon: IconType;
    headTitle: string;
    headBlurb: string;
    fields: WizardField[];
};

export type PrivacyWizardConfig = {
    domain: string;
    railIcon: IconType;
    railTitle: string;
    railSub: string;
    storeUrl: string;
    verb: string;
    successTitle: string;
    successBlurb: string;
    initial: Record<string, unknown>;
    steps: PrivacyWizardStep[];
    /** Optional payload shaping before POST (e.g. split textarea → string[]). */
    transform?: (data: Record<string, unknown>) => Record<string, unknown>;
};

export type StaffOption = { id: number; name: string };
export type ClientOption = { id: number; name: string };

/* ------------------------------------------------------------------ */
/*  Engine                                                             */
/* ------------------------------------------------------------------ */

export function PrivacyWizard({
    config,
    open,
    onClose,
    staff = [],
    clients = [],
    onCreated,
}: {
    config: PrivacyWizardConfig;
    open: boolean;
    onClose: () => void;
    staff?: StaffOption[];
    clients?: ClientOption[];
    onCreated?: () => void;
}) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- dynamic config-driven form shape
    const form = useForm<Record<string, any>>({
        ...config.initial,
        _modal: true,
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const steps: WizardStep[] = config.steps.map((s) => ({
        key: s.key,
        label: s.label,
        blurb: s.blurb,
        icon: s.icon,
    }));
    const cur = config.steps[stepIndex];
    const isReview = stepIndex === config.steps.length;

    // Completeness across every named, non-info/subhead field.
    const namedFields = useMemo(
        () =>
            config.steps.flatMap((s) =>
                s.fields.filter(
                    (f) => f.name && f.type !== 'subhead' && f.type !== 'info',
                ),
            ),
        [config],
    );
    const pct = useMemo(() => {
        if (!namedFields.length) return 0;
        const filled = namedFields.filter((f) =>
            isFilled(data[f.name as string]),
        ).length;
        return Math.round((filled / namedFields.length) * 100);
    }, [namedFields, data]);

    const fieldStep = (name: string): number => {
        const idx = config.steps.findIndex((s) =>
            s.fields.some((f) => f.name === name),
        );
        return idx < 0 ? 0 : idx;
    };

    const validateStep = (
        stepCfg: PrivacyWizardStep,
    ): Record<string, string> => {
        const e: Record<string, string> = {};
        for (const f of stepCfg.fields) {
            if (f.required && f.name && !isFilled(data[f.name])) {
                e[f.name] = `${f.label ?? 'This field'} is required`;
            }
            if (
                f.type === 'email' &&
                f.name &&
                isFilled(data[f.name]) &&
                !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(data[f.name]))
            ) {
                e[f.name] = 'Enter a valid email';
            }
        }
        return e;
    };

    const next = () => {
        const e = validateStep(cur);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, config.steps.length));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setData({ ...config.initial, _modal: true });
        setErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        const all: Record<string, string> = {};
        for (const s of config.steps) Object.assign(all, validateStep(s));
        if (Object.keys(all).length) {
            setErrors(all);
            setStepIndex(fieldStep(Object.keys(all)[0]));
            return;
        }
        setErrors({});
        form.transform((d) => (config.transform ? config.transform(d) : d));
        form.post(config.storeUrl, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                onCreated?.();
                if (addAnother) resetAll();
                else setDone(true);
            },
            onError: (errs) => {
                const first = Object.keys(errs)[0];
                if (first) setStepIndex(fieldStep(first));
            },
        });
    };

    const err = (name: string): string | undefined =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title={config.railTitle}
                description={config.successBlurb}
                railIcon={config.railIcon}
                railTitle={config.railTitle}
                railSub={config.railSub}
                steps={steps}
                stepIndex={config.steps.length - 1}
                onStepClick={() => undefined}
                success={
                    <WizardSuccessPane
                        title={config.successTitle}
                        blurb={config.successBlurb}
                        actions={
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={resetAll}
                                >
                                    <Plus className="h-4 w-4" /> Add another
                                </Button>
                                <Button type="button" onClick={onClose}>
                                    <Check className="h-4 w-4" /> Done
                                </Button>
                            </>
                        }
                    />
                }
            />
        );
    }

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={config.railTitle}
            description={`Guided wizard to ${config.verb.toLowerCase()}.`}
            railIcon={config.railIcon}
            railTitle={config.railTitle}
            railSub={config.railSub}
            steps={steps}
            stepIndex={Math.min(stepIndex, config.steps.length - 1)}
            onStepClick={(i) => setStepIndex(i)}
            pct={pct}
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
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => submit(true)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Plus className="h-4 w-4" />
                                )}
                                Save &amp; add another
                            </Button>
                            <Button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {config.verb}
                            </Button>
                        </>
                    ) : (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
        >
            {isReview ? (
                <WizardStepPane>
                    <StepHead
                        icon={Check}
                        title="Review &amp; confirm"
                        blurb="Check the details below, then save."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        {config.steps.map((s, i) => (
                            <ReviewCard
                                key={s.key}
                                icon={s.icon}
                                title={s.label}
                                onEdit={() => setStepIndex(i)}
                            >
                                {s.fields
                                    .filter(
                                        (f) =>
                                            f.name &&
                                            f.type !== 'subhead' &&
                                            f.type !== 'info',
                                    )
                                    .filter(
                                        (f) =>
                                            !f.reviewWhen || f.reviewWhen(data),
                                    )
                                    .map((f) => (
                                        <ReviewRow
                                            key={f.name}
                                            label={
                                                f.reviewLabel ??
                                                f.label ??
                                                f.name!
                                            }
                                            value={reviewValue(
                                                f,
                                                data,
                                                staff,
                                                clients,
                                            )}
                                        />
                                    ))}
                            </ReviewCard>
                        ))}
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead
                        icon={cur.icon}
                        title={cur.headTitle}
                        blurb={cur.headBlurb}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {cur.fields.map((f, i) => (
                            <FieldRenderer
                                key={f.name ?? `${cur.key}-${i}`}
                                field={f}
                                value={f.name ? data[f.name] : undefined}
                                error={f.name ? err(f.name) : undefined}
                                onChange={(v) => f.name && setData(f.name, v)}
                                staff={staff}
                                clients={clients}
                            />
                        ))}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Field renderer                                                     */
/* ------------------------------------------------------------------ */

function FieldRenderer({
    field: f,
    value,
    error,
    onChange,
    staff,
    clients,
}: {
    field: WizardField;
    value: unknown;
    error?: string;
    onChange: (v: unknown) => void;
    staff: StaffOption[];
    clients: ClientOption[];
}) {
    if (f.type === 'subhead') {
        return <SubHead icon={f.icon ?? Check}>{f.label}</SubHead>;
    }
    if (f.type === 'info') {
        return (
            <InfoCard icon={f.icon ?? Check} tone={f.tone ?? 'info'}>
                {f.text}
            </InfoCard>
        );
    }
    if (f.type === 'tiles') {
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint}
                error={error}
                span
            >
                <TilePicker
                    value={String(value ?? '')}
                    onChange={onChange}
                    cols={f.cols ?? 2}
                    options={(f.tiles ?? []).map((t) => ({
                        key: t.key,
                        label: t.label,
                        description: t.description,
                        icon: t.icon,
                    }))}
                />
            </Field>
        );
    }
    if (f.type === 'chips') {
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint ?? 'select all that apply'}
                error={error}
                span
            >
                <ChipMulti
                    values={Array.isArray(value) ? (value as string[]) : []}
                    onChange={onChange}
                    options={f.options ?? []}
                />
            </Field>
        );
    }
    if (f.type === 'select' || f.type === 'staff' || f.type === 'client') {
        const opts =
            f.type === 'staff'
                ? staff.map((s) => ({ value: String(s.id), label: s.name }))
                : f.type === 'client'
                  ? clients.map((c) => ({ value: String(c.id), label: c.name }))
                  : (f.options ?? []).map((o) => ({ value: o, label: o }));
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint}
                error={error}
                span={f.span}
            >
                <SelectInput
                    value={String(value ?? '')}
                    onChange={onChange}
                    placeholder={f.placeholder ?? 'Select…'}
                    options={opts}
                />
            </Field>
        );
    }
    if (f.type === 'textarea') {
        return (
            <Field
                label={f.label}
                required={f.required}
                hint={f.hint}
                error={error}
                span
            >
                <Textarea
                    rows={3}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={f.placeholder}
                />
            </Field>
        );
    }
    if (f.type === 'toggle') {
        return (
            <Field label={f.label} hint={f.hint} error={error} span={f.span}>
                <div className="flex items-center gap-2.5">
                    <Switch
                        checked={Boolean(value)}
                        onCheckedChange={onChange}
                    />
                    <span className="text-[13px] text-muted-foreground">
                        {f.placeholder ?? (value ? 'Yes' : 'No')}
                    </span>
                </div>
            </Field>
        );
    }
    // text / email / number / date
    return (
        <Field
            label={f.label}
            required={f.required}
            hint={f.hint}
            error={error}
            span={f.span}
        >
            <Input
                type={
                    f.type === 'number'
                        ? 'number'
                        : f.type === 'date'
                          ? 'date'
                          : f.type === 'email'
                            ? 'email'
                            : 'text'
                }
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
                placeholder={f.placeholder}
                aria-invalid={!!error}
            />
        </Field>
    );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function isFilled(v: unknown): boolean {
    if (Array.isArray(v)) return v.length > 0;
    return v !== '' && v != null && v !== false;
}

function reviewValue(
    f: WizardField,
    data: Record<string, unknown>,
    staff: StaffOption[],
    clients: ClientOption[],
): string {
    const v = f.name ? data[f.name] : undefined;
    if (v == null || v === '') return '';
    if (f.type === 'toggle') return v ? 'Yes' : 'No';
    if (Array.isArray(v)) return v.join(', ');
    if (f.type === 'tiles')
        return f.tiles?.find((t) => t.key === v)?.label ?? String(v);
    if (f.type === 'staff')
        return staff.find((s) => String(s.id) === String(v))?.name ?? String(v);
    if (f.type === 'client')
        return (
            clients.find((c) => String(c.id) === String(v))?.name ?? String(v)
        );
    return String(v);
}
