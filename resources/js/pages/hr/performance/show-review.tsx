import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EvidenceAttachment } from '@/components/hr/performance/evidence-attachment';
import { PerformanceSatelliteHero } from '@/components/hr/performance/performance-hero';
import {
    PerformanceWizards,
    type Opt,
    type WizardState,
    type WizardSupport,
} from '@/components/hr/performance/performance-wizards';
import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    Award,
    Calendar,
    CheckCircle,
    FileText,
    GitBranch,
    GraduationCap,
    Sparkles,
    Star,
    Target,
    TrendingUp,
    User,
} from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type PerformanceReview = {
    id: number;
    employee: {
        id: number;
        name: string;
    };
    reviewer: {
        id: number;
        name: string;
    };
    review_type: string;
    review_period_start: string;
    review_period_end: string;
    status: string;
    overall_rating: number | null;
    strengths: string | null;
    development_areas: string | null;
    goals: string[] | null;
    training_recommendations: string[] | null;
    next_review_date: string | null;
    employee_signed_off: boolean;
    employee_signed_off_at: string | null;
    manager_signed_off: boolean;
    manager_signed_off_at: string | null;
    evidence_path: string | null;
    created_at: string;
};

type ReviewGoal = {
    id: number;
    description: string;
    status: string;
    rating: number | null;
    goal: { id: number; title: string } | null;
};

type NextSteps = {
    action: 'pip' | 'succession';
    employee_profile_id?: number;
    staff: Opt[];
    successionEmployees?: Opt[];
};

type Props = {
    review: PerformanceReview;
    reviewGoals?: ReviewGoal[];
    nextSteps?: NextSteps | null;
    can: { manage: boolean };
};

const GOAL_STATUS_STYLE: Record<string, string> = {
    open: 'bg-status-info-bg text-status-info',
    met: 'bg-status-success-bg text-status-success',
    partially_met: 'bg-status-warning-bg text-status-warning',
    missed: 'bg-status-critical-bg text-status-critical',
};

export default function ShowReview({ review, reviewGoals = [], nextSteps = null, can }: Props) {
    const [wizard, setWizard] = useState<WizardState | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Performance & Supervision', href: '/hr/performance' },
        { title: 'Reviews', href: '/hr/performance/reviews' },
        {
            title: 'Review Details',
            href: `/hr/performance/reviews/${review.id}`,
        },
    ];

    const formatDate = (value?: string | null) => {
        if (!value) return 'Not set';
        const d = new Date(value);
        return Number.isNaN(d.getTime())
            ? value
            : d.toLocaleDateString('en-NZ', {
                  day: '2-digit',
                  month: 'short',
                  year: 'numeric',
              });
    };

    const getReviewTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            annual: 'Annual Review',
            mid_year: 'Mid-Year Review',
            quarterly: 'Quarterly Review',
            ad_hoc: 'Ad Hoc Review',
        };
        return labels[type] || type.replace(/_/g, ' ');
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'completed':
            case 'signed_off':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'in_progress':
                return 'bg-status-info-bg text-status-info border-status-info/30';
            case 'draft':
                return 'bg-muted text-foreground border-border';
            default:
                return 'bg-muted text-foreground border-border';
        }
    };

    const renderStars = (rating: number | null) => {
        if (rating === null)
            return <span className="text-muted-foreground">Not rated</span>;
        return (
            <div className="flex items-center gap-1">
                {[1, 2, 3, 4, 5].map((star) => (
                    <Star
                        key={star}
                        className={`h-5 w-5 ${star <= rating ? 'fill-amberx text-status-warning' : 'text-foreground'}`}
                    />
                ))}
                <span className="ml-2 text-sm text-muted-foreground">
                    (
                    {
                        [
                            '',
                            'Needs Improvement',
                            'Below Expectations',
                            'Meets Expectations',
                            'Exceeds Expectations',
                            'Outstanding',
                        ][rating]
                    }
                    )
                </span>
            </div>
        );
    };

    // Deliberate "Next steps" seam — the server only sends `nextSteps` for
    // signed-off, manageable reviews where the outcome warrants action AND no
    // equivalent process is already underway. Nothing is auto-created.
    const wizardSupport: WizardSupport = {
        staff: nextSteps?.staff ?? [],
        reviewTypes: [],
        competencyOptions: [],
        successionEmployees: nextSteps?.successionEmployees ?? [],
    };

    const openNextStep = () => {
        if (!nextSteps) return;
        if (nextSteps.action === 'pip') {
            setWizard({
                kind: 'pip',
                context: {
                    reviewId: review.id,
                    prefill: {
                        employee: review.employee.id,
                        reason: `Performance review outcome — overall rating ${review.overall_rating}/5`,
                    },
                },
            });
        } else {
            setWizard({
                kind: 'succession',
                context: {
                    reviewId: review.id,
                    prefill: nextSteps.employee_profile_id
                        ? { candidates: [nextSteps.employee_profile_id] }
                        : {},
                },
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review - ${review.employee.name}`} />

            <PageLayout
                hero={
                    <PerformanceSatelliteHero
                        icon={Award}
                        backHref="/hr/performance/reviews"
                        backLabel="Reviews"
                        title="Performance review"
                        description={`${getReviewTypeLabel(review.review_type)} for ${review.employee.name}`}
                    />
                }
            >
                <div className="flex flex-wrap gap-2">
                    <Badge className={getStatusColor(review.status)}>
                        {review.status.replace(/_/g, ' ')}
                    </Badge>
                    {review.employee_signed_off && (
                        <Badge variant="outline">
                            <CheckCircle className="mr-1 h-3 w-3" /> Employee
                            Signed
                        </Badge>
                    )}
                    {review.manager_signed_off && (
                        <Badge variant="outline">
                            <CheckCircle className="mr-1 h-3 w-3" /> Manager
                            Signed
                        </Badge>
                    )}
                </div>

                {can.manage && review.status === 'signed_off' && nextSteps && (
                    <Card
                        className={
                            nextSteps.action === 'pip'
                                ? 'border-status-warning/40'
                                : 'border-status-success/40'
                        }
                    >
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Sparkles className="h-4 w-4" />
                                Next steps
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-center justify-between gap-3">
                            {nextSteps.action === 'pip' ? (
                                <>
                                    <p className="max-w-[64ch] text-sm text-muted-foreground">
                                        This review was signed off with an
                                        overall rating of{' '}
                                        <span className="font-semibold text-foreground">
                                            {review.overall_rating}/5
                                        </span>{' '}
                                        and {review.employee.name} has no open
                                        improvement plan. You can start one
                                        prefilled from this review — nothing is
                                        created automatically.
                                    </p>
                                    <Button onClick={openNextStep}>
                                        <TrendingUp className="mr-1.5 h-4 w-4" />
                                        Start improvement plan
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <p className="max-w-[64ch] text-sm text-muted-foreground">
                                        This review was signed off with an
                                        overall rating of{' '}
                                        <span className="font-semibold text-foreground">
                                            {review.overall_rating}/5
                                        </span>{' '}
                                        and {review.employee.name} isn&apos;t in
                                        any active succession plan&apos;s
                                        candidate pool. You can nominate them
                                        via a succession plan prefilled from
                                        this review.
                                    </p>
                                    <Button onClick={openNextStep}>
                                        <GitBranch className="mr-1.5 h-4 w-4" />
                                        Nominate for succession
                                    </Button>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-4 w-4" />
                            Evidence
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <EvidenceAttachment
                            uploadUrl={`/hr/performance/reviews/${review.id}/evidence`}
                            viewUrl={`/hr/performance/reviews/${review.id}/evidence`}
                            hasEvidence={!!review.evidence_path}
                            canManage={can.manage}
                            disabled={review.status === 'signed_off'}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-4 w-4" />
                                Participants
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Employee
                                </div>
                                <div className="font-medium">
                                    {review.employee.name}
                                </div>
                            </div>
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Reviewer
                                </div>
                                <div className="font-medium">
                                    {review.reviewer.name}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-4 w-4" />
                                Review Period
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        From
                                    </div>
                                    <div className="font-medium">
                                        {formatDate(review.review_period_start)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        To
                                    </div>
                                    <div className="font-medium">
                                        {formatDate(review.review_period_end)}
                                    </div>
                                </div>
                            </div>
                            {review.next_review_date && (
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Next Review
                                    </div>
                                    <div className="font-medium">
                                        {formatDate(review.next_review_date)}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Star className="h-4 w-4" />
                            Overall Rating
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {renderStars(review.overall_rating)}
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="h-4 w-4" />
                                Strengths
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {review.strengths ? (
                                <div className="text-sm whitespace-pre-wrap">
                                    {review.strengths}
                                </div>
                            ) : (
                                <p className="text-muted-foreground italic">
                                    No strengths recorded
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Target className="h-4 w-4" />
                                Development Areas
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {review.development_areas ? (
                                <div className="text-sm whitespace-pre-wrap">
                                    {review.development_areas}
                                </div>
                            ) : (
                                <p className="text-muted-foreground italic">
                                    No development areas recorded
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {reviewGoals.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Target className="h-4 w-4" />
                                Goals
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                {reviewGoals.map((g, index) => (
                                    <li
                                        key={g.id}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <span className="font-medium text-muted-foreground">
                                            {index + 1}.
                                        </span>
                                        <span className="flex-1">
                                            {g.description}
                                            {g.goal && (
                                                <a
                                                    href={`/hr/goals/${g.goal.id}`}
                                                    className="ml-2 text-xs font-medium text-primary hover:underline"
                                                >
                                                    ↗ {g.goal.title}
                                                </a>
                                            )}
                                        </span>
                                        {g.status && g.status !== 'open' && (
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${GOAL_STATUS_STYLE[g.status] ?? 'bg-muted text-muted-foreground'}`}
                                            >
                                                {g.status.replace('_', ' ')}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {review.training_recommendations &&
                    review.training_recommendations.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <GraduationCap className="h-4 w-4" />
                                    Training Recommendations
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-2">
                                    {review.training_recommendations.map(
                                        (training, index) => (
                                            <li
                                                key={index}
                                                className="flex items-start gap-2 text-sm"
                                            >
                                                <span className="font-medium text-muted-foreground">
                                                    {index + 1}.
                                                </span>
                                                <span>{training}</span>
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </CardContent>
                        </Card>
                    )}

                <div className="flex items-center justify-end gap-4 pt-4">
                    <Link href="/hr/performance/reviews">
                        <Button variant="outline">Back to Reviews</Button>
                    </Link>
                    {can.manage && (
                        <Link
                            href={`/hr/performance/reviews/${review.id}/edit`}
                        >
                            <Button>Edit Review</Button>
                        </Link>
                    )}
                </div>
            </PageLayout>

            {wizard ? (
                <PerformanceWizards
                    state={wizard}
                    support={wizardSupport}
                    onClose={() => setWizard(null)}
                />
            ) : null}
        </AppLayout>
    );
}
