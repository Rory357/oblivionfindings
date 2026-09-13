import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import {
    firstErrorStep,
    pageHasFlashError,
} from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
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
    Lock,
    Loader2,
    Star,
    Target,
    UserCheck,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Shared vocab                                                       */
/* ------------------------------------------------------------------ */

export const REVIEW_TYPE_LABELS: Record<string, string> = {
    quarterly: 'Quarterly',
    annual: 'Annual',
    ad_hoc: 'Ad-hoc',
};

export const RATING_LABELS: Record<string, string> = {
    exceeds: 'Exceeds expectations',
    meets: 'Meets expectations',
    needs_improvement: 'Needs improvement',
    unsatisfactory: 'Unsatisfactory',
};

export const BOARD_DECISION_LABELS: Record<string, string> = {
    remuneration_increase: 'Remuneration increase',
    maintain: 'Maintain',
    development_plan: 'Development plan',
    performance_improvement: 'Performance improvement',
};

export function reviewStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'completed':
            return 'success';
        case 'board_review':
            return 'warning';
        case 'self_review':
        case 'peer_review':
            return 'info';
        default:
            return 'neutral';
    }
}

export function ratingVariant(rating: string | null): StatusVariant {
    switch (rating) {
        case 'exceeds':
            return 'success';
        case 'meets':
            return 'info';
        case 'needs_improvement':
            return 'warning';
        case 'unsatisfactory':
            return 'critical';
        default:
            return 'neutral';
    }
}

export const humanise = (value: string | null | undefined) =>
    value
        ? value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ')
        : '';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export interface BoardMemberOption {
    id: number;
    user_id: number;
    name: string;
    board_role: string | null;
}

/**
 * Edit mode only receives the fields the update route accepts. It is only
 * rendered for viewers allowed to update (who may also assess), so no
 * reviewee-masked value is ever prefilled here.
 */
export interface EditablePerformanceReview {
    id: number;
    reviewee: { id: number; name: string };
    review_cycle: string;
    review_type: string;
    period_start: string;
    period_end: string;
    overall_rating: string | null;
    overall_assessment: string | null;
    board_decision: string | null;
    decision_notes: string | null;
}

type ReviewForm = {
    reviewee_id: string;
    review_type: string;
    review_cycle: string;
    period_start: string;
    period_end: string;
    overall_rating: string;
    board_decision: string;
    overall_assessment: string;
    decision_notes: string;
};

type StepKey = 'reviewee' | 'cycle' | 'assessment' | 'review';

const ALL_STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'reviewee',
        label: 'Reviewee',
        blurb: 'Who is being reviewed',
        icon: UserCheck,
    },
    {
        key: 'cycle',
        label: 'Cycle & period',
        blurb: 'Type, cycle & review window',
        icon: CalendarRange,
    },
    {
        key: 'assessment',
        label: 'Board assessment',
        blurb: 'Rating, decision & narrative',
        icon: Star,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check and save',
        icon: ClipboardCheck,
    },
];

const FIELD_STEPS: Record<string, StepKey> = {
    reviewee_id: 'reviewee',
    review_type: 'cycle',
    review_cycle: 'cycle',
    period_start: 'cycle',
    period_end: 'cycle',
    overall_rating: 'assessment',
    board_decision: 'assessment',
    overall_assessment: 'assessment',
    decision_notes: 'assessment',
};

const TYPE_TILES = [
    {
        key: 'annual',
        label: 'Annual',
        description: 'Full-year appraisal against every pillar.',
        icon: CalendarDays,
    },
    {
        key: 'quarterly',
        label: 'Quarterly',
        description: 'Check-in on progress for the quarter.',
        icon: CalendarClock,
    },
    {
        key: 'ad_hoc',
        label: 'Ad-hoc',
        description: 'Out-of-cycle review the board requests.',
        icon: Zap,
    },
];

function validateStep(
    step: StepKey,
    data: ReviewForm,
    isEdit: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (isEdit) return errors;
    if (step === 'reviewee' && !data.reviewee_id) {
        errors.reviewee_id = 'Choose who is being reviewed.';
    }
    if (step === 'cycle') {
        if (!data.review_type) errors.review_type = 'Choose a review type.';
        if (!data.review_cycle.trim())
            errors.review_cycle = 'Choose the review cycle.';
        if (!data.period_start) errors.period_start = 'Set the period start.';
        if (!data.period_end) errors.period_end = 'Set the period end.';
        if (
            data.period_start &&
            data.period_end &&
            data.period_end <= data.period_start
        ) {
            errors.period_end = 'The period must end after it starts.';
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
    boardMembers?: BoardMemberOption[];
    reviewCycles?: Array<{ value: string; label: string }>;
    /** Edit mode — prefilled; PUTs the board-assessment summary fields. */
    review?: EditablePerformanceReview | null;
}

export function PerformanceReviewWizardDialog(
    props: PerformanceReviewWizardDialogProps,
) {
    return props.isOpen ? <PerformanceReviewWizardBody {...props} /> : null;
}

function PerformanceReviewWizardBody({
    isOpen,
    onClose,
    boardMembers = [],
    reviewCycles = [],
    review = null,
}: PerformanceReviewWizardDialogProps) {
    const isEdit = Boolean(review);
    const steps = useMemo(
        () =>
            ALL_STEPS.filter((step) => isEdit || step.key !== 'assessment'),
        [isEdit],
    );
    const annualCycle =
        reviewCycles.find((cycle) => cycle.value.endsWith('-Annual'))?.value ??
        '';

    const form = useForm<ReviewForm>({
        reviewee_id: review ? String(review.reviewee.id) : '',
        review_type: review?.review_type ?? 'annual',
        review_cycle: review?.review_cycle ?? annualCycle,
        period_start: review?.period_start?.slice(0, 10) ?? '',
        period_end: review?.period_end?.slice(0, 10) ?? '',
        overall_rating: review?.overall_rating ?? '',
        board_decision: review?.board_decision ?? '',
        overall_assessment: review?.overall_assessment ?? '',
        decision_notes: review?.decision_notes ?? '',
    });
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const step = steps[stepIndex] ?? steps[0];
    const err = (name: string): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const goTo = (key: StepKey) => {
        const index = steps.findIndex((s) => s.key === key);
        if (index >= 0) setStepIndex(index);
    };

    const pct = useMemo(() => {
        const fields = isEdit
            ? [
                  data.overall_rating,
                  data.board_decision,
                  data.overall_assessment.trim(),
                  data.decision_notes.trim(),
              ]
            : [
                  data.reviewee_id,
                  data.review_type,
                  data.review_cycle,
                  data.period_start,
                  data.period_end,
              ];
        return Math.round(
            (fields.filter(Boolean).length / fields.length) * 100,
        );
    }, [data, isEdit]);

    const next = () => {
        const errors = validateStep(step.key, data, isEdit);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, steps.length - 1));
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
        for (const s of steps) Object.assign(all, validateStep(s.key, data, isEdit));
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(firstErrorStep(all, FIELD_STEPS, 'reviewee') ?? 'reviewee');
            return;
        }
        setClientErrors({});

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

        if (review) {
            // The update route validates these summary fields as "sometimes":
            // untouched empty values are omitted rather than sent as null.
            form.transform((current) => {
                const payload: Record<string, string | null> = {
                    decision_notes: current.decision_notes || null,
                };
                if (current.overall_rating)
                    payload.overall_rating = current.overall_rating;
                if (current.board_decision)
                    payload.board_decision = current.board_decision;
                if (current.overall_assessment.trim())
                    payload.overall_assessment = current.overall_assessment;
                return payload;
            });
            form.put(`/governance/performance/${review.id}`, visit);
        } else {
            form.transform((current) => ({
                reviewee_id: current.reviewee_id,
                review_cycle: current.review_cycle,
                review_type: current.review_type,
                period_start: current.period_start,
                period_end: current.period_end,
            }));
            form.post('/governance/performance', visit);
        }
    };

    const revieweeName = review
        ? review.reviewee.name
        : (boardMembers.find(
              (member) => String(member.user_id) === data.reviewee_id,
          )?.name ?? null);
    const cycleLabel =
        reviewCycles.find((cycle) => cycle.value === data.review_cycle)
            ?.label ?? data.review_cycle;
    const isReview = step.key === 'review';

    const lockedNotice = (
        <InfoCard icon={Lock}>
            Fixed when the review was created. Goals, KPIs and the
            self-assessment are managed on the review page.
        </InfoCard>
    );

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Review updated' : 'Review created'}
            blurb={
                isEdit
                    ? `The board assessment summary for ${revieweeName ?? 'this review'} has been saved.`
                    : 'The review has been created with the default CEO goals and KPIs.'
            }
            actions={<Button onClick={onClose}>Close</Button>}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title={isEdit ? 'Edit performance review' : 'New performance review'}
                description="A guided wizard to set up or update a CEO performance review."
                railIcon={Target}
                railTitle={isEdit ? 'Edit review' : 'New review'}
                railSub={isEdit ? (review?.review_cycle ?? '') : 'CEO performance'}
                steps={steps}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
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
                                {isEdit ? 'Save changes' : 'Create review'}
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
                                blurb="Reviews are restricted to the reviewee, the chair and the committees with oversight."
                            />
                            {isEdit ? (
                                <>
                                    <Field label="Reviewee">
                                        <Input
                                            id="review-reviewee"
                                            value={review?.reviewee.name ?? ''}
                                            readOnly
                                        />
                                    </Field>
                                    {lockedNotice}
                                </>
                            ) : (
                                <Field
                                    label="Reviewee"
                                    required
                                    error={err('reviewee_id')}
                                >
                                    <SelectInput
                                        value={data.reviewee_id}
                                        onChange={(value) =>
                                            setData('reviewee_id', value)
                                        }
                                        placeholder="Select a board member"
                                        ariaLabel="Reviewee"
                                        options={boardMembers.map((member) => ({
                                            value: String(member.user_id),
                                            label: member.board_role
                                                ? `${member.name} (${humanise(member.board_role)})`
                                                : member.name,
                                        }))}
                                    />
                                </Field>
                            )}
                        </div>
                    ) : null}

                    {step.key === 'cycle' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={CalendarRange}
                                    title="Cycle and review window"
                                    blurb="Default CEO goals and KPIs are generated for the period when the review is created."
                                />
                            </div>
                            {isEdit ? (
                                <>
                                    <Field label="Review type">
                                        <Input
                                            id="review-type"
                                            value={
                                                REVIEW_TYPE_LABELS[data.review_type] ??
                                                humanise(data.review_type)
                                            }
                                            readOnly
                                        />
                                    </Field>
                                    <Field label="Review cycle">
                                        <Input
                                            id="review-cycle"
                                            value={data.review_cycle}
                                            readOnly
                                        />
                                    </Field>
                                    <Field label="Period start">
                                        <Input
                                            id="review-period-start"
                                            value={formatDateOnly(data.period_start)}
                                            readOnly
                                        />
                                    </Field>
                                    <Field label="Period end">
                                        <Input
                                            id="review-period-end"
                                            value={formatDateOnly(data.period_end)}
                                            readOnly
                                        />
                                    </Field>
                                    {lockedNotice}
                                </>
                            ) : (
                                <>
                                    <Field
                                        label="Review type"
                                        required
                                        span
                                        error={err('review_type')}
                                    >
                                        <TilePicker
                                            cols={3}
                                            value={data.review_type}
                                            onChange={(value) =>
                                                setData('review_type', value)
                                            }
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
                                            onChange={(value) =>
                                                setData('review_cycle', value)
                                            }
                                            placeholder="Select a cycle"
                                            ariaLabel="Review cycle"
                                            options={reviewCycles}
                                        />
                                    </Field>
                                    <Field
                                        label="Period start"
                                        required
                                        error={err('period_start')}
                                    >
                                        <Input
                                            id="review-period-start"
                                            type="date"
                                            value={data.period_start}
                                            onChange={(e) =>
                                                setData(
                                                    'period_start',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Period end"
                                        required
                                        error={err('period_end')}
                                    >
                                        <Input
                                            id="review-period-end"
                                            type="date"
                                            value={data.period_end}
                                            onChange={(e) =>
                                                setData(
                                                    'period_end',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </>
                            )}
                        </div>
                    ) : null}

                    {step.key === 'assessment' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <StepHead
                                    icon={Star}
                                    title="Board assessment summary"
                                    blurb="Goal-by-goal scores are recorded with Continue review on the review page."
                                />
                            </div>
                            <Field
                                label="Overall rating"
                                error={err('overall_rating')}
                            >
                                <SelectInput
                                    value={data.overall_rating}
                                    onChange={(value) =>
                                        setData('overall_rating', value)
                                    }
                                    placeholder="Select rating"
                                    ariaLabel="Overall rating"
                                    options={Object.entries(RATING_LABELS).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                />
                            </Field>
                            <Field
                                label="Board decision"
                                error={err('board_decision')}
                            >
                                <SelectInput
                                    value={data.board_decision}
                                    onChange={(value) =>
                                        setData('board_decision', value)
                                    }
                                    placeholder="Select decision"
                                    ariaLabel="Board decision"
                                    options={Object.entries(
                                        BOARD_DECISION_LABELS,
                                    ).map(([value, label]) => ({
                                        value,
                                        label,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Overall assessment"
                                span
                                error={err('overall_assessment')}
                            >
                                <Textarea
                                    id="review-overall-assessment"
                                    rows={5}
                                    value={data.overall_assessment}
                                    onChange={(e) =>
                                        setData(
                                            'overall_assessment',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Overall assessment narrative for the period."
                                />
                            </Field>
                            <Field
                                label="Decision notes"
                                span
                                error={err('decision_notes')}
                            >
                                <Textarea
                                    id="review-decision-notes"
                                    rows={3}
                                    value={data.decision_notes}
                                    onChange={(e) =>
                                        setData('decision_notes', e.target.value)
                                    }
                                    placeholder="Context for the board decision."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {step.key === 'review' ? (
                        <div className="grid gap-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title={isEdit ? 'Review your changes' : 'Review and create'}
                                blurb={
                                    isEdit
                                        ? 'Only the board assessment summary is saved from here.'
                                        : 'Creating the review adds the standard CEO goals and KPIs for this period.'
                                }
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
                                    title="Reviewee"
                                    onEdit={isEdit ? undefined : () => goTo('reviewee')}
                                >
                                    <ReviewRow label="Name" value={revieweeName} />
                                </ReviewCard>
                                <ReviewCard
                                    icon={CalendarRange}
                                    title="Cycle & period"
                                    onEdit={isEdit ? undefined : () => goTo('cycle')}
                                >
                                    <ReviewRow
                                        label="Type"
                                        value={REVIEW_TYPE_LABELS[data.review_type]}
                                    />
                                    <ReviewRow label="Cycle" value={cycleLabel} />
                                    <ReviewRow
                                        label="Period"
                                        value={
                                            data.period_start && data.period_end
                                                ? `${formatDateOnly(data.period_start)} – ${formatDateOnly(data.period_end)}`
                                                : null
                                        }
                                    />
                                </ReviewCard>
                                {isEdit ? (
                                    <ReviewCard
                                        icon={Star}
                                        title="Board assessment"
                                        onEdit={() => goTo('assessment')}
                                        span
                                    >
                                        <ReviewRow
                                            label="Overall rating"
                                            value={RATING_LABELS[data.overall_rating]}
                                        />
                                        <ReviewRow
                                            label="Board decision"
                                            value={
                                                BOARD_DECISION_LABELS[
                                                    data.board_decision
                                                ]
                                            }
                                        />
                                        <ReviewRow
                                            label="Assessment"
                                            value={
                                                data.overall_assessment.trim()
                                                    ? 'Provided'
                                                    : null
                                            }
                                        />
                                        <ReviewRow
                                            label="Decision notes"
                                            value={
                                                data.decision_notes.trim()
                                                    ? 'Provided'
                                                    : null
                                            }
                                        />
                                    </ReviewCard>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <DiscardDraftDialog
                open={confirmClose}
                onKeepEditing={() => setConfirmClose(false)}
                onDiscard={() => {
                    setConfirmClose(false);
                    onClose();
                }}
                description="Any details entered for this performance review will be lost."
            />
        </>
    );
}
