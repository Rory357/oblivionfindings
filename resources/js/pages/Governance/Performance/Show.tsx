import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
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
import { Badge } from '@/components/ui/badge';
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
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { assess as assessReview } from '@/routes/governance/performance';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import {
    Award,
    Pencil,
    Star,
    Target,
    TrendingUp,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    BOARD_DECISION_LABELS,
    PerformanceReviewWizardDialog,
    RATING_LABELS,
    humanise,
    ratingVariant,
    reviewStatusVariant,
} from './_dialogs';

interface Goal {
    id: number;
    pillar: string;
    goal_description: string;
    weight: number;
    target_score: number;
    actual_score: number | null;
    status: string;
    evidence_summary: string | null;
    board_assessment: string | null;
}

interface Kpi {
    id: number;
    kpi_name: string;
    target_value: number;
    actual_value: number | null;
    unit: string;
    is_automated: boolean;
}

interface Review {
    id: number;
    reviewee: { id: number; name: string };
    review_cycle: string;
    review_type: string;
    period_start: string;
    period_end: string;
    status: string;
    overall_rating: string | null;
    overall_assessment: string | null;
    board_decision: string | null;
    decision_notes: string | null;
    self_assessment_submitted_at: string | null;
    goals: Goal[];
    kpis: Kpi[];
}

interface Props extends PageProps {
    review: Review;
    can_assess: boolean;
    can_update?: boolean;
}

const PILLAR_LABELS: Record<string, string> = {
    safety: 'Safety',
    quality: 'Quality',
    people: 'People',
    finance: 'Finance',
    compliance: 'Compliance',
    it_resilience: 'IT Resilience',
};

function goalStatusVariant(status: string) {
    switch (status) {
        case 'achieved':
            return 'success' as const;
        case 'partially_achieved':
            return 'warning' as const;
        case 'missed':
            return 'critical' as const;
        case 'in_progress':
            return 'info' as const;
        default:
            return 'neutral' as const;
    }
}

const dateOnly = (value: string | null | undefined) =>
    formatDateOnly(value?.slice(0, 10));

export default function PerformanceShow({
    review,
    can_assess,
    can_update = false,
}: Props) {
    const [assessmentOpen, setAssessmentOpen] = useState(false);
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', can_update);

    const initialAssessments = useMemo(() => {
        return review.goals.reduce<
            Record<string, { score: string; comments: string }>
        >((acc, goal) => {
            acc[String(goal.id)] = {
                score: String(goal.actual_score ?? goal.target_score ?? 3),
                comments: goal.board_assessment ?? '',
            };
            return acc;
        }, {});
    }, [review.goals]);

    const { data, setData, post, processing, errors } = useForm({
        goal_assessments: initialAssessments,
        overall_rating: review.overall_rating ?? '',
        board_decision: review.board_decision ?? '',
        decision_notes: review.decision_notes ?? '',
    });

    const getPillarLabel = (pillar: string) => PILLAR_LABELS[pillar] || pillar;

    const getRatingLabel = (rating: string | null) => {
        if (!rating) return 'Not yet rated';
        return RATING_LABELS[rating] || rating;
    };

    const calculateOverallProgress = () => {
        if (review.goals.length === 0) return 0;
        const totalWeight = review.goals.reduce((sum, g) => sum + g.weight, 0);
        const weightedScore = review.goals.reduce((sum, g) => {
            const score = g.actual_score || 0;
            return sum + (score / g.target_score) * g.weight;
        }, 0);
        return Math.round((weightedScore / totalWeight) * 100);
    };

    const updateGoalAssessment = (
        goalId: number,
        field: 'score' | 'comments',
        value: string,
    ) => {
        const key = String(goalId);
        setData('goal_assessments', {
            ...data.goal_assessments,
            [key]: {
                ...(data.goal_assessments[key] ?? { score: '', comments: '' }),
                [field]: value,
            },
        });
    };

    const submitAssessment = (event: React.FormEvent) => {
        event.preventDefault();
        post(assessReview.url({ review: review.id }), {
            onSuccess: () => setAssessmentOpen(false),
        });
    };

    const progress = calculateOverallProgress();
    const isComplete = review.status === 'completed';
    const boardStageReached = review.status === 'board_review' || isComplete;

    const timeline = [
        {
            icon: User,
            label: 'Self assessment',
            done: Boolean(review.self_assessment_submitted_at),
            detail: review.self_assessment_submitted_at
                ? formatDateTimeLong(review.self_assessment_submitted_at)
                : 'Pending',
        },
        {
            icon: Star,
            label: 'Board assessment',
            done: boardStageReached,
            detail: boardStageReached ? 'Submitted' : 'Pending',
        },
        {
            icon: Award,
            label: 'Completed',
            done: isComplete,
            detail: isComplete ? 'Done' : 'Pending',
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'CEO performance', href: '/governance/performance' },
                {
                    title: `${review.reviewee.name} · ${review.review_cycle}`,
                    href: `/governance/performance/${review.id}`,
                },
            ]}
        >
            <Head title={`Performance review — ${review.reviewee.name}`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/performance"
                        icon={Target}
                        title={`Performance review — ${review.reviewee.name}`}
                        titleDusk="performance-heading"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={reviewStatusVariant(review.status)}
                            >
                                {humanise(review.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={`${review.review_cycle} · ${dateOnly(review.period_start)} – ${dateOnly(review.period_end)}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Goals"
                                    href={`/governance/performance/${review.id}`}
                                >
                                    <PageHeaderMeterBig>
                                        {review.goals.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {progress}% weighted progress
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="KPIs"
                                    href={`/governance/performance/${review.id}`}
                                >
                                    <PageHeaderMeterBig>
                                        {review.kpis.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Key metrics tracked
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Rating"
                                    href={`/governance/performance/${review.id}`}
                                >
                                    <PageHeaderMeterBig>
                                        {review.overall_rating
                                            ? getRatingLabel(
                                                  review.overall_rating,
                                              )
                                            : '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Overall board rating
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Status"
                                    href={`/governance/performance?status=${review.status}`}
                                    tone={isComplete ? 'success' : 'brand'}
                                >
                                    <PageHeaderMeterBig>
                                        {humanise(review.status)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Current stage
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        actions={
                            <>
                                {can_update ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit review
                                    </PageHeaderGlassButton>
                                ) : null}
                                {!isComplete && can_assess ? (
                                    <PageHeaderPrimaryButton
                                        icon={Star}
                                        onClick={() => setAssessmentOpen(true)}
                                    >
                                        Continue review
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Review overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <div className="rounded-lg bg-muted p-4">
                                    <p className="text-caption">Period</p>
                                    <p className="font-medium">
                                        {dateOnly(review.period_start)} –{' '}
                                        {dateOnly(review.period_end)}
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted p-4">
                                    <p className="text-caption">
                                        Overall progress
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <Progress
                                            value={progress}
                                            className="flex-1"
                                        />
                                        <span className="font-medium tabular-nums">
                                            {progress}%
                                        </span>
                                    </div>
                                </div>
                                <div className="rounded-lg bg-muted p-4">
                                    <p className="text-caption">Goals</p>
                                    <p className="font-medium">
                                        {review.goals.length} defined
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted p-4">
                                    <p className="text-caption">KPIs</p>
                                    <p className="font-medium">
                                        {review.kpis.length} tracked
                                    </p>
                                </div>
                            </div>
                            {review.board_decision && (
                                <div className="rounded-lg border border-primary/40 bg-primary/10 p-4">
                                    <p className="text-sm font-medium text-primary">
                                        Board decision
                                    </p>
                                    <p className="text-primary">
                                        {BOARD_DECISION_LABELS[
                                            review.board_decision
                                        ] ?? humanise(review.board_decision)}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div className="flex flex-col gap-5 lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title flex items-center gap-2">
                                        <Target className="h-4 w-4" />
                                        Performance goals
                                    </CardTitle>
                                    <CardDescription>
                                        Goals by strategic pillar
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {review.goals.length > 0 ? (
                                        <div className="space-y-3">
                                            {review.goals.map((goal) => (
                                                <div
                                                    key={goal.id}
                                                    className="rounded-lg border p-4"
                                                >
                                                    <div className="mb-2 flex items-start justify-between gap-3">
                                                        <div>
                                                            <Badge
                                                                variant="outline"
                                                                className="mb-2"
                                                            >
                                                                {getPillarLabel(
                                                                    goal.pillar,
                                                                )}
                                                            </Badge>
                                                            <p className="font-medium">
                                                                {
                                                                    goal.goal_description
                                                                }
                                                            </p>
                                                        </div>
                                                        <StatusBadge
                                                            variant={goalStatusVariant(
                                                                goal.status,
                                                            )}
                                                        >
                                                            {humanise(
                                                                goal.status,
                                                            )}
                                                        </StatusBadge>
                                                    </div>
                                                    <div className="mt-3">
                                                        <div className="text-subtle mb-1 flex items-center justify-between">
                                                            <span>Progress</span>
                                                            <span className="tabular-nums">
                                                                {goal.actual_score ||
                                                                    0}{' '}
                                                                /{' '}
                                                                {
                                                                    goal.target_score
                                                                }{' '}
                                                                (Weight:{' '}
                                                                {goal.weight}%)
                                                            </span>
                                                        </div>
                                                        <Progress
                                                            value={
                                                                ((goal.actual_score ||
                                                                    0) /
                                                                    goal.target_score) *
                                                                100
                                                            }
                                                            className={cn(
                                                                goal.status ===
                                                                    'achieved' &&
                                                                    '[&>div]:bg-status-success',
                                                                goal.status ===
                                                                    'missed' &&
                                                                    '[&>div]:bg-status-critical',
                                                            )}
                                                        />
                                                    </div>
                                                    {goal.evidence_summary && (
                                                        <p className="text-subtle mt-2">
                                                            {
                                                                goal.evidence_summary
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <EmptyState
                                            variant="compact"
                                            icon={Target}
                                            title="No goals defined"
                                            description="Goals for this review have not been set."
                                        />
                                    )}
                                </CardContent>
                            </Card>

                            {review.kpis.length > 0 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title flex items-center gap-2">
                                            <TrendingUp className="h-4 w-4" />
                                            Key performance indicators
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="space-y-3">
                                            {review.kpis.map((kpi) => (
                                                <div
                                                    key={kpi.id}
                                                    className="flex items-center justify-between rounded-lg border p-3"
                                                >
                                                    <div>
                                                        <p className="font-medium">
                                                            {kpi.kpi_name}
                                                        </p>
                                                        <p className="text-subtle">
                                                            Target:{' '}
                                                            {kpi.target_value}{' '}
                                                            {kpi.unit}
                                                            {kpi.is_automated && (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="ml-2 text-xs"
                                                                >
                                                                    Auto
                                                                </Badge>
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div className="text-right">
                                                        <p className="text-lg font-semibold tabular-nums">
                                                            {kpi.actual_value !==
                                                            null
                                                                ? kpi.actual_value
                                                                : '—'}
                                                        </p>
                                                        <p className="text-caption">
                                                            {kpi.unit}
                                                        </p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}
                        </div>

                        <div className="flex flex-col gap-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Review timeline
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {timeline.map((stage) => {
                                        const Icon = stage.icon;
                                        return (
                                            <div
                                                key={stage.label}
                                                className="flex items-center gap-3"
                                            >
                                                <div
                                                    className={cn(
                                                        'flex h-8 w-8 items-center justify-center rounded-full',
                                                        stage.done
                                                            ? 'bg-status-success-bg text-status-success'
                                                            : 'bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    <Icon className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        {stage.label}
                                                    </p>
                                                    <p className="text-caption">
                                                        {stage.detail}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </CardContent>
                            </Card>

                            {review.overall_rating && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">
                                            Overall rating
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col items-center gap-2 text-center">
                                        <Star className="h-10 w-10 text-primary" />
                                        <StatusBadge
                                            variant={ratingVariant(
                                                review.overall_rating,
                                            )}
                                        >
                                            {getRatingLabel(
                                                review.overall_rating,
                                            )}
                                        </StatusBadge>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </div>
                </div>
            </PageLayout>

            <Dialog open={assessmentOpen} onOpenChange={setAssessmentOpen}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 900px)',
                        width: 'min(92vw, 900px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Continue review</DialogTitle>
                        <DialogDescription>
                            Score each goal and record the board&apos;s overall
                            rating and decision.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAssessment} className="space-y-6">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="overall_rating">
                                    Overall Rating
                                </Label>
                                <Select
                                    value={data.overall_rating}
                                    onValueChange={(value) =>
                                        setData('overall_rating', value)
                                    }
                                >
                                    <SelectTrigger id="overall_rating">
                                        <SelectValue placeholder="Select rating" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="exceeds">
                                            Exceeds Expectations
                                        </SelectItem>
                                        <SelectItem value="meets">
                                            Meets Expectations
                                        </SelectItem>
                                        <SelectItem value="needs_improvement">
                                            Needs Improvement
                                        </SelectItem>
                                        <SelectItem value="unsatisfactory">
                                            Unsatisfactory
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.overall_rating && (
                                    <p className="text-sm text-status-critical">
                                        {errors.overall_rating}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="board_decision">
                                    Board Decision
                                </Label>
                                <Select
                                    value={data.board_decision}
                                    onValueChange={(value) =>
                                        setData('board_decision', value)
                                    }
                                >
                                    <SelectTrigger id="board_decision">
                                        <SelectValue placeholder="Select decision" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="remuneration_increase">
                                            Remuneration increase
                                        </SelectItem>
                                        <SelectItem value="maintain">
                                            Maintain
                                        </SelectItem>
                                        <SelectItem value="development_plan">
                                            Development plan
                                        </SelectItem>
                                        <SelectItem value="performance_improvement">
                                            Performance improvement
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.board_decision && (
                                    <p className="text-sm text-status-critical">
                                        {errors.board_decision}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="decision_notes">
                                Decision Notes
                            </Label>
                            <Textarea
                                id="decision_notes"
                                value={data.decision_notes}
                                onChange={(e) =>
                                    setData('decision_notes', e.target.value)
                                }
                                rows={3}
                            />
                        </div>

                        <div className="space-y-4">
                            <h3 className="text-section-title">
                                Goal assessments
                            </h3>
                            {review.goals.map((goal) => (
                                <div
                                    key={goal.id}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Badge
                                                variant="outline"
                                                className="mb-2"
                                            >
                                                {getPillarLabel(goal.pillar)}
                                            </Badge>
                                            <p className="font-medium">
                                                {goal.goal_description}
                                            </p>
                                            <p className="text-subtle">
                                                Target: {goal.target_score}
                                            </p>
                                        </div>
                                        <div className="w-24">
                                            <Label
                                                className="text-xs"
                                                htmlFor={`goal-score-${goal.id}`}
                                            >
                                                Score (1-5)
                                            </Label>
                                            <Input
                                                id={`goal-score-${goal.id}`}
                                                type="number"
                                                min={1}
                                                max={5}
                                                step={1}
                                                value={
                                                    data.goal_assessments[
                                                        String(goal.id)
                                                    ]?.score ?? ''
                                                }
                                                onChange={(e) =>
                                                    updateGoalAssessment(
                                                        goal.id,
                                                        'score',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            {errors[
                                                `goal_assessments.${goal.id}.score` as keyof typeof errors
                                            ] && (
                                                <p className="text-xs text-status-critical">
                                                    {
                                                        errors[
                                                            `goal_assessments.${goal.id}.score` as keyof typeof errors
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="space-y-1">
                                        <Label
                                            className="text-xs"
                                            htmlFor={`goal-comments-${goal.id}`}
                                        >
                                            Comments
                                        </Label>
                                        <Textarea
                                            id={`goal-comments-${goal.id}`}
                                            value={
                                                data.goal_assessments[
                                                    String(goal.id)
                                                ]?.comments ?? ''
                                            }
                                            onChange={(e) =>
                                                updateGoalAssessment(
                                                    goal.id,
                                                    'comments',
                                                    e.target.value,
                                                )
                                            }
                                            rows={2}
                                        />
                                        {errors[
                                            `goal_assessments.${goal.id}.comments` as keyof typeof errors
                                        ] && (
                                            <p className="text-xs text-status-critical">
                                                {
                                                    errors[
                                                        `goal_assessments.${goal.id}.comments` as keyof typeof errors
                                                    ]
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAssessmentOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? 'Submitting...'
                                    : 'Submit Assessment'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {can_update ? (
                <PerformanceReviewWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    review={{
                        id: review.id,
                        reviewee: review.reviewee,
                        review_cycle: review.review_cycle,
                        review_type: review.review_type,
                        period_start: review.period_start,
                        period_end: review.period_end,
                        overall_rating: review.overall_rating,
                        overall_assessment: review.overall_assessment,
                        board_decision: review.board_decision,
                        decision_notes: review.decision_notes,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
