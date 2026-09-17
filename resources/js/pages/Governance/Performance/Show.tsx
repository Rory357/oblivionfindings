import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    pageHasFlashError,
    useDialogDeepLink,
} from '@/components/governance/governance-dialog-deep-link';
import { EntityChip } from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Progress } from '@/components/ui/progress';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly } from '@/lib/datetime';
import {
    goalStatusLabel,
    governanceStatus,
    performanceDecisionLabel,
    performanceRatingLabel,
    refSuffix,
    reviewCycleLabel,
    themeLabel,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Award,
    CheckCircle,
    FileText,
    Lock,
    Send,
    Star,
    Target,
    TrendingUp,
} from 'lucide-react';
import { useRef, useState, type FormEvent, type RefObject } from 'react';
import { CONFIDENTIAL_NOTICE } from './_dialogs';

interface Goal {
    id: number;
    pillar: string;
    goal_description: string;
    success_criteria?: string | null;
    weight: number | string;
    target_score: number | string;
    actual_score: number | string | null;
    status: string | null;
    evidence_summary: string | null;
    board_assessment: string | null;
}

interface Kpi {
    id: number;
    kpi_name: string;
    target_value: number | string | null;
    actual_value: number | string | null;
    unit: string | null;
    is_automated: boolean;
}

interface Review {
    id: number;
    reviewee: { id: number; name: string } | null;
    review_cycle: string;
    cycle_label?: string;
    review_type: string;
    period_start: string;
    period_end: string;
    status: string;
    overall_rating: string | null;
    overall_assessment: string | null;
    board_decision: string | null;
    decision_notes: string | null;
    self_assessment: string | null;
    self_assessment_submitted_at: string | null;
    approved_by_board_at: string | null;
    created_at: string | null;
    goals: Goal[];
    kpis: Kpi[];
}

interface CompletionResolution {
    id: number;
    title: string;
    reference: string | null;
    closed_at: string | null;
}

interface Props extends PageProps {
    review: Review;
    can_assess: boolean;
    can_update?: boolean;
    is_reviewee?: boolean;
    assessment_hidden?: boolean;
    board_assessment_recorded?: boolean | null;
    can_submit_self_assessment?: boolean;
    can_complete?: boolean;
    completion_resolutions?: CompletionResolution[];
    approval_resolution?: {
        id: number;
        title: string;
        reference: string | null;
    } | null;
}

const NONE = '__none';

/** What each score means — shown wherever the board scores a goal. */
const SCORE_LABELS: Record<string, string> = {
    '1': 'Not met',
    '2': 'Partly met',
    '3': 'Met',
    '4': 'Exceeded',
    '5': 'Well exceeded',
};

const RATING_VARIANT: Record<string, StatusVariant> = {
    exceeds: 'success',
    meets: 'info',
    needs_improvement: 'warning',
    unsatisfactory: 'critical',
};

const DECISIONS = [
    'remuneration_increase',
    'maintain',
    'development_plan',
    'performance_improvement',
];

const RATINGS = ['exceeds', 'meets', 'needs_improvement', 'unsatisfactory'];

const dateOnly = (value: string | null | undefined) =>
    formatDateOnly((value ?? '').slice(0, 10));

const scoreText = (score: number | string) => {
    const rounded = String(Math.round(Number(score)));
    return `${Number(score)} of 5${SCORE_LABELS[rounded] ? ` — ${SCORE_LABELS[rounded]}` : ''}`;
};

export default function PerformanceShow({
    review,
    can_assess,
    can_update = false,
    assessment_hidden = false,
    board_assessment_recorded = null,
    can_submit_self_assessment = false,
    can_complete = false,
    completion_resolutions = [],
    approval_resolution = null,
}: Props) {
    const isComplete = review.status === 'completed';
    const revieweeName = review.reviewee?.name ?? 'the person being reviewed';
    const cycle = review.cycle_label ?? reviewCycleLabel(review.review_cycle);
    const [assessmentOpen, setAssessmentOpen] = useDialogDeepLink(
        'edit',
        can_assess && !isComplete,
        isComplete
            ? "This review is complete, so the board's assessment can no longer change."
            : "You can't change this review.",
    );
    const [completeOpen, setCompleteOpen] = useState(false);
    const selfAssessmentRef = useRef<HTMLTextAreaElement>(null);

    const chip = governanceStatus('performance_review_status', review.status);
    const scoredGoals = review.goals.filter(
        (goal) => goal.actual_score !== null && goal.actual_score !== undefined,
    );
    const totalWeight = review.goals.reduce(
        (sum, goal) => sum + Number(goal.weight || 0),
        0,
    );
    const weightedProgress =
        scoredGoals.length > 0 && totalWeight > 0
            ? Math.round(
                  (review.goals.reduce(
                      (sum, goal) =>
                          sum +
                          (Number(goal.actual_score ?? 0) /
                              Math.max(Number(goal.target_score) || 1, 1)) *
                              Number(goal.weight || 0),
                      0,
                  ) /
                      totalWeight) *
                      100,
              )
            : null;
    const kpisWithData = review.kpis.filter(
        (kpi) => kpi.actual_value !== null && kpi.actual_value !== undefined,
    ).length;

    const writeSelfAssessment = () => {
        document
            .getElementById('self-assessment')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.setTimeout(() => selfAssessmentRef.current?.focus(), 300);
    };

    const primaryAction = can_complete
        ? 'complete'
        : can_assess && !isComplete
          ? 'assess'
          : can_submit_self_assessment
            ? 'self'
            : null;

    const timeline: Array<{
        key: string;
        label: string;
        state: string;
        variant: StatusVariant;
    }> = [
        {
            key: 'setup',
            label: 'Review set up',
            state: review.created_at
                ? formatDateLong(review.created_at)
                : 'Done',
            variant: 'success',
        },
        {
            key: 'self',
            label: 'Self-assessment',
            state: review.self_assessment_submitted_at
                ? `Sent ${formatDateLong(review.self_assessment_submitted_at)}`
                : isComplete
                  ? 'Not sent'
                  : 'Not sent yet',
            variant: review.self_assessment_submitted_at
                ? 'success'
                : 'neutral',
        },
        {
            key: 'board',
            label: "Board's assessment",
            state: assessment_hidden
                ? 'Shared when the review is completed'
                : board_assessment_recorded
                  ? 'Recorded'
                  : 'Not recorded yet',
            variant:
                !assessment_hidden && board_assessment_recorded
                    ? 'success'
                    : 'neutral',
        },
        {
            key: 'complete',
            label: 'Review completed',
            state: isComplete
                ? review.approved_by_board_at
                    ? formatDateLong(review.approved_by_board_at)
                    : 'Done'
                : 'Not yet',
            variant: isComplete ? 'success' : 'neutral',
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'CEO performance', href: '/governance/performance' },
                {
                    title: `${review.reviewee?.name ?? 'Performance review'} · ${cycle}`,
                    href: `/governance/performance/${review.id}`,
                },
            ]}
        >
            <Head title="Performance review" />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/performance"
                        icon={Target}
                        title={`Performance review — ${review.reviewee?.name ?? 'former user'}`}
                        titleDusk="performance-heading"
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${cycle} · ${dateOnly(review.period_start)} – ${dateOnly(review.period_end)}`}
                        actions={
                            <>
                                {primaryAction === 'complete' && can_assess ? (
                                    <PageHeaderGlassButton
                                        icon={Star}
                                        onClick={() => setAssessmentOpen(true)}
                                    >
                                        Update the board&apos;s assessment
                                    </PageHeaderGlassButton>
                                ) : null}
                                {primaryAction === 'complete' ? (
                                    <PageHeaderPrimaryButton
                                        icon={Award}
                                        onClick={() => setCompleteOpen(true)}
                                    >
                                        Complete review
                                    </PageHeaderPrimaryButton>
                                ) : primaryAction === 'assess' ? (
                                    <PageHeaderPrimaryButton
                                        icon={Star}
                                        onClick={() => setAssessmentOpen(true)}
                                    >
                                        {board_assessment_recorded
                                            ? "Update the board's assessment"
                                            : "Record the board's assessment"}
                                    </PageHeaderPrimaryButton>
                                ) : primaryAction === 'self' ? (
                                    <PageHeaderPrimaryButton
                                        icon={FileText}
                                        onClick={writeSelfAssessment}
                                    >
                                        Write my self-assessment
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Goals"
                                    href={`/governance/performance/${review.id}#goals`}
                                    preserveScroll
                                >
                                    <PageHeaderMeterBig>
                                        {review.goals.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {assessment_hidden
                                            ? 'Scores shared when complete'
                                            : weightedProgress !== null
                                              ? `${weightedProgress}% of target, weighted`
                                              : 'Not scored yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Key performance measures"
                                    href={`/governance/performance/${review.id}#kpis`}
                                    preserveScroll
                                >
                                    <PageHeaderMeterBig>
                                        {review.kpis.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {review.kpis.length === 0
                                            ? 'None set'
                                            : `${kpisWithData} with data`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Self-assessment"
                                    href={`/governance/performance/${review.id}#self-assessment`}
                                    preserveScroll
                                    tone={
                                        review.self_assessment_submitted_at
                                            ? 'success'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {review.self_assessment_submitted_at
                                            ? 'Sent'
                                            : 'Not sent'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {review.self_assessment_submitted_at
                                            ? formatDateLong(
                                                  review.self_assessment_submitted_at,
                                              )
                                            : `Written by ${revieweeName}`}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Board decision"
                                    href={`/governance/performance/${review.id}#outcome`}
                                    preserveScroll
                                    tone={isComplete ? 'success' : 'brand'}
                                >
                                    <PageHeaderMeterBig>
                                        {assessment_hidden
                                            ? 'Not shared yet'
                                            : review.overall_rating
                                              ? performanceRatingLabel(
                                                    review.overall_rating,
                                                )
                                              : 'Not recorded'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {assessment_hidden
                                            ? 'Shared when the review is completed'
                                            : review.board_decision
                                              ? performanceDecisionLabel(
                                                    review.board_decision,
                                                )
                                              : 'No decision recorded'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <InfoCard icon={Lock}>{CONFIDENTIAL_NOTICE}</InfoCard>

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div className="flex flex-col gap-5 lg:col-span-2">
                            <SelfAssessmentCard
                                review={review}
                                canSubmit={can_submit_self_assessment}
                                isComplete={isComplete}
                                revieweeName={revieweeName}
                                textareaRef={selfAssessmentRef}
                            />

                            <Card id="goals" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle className="text-section-title flex items-center gap-2">
                                        <Target className="h-4 w-4" />
                                        Goals
                                    </CardTitle>
                                    <CardDescription>
                                        Scored by the board from 1 to 5: 1 Not
                                        met · 3 Met · 5 Well exceeded
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {review.goals.length > 0 ? (
                                        <div className="flex flex-col gap-3">
                                            {review.goals.map((goal) => (
                                                <GoalRow
                                                    key={goal.id}
                                                    goal={goal}
                                                    hidden={assessment_hidden}
                                                />
                                            ))}
                                        </div>
                                    ) : (
                                        <EmptyState
                                            variant="compact"
                                            icon={Target}
                                            title="No goals set"
                                            description="Goals for this review haven't been set."
                                        />
                                    )}
                                </CardContent>
                            </Card>

                            <Card id="kpis" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle className="text-section-title flex items-center gap-2">
                                        <TrendingUp className="h-4 w-4" />
                                        Key performance measures (KPIs)
                                    </CardTitle>
                                    <CardDescription>
                                        Figures from the organisation&apos;s
                                        records for the review period.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {review.kpis.length > 0 ? (
                                        <div className="flex flex-col gap-3">
                                            {review.kpis.map((kpi) => (
                                                <div
                                                    key={kpi.id}
                                                    className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                                >
                                                    <div className="min-w-0">
                                                        <p className="font-medium">
                                                            {kpi.kpi_name}
                                                        </p>
                                                        <p className="text-subtle">
                                                            Target{' '}
                                                            {measureText(
                                                                kpi.target_value,
                                                                kpi.unit,
                                                            )}
                                                            {kpi.is_automated
                                                                ? ' · Updated automatically'
                                                                : ''}
                                                        </p>
                                                    </div>
                                                    <p className="text-sm font-semibold tabular-nums">
                                                        {kpi.actual_value !==
                                                            null &&
                                                        kpi.actual_value !==
                                                            undefined
                                                            ? measureText(
                                                                  kpi.actual_value,
                                                                  kpi.unit,
                                                              )
                                                            : 'No data yet'}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <EmptyState
                                            variant="compact"
                                            icon={TrendingUp}
                                            title="No key performance measures"
                                            description="None have been set for this review."
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        <div className="flex flex-col gap-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Where the review is up to
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ol className="flex flex-col gap-3">
                                        {timeline.map((stage) => (
                                            <li
                                                key={stage.key}
                                                className="flex items-center justify-between gap-3"
                                            >
                                                <span className="text-sm font-medium">
                                                    {stage.label}
                                                </span>
                                                <StatusBadge
                                                    variant={stage.variant}
                                                >
                                                    {stage.state}
                                                </StatusBadge>
                                            </li>
                                        ))}
                                    </ol>
                                </CardContent>
                            </Card>

                            <Card id="outcome" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Board&apos;s rating and decision
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 text-sm">
                                    {assessment_hidden ? (
                                        <p className="text-subtle">
                                            The board&apos;s scores, rating and
                                            decision are shared with you when
                                            the review is completed.
                                        </p>
                                    ) : review.overall_rating ? (
                                        <>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <StatusBadge
                                                    variant={
                                                        RATING_VARIANT[
                                                            review
                                                                .overall_rating
                                                        ] ?? 'neutral'
                                                    }
                                                >
                                                    {performanceRatingLabel(
                                                        review.overall_rating,
                                                    )}
                                                </StatusBadge>
                                                {review.board_decision ? (
                                                    <EntityChip>
                                                        {performanceDecisionLabel(
                                                            review.board_decision,
                                                        )}
                                                    </EntityChip>
                                                ) : null}
                                            </div>
                                            {review.overall_assessment ? (
                                                <p className="whitespace-pre-wrap">
                                                    {review.overall_assessment}
                                                </p>
                                            ) : null}
                                            {review.decision_notes ? (
                                                <p className="text-subtle whitespace-pre-wrap">
                                                    Notes:{' '}
                                                    {review.decision_notes}
                                                </p>
                                            ) : null}
                                            {!isComplete ? (
                                                <p className="text-caption">
                                                    {revieweeName} can&apos;t
                                                    see this until the review
                                                    is completed.
                                                </p>
                                            ) : null}
                                        </>
                                    ) : (
                                        <p className="text-subtle">
                                            The board hasn&apos;t recorded its
                                            assessment yet.
                                        </p>
                                    )}
                                    {isComplete && approval_resolution ? (
                                        <p className="text-subtle">
                                            Resolution:{' '}
                                            <Link
                                                href={`/governance/resolutions/${approval_resolution.id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {approval_resolution.title}
                                            </Link>
                                            {approval_resolution.reference
                                                ? ` · ${refSuffix(approval_resolution.reference)}`
                                                : ''}
                                        </p>
                                    ) : null}
                                    {can_update &&
                                    !isComplete &&
                                    !can_complete ? (
                                        <p className="text-caption">
                                            The review can be completed once the
                                            board&apos;s rating and decision are
                                            recorded.
                                        </p>
                                    ) : null}
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </PageLayout>

            {can_assess && !isComplete && assessmentOpen ? (
                <AssessmentDialog
                    review={review}
                    revieweeName={revieweeName}
                    onClose={() => setAssessmentOpen(false)}
                />
            ) : null}

            {can_complete && completeOpen ? (
                <CompleteDialog
                    review={review}
                    revieweeName={revieweeName}
                    resolutions={completion_resolutions}
                    onClose={() => setCompleteOpen(false)}
                />
            ) : null}
        </AppLayout>
    );
}

function measureText(value: number | string | null, unit: string | null) {
    if (value === null || value === undefined || value === '') return '—';
    const number = Number(value);
    const shown = Number.isFinite(number)
        ? String(Math.round(number * 100) / 100)
        : String(value);
    if (unit === 'percentage') return `${shown}%`;
    if (unit === 'count' || !unit) return shown;
    return `${shown} ${unit}`;
}

function GoalRow({ goal, hidden }: { goal: Goal; hidden: boolean }) {
    const scored = goal.actual_score !== null && goal.actual_score !== undefined;
    const statusChip =
        scored && goal.status
            ? governanceStatus('goal_status', goal.status)
            : null;

    return (
        <div className="rounded-lg border p-4">
            <div className="mb-2 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <EntityChip>{themeLabel(goal.pillar)}</EntityChip>
                    <p className="mt-2 font-medium">{goal.goal_description}</p>
                    {goal.success_criteria ? (
                        <p className="text-subtle mt-1">
                            How it&apos;s measured: {goal.success_criteria}
                        </p>
                    ) : null}
                </div>
                {statusChip ? (
                    <StatusBadge variant={statusChip.variant}>
                        {statusChip.label}
                    </StatusBadge>
                ) : null}
            </div>
            <p className="text-caption">
                Weight {Number(goal.weight)}% · Target{' '}
                {scoreText(goal.target_score)}
            </p>
            <div className="mt-3">
                {hidden ? (
                    <p className="text-subtle">
                        The board&apos;s score is shared when the review is
                        completed.
                    </p>
                ) : scored ? (
                    <>
                        <div className="text-subtle mb-1 flex items-center justify-between">
                            <span>Board&apos;s score</span>
                            <span className="tabular-nums">
                                {scoreText(goal.actual_score as number)}
                            </span>
                        </div>
                        <Progress
                            value={Math.min(
                                100,
                                (Number(goal.actual_score) /
                                    Math.max(Number(goal.target_score) || 1, 1)) *
                                    100,
                            )}
                        />
                        {goal.board_assessment ? (
                            <p className="text-subtle mt-2 whitespace-pre-wrap">
                                {goal.board_assessment}
                            </p>
                        ) : null}
                    </>
                ) : (
                    <p className="text-subtle">Not scored yet</p>
                )}
            </div>
            {goal.evidence_summary ? (
                <p className="text-subtle mt-2">{goal.evidence_summary}</p>
            ) : null}
        </div>
    );
}

function SelfAssessmentCard({
    review,
    canSubmit,
    isComplete,
    revieweeName,
    textareaRef,
}: {
    review: Review;
    canSubmit: boolean;
    isComplete: boolean;
    revieweeName: string;
    textareaRef: RefObject<HTMLTextAreaElement | null>;
}) {
    const form = useForm({ self_assessment: review.self_assessment ?? '' });
    const [confirmOpen, setConfirmOpen] = useState(false);

    const send = () =>
        form.post(`/governance/performance/${review.id}/self-assessment`, {
            preserveScroll: true,
        });

    return (
        <Card id="self-assessment" className="scroll-mt-5">
            <CardHeader>
                <CardTitle className="text-section-title flex items-center gap-2">
                    <FileText className="h-4 w-4" />
                    Self-assessment
                </CardTitle>
                <CardDescription>
                    {revieweeName}&apos;s own view of how they did against each
                    goal, written before the board completes its review.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {review.self_assessment_submitted_at ? (
                    <>
                        <p className="text-sm whitespace-pre-wrap">
                            {review.self_assessment ||
                                'The self-assessment was sent without text.'}
                        </p>
                        <p className="text-caption">
                            Sent to the board{' '}
                            {formatDateLong(review.self_assessment_submitted_at)}
                        </p>
                    </>
                ) : canSubmit ? (
                    <form
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            if (form.data.self_assessment.trim() === '') return;
                            setConfirmOpen(true);
                        }}
                        className="flex flex-col gap-3"
                    >
                        <Field
                            label="Your self-assessment"
                            required
                            hint="how you did against each goal, with examples"
                            error={form.errors.self_assessment}
                        >
                            <Textarea
                                id="self-assessment-text"
                                ref={textareaRef}
                                rows={8}
                                maxLength={10000}
                                value={form.data.self_assessment}
                                onChange={(e) =>
                                    form.setData(
                                        'self_assessment',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-caption">
                                The board reads this before it scores your
                                goals. You can&apos;t change it after sending.
                            </p>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    form.data.self_assessment.trim() === ''
                                }
                            >
                                <Send className="h-4 w-4" />
                                Send to the board
                            </Button>
                        </div>
                    </form>
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={FileText}
                        title={isComplete ? 'No self-assessment sent' : 'Not sent yet'}
                        description={
                            isComplete
                                ? 'The review was completed without a self-assessment.'
                                : `${revieweeName} hasn't sent their self-assessment yet.`
                        }
                    />
                )}
            </CardContent>
            {canSubmit ? (
                <ConfirmDialog
                    open={confirmOpen}
                    onClose={() => setConfirmOpen(false)}
                    onConfirm={send}
                    title="Send your self-assessment to the board?"
                    description="The board will read it when they assess your review. You can't change it after sending."
                    confirmText="Send to the board"
                    variant="default"
                />
            ) : null}
        </Card>
    );
}

function AssessmentDialog({
    review,
    revieweeName,
    onClose,
}: {
    review: Review;
    revieweeName: string;
    onClose: () => void;
}) {
    const form = useForm({
        overall_rating: review.overall_rating ?? NONE,
        board_decision: review.board_decision ?? NONE,
        overall_assessment: review.overall_assessment ?? '',
        decision_notes: review.decision_notes ?? '',
        goal_assessments: Object.fromEntries(
            review.goals.map((goal) => [
                String(goal.id),
                {
                    score:
                        goal.actual_score !== null &&
                        goal.actual_score !== undefined
                            ? String(Math.round(Number(goal.actual_score)))
                            : NONE,
                    comments: goal.board_assessment ?? '',
                },
            ]),
        ) as Record<string, { score: string; comments: string }>,
    });
    const errors = form.errors as Record<string, string>;
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [confirmOpen, setConfirmOpen] = useState(false);
    const err = (name: string) => clientErrors[name] ?? errors[name];

    const updateGoal = (
        goalId: number,
        field: 'score' | 'comments',
        value: string,
    ) =>
        form.setData('goal_assessments', {
            ...form.data.goal_assessments,
            [String(goalId)]: {
                ...(form.data.goal_assessments[String(goalId)] ?? {
                    score: NONE,
                    comments: '',
                }),
                [field]: value,
            },
        });

    const check = (event: FormEvent) => {
        event.preventDefault();
        const next: Record<string, string> = {};
        if (form.data.overall_rating === NONE)
            next.overall_rating = "Choose the board's overall rating.";
        if (form.data.board_decision === NONE)
            next.board_decision = "Choose the board's decision.";
        for (const goal of review.goals) {
            if (form.data.goal_assessments[String(goal.id)]?.score === NONE) {
                next[`goal_assessments.${goal.id}.score`] =
                    'Give this goal a score from 1 to 5.';
            }
        }
        setClientErrors(next);
        if (Object.keys(next).length === 0) setConfirmOpen(true);
    };

    const save = () => {
        form.transform((data) => ({
            overall_rating: data.overall_rating,
            board_decision: data.board_decision,
            overall_assessment: data.overall_assessment.trim() || null,
            decision_notes: data.decision_notes.trim() || null,
            goal_assessments: Object.fromEntries(
                Object.entries(data.goal_assessments).map(([id, value]) => [
                    id,
                    {
                        score: Number(value.score),
                        comments: value.comments.trim() || null,
                    },
                ]),
            ),
        }));
        form.post(`/governance/performance/${review.id}/assess`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    const scoreOptions = [
        { value: NONE, label: 'Choose a score' },
        ...Object.entries(SCORE_LABELS).map(([value, label]) => ({
            value,
            label: `${value} — ${label}`,
        })),
    ];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 860px)',
                    width: 'min(92vw, 860px)',
                }}
            >
                <form onSubmit={check} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>The board&apos;s assessment</DialogTitle>
                        <DialogDescription>
                            Score each goal and record the board&apos;s overall
                            rating and decision. {revieweeName} won&apos;t see
                            any of this until the review is completed.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 md:grid-cols-2">
                        <Field
                            label="Overall rating"
                            required
                            error={err('overall_rating')}
                        >
                            <SelectInput
                                value={form.data.overall_rating}
                                onChange={(value) =>
                                    form.setData('overall_rating', value)
                                }
                                placeholder="Choose a rating"
                                ariaLabel="Overall rating"
                                options={[
                                    { value: NONE, label: 'Choose a rating' },
                                    ...RATINGS.map((value) => ({
                                        value,
                                        label: performanceRatingLabel(value),
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Board decision"
                            required
                            error={err('board_decision')}
                        >
                            <SelectInput
                                value={form.data.board_decision}
                                onChange={(value) =>
                                    form.setData('board_decision', value)
                                }
                                placeholder="Choose a decision"
                                ariaLabel="Board decision"
                                options={[
                                    { value: NONE, label: 'Choose a decision' },
                                    ...DECISIONS.map((value) => ({
                                        value,
                                        label: performanceDecisionLabel(value),
                                    })),
                                ]}
                            />
                        </Field>
                        <Field
                            label="Overall assessment"
                            hint="optional"
                            span
                            error={err('overall_assessment')}
                        >
                            <Textarea
                                id="assessment-overall"
                                rows={4}
                                value={form.data.overall_assessment}
                                onChange={(e) =>
                                    form.setData(
                                        'overall_assessment',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes on the decision"
                            hint="optional"
                            span
                            error={err('decision_notes')}
                        >
                            <Textarea
                                id="assessment-notes"
                                rows={3}
                                value={form.data.decision_notes}
                                onChange={(e) =>
                                    form.setData(
                                        'decision_notes',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>

                    <div className="flex flex-col gap-3">
                        <h3 className="text-section-title">Goal scores</h3>
                        <p className="text-caption">
                            1 Not met · 2 Partly met · 3 Met · 4 Exceeded · 5
                            Well exceeded
                        </p>
                        {review.goals.map((goal) => (
                            <div
                                key={goal.id}
                                className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_220px]"
                            >
                                <div className="min-w-0">
                                    <EntityChip>{themeLabel(goal.pillar)}</EntityChip>
                                    <p className="mt-2 font-medium">
                                        {goal.goal_description}
                                    </p>
                                    <p className="text-caption">
                                        Target {scoreText(goal.target_score)} ·
                                        Weight {Number(goal.weight)}%
                                        {goal.status
                                            ? ` · ${goalStatusLabel(goal.status)}`
                                            : ''}
                                    </p>
                                </div>
                                <Field
                                    label="Score"
                                    required
                                    error={err(
                                        `goal_assessments.${goal.id}.score`,
                                    )}
                                >
                                    <SelectInput
                                        value={
                                            form.data.goal_assessments[
                                                String(goal.id)
                                            ]?.score ?? NONE
                                        }
                                        onChange={(value) =>
                                            updateGoal(goal.id, 'score', value)
                                        }
                                        placeholder="Choose a score"
                                        ariaLabel={`Score for ${goal.goal_description}`}
                                        options={scoreOptions}
                                    />
                                </Field>
                                <div className="md:col-span-2">
                                    <Field
                                        label="Comments"
                                        hint="optional"
                                        error={err(
                                            `goal_assessments.${goal.id}.comments`,
                                        )}
                                    >
                                        <Textarea
                                            id={`goal-comments-${goal.id}`}
                                            rows={2}
                                            value={
                                                form.data.goal_assessments[
                                                    String(goal.id)
                                                ]?.comments ?? ''
                                            }
                                            onChange={(e) =>
                                                updateGoal(
                                                    goal.id,
                                                    'comments',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </div>
                        ))}
                    </div>

                    {Object.keys(errors).length > 0 ? (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            Some details need attention — check the highlighted
                            fields.
                        </InfoCard>
                    ) : null}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Star className="h-4 w-4" />
                            Save the board&apos;s assessment
                        </Button>
                    </DialogFooter>
                </form>

                <ConfirmDialog
                    open={confirmOpen}
                    onClose={() => setConfirmOpen(false)}
                    onConfirm={save}
                    title="Save the board's assessment?"
                    description={`${revieweeName} won't see it until the review is completed.${
                        form.data.board_decision === 'remuneration_increase'
                            ? ' This records a decision to increase their pay.'
                            : ''
                    }`}
                    confirmText="Save assessment"
                    variant="default"
                />
            </DialogContent>
        </Dialog>
    );
}

function CompleteDialog({
    review,
    revieweeName,
    resolutions,
    onClose,
}: {
    review: Review;
    revieweeName: string;
    resolutions: CompletionResolution[];
    onClose: () => void;
}) {
    const form = useForm({ resolution_id: NONE });
    const errors = form.errors as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            data.resolution_id === NONE
                ? {}
                : { resolution_id: Number(data.resolution_id) },
        );
        form.post(`/governance/performance/${review.id}/approve`, {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                if (!pageHasFlashError(page)) onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 540px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Complete this review?</DialogTitle>
                        <DialogDescription>
                            {revieweeName} will see the rating, decision and
                            notes. The board&apos;s assessment can&apos;t change
                            after this.
                        </DialogDescription>
                    </DialogHeader>
                    {review.overall_rating ? (
                        <p className="text-subtle">
                            Rating: {performanceRatingLabel(review.overall_rating)}
                            {review.board_decision
                                ? ` · Decision: ${performanceDecisionLabel(review.board_decision)}`
                                : ''}
                        </p>
                    ) : null}
                    <Field
                        label="Resolution"
                        hint="optional — if the board passed one about this review"
                        error={errors.resolution_id}
                    >
                        <SelectInput
                            value={form.data.resolution_id}
                            onChange={(value) =>
                                form.setData('resolution_id', value)
                            }
                            placeholder="Complete without a resolution"
                            ariaLabel="Resolution"
                            options={[
                                {
                                    value: NONE,
                                    label: 'Complete without linking a resolution',
                                },
                                ...resolutions.map((resolution) => ({
                                    value: String(resolution.id),
                                    label: `${resolution.title}${resolution.reference ? ` · ${refSuffix(resolution.reference)}` : ''}`,
                                })),
                            ]}
                        />
                    </Field>
                    {resolutions.length === 0 ? (
                        <p className="text-caption">
                            No passed resolution is linked to this review, so it
                            will be completed without one.
                        </p>
                    ) : null}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <CheckCircle className="h-4 w-4" />
                            Complete review
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
