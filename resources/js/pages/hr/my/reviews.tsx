import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { type BreadcrumbItem } from '@/types';
import { ChevronDown, Star } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

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
    draft: { className: 'border-slate-500/30 text-muted-foreground bg-slate-500/10', label: 'Draft' },
    in_progress: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'In Progress' },
    completed: { className: 'border-amber-500/30 text-amber-400 bg-amber-500/10', label: 'Completed' },
    signed_off: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Signed Off' },
};

function RatingStars({ rating }: { rating: number | null }) {
    if (!rating) return <span className="text-sm text-muted-foreground">No rating</span>;
    return (
        <div className="flex items-center gap-0.5">
            {Array.from({ length: 5 }, (_, i) => (
                <Star key={i} className={`h-4 w-4 ${i < rating ? 'fill-amber-400 text-amber-400' : 'text-slate-300'}`} />
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

    const canComment = review.status === 'in_progress' || review.status === 'completed';
    const canSignOff = review.status === 'completed' && !review.employee_signed_off;
    const sc = statusConfig[review.status] || statusConfig.draft;

    const handleSave = () => {
        form.put(`/hr/my/reviews/${review.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const handleSignOff = () => {
        if (!confirm('Are you sure you want to sign off on this review? This action cannot be undone.')) return;
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
                                    {review.review_type?.replace('_', ' ') || 'Performance Review'}
                                </CardTitle>
                                <Badge variant="outline" className={sc.className}>{sc.label}</Badge>
                                {review.employee_signed_off && (
                                    <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 bg-emerald-500/10 text-xs">
                                        You signed off
                                    </Badge>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <RatingStars rating={review.overall_rating} />
                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </div>
                        <div className="flex gap-4 text-sm text-muted-foreground mt-1">
                            {review.review_period_start && review.review_period_end && (
                                <span>Period: {review.review_period_start} to {review.review_period_end}</span>
                            )}
                            {review.reviewer && <span>Reviewer: {review.reviewer.name}</span>}
                        </div>
                    </CardHeader>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent className="space-y-4 pt-0">
                        {review.strengths && (
                            <div>
                                <Label className="text-sm font-medium">Strengths</Label>
                                <p className="text-sm text-muted-foreground mt-1 whitespace-pre-wrap">{review.strengths}</p>
                            </div>
                        )}
                        {review.development_areas && (
                            <div>
                                <Label className="text-sm font-medium">Development Areas</Label>
                                <p className="text-sm text-muted-foreground mt-1 whitespace-pre-wrap">{review.development_areas}</p>
                            </div>
                        )}
                        {review.goals && (
                            <div>
                                <Label className="text-sm font-medium">Goals</Label>
                                <p className="text-sm text-muted-foreground mt-1 whitespace-pre-wrap">{review.goals}</p>
                            </div>
                        )}
                        {review.training_recommendations && (
                            <div>
                                <Label className="text-sm font-medium">Training Recommendations</Label>
                                <p className="text-sm text-muted-foreground mt-1 whitespace-pre-wrap">{review.training_recommendations}</p>
                            </div>
                        )}

                        <div className="border-t pt-4">
                            <Label className="text-sm font-medium">Your Comments</Label>
                            {editing ? (
                                <div className="mt-2 space-y-2">
                                    <Textarea
                                        value={form.data.employee_comments}
                                        onChange={(e) => form.setData('employee_comments', e.target.value)}
                                        className="min-h-[80px]"
                                        placeholder="Add your comments about this review..."
                                    />
                                    <div className="flex gap-2">
                                        <Button size="sm" onClick={handleSave} disabled={form.processing}>Save</Button>
                                        <Button size="sm" variant="outline" onClick={() => setEditing(false)}>Cancel</Button>
                                    </div>
                                </div>
                            ) : (
                                <div className="mt-1">
                                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">
                                        {review.employee_comments || 'No comments yet.'}
                                    </p>
                                    {canComment && (
                                        <Button size="sm" variant="outline" className="mt-2" onClick={() => setEditing(true)}>
                                            {review.employee_comments ? 'Edit Comments' : 'Add Comments'}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>

                        {canSignOff && (
                            <div className="border-t pt-4">
                                <Button onClick={handleSignOff} disabled={form.processing}>
                                    Sign Off on Review
                                </Button>
                                <p className="text-xs text-muted-foreground mt-1">
                                    By signing off, you acknowledge that you have read and discussed this review.
                                </p>
                            </div>
                        )}

                        {review.employee_signed_off_at && (
                            <p className="text-xs text-muted-foreground">
                                Signed off on {new Date(review.employee_signed_off_at).toLocaleDateString()}
                            </p>
                        )}

                        {review.next_review_date && (
                            <p className="text-xs text-muted-foreground">Next review: {review.next_review_date}</p>
                        )}
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

export default function MyReviews({ reviews }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Reviews" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Performance Reviews</h1>

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
            </div>
        </AppLayout>
    );
}
