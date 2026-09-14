/**
 * Risk register dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - RiskWizardDialog — the entity add/edit wizard (WizardShell). Add opens
 *     from the register header, Edit from the risk record; both use the SAME
 *     steps, prefilled on edit.
 *   - AddTreatmentDialog / AcceptRiskDialog — simple single-section dialogs
 *     opened from the risk record.
 *
 * Fields, rules and side effects mirror StoreRiskRegisterRequest /
 * UpdateRiskRegisterRequest and RiskRegisterController::addTreatment/accept.
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
import { riskScoreLevel } from '@/lib/governance-status';
import { accept as acceptRisk, store as storeRisk } from '@/routes/governance/risks';
import { add as addTreatment } from '@/routes/governance/risks/treatments';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    Ban,
    Banknote,
    Briefcase,
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
    Stethoscope,
    Target,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

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

export const LIKELIHOOD_OPTIONS = [
    { value: '1', label: '1 · Rare' },
    { value: '2', label: '2 · Unlikely' },
    { value: '3', label: '3 · Possible' },
    { value: '4', label: '4 · Likely' },
    { value: '5', label: '5 · Almost certain' },
];

export const IMPACT_OPTIONS = [
    { value: '1', label: '1 · Insignificant' },
    { value: '2', label: '2 · Minor' },
    { value: '3', label: '3 · Moderate' },
    { value: '4', label: '4 · Major' },
    { value: '5', label: '5 · Catastrophic' },
];

export const CONTROL_OPTIONS = [
    { value: 'none', label: 'None' },
    { value: 'weak', label: 'Weak' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'strong', label: 'Strong' },
];

/** Mirrors RiskScoringService::CONTROL_MULTIPLIERS. */
const CONTROL_MULTIPLIERS: Record<string, number> = {
    none: 1,
    weak: 0.8,
    moderate: 0.5,
    strong: 0.2,
};

export const STRATEGY_OPTIONS = [
    {
        key: 'treat',
        label: 'Treat',
        description: 'Reduce likelihood or impact with treatment actions.',
        icon: Wrench,
    },
    {
        key: 'transfer',
        label: 'Transfer',
        description: 'Share the risk — insurance, contracts or partners.',
        icon: ArrowRightLeft,
    },
    {
        key: 'terminate',
        label: 'Terminate',
        description: 'Stop the activity that creates the risk.',
        icon: Ban,
    },
    {
        key: 'tolerate',
        label: 'Tolerate',
        description: 'Accept the risk within appetite and monitor it.',
        icon: ShieldCheck,
    },
];

export const REVIEW_FREQUENCY_OPTIONS = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annual', label: 'Annual' },
];

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
        label: 'Identify',
        blurb: 'Category, title & description',
        icon: ShieldAlert,
    },
    {
        key: 'assess',
        label: 'Assess',
        blurb: 'Likelihood, impact & controls',
        icon: Gauge,
    },
    {
        key: 'respond',
        label: 'Respond',
        blurb: 'Strategy, owner & review cycle',
        icon: Target,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
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
        if (!data.category) e.category = 'Choose a risk category';
        if (!data.title.trim()) e.title = 'A risk title is required';
        else if (data.title.length > 255)
            e.title = 'Keep the title to 255 characters or fewer';
        if (!data.description.trim())
            e.description = 'Describe the risk and its causes';
    }
    return e;
}

export interface RiskWizardDialogProps {
    open: boolean;
    onClose: () => void;
    options: RiskFormOptions;
    /** When set the wizard edits this risk; otherwise it registers a new one. */
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
    const residual = Math.max(
        1,
        Math.round(
            inherent * (CONTROL_MULTIPLIERS[data.control_effectiveness] ?? 1),
        ),
    );

    const categoryLabel =
        optionLabel(options.categories, data.category) ?? data.category;
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
            // UpdateRiskRegisterRequest accepts only these fields; category and
            // review frequency are fixed once a risk is registered.
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
        form.post(storeRisk.url(), visit);
    };

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Risk updated' : 'Risk registered'}
            blurb={
                isEdit ? (
                    <>
                        <strong>{data.title}</strong> has been updated and its
                        scores recalculated.
                    </>
                ) : (
                    <>
                        <strong>{data.title}</strong> ({categoryLabel}) is now
                        on the register with a residual score of {residual}.
                        The appetite threshold for its category is applied
                        automatically.
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
                            <Plus className="h-4 w-4" /> Register another
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
                title={isEdit ? 'Edit risk' : 'Register a risk'}
                description={
                    isEdit
                        ? 'Update the assessment, controls and response for this risk.'
                        : 'A guided wizard to register a new enterprise risk.'
                }
                railIcon={ShieldAlert}
                railTitle={isEdit ? 'Edit risk' : 'New risk'}
                railSub={
                    isEdit
                        ? (risk?.risk_reference ?? 'Risk register')
                        : 'Risk register'
                }
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
                                {isEdit ? 'Save changes' : 'Register risk'}
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
                            blurb="Choose where the risk sits and describe what could happen."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Category"
                                required
                                error={err('category')}
                                hint={
                                    isEdit
                                        ? 'set when the risk was registered'
                                        : undefined
                                }
                            >
                                {isEdit ? (
                                    <InfoCard
                                        icon={riskCategoryIcon(data.category)}
                                    >
                                        <strong>{categoryLabel}</strong> — the
                                        category (and its appetite threshold)
                                        is fixed once a risk is registered.
                                    </InfoCard>
                                ) : (
                                    <TilePicker
                                        value={data.category}
                                        onChange={(v) => set('category', v)}
                                        cols={2}
                                        options={options.categories.map(
                                            (c) => ({
                                                key: c.value,
                                                label: c.label,
                                                icon: riskCategoryIcon(
                                                    c.value,
                                                ),
                                            }),
                                        )}
                                    />
                                )}
                            </Field>
                            <Field
                                label="Risk title"
                                required
                                error={err('title')}
                            >
                                <Input
                                    value={data.title}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    maxLength={255}
                                    placeholder="e.g. Client information privacy breach"
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
                            blurb="Score the likelihood and impact on the 5×5 matrix, then rate the existing controls."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Likelihood"
                                required
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
                                error={err('impact_score')}
                            >
                                <Segmented
                                    value={data.impact_score}
                                    onChange={(v) => set('impact_score', v)}
                                    options={IMPACT_OPTIONS}
                                />
                            </Field>
                            <Field
                                label="Control effectiveness"
                                error={err('control_effectiveness')}
                            >
                                <Segmented
                                    value={data.control_effectiveness}
                                    onChange={(v) =>
                                        set('control_effectiveness', v)
                                    }
                                    options={CONTROL_OPTIONS}
                                />
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
                                Inherent score <strong>{inherent}</strong>{' '}
                                ({riskScoreLevel(inherent)}) · residual score
                                after controls <strong>{residual}</strong> (
                                {riskScoreLevel(residual)}). The server
                                confirms both when the risk is saved.
                            </InfoCard>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'respond' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={Target}
                            title="How will we respond?"
                            blurb="Pick the mitigation strategy, the accountable owner and how often it is reviewed."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Mitigation strategy"
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
                                <SubHead icon={Users}>Accountability</SubHead>
                                <Field
                                    label="Risk owner"
                                    hint={
                                        isEdit
                                            ? undefined
                                            : 'defaults to you'
                                    }
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
                                    label="Review frequency"
                                    hint={
                                        isEdit
                                            ? 'set when registered'
                                            : undefined
                                    }
                                    error={err('review_frequency')}
                                >
                                    {isEdit ? (
                                        <p className="text-sm text-muted-foreground">
                                            {optionLabel(
                                                REVIEW_FREQUENCY_OPTIONS,
                                                data.review_frequency,
                                            ) ?? '—'}
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
                            title={
                                isEdit ? 'Review changes' : 'Review & register'
                            }
                            blurb="Check the details before saving to the risk register."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={ShieldAlert}
                                title="Risk"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Category"
                                    value={categoryLabel}
                                />
                                <ReviewRow label="Title" value={data.title} />
                            </ReviewCard>
                            <ReviewCard
                                icon={Gauge}
                                title="Assessment"
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
                                    value={`${inherent} inherent · ${residual} residual`}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Target}
                                title="Response"
                                onEdit={() => goTo(2)}
                            >
                                <ReviewRow
                                    label="Strategy"
                                    value={
                                        STRATEGY_OPTIONS.find(
                                            (s) =>
                                                s.key ===
                                                data.mitigation_strategy,
                                        )?.label
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
                                <p className="text-[13px] whitespace-pre-wrap text-muted-foreground">
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
/*  Add treatment (simple dialog)                                      */
/* ------------------------------------------------------------------ */

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

export function AddTreatmentDialog({
    open,
    onClose,
    riskId,
    assignees,
}: {
    open: boolean;
    onClose: () => void;
    riskId: number;
    assignees: Array<{ id: number; name: string; email?: string }>;
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
    assignees: Array<{ id: number; name: string; email?: string }>;
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
        form.post(addTreatment.url({ risk: riskId }), {
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
                    Add treatment action
                </DialogTitle>
                <DialogDescription>
                    Assign a mitigation action that reduces this risk&apos;s
                    likelihood or impact.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label htmlFor="treatment-description">
                        Action description{' '}
                        <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea
                        id="treatment-description"
                        rows={3}
                        value={form.data.action_description}
                        onChange={(e) =>
                            form.setData('action_description', e.target.value)
                        }
                        placeholder="e.g. Roll out multi-factor authentication for all client-record systems"
                    />
                    <FieldError message={form.errors.action_description} />
                </div>
                <div>
                    <Label>
                        Assign to <span className="text-status-critical">*</span>
                    </Label>
                    <SelectInput
                        value={form.data.assigned_to}
                        onChange={(v) => form.setData('assigned_to', v)}
                        placeholder="Choose staff member"
                        ariaLabel="Assign to"
                        options={assignees.map((user) => ({
                            value: String(user.id),
                            label: user.email
                                ? `${user.name} (${user.email})`
                                : user.name,
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
                        value={form.data.due_date}
                        onChange={(e) => form.setData('due_date', e.target.value)}
                    />
                    <FieldError message={form.errors.due_date} />
                </div>
                <div>
                    <Label htmlFor="treatment-reduction">
                        Expected score reduction
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
                    <FieldError message={form.errors.expected_score_reduction} />
                </div>
                <div className="flex items-end pb-2">
                    <Label
                        htmlFor="treatment-evidence"
                        className="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            id="treatment-evidence"
                            checked={form.data.evidence_required}
                            onCheckedChange={(checked) =>
                                form.setData('evidence_required', checked === true)
                            }
                        />
                        Evidence required to close
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
                    Add treatment
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Accept risk (simple dialog)                                        */
/* ------------------------------------------------------------------ */

const ACCEPTANCE_PERIODS = [
    { value: '3', label: '3 months' },
    { value: '6', label: '6 months' },
    { value: '12', label: '12 months' },
    { value: '24', label: '24 months' },
];

export function AcceptRiskDialog({
    open,
    onClose,
    riskId,
    appetiteThreshold,
}: {
    open: boolean;
    onClose: () => void;
    riskId: number;
    appetiteThreshold: number;
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
                        appetiteThreshold={appetiteThreshold}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function AcceptRiskBody({
    onClose,
    riskId,
    appetiteThreshold,
}: {
    onClose: () => void;
    riskId: number;
    appetiteThreshold: number;
}) {
    const form = useForm({
        justification: '',
        expiry_months: '12',
        conditions: [] as string[],
    });
    const [submitError, setSubmitError] = useState<string | null>(null);
    const tooShort = form.data.justification.trim().length < 50;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setSubmitError(null);
        form.transform((current) => ({
            ...current,
            expiry_months: Number(current.expiry_months),
        }));
        form.post(acceptRisk.url({ risk: riskId }), {
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
                    Accept risk above appetite
                </DialogTitle>
                <DialogDescription>
                    Formally acknowledge and accept this risk for a fixed
                    period.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3">
                <InfoCard icon={AlertTriangle} tone="warn">
                    This risk is above its appetite threshold ({appetiteThreshold}
                    ). Board acceptance is required, and above-appetite risks
                    must be linked to a Board resolution.
                </InfoCard>
                <div>
                    <Label htmlFor="accept-justification">
                        Justification{' '}
                        <span className="text-status-critical">*</span>{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            minimum 50 characters
                        </span>
                    </Label>
                    <Textarea
                        id="accept-justification"
                        rows={4}
                        value={form.data.justification}
                        onChange={(e) =>
                            form.setData('justification', e.target.value)
                        }
                        placeholder="Why the organisation accepts this level of risk, and what monitoring remains in place."
                    />
                    <FieldError message={form.errors.justification} />
                </div>
                <div>
                    <Label>Acceptance period</Label>
                    <SelectInput
                        value={form.data.expiry_months}
                        onChange={(v) => form.setData('expiry_months', v)}
                        placeholder="Choose a period"
                        ariaLabel="Acceptance period"
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
                <Button type="submit" disabled={form.processing || tooShort}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Accept risk
                </Button>
            </DialogFooter>
        </form>
    );
}
