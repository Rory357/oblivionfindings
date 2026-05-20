import { PageHero, type PageHeroMetaItem } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Calendar, CheckCircle2, MessageSquare, Send, Star } from 'lucide-react';

type User = { id: number; name: string };
type FeedbackRequestData = {
    id: number;
    subject: User | null;
    review_type: string;
    due_date: string | null;
};
type Props = {
    feedbackRequest: FeedbackRequestData;
    questions: Record<string, string>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: '360 Feedback', href: '/hr/feedback' },
    { title: 'Respond', href: '#' },
];

const RATING_LABELS = [
    '',
    'Poor',
    'Below Average',
    'Average',
    'Good',
    'Excellent',
];

const QUESTION_ICONS: Record<string, string> = {
    communication: 'from-status-info/10 to-status-info/5',
    teamwork: 'from-status-success/10 to-status-success/5',
    leadership: 'from-primary/10 to-primary/5',
    technical: 'from-status-warning/10 to-status-warning/5',
    initiative: 'from-status-critical/10 to-status-critical/5',
    overall: 'from-primary/10 to-primary/5',
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

function StarRating({
    value,
    onChange,
}: {
    value: number;
    onChange: (v: number) => void;
}) {
    return (
        <div className="flex items-center gap-3">
            <div className="flex gap-1">
                {[1, 2, 3, 4, 5].map((star) => (
                    <Button
                        key={star}
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={() => onChange(star)}
                        className="group/star h-8 w-8"
                    >
                        <Star
                            className={`size-7 transition-all ${star <= value ? 'scale-110 fill-amber-400 text-status-warning' : 'text-muted-foreground/20 group-hover/star:scale-110 hover:text-status-warning'}`}
                        />
                    </Button>
                ))}
            </div>
            {value > 0 && (
                <Badge
                    variant="outline"
                    className={`text-[10px] ${value >= 4 ? 'border-status-success/30 bg-status-success-bg text-status-success' : value >= 3 ? 'border-status-warning/30 bg-status-warning-bg text-status-warning' : 'border-status-critical/30 bg-status-critical-bg text-status-critical'}`}
                >
                    {RATING_LABELS[value]}
                </Badge>
            )}
        </div>
    );
}

export default function FeedbackRespond({ feedbackRequest, questions }: Props) {
    const questionKeys = Object.keys(questions);
    const form = useForm({
        responses: questionKeys.map((key) => ({
            question_key: key,
            rating: 0,
            comment: '',
        })),
    });

    const updateResponse = (
        index: number,
        field: 'rating' | 'comment',
        value: number | string,
    ) => {
        const updated = [...form.data.responses];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('responses', updated);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/hr/feedback/${feedbackRequest.id}/respond`);
    };
    const answeredCount = form.data.responses.filter(
        (r) => r.rating > 0,
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Submit Feedback" />
            <div className="space-y-6 p-4 lg:p-6">
                {/* Hero */}
                {(() => {
                    const heroMeta: PageHeroMetaItem[] = [];
                    if (feedbackRequest.due_date)
                        heroMeta.push({
                            icon: Calendar,
                            label: `Due ${feedbackRequest.due_date}`,
                        });

                    return (
                        <PageHero
                            icon={MessageSquare}
                            backHref="/hr/feedback"
                            backLabel="Back to Feedback"
                            title="Provide Feedback"
                            description={
                                <>
                                    for{' '}
                                    <strong className="text-primary-foreground">
                                        {feedbackRequest.subject?.name ?? 'Unknown'}
                                    </strong>
                                </>
                            }
                            meta={heroMeta}
                            stats={[
                                {
                                    label: 'Answered',
                                    value: `${answeredCount}/${questionKeys.length}`,
                                },
                            ]}
                        >
                            {/* Progress bar */}
                            <div className="h-1.5 overflow-hidden rounded-full bg-primary-foreground/20">
                                <div
                                    className="h-full rounded-full bg-primary-foreground/80 transition-all duration-500"
                                    style={{
                                        width: `${(answeredCount / questionKeys.length) * 100}%`,
                                    }}
                                />
                            </div>
                        </PageHero>
                    );
                })()}

                <form onSubmit={submit} className="mx-auto max-w-3xl space-y-4">
                    {questionKeys.map((key, index) => {
                        const gradient =
                            QUESTION_ICONS[key] || 'from-muted/10 to-muted/5';
                        return (
                            <Card
                                key={key}
                                className={`overflow-hidden bg-gradient-to-br ${gradient} transition-all hover:shadow-sm`}
                            >
                                <CardContent className="p-5">
                                    <div className="mb-4 flex items-start gap-3">
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">
                                            {index + 1}
                                        </div>
                                        <div>
                                            <h3 className="text-sm font-semibold capitalize">
                                                {key.replace(/_/g, ' ')}
                                            </h3>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {questions[key]}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="ml-10 space-y-3">
                                        <StarRating
                                            value={
                                                form.data.responses[index]
                                                    .rating
                                            }
                                            onChange={(v) =>
                                                updateResponse(
                                                    index,
                                                    'rating',
                                                    v,
                                                )
                                            }
                                        />
                                        {(
                                            form.errors as Record<
                                                string,
                                                string
                                            >
                                        )[`responses.${index}.rating`] && (
                                            <p className="text-xs text-status-critical">
                                                {
                                                    (
                                                        form.errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    )[
                                                        `responses.${index}.rating`
                                                    ]
                                                }
                                            </p>
                                        )}
                                        <Textarea
                                            value={
                                                form.data.responses[index]
                                                    .comment
                                            }
                                            onChange={(e) =>
                                                updateResponse(
                                                    index,
                                                    'comment',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Share your observations... (optional)"
                                            rows={2}
                                            className="bg-primary-foreground/80 text-sm"
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {/* Submit */}
                    <div className="flex items-center justify-between rounded-xl border bg-primary/10 p-4">
                        <div className="flex items-center gap-2 text-sm">
                            {answeredCount === questionKeys.length ? (
                                <>
                                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                                    <span className="font-medium text-status-success">
                                        All questions answered
                                    </span>
                                </>
                            ) : (
                                <span className="text-muted-foreground">
                                    {answeredCount} of {questionKeys.length}{' '}
                                    questions rated
                                </span>
                            )}
                        </div>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => history.back()}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="gap-1.5 bg-primary hover:bg-primary"
                                disabled={form.processing}
                            >
                                <Send className="h-3.5 w-3.5" />
                                Submit Feedback
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
