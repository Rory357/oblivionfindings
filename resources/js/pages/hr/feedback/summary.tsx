import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import { Star, MessageSquare } from 'lucide-react';

type User = { id: number; name: string };

type QuestionSummary = {
    question: string;
    average_rating: number | null;
    rating_count: number;
    min_rating: number | null;
    max_rating: number | null;
    comments: string[];
};

type Summary = {
    total_reviews: number;
    questions: Record<string, QuestionSummary>;
};

type Props = {
    subjectUser: User;
    summary: Summary;
    questions: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Summary', href: '#' },
];

function RatingBar({ value, max = 5 }: { value: number | null; max?: number }) {
    if (value === null) return <span className="text-sm text-muted-foreground">No ratings</span>;
    const percentage = (value / max) * 100;
    return (
        <div className="flex items-center gap-3">
            <div className="h-3 flex-1 rounded-full bg-muted">
                <div
                    className="h-3 rounded-full bg-amber-400 transition-all"
                    style={{ width: `${percentage}%` }}
                />
            </div>
            <span className="w-12 text-right text-sm font-semibold">{value.toFixed(1)}</span>
            <span className="text-sm text-muted-foreground">/ {max}</span>
        </div>
    );
}

export default function FeedbackSummary({ subjectUser, summary, questions }: Props) {
    const questionKeys = Object.keys(questions);

    // Calculate overall average
    const allRatings = questionKeys
        .map((key) => summary.questions[key]?.average_rating)
        .filter((r): r is number => r !== null && r !== undefined);
    const overallAvg = allRatings.length > 0
        ? allRatings.reduce((sum, r) => sum + r, 0) / allRatings.length
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Feedback Summary - ${subjectUser.name}`} />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Feedback Summary</h1>
                    <p className="text-sm text-muted-foreground">
                        360-degree feedback results for <strong>{subjectUser.name}</strong>
                    </p>
                </div>

                {summary.total_reviews === 0 ? (
                    <Card>
                        <CardContent className="py-12 text-center text-muted-foreground">
                            No completed feedback reviews for this employee yet.
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* Overview */}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <div className="text-3xl font-bold">
                                        {summary.total_reviews}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Total Reviews</div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <div className="flex items-center justify-center gap-1 text-3xl font-bold">
                                        {overallAvg !== null ? overallAvg.toFixed(1) : 'N/A'}
                                        <Star className="h-6 w-6 fill-amber-400 text-amber-400" />
                                    </div>
                                    <div className="text-sm text-muted-foreground">Overall Average</div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="pt-6 text-center">
                                    <div className="text-3xl font-bold">
                                        {questionKeys.reduce(
                                            (sum, key) => sum + (summary.questions[key]?.comments?.length ?? 0),
                                            0
                                        )}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Total Comments</div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Ratings Chart (bar-style) */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Ratings by Category</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {questionKeys.map((key) => {
                                    const q = summary.questions[key];
                                    return (
                                        <div key={key} className="space-y-1">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium capitalize">{key}</span>
                                                {q && (
                                                    <Badge variant="secondary" className="text-xs">
                                                        {q.rating_count} ratings
                                                    </Badge>
                                                )}
                                            </div>
                                            <RatingBar value={q?.average_rating ?? null} />
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Comments (Anonymized) */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <MessageSquare className="h-4 w-4" />
                                    Feedback Comments (Anonymized)
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {questionKeys.map((key) => {
                                    const q = summary.questions[key];
                                    const comments = q?.comments ?? [];
                                    if (comments.length === 0) return null;
                                    return (
                                        <div key={key}>
                                            <h4 className="mb-2 text-sm font-semibold capitalize">{key}</h4>
                                            <ul className="space-y-2">
                                                {comments.map((comment, i) => (
                                                    <li
                                                        key={i}
                                                        className="rounded-md border bg-muted/30 p-3 text-sm"
                                                    >
                                                        {comment}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    );
                                })}
                                {questionKeys.every(
                                    (key) => (summary.questions[key]?.comments?.length ?? 0) === 0
                                ) && (
                                    <p className="text-sm text-muted-foreground">No comments provided.</p>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
