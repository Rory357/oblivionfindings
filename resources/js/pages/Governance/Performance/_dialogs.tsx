import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
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
import { performanceReviewTypeLabel } from '@/lib/governance-labels';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Loader2,
    Lock,
    Target,
    UserCheck,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/** Shown on every CEO performance page. */
export const CONFIDENTIAL_NOTICE =
    'Confidential — visible only to the CEO being reviewed (for their own self-assessment and final outcome), the chair and the people who run the review.';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface RevieweeOption {
    user_id: number;
    name: string;
    role_label: string | null;
}

export interface ReviewCycleOption {
    value: string;
    label: string;
    review_type?: string;
    period_start?: string;
    period_end?: string;
    financial_year?: string;
    is_current?: boolean;
}

type ReviewForm = {
    reviewee_id: string;
    review_type: string;
    review_cycle: string;
    period_start: string;
    period_end: string;
};

type StepKey = 'reviewee' | 'cycle' | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'reviewee',
        label: 'Who',
        blurb: 'Who is being reviewed',
        icon: UserCheck,
    },
    {
        key: 'cycle',
        label: 'Review period',
        blurb: 'Kind of review and dates',
        icon: CalendarRange,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and create',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StepKey> = {
    reviewee_id: 'reviewee',
    review_type: 'cycle',
    review_cycle: 'cycle',
    period_start: 'cycle',
    period_end: 'cycle',
};

const TYPE_TILES = [
    {
        key: 'annual',
        label: 'Annual',
        description: 'A full-year review against every goal.',
        icon: CalendarDays,
    },
    {
        key: 'quarterly',
        label: 'Quarterly',
        description: 'A check-in on progress over three months.',
        icon: CalendarClock,
    },
    {
        key: 'ad_hoc',
        label: 'One-off',
        description: 'A review the board asks for outside the usual cycle.',
        icon: Zap,
    },
];

/** The cycles offered for a kind of review ("one-off" can sit in any cycle). */
export function cyclesForType(
    cycles: ReviewCycleOption[],
    type: string,
): ReviewCycleOption[] {
    if (type === 'ad_hoc') return cycles;
    return cycles.filter(
        (cycle) => !cycle.review_type || cycle.review_type === type,
    );
}

function validateStep(step: StepKey, data: ReviewForm): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 'reviewee' && !data.reviewee_id) {
        errors.reviewee_id = 'Choose who is being reviewed.';
    }
    if (step === 'cycle') {
        if (!data.review_type) errors.review_type = 'Choose the kind of review.';
        if (!data.review_cycle.trim())
            errors.review_cycle = 'Choose the review cycle.';
        if (!data.period_start)
            errors.period_start = 'Set the date the review period starts.';
        if (!data.period_end)
            errors.period_end = 'Set the date the review period ends.';
        if (
            data.period_start &&
            data.period_end &&
            data.period_end <= data.period_start
        ) {
            errors.period_end = 'The review period must end after it starts.';
        }
    }
    return errors;
}

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export interface PerformanceReviewWizardDialogProps {
    isOpen: boolean;
    onClose: () => void;
    reviewees?: RevieweeOption[];
    reviewCycles?: ReviewCycleOption[];
}

export function PerformanceReviewWizardDialog(
    props: PerformanceReviewWizardDialogProps,
) {
    return props.isOpen ? <PerformanceReviewWizardBody {...props} /> : null;
}

function PerformanceReviewWizardBody({
    isOpen,
    onClose,
    reviewees = [],
    reviewCycles = [],
}: PerformanceReviewWizardDialogProps) {
    const initialCycle =
        reviewCycles.find(
            (cycle) => cycle.review_type === 'annual' && cycle.is_current,
        ) ??
        reviewCycles.find((cycle) => cycle.review_type === 'annual') ??
        reviewCycles[0];

    const form = useForm<ReviewForm>({
        reviewee_id: reviewees.length === 1 ? String(reviewees[0].user_id) : '',
        review_type: 'annual',
        review_cycle: initialCycle?.value ?? '',
        period_start: initialCycle?.period_start ?? '',
        period_end: initialCycle?.period_end ?? '',
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const step = STEPS[stepIndex] ?? STEPS[0];
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StepKey) => {
        const index = STEPS.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const pct = useMemo(() => {
        const fields = [
            data.reviewee_id,
            data.review_type,
            data.review_cycle,
            data.period_start,
            data.period_end,
        ];
        return Math.round(
            (fields.filter(Boolean).length / fields.length) * 100,
        );
    }, [data]);

    const availableCycles = cyclesForType(reviewCycles, data.review_type);
    const selectedCycle = reviewCycles.find(
        (cycle) => cycle.value === data.review_cycle,
    );

    const chooseCycle = (value: string) => {
        const cycle = reviewCycles.find((option) => option.value === value);
        setData('review_cycle', value);
        if (cycle?.period_start && cycle.period_end) {
            setData('period_start', cycle.period_start);
            setData('period_end', cycle.period_end);
        }
    };

    const chooseType = (type: string) => {
        setData('review_type', type);
        const options = cyclesForType(reviewCycles, type);
        if (options.some((cycle) => cycle.value === data.review_cycle)) return;
        const cycle =
            options.find((option) => option.is_current) ?? options[0];
        if (cycle) chooseCycle(cycle.value);
    };

    const next = () => {
        const errors = validateStep(step.key, data);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = () => {
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'reviewee') ?? 'reviewee');
            return;
        }
        setClientErrors({});

        form.post('/governance/performance', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const target = firstErrorStep(errors, FIELD_STEPS, 'review');
                if (target) goTo(target);
            },
        });
    };

    const reviewee = reviewees.find(
        (option) => String(option.user_id) === data.reviewee_id,
    );
    const isReview = step.key === 'review';

    const success = done ? (
        <WizardSuccessPane
            title="Review created"
            blurb="The review has the standard goals and key performance measures. The person being reviewed can now write their self-assessment."
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title="New performance review"
                description="Set up a performance review for the CEO or an executive."
                railIcon={Target}
                railTitle="New review"
                railSub="CEO performance"
                steps={STEPS}
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
                                Create review
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
                    {step.key === 'reviewee' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={UserCheck}
                                title="Who is being reviewed?"
                                blurb="The board reviews the CEO and, where it chooses to, other executives."
                            />
                            <InfoCard icon={Lock}>
                                Reviews are confidential. Only the person being
                                reviewed, the chair and the people who run the
                                review can see them.
                            </InfoCard>
                            <Field
                                label="Person being reviewed"
                                required
                                error={err('reviewee_id')}
                            >
                                <SelectInput
                                    value={data.reviewee_id}
                                    onChange={(value) =>
                                        setData('reviewee_id', value)
                                    }
                                    placeholder="Select the CEO or executive"
                                    ariaLabel="Person being reviewed"
                                    options={reviewees.map((option) => ({
                                        value: String(option.user_id),
                                        label: option.role_label
                                            ? `${option.name} (${option.role_label})`
                                            : option.name,
                                    }))}
                                />
                            </Field>
                            {reviewees.length === 0 ? (
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    Nobody has the CEO or executive role yet.
                                    Ask an administrator to give the CEO their
                                    role first.
                                </InfoCard>
                            ) : null}
                        </div>
                    ) : null}

                    {step.key === 'cycle' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={CalendarRange}
                                    title="Review period"
                                    blurb="Choose the kind of review and the period it covers. Reviews follow the financial year (1 July – 30 June)."
                                />
                            </div>
                            <Field
                                label="Kind of review"
                                required
                                span
                                error={err('review_type')}
                            >
                                <TilePicker
                                    cols={3}
                                    value={data.review_type}
                                    onChange={chooseType}
                                    options={TYPE_TILES}
                                />
                            </Field>
                            <Field
                                label="Review cycle"
                                required
                                span
                                error={err('review_cycle')}
                            >
                                <SelectInput
                                    value={data.review_cycle}
                                    onChange={chooseCycle}
                                    placeholder="Select a cycle"
                                    ariaLabel="Review cycle"
                                    options={availableCycles.map((cycle) => ({
                                        value: cycle.value,
                                        label: cycle.is_current
                                            ? `${cycle.label} — current`
                                            : cycle.label,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Period starts"
                                required
                                error={err('period_start')}
                            >
                                <Input
                                    id="review-period-start"
                                    type="date"
                                    value={data.period_start}
                                    onChange={(e) =>
                                        setData('period_start', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Period ends"
                                required
                                error={err('period_end')}
                            >
                                <Input
                                    id="review-period-end"
                                    type="date"
                                    value={data.period_end}
                                    onChange={(e) =>
                                        setData('period_end', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review and create"
                                blurb="Creating the review adds the standard CEO goals and key performance measures (KPIs) for this period."
                            />
                            {Object.keys(form.errors).length > 0 ? (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    Some details need attention — check the
                                    highlighted steps.
                                </InfoCard>
                            ) : null}
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard
                                    icon={UserCheck}
                                    title="Who"
                                    onEdit={() => goTo('reviewee')}
                                >
                                    <ReviewRow
                                        label="Name"
                                        value={reviewee?.name ?? null}
                                    />
                                    <ReviewRow
                                        label="Role"
                                        value={reviewee?.role_label ?? null}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={CalendarRange}
                                    title="Review period"
                                    onEdit={() => goTo('cycle')}
                                >
                                    <ReviewRow
                                        label="Kind of review"
                                        value={performanceReviewTypeLabel(
                                            data.review_type,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Cycle"
                                        value={
                                            selectedCycle?.label ??
                                            (data.review_cycle || null)
                                        }
                                    />
                                    <ReviewRow
                                        label="Period"
                                        value={
                                            data.period_start && data.period_end
                                                ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
                                                : null
                                        }
                                    />
                                </ReviewCard>
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                mode="create"
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description="The details you entered for this performance review will be lost."
            />
        </>
    );
}
