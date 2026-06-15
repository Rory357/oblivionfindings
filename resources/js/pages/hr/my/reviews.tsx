import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import { MyHrTabs } from '@/components/hr';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, Star, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface Review {
    id: number;
    review_type: string;
    review_period_start: string | null;
    review_period_end: string | null;
    status: 'draft' | 'in_progress' | 'completed' | 'signed_off';
    overall_rating: number | null;
    strengths: string | null;
    development_areas: string | null;
    goals: string | null;
    training_recommendations: string | null;
    employee_comments: string | null;
    employee_signed_off: boolean;
    employee_signed_off_at: string | null;
    manager_signed_off: boolean;
    next_review_date: string | null;
    reviewer: { id: number; name: string } | null;
}

interface Props {
    reviews: {
        data: Review[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Reviews', href: '/hr/my/reviews' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'In Progress',
    },
    completed: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Completed',
    },
    signed_off: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Signed Off',
    },
};

function RatingStars({ rating }: { rating: number | null }) {
    if (!rating)
        return <span className="text-sm text-muted-foreground">No rating</span>;
    return (
        <div className="flex items-center gap-0.5">
            {Array.from({ length: 5 }, (_, i) => (
                <Star
                    key={i}
                    className={`h-4 w-4 ${i < rating ? 'fill-amberx text-status-warning' : 'text-muted-foreground'}`}
                />
            ))}
        </div>
    );
}

function ReviewCard({ review }: { review: Review }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        employee_comments: review.employee_comments ?? '',
        employee_signed_off: false,
    });

    const canComment =
        review.status === 'in_progress' || review.status === 'completed';
    const canSignOff =
        review.status === 'completed' && !review.employee_signed_off;
    const sc = statusConfig[review.status] || statusConfig.draft;

    const handleSave = () => {
        form.put(`/hr/my/reviews/${review.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const handleSignOff = () => {
        if (
            !confirm(
                'Are you sure you want to sign off on this review? This action cannot be undone.',
            )
        )
            return;
        form.transform((data) => ({ ...data, employee_signed_off: true }));
        form.put(`/hr/my/reviews/${review.id}`, { preserveScroll: true });
    };

    return (
        <Card>
            <Collapsible>
                <CollapsibleTrigger className="w-full text-left">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <CardTitle className="text-base capitalize">
                                    {review.review_type?.replace('_', ' ') ||
                                        'Performance Review'}
                                </CardTitle>
                                <Badge
                                    variant="outline"
                                    className={sc.className}
                                >
                                    {sc.label}
                                </Badge>
                                {review.employee_signed_off && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-success/30 bg-status-success-bg text-xs text-status-success"
                                    >
                                        You signed off
                                    </Badge>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <RatingStars rating={review.overall_rating} />
                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </div>
                        <div className="mt-1 flex gap-4 text-sm text-muted-foreground">
                            {review.review_period_start &&
                                review.review_period_end && (
                                    <span>
                                        Period: {review.review_period_start} to{' '}
                                        {review.review_period_end}
                                    </span>
                                )}
                            {review.reviewer && (
                                <span>Reviewer: {review.reviewer.name}</span>
                            )}
                        </div>
                    </CardHeader>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent className="space-y-4 pt-0">
                        {review.strengths && (
                            <div>
                                <Label className="text-sm font-medium">
                                    Strengths
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {review.strengths}
                                </p>
                            </div>
                        )}
                        {review.development_areas && (
                            <div>
                                <Label className="text-sm font-medium">
                                    Development Areas
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {review.development_areas}
                                </p>
                            </div>
                        )}
                        {review.goals && (
                            <div>
                                <Label className="text-sm font-medium">
                                    Goals
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {review.goals}
                                </p>
                            </div>
                        )}
                        {review.training_recommendations && (
                            <div>
                                <Label className="text-sm font-medium">
                                    Training Recommendations
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {review.training_recommendations}
                                </p>
                            </div>
                        )}

                        <div className="border-t pt-4">
                            <Label className="text-sm font-medium">
                                Your Comments
                            </Label>
                            {editing ? (
                                <div className="mt-2 space-y-2">
                                    <Textarea
                                        value={form.data.employee_comments}
                                        onChange={(e) =>
                                            form.setData(
                                                'employee_comments',
                                                e.target.value,
                                            )
                                        }
                                        className="min-h-[80px]"
                                        placeholder="Add your comments about this review..."
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            onClick={handleSave}
                                            disabled={form.processing}
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <div className="mt-1">
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {review.employee_comments ||
                                            'No comments yet.'}
                                    </p>
                                    {canComment && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="mt-2"
                                            onClick={() => setEditing(true)}
                                        >
                                            {review.employee_comments
                                                ? 'Edit Comments'
                                                : 'Add Comments'}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>

                        {canSignOff && (
                            <div className="border-t pt-4">
                                <Button
                                    onClick={handleSignOff}
                                    disabled={form.processing}
                                >
                                    Sign Off on Review
                                </Button>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    By signing off, you acknowledge that you
                                    have read and discussed this review.
                                </p>
                            </div>
                        )}

                        {review.employee_signed_off_at && (
                            <p className="text-xs text-muted-foreground">
                                Signed off on{' '}
                                {new Date(
                                    review.employee_signed_off_at,
                                ).toLocaleDateString()}
                            </p>
                        )}

                        {review.next_review_date && (
                            <p className="text-xs text-muted-foreground">
                                Next review: {review.next_review_date}
                            </p>
                        )}
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

export default function MyReviews({ reviews }: Props) {
    const signedOff = reviews.data.filter((r) => r.employee_signed_off).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Reviews" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={TrendingUp}
                        title="My Performance Reviews"
                        description="View and respond to your performance review cycles."
                        stats={[
                            { label: 'Total', value: reviews.data.length },
                            { label: 'Signed Off', value: signedOff },
                        ]}
                    />
                }
            >
                <MyHrTabs active="reviews" />

                {reviews.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No performance reviews found.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {reviews.data.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </div>
                )}

                {reviews.last_page > 1 && (
                    <LaravelPagination links={reviews.links} />
                )}
            </PageLayout>
        </AppLayout>
    );
}
