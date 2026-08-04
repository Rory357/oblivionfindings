import { MyHrShell, type MyHrShellData } from '@/components/hr';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { useForm } from '@inertiajs/react';
import { ChevronDown, Star } from 'lucide-react';
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
    myHr: MyHrShellData;
    reviews: {
        data: Review[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
}

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
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
                    className={`h-4 w-4 ${i < rating ? 'fill-status-warning text-status-warning' : 'text-muted-foreground'}`}
                />
            ))}
        </div>
    );
}

function ReviewCard({ review }: { review: Review }) {
    const [editing, setEditing] = useState(false);
    const [showSignOff, setShowSignOff] = useState(false);
    const form = useForm({
        employee_comments: review.employee_comments ?? '',
        employee_signed_off: false,
    });

    const canComment =
        !review.employee_signed_off &&
        (review.status === 'in_progress' ||
            review.status === 'completed' ||
            review.status === 'signed_off');
    const canSignOff =
        review.status === 'signed_off' &&
        review.manager_signed_off &&
        !review.employee_signed_off;
    const sc = statusConfig[review.status] || statusConfig.draft;

    const handleSave = () => {
        form.put(`/hr/my/reviews/${review.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const handleSignOff = () => {
        setShowSignOff(false);
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
                                    onClick={() => setShowSignOff(true)}
                                    disabled={form.processing}
                                >
                                    Acknowledge review
                                </Button>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Confirm that you have read and discussed
                                    this manager-approved review.
                                </p>
                                <AlertDialog
                                    open={showSignOff}
                                    onOpenChange={setShowSignOff}
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>
                                                Acknowledge this review?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Acknowledging confirms you have
                                                read and discussed this review
                                                with your reviewer. This cannot
                                                be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>
                                                Not yet
                                            </AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={handleSignOff}
                                            >
                                                Acknowledge
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        )}

                        {review.employee_signed_off_at && (
                            <p className="text-xs text-muted-foreground">
                                Acknowledged on{' '}
                                {new Date(
                                    review.employee_signed_off_at,
                                ).toLocaleDateString('en-NZ')}
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

export default function MyReviews({ myHr, reviews }: Props) {
    return (
        <MyHrShell active="reviews" myHr={myHr} title="Reviews · My HR">
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
        </MyHrShell>
    );
}
