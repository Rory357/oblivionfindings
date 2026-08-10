import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import {
    BarChart3,
    MessageSquare,
    Star,
    TrendingUp,
    Users,
} from 'lucide-react';

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

const QUESTION_COLORS: Record<
    string,
    { bar: string; bg: string; text: string }
> = {
    communication: {
        bar: 'bg-status-info',
        bg: 'bg-status-info-bg',
        text: 'text-status-info',
    },
    teamwork: {
        bar: 'bg-status-success',
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
    },
    leadership: {
        bar: 'bg-primary',
        bg: 'bg-primary/10',
        text: 'text-primary',
    },
    technical: {
        bar: 'bg-status-warning',
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
    },
    initiative: {
        bar: 'bg-status-critical',
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
    },
    overall: { bar: 'bg-primary', bg: 'bg-primary/10', text: 'text-primary' },
};

const AVATAR_COLORS = [
    'bg-status-info',
    'bg-primary',
    'bg-status-success',
    'bg-status-warning',
    'bg-status-critical',
    'bg-status-info',
];
function avatarColor(id: number) {
    return AVATAR_COLORS[id % AVATAR_COLORS.length];
}
function getInitials(name: string) {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function ratingColor(v: number | null): string {
    if (v === null) return 'text-muted-foreground';
    if (v >= 4) return 'text-status-success';
    if (v >= 3) return 'text-status-warning';
    return 'text-status-critical';
}
function ratingBarColor(v: number | null): string {
    if (v === null) return 'bg-muted';
    if (v >= 4) return 'bg-status-success';
    if (v >= 3) return 'bg-status-warning';
    return 'bg-status-critical';
}

export default function FeedbackSummary({
    subjectUser,
    summary,
    questions,
}: Props) {
    const questionKeys = Object.keys(questions);

    const allRatings = questionKeys
        .map((k) => summary.questions[k]?.average_rating)
        .filter((r): r is number => r !== null);
    const overallAvg =
        allRatings.length > 0
            ? allRatings.reduce((s, r) => s + r, 0) / allRatings.length
            : null;
    const totalComments = questionKeys.reduce(
        (s, k) => s + (summary.questions[k]?.comments?.length ?? 0),
        0,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Feedback Summary - ${subjectUser.name}`} />
            <div className="space-y-6 p-4 lg:p-6">
                {/* Hero Banner */}
                <PageHero
                    category="hr"
                    icon={BarChart3}
                    backHref="/hr/feedback"
                    backLabel="Back to Feedback"
                    title="Feedback Summary"
                    description={
                        <>
                            360-degree feedback for{' '}
                            <strong className="text-primary-foreground">
                                {subjectUser.name}
                            </strong>
                        </>
                    }
                    stats={
                        overallAvg !== null
                            ? [
                                  {
                                      label: 'Overall',
                                      value: overallAvg.toFixed(1),
                                  },
                                  {
                                      label: 'Reviews',
                                      value: summary.total_reviews,
                                  },
                              ]
                            : undefined
                    }
                />

                {summary.total_reviews === 0 ? (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                                <MessageSquare className="h-8 w-8 text-primary" />
                            </div>
                            <p className="font-medium">No Feedback Yet</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                No completed feedback reviews for this employee.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {/* KPI Cards */}
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            {[
                                {
                                    label: 'Total Reviews',
                                    value: summary.total_reviews,
                                    icon: Users,
                                    gradient: 'from-primary/10 to-primary/5',
                                    iconBg: 'bg-primary/10',
                                    iconColor: 'text-primary',
                                },
                                {
                                    label: 'Overall Average',
                                    value:
                                        overallAvg !== null
                                            ? overallAvg.toFixed(1)
                                            : 'N/A',
                                    icon: Star,
                                    gradient:
                                        'from-status-warning/10 to-status-warning/5',
                                    iconBg: 'bg-status-warning-bg',
                                    iconColor: 'text-status-warning',
                                },
                                {
                                    label: 'Highest Area',
                                    value:
                                        allRatings.length > 0
                                            ? Math.max(...allRatings).toFixed(1)
                                            : 'N/A',
                                    icon: TrendingUp,
                                    gradient:
                                        'from-status-success/10 to-status-success/5',
                                    iconBg: 'bg-status-success-bg',
                                    iconColor: 'text-status-success',
                                },
                                {
                                    label: 'Total Comments',
                                    value: totalComments,
                                    icon: MessageSquare,
                                    gradient:
                                        'from-status-info/10 to-primary/5',
                                    iconBg: 'bg-status-info-bg',
                                    iconColor: 'text-status-info',
                                },
                            ].map((kpi) => {
                                const Icon = kpi.icon;
                                return (
                                    <Card
                                        key={kpi.label}
                                        className={`group overflow-hidden bg-gradient-to-br ${kpi.gradient} transition-all hover:shadow-md`}
                                    >
                                        <CardContent className="pt-5">
                                            <div className="flex items-start justify-between">
                                                <div>
                                                    <p className="text-[11px] font-medium tracking-wider text-muted-foreground uppercase">
                                                        {kpi.label}
                                                    </p>
                                                    <p className="mt-1 text-3xl font-bold tracking-tight">
                                                        {kpi.value}
                                                    </p>
                                                </div>
                                                <div
                                                    className={`flex h-10 w-10 items-center justify-center rounded-xl ${kpi.iconBg} transition-transform group-hover:scale-110`}
                                                >
                                                    <Icon
                                                        className={`h-5 w-5 ${kpi.iconColor}`}
                                                    />
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* Ratings by Category */}
                        <Card className="overflow-hidden">
                            <CardHeader className="border-b bg-gradient-to-r from-primary/10 to-transparent pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                                        <BarChart3 className="h-4 w-4 text-primary" />
                                    </div>
                                    Ratings by Category
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-5 pt-5">
                                {questionKeys.map((key) => {
                                    const q = summary.questions[key];
                                    const colors = QUESTION_COLORS[key] || {
                                        bar: 'bg-muted-foreground/80',
                                        bg: 'bg-muted',
                                        text: 'text-foreground',
                                    };
                                    const avg = q?.average_rating;
                                    const pct =
                                        avg !== null && avg !== undefined
                                            ? (avg / 5) * 100
                                            : 0;
                                    return (
                                        <div key={key}>
                                            <div className="mb-1.5 flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        className={`border-0 text-[10px] capitalize ${colors.bg} ${colors.text}`}
                                                    >
                                                        {key.replace(/_/g, ' ')}
                                                    </Badge>
                                                    {q && (
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {q.rating_count}{' '}
                                                            rating
                                                            {q.rating_count !==
                                                            1
                                                                ? 's'
                                                                : ''}
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {q?.min_rating !== null &&
                                                        q?.max_rating !==
                                                            null &&
                                                        q.min_rating !==
                                                            q.max_rating && (
                                                            <span className="text-[10px] text-muted-foreground">
                                                                {q.min_rating}
                                                                {' \u2013 '}
                                                                {q.max_rating}
                                                            </span>
                                                        )}
                                                    <span
                                                        className={`text-sm font-bold ${ratingColor(avg ?? null)}`}
                                                    >
                                                        {avg !== null &&
                                                        avg !== undefined
                                                            ? avg.toFixed(1)
                                                            : '\u2014'}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        / 5
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="h-3 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={`h-full rounded-full ${ratingBarColor(avg ?? null)} transition-all duration-700`}
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </CardContent>
                        </Card>

                        {/* Comments */}
                        <Card className="overflow-hidden">
                            <CardHeader className="border-b bg-gradient-to-r from-status-info-bg to-transparent pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-info-bg">
                                        <MessageSquare className="h-4 w-4 text-status-info" />
                                    </div>
                                    Feedback Comments
                                    <span className="text-xs font-normal text-muted-foreground">
                                        (Anonymised)
                                    </span>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-6 pt-5">
                                {questionKeys.map((key) => {
                                    const q = summary.questions[key];
                                    const comments = q?.comments ?? [];
                                    if (comments.length === 0) return null;
                                    const colors = QUESTION_COLORS[key] || {
                                        bar: 'bg-muted-foreground/80',
                                        bg: 'bg-muted',
                                        text: 'text-foreground',
                                    };
                                    return (
                                        <div key={key}>
                                            <div className="mb-2 flex items-center gap-2">
                                                <Badge
                                                    className={`border-0 text-[10px] capitalize ${colors.bg} ${colors.text}`}
                                                >
                                                    {key.replace(/_/g, ' ')}
                                                </Badge>
                                                <Badge
                                                    variant="secondary"
                                                    className="text-[9px]"
                                                >
                                                    {comments.length}
                                                </Badge>
                                            </div>
                                            <div className="space-y-2 pl-2">
                                                {comments.map((comment, i) => (
                                                    <div
                                                        key={i}
                                                        className="relative rounded-xl border bg-gradient-to-br from-muted/30 to-muted/10 p-3 pl-4 text-sm"
                                                    >
                                                        <div className="absolute top-3 bottom-3 left-0 w-1 rounded-full bg-primary/20" />
                                                        {comment}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                                {questionKeys.every(
                                    (k) =>
                                        (summary.questions[k]?.comments
                                            ?.length ?? 0) === 0,
                                ) && (
                                    <div className="flex flex-col items-center gap-2 py-6">
                                        <MessageSquare className="h-8 w-8 text-foreground" />
                                        <p className="text-sm text-muted-foreground">
                                            No comments provided.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
