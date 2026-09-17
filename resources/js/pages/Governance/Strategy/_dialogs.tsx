import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    InfoCard,
    SelectInput,
    StepHead,
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
import { formatDateOnly } from '@/lib/datetime';
import { planLengthLabel } from '@/lib/governance-labels';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarRange,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Compass,
    Eye,
    Gem,
    Loader2,
    Plus,
    Target,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types + shared helpers                                             */
/* ------------------------------------------------------------------ */

export interface StrategicPlanFormOptions {
    horizons: Record<string, string>;
    pillars: Record<string, string>;
}

export type PlanValue = string | { value: string; description?: string | null };

export interface EditableStrategicPlan {
    id: number;
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    vision_statement: string | null;
    mission_statement: string | null;
    values: PlanValue[] | null;
    status: string;
    version_number: number;
    goals: Array<{
        id: number;
        title: string;
        pillar: string;
        timeframe: string | null;
    }>;
}

type ValueRow = { key: string; value: string; description: string };
type KeyResultRow = { key: string; result: string };
type GoalRow = {
    key: string;
    title: string;
    description: string;
    pillar: string;
    timeframe: string;
    key_results: KeyResultRow[];
};

type PlanForm = {
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    vision_statement: string;
    mission_statement: string;
    values: ValueRow[];
    goals: GoalRow[];
};

export type StrategicPlanStepKey =
    | 'plan'
    | 'direction'
    | 'values'
    | 'goals'
    | 'review';

export const STRATEGIC_PLAN_STEPS: readonly (WizardStep & {
    key: StrategicPlanStepKey;
})[] = [
    {
        key: 'plan',
        label: 'Plan',
        blurb: 'Title, plan length and dates',
        icon: Compass,
    },
    {
        key: 'direction',
        label: 'Direction',
        blurb: 'Vision and mission',
        icon: Eye,
    },
    {
        key: 'values',
        label: 'Values',
        blurb: 'What guides the plan',
        icon: Gem,
    },
    {
        key: 'goals',
        label: 'Goals',
        blurb: 'Goals and measures of success',
        icon: Target,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save the plan',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StrategicPlanStepKey> = {
    title: 'plan',
    planning_horizon: 'plan',
    period_start: 'plan',
    period_end: 'plan',
    description: 'direction',
    vision_statement: 'direction',
    mission_statement: 'direction',
    values: 'values',
    goals: 'goals',
    status: 'review',
};

const HORIZON_BLURBS: Record<string, string> = {
    '3_year': 'Medium-term priorities, refreshed each year.',
    '5_year': 'Long-term direction for major investment.',
};

const HORIZON_YEARS: Record<string, number> = {
    annual: 1,
    '1_year': 1,
    '3_year': 3,
    '5_year': 5,
    '10_year': 10,
};

/** "3-year plan" — shared plain label for a stored plan length. */
export const humaniseHorizon = (horizon: string) => {
    const years = /^(\d+)_year$/.exec(horizon);
    return years ? `${years[1]}-year plan` : planLengthLabel(horizon);
};

/** Plan values arrive as legacy strings or `{value, description}` objects. */
export function normaliseValues(
    values: PlanValue[] | null | undefined,
): Array<{ value: string; description: string | null }> {
    return (values ?? [])
        .map((entry) =>
            typeof entry === 'string'
                ? { value: entry, description: null }
                : {
                      value: entry?.value ?? '',
                      description: entry?.description ?? null,
                  },
        )
        .filter((entry) => entry.value.trim() !== '');
}

/** The day before the same date N years later: 1 Jul 2026 + 3 years → 30 Jun 2029. */
export function suggestedPlanEnd(start: string, horizon: string): string {
    const years = HORIZON_YEARS[horizon];
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(start);
    if (!years || !match) return '';
    const date = new Date(
        Date.UTC(Number(match[1]) + years, Number(match[2]) - 1, Number(match[3])),
    );
    date.setUTCDate(date.getUTCDate() - 1);
    return date.toISOString().slice(0, 10);
}

/**
 * "The dates cover 1 year and 2 months." when the plan period doesn't match
 * its length (more than two months out); null when they agree.
 */
export function planLengthMismatch(
    start: string,
    end: string,
    horizon: string,
): string | null {
    const years = HORIZON_YEARS[horizon];
    const from = Date.parse(`${start}T00:00:00Z`);
    const to = Date.parse(`${end}T00:00:00Z`);
    if (!years || Number.isNaN(from) || Number.isNaN(to) || to <= from) {
        return null;
    }
    // Inclusive of the end date: 1 Jul 2026 – 30 Jun 2029 is 3 years.
    const days = (to - from) / 86_400_000 + 1;
    if (Math.abs(days - years * 365.25) <= 62) return null;
    const months = Math.round(days / 30.4375);
    const wholeYears = Math.floor(months / 12);
    const rest = months % 12;
    const parts = [
        wholeYears > 0
            ? `${wholeYears} year${wholeYears === 1 ? '' : 's'}`
            : null,
        rest > 0 ? `${rest} month${rest === 1 ? '' : 's'}` : null,
    ].filter(Boolean);
    return `A ${years}-year plan usually runs about ${years} year${years === 1 ? '' : 's'}, but these dates cover ${parts.join(' and ') || 'less than a month'}.`;
}

/** "2026-07-01 - 2029-06-30" (stored goal timeframe) → "1 Jul 2026 – 30 Jun 2029". */
export function formatTimeframe(timeframe: string | null | undefined): string {
    if (!timeframe) return '';
    const match = /^(\d{4}-\d{2}-\d{2})\s*[-–]\s*(\d{4}-\d{2}-\d{2})$/.exec(
        timeframe.trim(),
    );
    return match
        ? `${formatDateOnly(match[1])} – ${formatDateOnly(match[2])}`
        : timeframe;
}

let rowSeq = 0;
const nextKey = (prefix: string) => `${prefix}-${++rowSeq}`;

const dateOnly = (value: string | null | undefined) =>
    (value ?? '').slice(0, 10);

/* ------------------------------------------------------------------ */
/*  Validation + completeness                                          */
/* ------------------------------------------------------------------ */

function validateStep(
    step: StrategicPlanStepKey,
    data: PlanForm,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'plan') {
        if (!data.title.trim()) errors.title = 'Give the plan a title.';
        else if (data.title.trim().length > 255)
            errors.title = 'Keep the title to 255 characters.';
        if (!data.planning_horizon)
            errors.planning_horizon = 'Choose how many years the plan covers.';
        if (!data.period_start) errors.period_start = 'Choose the start date.';
        if (!data.period_end) errors.period_end = 'Choose the end date.';
        else if (data.period_start && data.period_end <= data.period_start)
            errors.period_end = 'The plan must end after it starts.';
    }
    if (step === 'values') {
        data.values.forEach((row, i) => {
            if (!row.value.trim())
                errors[`values.${i}.value`] =
                    'Name the value or remove the row.';
        });
    }
    if (step === 'goals') {
        data.goals.forEach((goal, i) => {
            if (!goal.title.trim())
                errors[`goals.${i}.title`] = 'Give the goal a title.';
            if (!goal.description.trim())
                errors[`goals.${i}.description`] =
                    'Describe what the goal achieves.';
            if (!goal.pillar)
                errors[`goals.${i}.pillar`] =
                    'Choose the theme this goal belongs to.';
        });
    }
    return errors;
}

function completeness(data: PlanForm, existingGoals: number): number {
    const filled = [
        data.title.trim(),
        data.planning_horizon,
        data.period_start && data.period_end,
        data.vision_statement.trim(),
        data.mission_statement.trim(),
        data.values.length > 0,
        data.goals.length + existingGoals > 0,
    ].filter(Boolean).length;
    return Math.round((filled / 7) * 100);
}

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export interface StrategicPlanWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    options: StrategicPlanFormOptions;
    /** Edit mode — the same wizard, prefilled, PUTs to the plan. */
    plan?: EditableStrategicPlan | null;
    /** Open on a specific step (e.g. "Add goal" opens on Goals). */
    initialStep?: StrategicPlanStepKey;
}

export function StrategicPlanWizardDialog(
    props: StrategicPlanWizardDialogProps,
) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <StrategicPlanWizardBody {...props} /> : null;
}

function StrategicPlanWizardBody({
    isOpen,
    onClose,
    options,
    plan = null,
    initialStep = 'plan',
}: StrategicPlanWizardDialogProps) {
    const isEdit = Boolean(plan);
    const horizonKeys = Object.keys(options.horizons);
    const pillarKeys = Object.keys(options.pillars);
    const legacyHorizon =
        plan && !horizonKeys.includes(plan.planning_horizon)
            ? plan.planning_horizon
            : null;

    const form = useForm<PlanForm>({
        title: plan?.title ?? '',
        planning_horizon:
            plan?.planning_horizon ??
            (horizonKeys.includes('3_year')
                ? '3_year'
                : (horizonKeys[0] ?? '')),
        period_start: dateOnly(plan?.period_start),
        period_end: dateOnly(plan?.period_end),
        vision_statement: plan?.vision_statement ?? '',
        mission_statement: plan?.mission_statement ?? '',
        values: normaliseValues(plan?.values).map((entry) => ({
            key: nextKey('value'),
            value: entry.value,
            description: entry.description ?? '',
        })),
        goals: [],
    });
    const { data, setData, processing } = form;
    const existingGoals = plan?.goals ?? [];

    const [stepIndex, setStepIndex] = useState(() =>
        Math.max(
            0,
            STRATEGIC_PLAN_STEPS.findIndex((s) => s.key === initialStep),
        ),
    );
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [suggestedEnd, setSuggestedEnd] = useState<string | null>(null);

    const step = STRATEGIC_PLAN_STEPS[stepIndex];
    const pct = useMemo(
        () => completeness(data, existingGoals.length),
        [data, existingGoals.length],
    );
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StrategicPlanStepKey) => {
        const index = STRATEGIC_PLAN_STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const next = () => {
        const errors = validateStep(step.key, data);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, STRATEGIC_PLAN_STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    /**
     * Suggest the end date from the plan length whenever the end is empty or
     * still the date we suggested last time — never over a date typed in.
     */
    const suggestEnd = (start: string, horizon: string) => {
        if (data.period_end && data.period_end !== suggestedEnd) return;
        const suggestion = suggestedPlanEnd(start, horizon);
        if (!suggestion) return;
        setSuggestedEnd(suggestion);
        setData('period_end', suggestion);
    };

    /* values */
    const patchValue = (key: string, patch: Partial<ValueRow>) =>
        setData(
            'values',
            data.values.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );
    const addValue = () =>
        setData('values', [
            ...data.values,
            { key: nextKey('value'), value: '', description: '' },
        ]);
    const removeValue = (key: string) => {
        setClientErrors({});
        setData(
            'values',
            data.values.filter((row) => row.key !== key),
        );
    };

    /* goals */
    const patchGoal = (key: string, patch: Partial<GoalRow>) =>
        setData(
            'goals',
            data.goals.map((goal) =>
                goal.key === key ? { ...goal, ...patch } : goal,
            ),
        );
    const addGoal = () =>
        setData('goals', [
            ...data.goals,
            {
                key: nextKey('goal'),
                title: '',
                description: '',
                pillar: pillarKeys.includes('quality')
                    ? 'quality'
                    : (pillarKeys[0] ?? ''),
                timeframe: '',
                key_results: [],
            },
        ]);
    const removeGoal = (key: string) => {
        setClientErrors({});
        setData(
            'goals',
            data.goals.filter((goal) => goal.key !== key),
        );
    };
    const addKeyResult = (goal: GoalRow) =>
        patchGoal(goal.key, {
            key_results: [
                ...goal.key_results,
                { key: nextKey('kr'), result: '' },
            ],
        });
    const patchKeyResult = (goal: GoalRow, key: string, result: string) =>
        patchGoal(goal.key, {
            key_results: goal.key_results.map((kr) =>
                kr.key === key ? { ...kr, result } : kr,
            ),
        });
    const removeKeyResult = (goal: GoalRow, key: string) =>
        patchGoal(goal.key, {
            key_results: goal.key_results.filter((kr) => kr.key !== key),
        });

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of STRATEGIC_PLAN_STEPS)
            Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'plan') ?? 'plan');
            return;
        }
        setClientErrors({});

        form.transform((current) => {
            const payload: Record<string, unknown> = {
                title: current.title.trim(),
                period_start: current.period_start,
                period_end: current.period_end,
                vision_statement: current.vision_statement.trim() || null,
                mission_statement: current.mission_statement.trim() || null,
                values: current.values.map((row) => ({
                    value: row.value.trim(),
                    description: row.description.trim() || null,
                })),
                goals: current.goals.map((goal) => ({
                    title: goal.title.trim(),
                    description: goal.description.trim(),
                    pillar: goal.pillar,
                    timeframe: goal.timeframe.trim() || null,
                    key_results: goal.key_results
                        .map((kr) => kr.result.trim())
                        .filter(Boolean)
                        .map((result) => ({ result })),
                })),
            };
            // A legacy plan length the register no longer offers is kept
            // as-is unless the editor picks a current one.
            if (
                !(legacyHorizon && current.planning_horizon === legacyHorizon)
            ) {
                payload.planning_horizon = current.planning_horizon;
            }
            return payload;
        });

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const target = firstErrorStep(errors, FIELD_STEPS, 'review');
                if (target) goTo(target);
            },
        };

        if (plan) {
            form.put(`/governance/strategy/${plan.id}`, visit);
        } else {
            form.post('/governance/strategy', visit);
        }
    };

    const isReview = step.key === 'review';
    const horizonOptions = [
        ...horizonKeys.map((key) => ({
            key,
            label: options.horizons[key] ?? humaniseHorizon(key),
            description: HORIZON_BLURBS[key],
            icon: CalendarRange,
        })),
        ...(legacyHorizon
            ? [
                  {
                      key: legacyHorizon,
                      label: humaniseHorizon(legacyHorizon),
                      description:
                          'This plan’s current length — no longer offered for new plans.',
                      icon: CalendarRange,
                  },
              ]
            : []),
    ];
    const lengthWarning = planLengthMismatch(
        data.period_start,
        data.period_end,
        data.planning_horizon,
    );
    const defaultTimeframe =
        data.period_start && data.period_end
            ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
            : 'the plan dates';

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Plan updated' : 'Plan saved'}
            blurb={
                isEdit
                    ? `${data.title.trim() || 'The plan'} has been saved${data.goals.length > 0 ? ` with ${data.goals.length} new goal${data.goals.length === 1 ? '' : 's'}` : ''}.`
                    : `${data.title.trim() || 'The plan'} is saved as a draft. When it's ready for the board, the secretary prepares a resolution that names it.`
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit strategic plan' : 'New strategic plan'}
                description={
                    isEdit
                        ? 'Update this draft strategic plan.'
                        : "Set out the board's plan: how long it runs, its direction, values and goals."
                }
                railIcon={Compass}
                railTitle={isEdit ? 'Edit plan' : 'New plan'}
                railSub={
                    plan ? `Version ${plan.version_number}` : 'Strategic plan'
                }
                steps={STRATEGIC_PLAN_STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() =>
                                setStepIndex((i) => Math.max(i - 1, 0))
                            }
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
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
                                {isEdit ? 'Save plan' : 'Create plan'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={step.key}>
                    {step.key === 'plan' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Compass}
                                    title="Plan and dates"
                                    blurb="Name the plan, choose how many years it covers and when it runs."
                                />
                            </div>
                            {plan ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    If a resolution has already been prepared
                                    for this version, changing it means the
                                    resolution must be linked to it again
                                    before the board votes.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Title"
                                required
                                span
                                error={err('title')}
                            >
                                <Input
                                    id="plan-title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g. Strategic plan 2026–2029"
                                />
                            </Field>
                            <Field
                                label="Plan length"
                                hint="how many years the plan covers"
                                required
                                span
                                error={err('planning_horizon')}
                            >
                                <TilePicker
                                    value={data.planning_horizon}
                                    onChange={(value) => {
                                        setData('planning_horizon', value);
                                        suggestEnd(data.period_start, value);
                                    }}
                                    options={horizonOptions}
                                />
                            </Field>
                            <Field
                                label="Starts"
                                required
                                error={err('period_start')}
                            >
                                <Input
                                    id="plan-period-start"
                                    type="date"
                                    value={data.period_start}
                                    onChange={(e) => {
                                        setData('period_start', e.target.value);
                                        suggestEnd(
                                            e.target.value,
                                            data.planning_horizon,
                                        );
                                    }}
                                />
                            </Field>
                            <Field
                                label="Ends"
                                hint={
                                    suggestedEnd &&
                                    data.period_end === suggestedEnd
                                        ? 'suggested from the plan length'
                                        : undefined
                                }
                                required
                                error={err('period_end')}
                            >
                                <Input
                                    id="plan-period-end"
                                    type="date"
                                    value={data.period_end}
                                    onChange={(e) =>
                                        setData('period_end', e.target.value)
                                    }
                                />
                            </Field>
                            {lengthWarning ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    {lengthWarning} Check the dates or the plan
                                    length.
                                </InfoCard>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'direction' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Eye}
                                title="Vision and mission"
                                blurb="The future the plan works towards, and how the organisation gets there."
                            />
                            <Field
                                label="Vision"
                                hint="optional — you can add it later"
                                error={
                                    err('vision_statement') ??
                                    err('description')
                                }
                            >
                                <Textarea
                                    id="plan-vision"
                                    rows={4}
                                    value={data.vision_statement}
                                    onChange={(e) =>
                                        setData(
                                            'vision_statement',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Every person we support lives a good life in a home of their choosing."
                                />
                            </Field>
                            <Field
                                label="Mission"
                                hint="optional — you can add it later"
                                error={err('mission_statement')}
                            >
                                <Textarea
                                    id="plan-mission"
                                    rows={4}
                                    value={data.mission_statement}
                                    onChange={(e) =>
                                        setData(
                                            'mission_statement',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Safe, person-led supported living delivered with whānau."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'values' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Gem}
                                title="Values"
                                blurb="The values that shape how the plan is delivered."
                            />
                            {data.values.length === 0 ? (
                                <InfoCard icon={Gem}>
                                    No values yet. Add the values the board
                                    wants this plan to reflect, or continue
                                    without.
                                </InfoCard>
                            ) : null}
                            {data.values.map((row, index) => (
                                <div
                                    key={row.key}
                                    className="grid gap-3 rounded-xl border border-border bg-muted/20 p-4 sm:grid-cols-[1fr_2fr_auto]"
                                >
                                    <Field
                                        label={`Value ${index + 1}`}
                                        required
                                        error={err(`values.${index}.value`)}
                                    >
                                        <Input
                                            value={row.value}
                                            onChange={(e) =>
                                                patchValue(row.key, {
                                                    value: e.target.value,
                                                })
                                            }
                                            placeholder="e.g. Manaakitanga"
                                        />
                                    </Field>
                                    <Field
                                        label="What it means"
                                        hint="optional"
                                        error={err(
                                            `values.${index}.description`,
                                        )}
                                    >
                                        <Input
                                            value={row.description}
                                            onChange={(e) =>
                                                patchValue(row.key, {
                                                    description: e.target.value,
                                                })
                                            }
                                            placeholder="e.g. Care, respect and hospitality"
                                        />
                                    </Field>
                                    <div className="flex items-end">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeValue(row.key)}
                                            aria-label={`Remove value ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            <FieldErr>{err('values')}</FieldErr>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addValue}
                                >
                                    <Plus className="h-4 w-4" /> Add value
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {step.key === 'goals' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={Target}
                                title="Strategic goals"
                                blurb={
                                    isEdit
                                        ? 'Add goals to this plan. Existing goals keep their progress and initiatives.'
                                        : 'Add the goals this plan commits to, and how you’ll know each one is met.'
                                }
                            />
                            {existingGoals.length > 0 ? (
                                <ReviewCard
                                    icon={Target}
                                    title={`Current goals (${existingGoals.length})`}
                                >
                                    {existingGoals.map((goal) => (
                                        <ReviewRow
                                            key={goal.id}
                                            label={goal.title}
                                            value={
                                                options.pillars[goal.pillar] ??
                                                goal.pillar
                                            }
                                        />
                                    ))}
                                </ReviewCard>
                            ) : null}
                            {data.goals.length === 0 &&
                            existingGoals.length === 0 ? (
                                <InfoCard icon={Target}>
                                    No goals yet. Add them now or later from the
                                    plan page.
                                </InfoCard>
                            ) : null}
                            {data.goals.map((goal, index) => (
                                <div
                                    key={goal.key}
                                    className="rounded-xl border border-border bg-muted/20 p-4"
                                >
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-caption font-semibold">
                                            New goal {index + 1}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeGoal(goal.key)}
                                            aria-label={`Remove goal ${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field
                                            label="Goal"
                                            required
                                            span
                                            error={err(`goals.${index}.title`)}
                                        >
                                            <Input
                                                value={goal.title}
                                                onChange={(e) =>
                                                    patchGoal(goal.key, {
                                                        title: e.target.value,
                                                    })
                                                }
                                                placeholder="e.g. Zero avoidable harm"
                                            />
                                        </Field>
                                        <Field
                                            label="Theme"
                                            hint="the strategic theme this goal belongs to"
                                            required
                                            error={err(`goals.${index}.pillar`)}
                                        >
                                            <SelectInput
                                                value={goal.pillar}
                                                onChange={(value) =>
                                                    patchGoal(goal.key, {
                                                        pillar: value,
                                                    })
                                                }
                                                placeholder="Theme"
                                                ariaLabel={`Goal ${index + 1} theme`}
                                                options={pillarKeys.map(
                                                    (key) => ({
                                                        value: key,
                                                        label:
                                                            options.pillars[
                                                                key
                                                            ] ?? key,
                                                    }),
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            label="Timeframe"
                                            hint="optional"
                                            error={err(
                                                `goals.${index}.timeframe`,
                                            )}
                                        >
                                            <Input
                                                value={goal.timeframe}
                                                onChange={(e) =>
                                                    patchGoal(goal.key, {
                                                        timeframe:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder={`Leave blank to use ${defaultTimeframe}`}
                                            />
                                        </Field>
                                        <Field
                                            label="Description"
                                            required
                                            span
                                            error={err(
                                                `goals.${index}.description`,
                                            )}
                                        >
                                            <Textarea
                                                rows={3}
                                                value={goal.description}
                                                onChange={(e) =>
                                                    patchGoal(goal.key, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="What the goal achieves and why it matters."
                                            />
                                        </Field>
                                        <div className="grid gap-2 sm:col-span-2">
                                            <span className="text-caption">
                                                Measures of success — how
                                                you&apos;ll know the goal is met
                                                (measurable)
                                            </span>
                                            {goal.key_results.map(
                                                (kr, krIndex) => (
                                                    <div
                                                        key={kr.key}
                                                        className="flex items-start gap-2"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <Input
                                                                aria-label={`Goal ${index + 1} measure of success ${krIndex + 1}`}
                                                                value={
                                                                    kr.result
                                                                }
                                                                onChange={(e) =>
                                                                    patchKeyResult(
                                                                        goal,
                                                                        kr.key,
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. Medication errors under 1 per 1,000 doses"
                                                            />
                                                            <FieldErr>
                                                                {err(
                                                                    `goals.${index}.key_results.${krIndex}.result`,
                                                                )}
                                                            </FieldErr>
                                                        </div>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                removeKeyResult(
                                                                    goal,
                                                                    kr.key,
                                                                )
                                                            }
                                                            aria-label={`Remove measure of success ${krIndex + 1} from goal ${index + 1}`}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                ),
                                            )}
                                            <div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        addKeyResult(goal)
                                                    }
                                                >
                                                    <Plus className="h-3.5 w-3.5" />{' '}
                                                    Add measure of success
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            <FieldErr>{err('goals')}</FieldErr>
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={addGoal}
                                    disabled={pillarKeys.length === 0}
                                >
                                    <Plus className="h-4 w-4" /> Add goal
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review the plan"
                                blurb={
                                    isEdit
                                        ? 'Saving updates the plan and adds any new goals.'
                                        : 'The plan is saved as a draft. It’s approved by the board when a resolution naming this version passes.'
                                }
                            />
                            {err('status') ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    {err('status')}
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Compass}
                                    title="Plan"
                                    onEdit={() => goTo('plan')}
                                >
                                    <ReviewRow
                                        label="Title"
                                        value={data.title}
                                    />
                                    <ReviewRow
                                        label="Plan length"
                                        value={
                                            options.horizons[
                                                data.planning_horizon
                                            ] ??
                                            humaniseHorizon(
                                                data.planning_horizon,
                                            )
                                        }
                                    />
                                    <ReviewRow
                                        label="Dates"
                                        value={
                                            data.period_start && data.period_end
                                                ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Eye}
                                    title="Direction"
                                    onEdit={() => goTo('direction')}
                                >
                                    <ReviewRow
                                        label="Vision"
                                        value={
                                            data.vision_statement.trim() ||
                                            'Not written yet'
                                        }
                                    />
                                    <ReviewRow
                                        label="Mission"
                                        value={
                                            data.mission_statement.trim() ||
                                            'Not written yet'
                                        }
                                    />
                                    <ReviewRow
                                        label="Values"
                                        value={
                                            data.values.length > 0
                                                ? data.values
                                                      .map((row) =>
                                                          row.value.trim(),
                                                      )
                                                      .filter(Boolean)
                                                      .join(', ')
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Target}
                                    title={
                                        isEdit
                                            ? `New goals (${data.goals.length})`
                                            : `Goals (${data.goals.length})`
                                    }
                                    onEdit={() => goTo('goals')}
                                    span
                                >
                                    {data.goals.length > 0 ? (
                                        data.goals.map((goal, index) => {
                                            const measures =
                                                goal.key_results.filter((kr) =>
                                                    kr.result.trim(),
                                                ).length;
                                            return (
                                                <ReviewRow
                                                    key={goal.key}
                                                    label={
                                                        goal.title ||
                                                        `Goal ${index + 1}`
                                                    }
                                                    value={`${options.pillars[goal.pillar] ?? goal.pillar} · ${measures} measure${measures === 1 ? '' : 's'} of success`}
                                                />
                                            );
                                        })
                                    ) : (
                                        <p className="text-caption">
                                            {isEdit
                                                ? `No new goals — ${existingGoals.length} existing goal${existingGoals.length === 1 ? '' : 's'} unchanged.`
                                                : 'No goals added.'}
                                        </p>
                                    )}
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                mode={isEdit ? 'edit' : 'create'}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description={
                    isEdit
                        ? 'Your changes to this strategic plan will be lost.'
                        : 'The details you entered for this plan will be lost.'
                }
            />
        </>
    );
}
