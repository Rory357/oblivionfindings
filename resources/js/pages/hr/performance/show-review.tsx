import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Calendar,
    CheckCircle,
    FileText,
    GraduationCap,
    Star,
    Target,
    User,
} from 'lucide-react';

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
    created_at: string;
};

type Props = {
    review: PerformanceReview;
    can: { manage: boolean };
};

export default function ShowReview({ review, can }: Props) {
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review - ${review.employee.name}`} />

            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/performance/reviews"
                        title="Performance Review"
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

                {review.goals && review.goals.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Target className="h-4 w-4" />
                                Goals
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2">
                                {review.goals.map((goal, index) => (
                                    <li
                                        key={index}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <span className="font-medium text-muted-foreground">
                                            {index + 1}.
                                        </span>
                                        <span>{goal}</span>
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
        </AppLayout>
    );
}
