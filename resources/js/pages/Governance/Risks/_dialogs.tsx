/**
 * Risk register dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - RiskWizardDialog — the entity add/edit wizard (WizardShell). Add opens
 *     from the register header, Edit from the risk record; both use the SAME
 *     steps, prefilled on edit.
 *   - AddTreatmentDialog / AcceptRiskDialog / CloseRiskDialog /
 *     CompleteTreatmentDialog / ChangeTreatmentDueDateDialog — simple dialogs
 *     opened from the risk record.
 *
 * Wording follows vocabulary.md: risk before/after controls, the board's
 * limit, "Reduce it (treat)". Fields, rules and side effects mirror
 * StoreRiskRegisterRequest / UpdateRiskRegisterRequest and
 * RiskRegisterController.
 */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
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
import { formatDateLong, formatDateOnly, toDateInput } from '@/lib/datetime';
import {
    frequencyLabel,
    refSuffix,
    riskCategoryLabel,
    riskImpactLabel,
    riskLikelihoodLabel,
} from '@/lib/governance-labels';
import { Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    Ban,
    Banknote,
    Briefcase,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Cpu,
    Gauge,
    HeartPulse,
    Loader2,
    Megaphone,
    Plus,
    Scale,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    Stethoscope,
    Target,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

import {
    CONTROL_HELP,
    controlText,
    residualFor,
    riskBandLabel,
    strategyLabel,
} from './_shared';

/* ------------------------------------------------------------------ */
/*  Registries                                                         */
/* ------------------------------------------------------------------ */

export interface RiskOption {
    value: string;
    label: string;
}

export interface RiskOwnerOption {
    id: number;
    name: string;
}

export interface RiskFormOptions {
    categories: RiskOption[];
    owners: RiskOwnerOption[];
    /** The board's limit (appetite threshold) per category. */
    limits?: Record<string, number>;
}

const CATEGORY_ICONS: Record<string, LucideIcon> = {
    client_safety: HeartPulse,
    reputational: Megaphone,
    financial: Banknote,
    it_cyber: Cpu,
    workforce: Users,
    legal_compliance: Scale,
    operational: Briefcase,
    clinical: Stethoscope,
};

export function riskCategoryIcon(category: string): LucideIcon {
    return CATEGORY_ICONS[category] ?? ShieldAlert;
}

export const LIKELIHOOD_OPTIONS = ['1', '2', '3', '4', '5'].map((value) => ({
    value,
    label: `${value} · ${riskLikelihoodLabel(value)}`,
}));

export const IMPACT_OPTIONS = ['1', '2', '3', '4', '5'].map((value) => ({
    value,
    label: `${value} · ${riskImpactLabel(value)}`,
}));

export const CONTROL_OPTIONS = ['none', 'weak', 'moderate', 'strong'].map(
    (value) => ({ value, label: controlText(value) }),
);

export const STRATEGY_OPTIONS = [
    {
        key: 'treat',
        description: 'Take action to make it less likely or less harmful.',
        icon: Wrench,
    },
    {
        key: 'transfer',
        description: 'Share it through insurance, contracts or partners.',
        icon: ArrowRightLeft,
    },
    {
        key: 'terminate',
        description: 'Stop the activity that creates the risk.',
        icon: Ban,
    },
    {
        key: 'tolerate',
        description:
            "Keep it as it is and keep watching it. Above the board's limit, only the board can accept a risk.",
        icon: ShieldCheck,
    },
].map((option) => ({ ...option, label: strategyLabel(option.key) }));

export const REVIEW_FREQUENCY_OPTIONS = ['monthly', 'quarterly', 'annual'].map(
    (value) => ({ value, label: frequencyLabel(value) }),
);

function optionLabel(
    options: { value: string; label: string }[],
    value: string | null | undefined,
): string | undefined {
    return options.find((o) => o.value === value)?.label;
}

type FlashPage = { props: { flash?: { error?: string | null } } };

function flashError(page: unknown): string | null {
    return (page as FlashPage | undefined)?.props?.flash?.error ?? null;
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

/** "32 / 50 characters" */
function CharacterCount({ value, min }: { value: string; min: number }) {
    const length = value.trim().length;
    return (
        <span
            className={
                length >= min
                    ? 'text-xs text-status-success'
                    : 'text-xs text-muted-foreground'
            }
            aria-live="polite"
        >
            {length} / {min} characters
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Risk wizard (add + edit)                                           */
/* ------------------------------------------------------------------ */

export interface RiskRecord {
    id: number;
    risk_reference?: string;
    title: string;
    category: string;
    description: string | null;
    likelihood_score: number;
    impact_score: number;
    control_effectiveness: string;
    mitigation_strategy: string | null;
    review_frequency: string | null;
    risk_owner_id?: number | null;
    risk_owner?: { id: number; name: string } | null;
    appetite_threshold?: number;
}

type RiskWizardForm = {
    category: string;
    title: string;
    description: string;
    likelihood_score: string;
    impact_score: string;
    control_effectiveness: string;
    risk_owner_id: string;
    mitigation_strategy: string;
    review_frequency: string;
};

const RISK_STEPS: readonly WizardStep[] = [
    {
        key: 'identify',
        label: 'The risk',
        blurb: 'Kind of risk, name and description',
        icon: ShieldAlert,
    },
    {
        key: 'assess',
        label: 'How serious',
        blurb: 'Likelihood, impact and controls',
        icon: Gauge,
    },
    {
        key: 'respond',
        label: 'Response',
        blurb: 'What you will do, owner and reviews',
        icon: Target,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: CheckCircle2,
    },
];

const RISK_STEP_FOR_FIELD: Record<string, number> = {
    category: 0,
    title: 0,
    description: 0,
    likelihood_score: 1,
    impact_score: 1,
    control_effectiveness: 1,
    mitigation_strategy: 2,
    risk_owner_id: 2,
    review_frequency: 2,
};

function initialRiskForm(risk: RiskRecord | null): RiskWizardForm {
    return {
        category: risk?.category ?? '',
        title: risk?.title ?? '',
        description: risk?.description ?? '',
        likelihood_score: String(risk?.likelihood_score ?? 3),
        impact_score: String(risk?.impact_score ?? 3),
        control_effectiveness: risk?.control_effectiveness ?? 'moderate',
        risk_owner_id:
            risk?.risk_owner_id != null
                ? String(risk.risk_owner_id)
                : risk?.risk_owner?.id != null
                  ? String(risk.risk_owner.id)
                  : '',
        mitigation_strategy: risk
            ? (risk.mitigation_strategy ?? '')
            : 'treat',
        review_frequency: risk?.review_frequency ?? 'quarterly',
    };
}

function validateRiskStep(
    index: number,
    data: RiskWizardForm,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (index === 0) {
        if (!data.category) e.category = 'Choose what kind of risk this is.';
        if (!data.title.trim()) e.title = 'Give the risk a name.';
        else if (data.title.length > 255)
            e.title = 'Keep the name to 255 characters or fewer.';
        if (!data.description.trim())
            e.description = 'Describe what could happen and why.';
    }
    return e;
}

export interface RiskWizardDialogProps {
    open: boolean;
    onClose: () => void;
    options: RiskFormOptions;
    /** When set the wizard edits this risk; otherwise it adds a new one. */
    risk?: RiskRecord | null;
    onSaved?: () => void;
}

export function RiskWizardDialog(props: RiskWizardDialogProps) {
    // Re-mount the body on every open so the form resets cleanly.
    return props.open ? <RiskWizardBody {...props} /> : null;
}

function RiskWizardBody({
    open,
    onClose,
    options,
    risk = null,
    onSaved,
}: RiskWizardDialogProps) {
    const isEdit = risk != null;
    const initial = useMemo(() => initialRiskForm(risk), [risk]);
    const form = useForm<RiskWizardForm>(initial);
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const set = (key: keyof RiskWizardForm, value: string) =>
        setData((prev) => ({ ...prev, [key]: value }));
    const err = (name: string): string | undefined =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const cur = RISK_STEPS[stepIndex];
    const isReview = cur.key === 'review';

    const inherent = Number(data.likelihood_score) * Number(data.impact_score);
    const residual = residualFor(inherent, data.control_effectiveness);
    const limit =
        (data.category ? options.limits?.[data.category] : undefined) ??
        (isEdit ? risk?.appetite_threshold : undefined);

    const categoryLabel = data.category
        ? (optionLabel(options.categories, data.category) ??
          riskCategoryLabel(data.category))
        : '';
    const ownerName =
        options.owners.find((o) => String(o.id) === data.risk_owner_id)
            ?.name ??
        (isEdit ? risk?.risk_owner?.name : undefined);

    const pct = useMemo(() => {
        const checks = [
            !!data.category,
            !!data.title.trim(),
            !!data.description.trim(),
            !!data.likelihood_score,
            !!data.impact_score,
            !!data.control_effectiveness,
            !!data.mitigation_strategy,
            !!data.risk_owner_id,
            !!data.review_frequency,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goTo = (index: number) =>
        setStepIndex(Math.max(0, Math.min(index, RISK_STEPS.length - 1)));

    const next = () => {
        const e = validateRiskStep(stepIndex, data);
        setErrors(e);
        if (Object.keys(e).length === 0) goTo(stepIndex + 1);
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const resetAll = () => {
        form.clearErrors();
        setData(initialRiskForm(null));
        setErrors({});
        setSubmitError(null);
        setStepIndex(0);
        setDone(false);
    };

    const handleError = (errs: Record<string, string>) => {
        const first = Object.keys(errs)[0];
        if (first) goTo(RISK_STEP_FOR_FIELD[first] ?? 0);
    };

    const submit = () => {
        const all = validateRiskStep(0, data);
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(RISK_STEP_FOR_FIELD[Object.keys(all)[0]] ?? 0);
            return;
        }
        setErrors({});
        setSubmitError(null);

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                setDone(true);
                onSaved?.();
            },
            onError: handleError,
        };

        if (isEdit && risk) {
            // UpdateRiskRegisterRequest accepts only these fields; the kind of
            // risk and how often it is reviewed are fixed once it is added.
            form.transform((current) => {
                const payload: Record<string, unknown> = {
                    title: current.title,
                    description: current.description,
                    likelihood_score: Number(current.likelihood_score),
                    impact_score: Number(current.impact_score),
                    control_effectiveness: current.control_effectiveness,
                };
                if (current.mitigation_strategy) {
                    payload.mitigation_strategy = current.mitigation_strategy;
                }
                if (
                    current.risk_owner_id &&
                    current.risk_owner_id !== initial.risk_owner_id
                ) {
                    payload.risk_owner_id = Number(current.risk_owner_id);
                }
                return payload as RiskWizardForm;
            });
            form.put(`/governance/risks/${risk.id}`, visit);
            return;
        }

        form.transform(
            (current) =>
                ({
                    ...current,
                    likelihood_score: Number(current.likelihood_score),
                    impact_score: Number(current.impact_score),
                    risk_owner_id: current.risk_owner_id
                        ? Number(current.risk_owner_id)
                        : null,
                    _modal: true,
                }) as unknown as RiskWizardForm,
        );
        form.post('/governance/risks', visit);
    };

    const limitSentence =
        limit !== undefined
            ? residual > limit
                ? `The board's limit for ${categoryLabel.toLowerCase()} risks is ${limit}, so this risk would be above it.`
                : `The board's limit for ${categoryLabel.toLowerCase()} risks is ${limit}, so this risk would be within it.`
            : null;

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Risk saved' : 'Risk added'}
            blurb={
                isEdit ? (
                    <>
                        <strong>{data.title}</strong> has been saved and its
                        scores recalculated.
                    </>
                ) : (
                    <>
                        <strong>{data.title}</strong> ({categoryLabel}) is now on
                        the register with a risk after controls of {residual}.
                    </>
                )
            }
            actions={
                isEdit ? (
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={resetAll}
                        >
                            <Plus className="h-4 w-4" /> Add another
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Done
                        </Button>
                    </>
                )
            }
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                title={isEdit ? 'Edit risk' : 'Add risk'}
                description={
                    isEdit
                        ? 'Update how serious this risk is and how it is handled. Scores are recalculated when you save.'
                        : 'Record a risk the board should know about, how serious it is and how it will be handled.'
                }
                railIcon={ShieldAlert}
                railTitle={isEdit ? 'Edit risk' : 'New risk'}
                railSub="Risk register"
                steps={RISK_STEPS}
                stepIndex={stepIndex}
                onStepClick={goTo}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => goTo(stepIndex - 1)}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        {submitError ? (
                            <span
                                role="alert"
                                className="max-w-xs text-xs text-status-critical"
                            >
                                {submitError}
                            </span>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
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
                                {isEdit ? 'Save changes' : 'Add risk'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                {cur.key === 'identify' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={ShieldAlert}
                            title="What is the risk?"
                            blurb="Choose what kind of risk it is and describe what could happen."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Kind of risk"
                                required
                                error={err('category')}
                                hint={isEdit ? 'set when the risk was added' : undefined}
                            >
                                {isEdit ? (
                                    <InfoCard
                                        icon={riskCategoryIcon(data.category)}
                                    >
                                        <strong>{categoryLabel}</strong> — the kind
                                        of risk, and the board&apos;s limit that
                                        goes with it, stay fixed once a risk is
                                        added.
                                    </InfoCard>
                                ) : (
                                    <TilePicker
                                        value={data.category}
                                        onChange={(v) => set('category', v)}
                                        cols={2}
                                        options={options.categories.map((c) => ({
                                            key: c.value,
                                            label: c.label,
                                            icon: riskCategoryIcon(c.value),
                                            meta:
                                                options.limits?.[c.value] !== undefined
                                                    ? `The board's limit: ${options.limits[c.value]}`
                                                    : undefined,
                                        }))}
                                    />
                                )}
                            </Field>
                            <Field label="Name" required error={err('title')}>
                                <Input
                                    value={data.title}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    maxLength={255}
                                    placeholder="e.g. Private information about the people we support is shared by mistake"
                                    aria-invalid={!!err('title')}
                                />
                            </Field>
                            <Field
                                label="Description"
                                required
                                error={err('description')}
                            >
                                <Textarea
                                    rows={4}
                                    value={data.description}
                                    onChange={(e) =>
                                        set('description', e.target.value)
                                    }
                                    placeholder="What could happen, what causes it, and who would be affected."
                                    aria-invalid={!!err('description')}
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'assess' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={Gauge}
                            title="How serious is it?"
                            blurb="Score how likely it is and how much harm it would do, then say how well the current controls work."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Likelihood"
                                required
                                hint="how likely it is to happen"
                                error={err('likelihood_score')}
                            >
                                <Segmented
                                    value={data.likelihood_score}
                                    onChange={(v) =>
                                        set('likelihood_score', v)
                                    }
                                    options={LIKELIHOOD_OPTIONS}
                                />
                            </Field>
                            <Field
                                label="Impact"
                                required
                                hint="how much harm it would do"
                                error={err('impact_score')}
                            >
                                <Segmented
                                    value={data.impact_score}
                                    onChange={(v) => set('impact_score', v)}
                                    options={IMPACT_OPTIONS}
                                />
                            </Field>
                            <Field
                                label="How well current controls work"
                                error={err('control_effectiveness')}
                            >
                                <Segmented
                                    value={data.control_effectiveness}
                                    onChange={(v) =>
                                        set('control_effectiveness', v)
                                    }
                                    options={CONTROL_OPTIONS}
                                />
                                <p className="text-caption mt-1.5">{CONTROL_HELP}</p>
                            </Field>
                            <InfoCard
                                icon={AlertTriangle}
                                tone={
                                    residual >= 20
                                        ? 'crit'
                                        : residual >= 10
                                          ? 'warn'
                                          : 'info'
                                }
                            >
                                Risk before controls <strong>{inherent}</strong>{' '}
                                ({riskBandLabel(inherent)}) · risk after controls{' '}
                                <strong>{residual}</strong> ({riskBandLabel(residual)}).
                                {limitSentence ? ` ${limitSentence}` : ''} Scores
                                are recalculated when you save.
                            </InfoCard>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'respond' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={Target}
                            title="How will you respond?"
                            blurb="Choose what you will do about it, who is responsible and how often it is reviewed."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Response"
                                error={err('mitigation_strategy')}
                            >
                                <TilePicker
                                    value={data.mitigation_strategy}
                                    onChange={(v) =>
                                        set('mitigation_strategy', v)
                                    }
                                    cols={2}
                                    options={STRATEGY_OPTIONS}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={Users}>Responsibility</SubHead>
                                <Field
                                    label="Risk owner"
                                    hint={isEdit ? undefined : 'you, if left blank'}
                                    error={err('risk_owner_id')}
                                >
                                    <SelectInput
                                        value={data.risk_owner_id}
                                        onChange={(v) =>
                                            set('risk_owner_id', v)
                                        }
                                        placeholder="Choose an owner"
                                        ariaLabel="Risk owner"
                                        options={options.owners.map((o) => ({
                                            value: String(o.id),
                                            label: o.name,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    label="Review how often"
                                    hint={isEdit ? 'set when added' : undefined}
                                    error={err('review_frequency')}
                                >
                                    {isEdit ? (
                                        <p className="text-sm text-muted-foreground">
                                            {optionLabel(
                                                REVIEW_FREQUENCY_OPTIONS,
                                                data.review_frequency,
                                            ) ?? 'Not set'}
                                        </p>
                                    ) : (
                                        <Segmented
                                            value={data.review_frequency}
                                            onChange={(v) =>
                                                set('review_frequency', v)
                                            }
                                            options={REVIEW_FREQUENCY_OPTIONS}
                                        />
                                    )}
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {isReview ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CheckCircle2}
                            title={isEdit ? 'Review changes' : 'Review and add'}
                            blurb="Check the details before saving them to the risk register."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={ShieldAlert}
                                title="Risk"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Kind of risk"
                                    value={categoryLabel}
                                />
                                <ReviewRow label="Name" value={data.title} />
                            </ReviewCard>
                            <ReviewCard
                                icon={Gauge}
                                title="How serious"
                                onEdit={() => goTo(1)}
                            >
                                <ReviewRow
                                    label="Likelihood"
                                    value={optionLabel(
                                        LIKELIHOOD_OPTIONS,
                                        data.likelihood_score,
                                    )}
                                />
                                <ReviewRow
                                    label="Impact"
                                    value={optionLabel(
                                        IMPACT_OPTIONS,
                                        data.impact_score,
                                    )}
                                />
                                <ReviewRow
                                    label="Controls"
                                    value={optionLabel(
                                        CONTROL_OPTIONS,
                                        data.control_effectiveness,
                                    )}
                                />
                                <ReviewRow
                                    label="Scores"
                                    value={`Before controls ${inherent} → after controls ${residual}`}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Target}
                                title="Response"
                                onEdit={() => goTo(2)}
                            >
                                <ReviewRow
                                    label="Response"
                                    value={
                                        data.mitigation_strategy
                                            ? strategyLabel(data.mitigation_strategy)
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Owner"
                                    value={
                                        ownerName ?? (isEdit ? undefined : 'You')
                                    }
                                />
                                <ReviewRow
                                    label="Review"
                                    value={optionLabel(
                                        REVIEW_FREQUENCY_OPTIONS,
                                        data.review_frequency,
                                    )}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Description"
                                onEdit={() => goTo(0)}
                            >
                                <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                    {data.description || '—'}
                                </p>
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                ) : null}
            </WizardShell>

            <Dialog open={confirmClose} onOpenChange={setConfirmClose}>
                <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                    <DialogHeader>
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            {isEdit
                                ? 'Your unsaved changes to this risk will be lost.'
                                : 'The details entered for this risk will be lost.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmClose(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirmClose(false);
                                onClose();
                            }}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Add an action to reduce a risk (simple dialog)                     */
/* ------------------------------------------------------------------ */

export function AddTreatmentDialog({
    open,
    onClose,
    riskId,
    assignees,
}: {
    open: boolean;
    onClose: () => void;
    riskId: number;
    assignees: Array<{ id: number; name: string }>;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <AddTreatmentBody
                        onClose={onClose}
                        riskId={riskId}
                        assignees={assignees}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function AddTreatmentBody({
    onClose,
    riskId,
    assignees,
}: {
    onClose: () => void;
    riskId: number;
    assignees: Array<{ id: number; name: string }>;
}) {
    const form = useForm({
        action_description: '',
        assigned_to: '',
        due_date: '',
        expected_score_reduction: '',
        evidence_required: false,
    });
    const [submitError, setSubmitError] = useState<string | null>(null);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setSubmitError(null);
        form.transform((current) => ({
            ...current,
            expected_score_reduction: current.expected_score_reduction
                ? Number(current.expected_score_reduction)
                : null,
        }));
        form.post(`/governance/risks/${riskId}/treatments`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Wrench className="h-4 w-4 text-primary" />
                    Add an action to reduce this risk
                </DialogTitle>
                <DialogDescription>
                    Give someone a piece of work that makes the risk less likely
                    or less harmful, and when it is due.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label htmlFor="treatment-description">
                        What will be done{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea
                        id="treatment-description"
                        rows={3}
                        value={form.data.action_description}
                        onChange={(e) =>
                            form.setData('action_description', e.target.value)
                        }
                        placeholder="e.g. Turn on two-step sign-in for every system that holds client records"
                    />
                    <FieldError message={form.errors.action_description} />
                </div>
                <div>
                    <Label>
                        Who will do it{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <SelectInput
                        value={form.data.assigned_to}
                        onChange={(v) => form.setData('assigned_to', v)}
                        placeholder="Choose a person"
                        ariaLabel="Who will do it"
                        options={assignees.map((user) => ({
                            value: String(user.id),
                            label: user.name,
                        }))}
                    />
                    <FieldError message={form.errors.assigned_to} />
                </div>
                <div>
                    <Label htmlFor="treatment-due">
                        Due date <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="treatment-due"
                        type="date"
                        min={toDateInput(Date.now() + 86_400_000)}
                        value={form.data.due_date}
                        onChange={(e) => form.setData('due_date', e.target.value)}
                    />
                    <FieldError message={form.errors.due_date} />
                </div>
                <div>
                    <Label htmlFor="treatment-reduction">
                        Expected drop in the score{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    </Label>
                    <Input
                        id="treatment-reduction"
                        type="number"
                        min={1}
                        max={24}
                        value={form.data.expected_score_reduction}
                        onChange={(e) =>
                            form.setData(
                                'expected_score_reduction',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. 5"
                    />
                    <p className="text-caption mt-1">
                        How much lower the risk after controls should be once
                        it is done.
                    </p>
                    <FieldError message={form.errors.expected_score_reduction} />
                </div>
                <div className="flex items-start pt-6">
                    <Label
                        htmlFor="treatment-evidence"
                        className="flex items-start gap-2 font-normal"
                    >
                        <Checkbox
                            id="treatment-evidence"
                            checked={form.data.evidence_required}
                            onCheckedChange={(checked) =>
                                form.setData('evidence_required', checked === true)
                            }
                        />
                        <span>
                            Evidence needed to mark it done
                            <span className="text-caption block">
                                A file has to be attached before it can be
                                marked done.
                            </span>
                        </span>
                    </Label>
                </div>
            </div>

            {submitError ? (
                <p role="alert" className="mt-3 text-sm text-status-critical">
                    {submitError}
                </p>
            ) : null}

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Add action
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Accept a risk (simple dialog)                                      */
/* ------------------------------------------------------------------ */

const ACCEPTANCE_PERIODS = ['3', '6', '12', '24'].map((value) => ({
    value,
    label: `${value} months`,
}));

export interface PassedResolutionOption {
    id: number;
    title: string;
    reference: string | null;
    decided_at: string | null;
}

/** The value used while no resolution is chosen (Radix Select can't use ""). */
export const NO_RESOLUTION = 'none';

export function AcceptRiskDialog({
    open,
    onClose,
    riskId,
    categoryLabel,
    appetiteThreshold,
    aboveLimit,
    resolutionOptions,
    canViewResolutions,
}: {
    open: boolean;
    onClose: () => void;
    riskId: number;
    categoryLabel: string;
    appetiteThreshold: number;
    aboveLimit: boolean;
    resolutionOptions: PassedResolutionOption[];
    canViewResolutions: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <AcceptRiskBody
                        onClose={onClose}
                        riskId={riskId}
                        categoryLabel={categoryLabel}
                        appetiteThreshold={appetiteThreshold}
                        aboveLimit={aboveLimit}
                        resolutionOptions={resolutionOptions}
                        canViewResolutions={canViewResolutions}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function AcceptRiskBody({
    onClose,
    riskId,
    categoryLabel,
    appetiteThreshold,
    aboveLimit,
    resolutionOptions,
    canViewResolutions,
}: {
    onClose: () => void;
    riskId: number;
    categoryLabel: string;
    appetiteThreshold: number;
    aboveLimit: boolean;
    resolutionOptions: PassedResolutionOption[];
    canViewResolutions: boolean;
}) {
    const form = useForm({
        justification: '',
        expiry_months: '12',
        conditions: '',
        resolution_id: NO_RESOLUTION,
    });
    const [submitError, setSubmitError] = useState<string | null>(null);
    const tooShort = form.data.justification.trim().length < 50;
    const needsResolution = aboveLimit;
    const noResolutions = needsResolution && resolutionOptions.length === 0;
    const missingResolution =
        needsResolution && form.data.resolution_id === NO_RESOLUTION;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setSubmitError(null);
        form.transform((current) => ({
            justification: current.justification,
            expiry_months: Number(current.expiry_months),
            conditions: current.conditions
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean),
            resolution_id:
                current.resolution_id !== NO_RESOLUTION
                    ? Number(current.resolution_id)
                    : null,
        }));
        form.post(`/governance/risks/${riskId}/accept`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Accept this risk?
                </DialogTitle>
                <DialogDescription>
                    The board accepts the risk as it is for a set time. It stays on
                    the register, and comes back for review when the acceptance
                    ends.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3">
                {needsResolution ? (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        This risk is above the board&apos;s limit for{' '}
                        {categoryLabel.toLowerCase()} risks ({appetiteThreshold}).
                        It can only be accepted with a resolution the board has
                        passed.
                    </InfoCard>
                ) : null}

                {needsResolution ? (
                    noResolutions ? (
                        <InfoCard icon={AlertTriangle} tone="warn">
                            No passed resolution is available to accept this risk
                            yet. Ask the board secretary to put a resolution to the
                            board — for example at the next meeting. Once it
                            passes, you can accept the risk here.
                            {canViewResolutions ? (
                                <>
                                    {' '}
                                    <Link
                                        href="/governance/resolutions"
                                        className="font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        Go to Resolutions
                                    </Link>
                                </>
                            ) : null}
                        </InfoCard>
                    ) : (
                        <Field
                            label="The board's resolution"
                            required
                            error={form.errors.resolution_id}
                        >
                            <SelectInput
                                value={form.data.resolution_id}
                                onChange={(v) => form.setData('resolution_id', v)}
                                placeholder="Choose the resolution the board passed"
                                ariaLabel="The board's resolution"
                                options={[
                                    {
                                        value: NO_RESOLUTION,
                                        label: 'Choose the resolution the board passed',
                                    },
                                    ...resolutionOptions.map((option) => ({
                                        value: String(option.id),
                                        label: [
                                            option.title,
                                            option.decided_at
                                                ? `passed ${formatDateLong(option.decided_at)}`
                                                : null,
                                            refSuffix(option.reference) || null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })),
                                ]}
                            />
                        </Field>
                    )
                ) : null}

                <div>
                    <div className="flex items-center justify-between gap-2">
                        <Label htmlFor="accept-justification">
                            Why the board accepts it{' '}
                            <span className="text-status-critical">*</span>
                        </Label>
                        <CharacterCount value={form.data.justification} min={50} />
                    </div>
                    <Textarea
                        id="accept-justification"
                        rows={4}
                        value={form.data.justification}
                        onChange={(e) =>
                            form.setData('justification', e.target.value)
                        }
                        placeholder="Why the organisation accepts this level of risk, and what monitoring stays in place."
                    />
                    <FieldError message={form.errors.justification} />
                </div>
                <div>
                    <Label htmlFor="accept-conditions">
                        Conditions{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            optional — one per line
                        </span>
                    </Label>
                    <Textarea
                        id="accept-conditions"
                        rows={3}
                        value={form.data.conditions}
                        onChange={(e) => form.setData('conditions', e.target.value)}
                        placeholder={'e.g. Report incidents to the board every month'}
                    />
                </div>
                <div>
                    <Label>Accepted for</Label>
                    <SelectInput
                        value={form.data.expiry_months}
                        onChange={(v) => form.setData('expiry_months', v)}
                        placeholder="Choose how long"
                        ariaLabel="Accepted for"
                        options={ACCEPTANCE_PERIODS}
                    />
                    <FieldError message={form.errors.expiry_months} />
                </div>
            </div>

            {submitError ? (
                <p role="alert" className="mt-3 text-sm text-status-critical">
                    {submitError}
                </p>
            ) : null}

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        tooShort ||
                        missingResolution ||
                        noResolutions
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Accept risk
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Close a risk (confirm + reason)                                    */
/* ------------------------------------------------------------------ */

export function CloseRiskDialog({
    open,
    onClose,
    riskId,
    riskTitle,
}: {
    open: boolean;
    onClose: () => void;
    riskId: number;
    riskTitle: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)', width: 'min(92vw, 560px)' }}>
                {open ? (
                    <CloseRiskBody onClose={onClose} riskId={riskId} riskTitle={riskTitle} />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function CloseRiskBody({
    onClose,
    riskId,
    riskTitle,
}: {
    onClose: () => void;
    riskId: number;
    riskTitle: string;
}) {
    const form = useForm({ rationale: '' });
    const tooShort = form.data.rationale.trim().length < 20;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/governance/risks/${riskId}/close`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!flashError(page)) onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <ShieldOff className="h-4 w-4 text-primary" />
                    Close this risk?
                </DialogTitle>
                <DialogDescription>
                    “{riskTitle}” comes off the open register. It stays on record as
                    closed with your reason, and it can&apos;t be reopened here.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <div className="flex items-center justify-between gap-2">
                    <Label htmlFor="close-rationale">
                        Why it is being closed{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <CharacterCount value={form.data.rationale} min={20} />
                </div>
                <Textarea
                    id="close-rationale"
                    rows={4}
                    value={form.data.rationale}
                    onChange={(e) => form.setData('rationale', e.target.value)}
                    placeholder="e.g. The service this risk related to has closed."
                />
                <FieldError message={form.errors.rationale} />
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || tooShort}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Close risk
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Actions to reduce a risk — mark done / change due date             */
/* ------------------------------------------------------------------ */

export interface TreatmentSummary {
    id: number;
    action_description: string;
    due_date: string | null;
    expected_score_reduction: number | null;
}

export function CompleteTreatmentDialog({
    riskId,
    treatment,
    onClose,
}: {
    riskId: number;
    treatment: TreatmentSummary | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={treatment !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)', width: 'min(92vw, 560px)' }}>
                {treatment ? (
                    <CompleteTreatmentBody
                        riskId={riskId}
                        treatment={treatment}
                        onClose={onClose}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function CompleteTreatmentBody({
    riskId,
    treatment,
    onClose,
}: {
    riskId: number;
    treatment: TreatmentSummary;
    onClose: () => void;
}) {
    const form = useForm({ completion_notes: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/governance/risks/${riskId}/treatments/${treatment.id}/complete`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!flashError(page)) onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CheckCircle2 className="h-4 w-4 text-primary" />
                    Mark this action as done?
                </DialogTitle>
                <DialogDescription>
                    “{treatment.action_description}” will be recorded as done today
                    by you.
                    {treatment.expected_score_reduction
                        ? ` The risk after controls drops by ${treatment.expected_score_reduction}.`
                        : ''}{' '}
                    This can&apos;t be undone.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Label htmlFor="treatment-completion-notes">
                    What was done{' '}
                    <span className="text-xs font-normal text-muted-foreground">
                        optional
                    </span>
                </Label>
                <Textarea
                    id="treatment-completion-notes"
                    rows={3}
                    maxLength={2000}
                    value={form.data.completion_notes}
                    onChange={(e) => form.setData('completion_notes', e.target.value)}
                    placeholder="e.g. Two-step sign-in turned on for all client record systems on 12 September."
                />
                <FieldError message={errors.completion_notes ?? errors.treatment} />
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Mark as done
                </Button>
            </DialogFooter>
        </form>
    );
}

export function ChangeTreatmentDueDateDialog({
    riskId,
    treatment,
    onClose,
}: {
    riskId: number;
    treatment: TreatmentSummary | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={treatment !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' }}>
                {treatment ? (
                    <ChangeDueDateBody
                        riskId={riskId}
                        treatment={treatment}
                        onClose={onClose}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function ChangeDueDateBody({
    riskId,
    treatment,
    onClose,
}: {
    riskId: number;
    treatment: TreatmentSummary;
    onClose: () => void;
}) {
    const form = useForm({
        due_date: treatment.due_date ?? '',
        reason: '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/governance/risks/${riskId}/treatments/${treatment.id}/due-date`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!flashError(page)) onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CalendarClock className="h-4 w-4 text-primary" />
                    Change the due date
                </DialogTitle>
                <DialogDescription>
                    {treatment.due_date
                        ? `Currently due ${formatDateOnly(treatment.due_date)}. The change is kept in the audit log.`
                        : 'The change is kept in the audit log.'}
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3 grid gap-3">
                <div>
                    <Label htmlFor="treatment-new-due">
                        New due date <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="treatment-new-due"
                        type="date"
                        min={toDateInput(new Date())}
                        value={form.data.due_date}
                        onChange={(e) => form.setData('due_date', e.target.value)}
                    />
                    <FieldError message={form.errors.due_date} />
                </div>
                <div>
                    <Label htmlFor="treatment-due-reason">
                        Why{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    </Label>
                    <Textarea
                        id="treatment-due-reason"
                        rows={2}
                        maxLength={500}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="e.g. Waiting on the supplier's security audit."
                    />
                    <FieldError message={form.errors.reason} />
                </div>
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || !form.data.due_date}
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Change due date
                </Button>
            </DialogFooter>
        </form>
    );
}
