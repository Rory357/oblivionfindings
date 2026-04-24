import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Lock, Star, ThumbsDown, ThumbsUp } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

interface ExitInterview {
    id: number;
    interview_date: string;
    departure_reason: string;
    would_recommend: boolean | null;
    overall_satisfaction: number | null;
    what_went_well: string | null;
    what_could_improve: string | null;
    management_feedback: string | null;
    culture_feedback: string | null;
    additional_comments: string | null;
    is_confidential: boolean;
    employee_profile: {
        id: number;
        position_title: string;
        user: { id: number; name: string };
    };
    interviewer: { id: number; name: string };
    creator: { id: number; name: string } | null;
    created_at: string;
}

interface Props {
    interview: ExitInterview;
    can: { manage: boolean };
}

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

const reasonLabels: Record<string, string> = {
    career_growth: 'Career Growth',
    compensation: 'Compensation',
    work_life_balance: 'Work-Life Balance',
    management: 'Management Issues',
    culture: 'Company Culture',
    relocation: 'Relocation',
    retirement: 'Retirement',
    personal: 'Personal Reasons',
    redundancy: 'Redundancy',
    contract_end: 'Contract End',
    other: 'Other',
};

function SatisfactionStars({ rating }: { rating: number | null }) {
    if (rating === null)
        return <span className="text-sm text-muted-foreground">Not rated</span>;
    return (
        <div className="flex items-center gap-0.5">
            {[1, 2, 3, 4, 5].map((star) => (
                <Star
                    key={star}
                    className={`h-5 w-5 ${star <= rating ? 'fill-yellow-400 text-status-warning' : 'text-muted-foreground'}`}
                />
            ))}
            <span className="ml-2 text-sm text-muted-foreground">
                {rating}/5
            </span>
        </div>
    );
}

function FeedbackSection({
    title,
    content,
}: {
    title: string;
    content: string | null;
}) {
    if (!content) return null;
    return (
        <div>
            <h4 className="mb-1 text-sm font-medium text-foreground">
                {title}
            </h4>
            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                {content}
            </p>
        </div>
    );
}

export default function ExitInterviewShow({ interview, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Exit Interviews', href: '/hr/exit-interviews' },
        {
            title: interview.employee_profile?.user?.name ?? 'Interview',
            href: `/hr/exit-interviews/${interview.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`Exit Interview - ${interview.employee_profile?.user?.name}`}
            />

            <PageShell>
                <PageHeader
                    title={`Exit Interview: ${interview.employee_profile?.user?.name ?? 'Unknown'}`}
                    description={`Conducted on ${formatDate(interview.interview_date)}`}
                />

                <div className="space-y-4">
                    {/* Overview */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Overview
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Employee
                                    </p>
                                    <p className="font-medium">
                                        {interview.employee_profile?.user
                                            ?.name ?? '-'}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {interview.employee_profile
                                            ?.position_title ?? '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Interviewer
                                    </p>
                                    <p className="font-medium">
                                        {interview.interviewer?.name ?? '-'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Date
                                    </p>
                                    <p className="font-medium">
                                        {formatDate(interview.interview_date)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Departure Reason
                                    </p>
                                    <Badge variant="outline" className="mt-1">
                                        {reasonLabels[
                                            interview.departure_reason
                                        ] ?? interview.departure_reason}
                                    </Badge>
                                </div>
                                <div>
                                    <p className="mb-1 text-xs text-muted-foreground">
                                        Overall Satisfaction
                                    </p>
                                    <SatisfactionStars
                                        rating={interview.overall_satisfaction}
                                    />
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Would Recommend
                                    </p>
                                    {interview.would_recommend === null ? (
                                        <span className="text-sm text-muted-foreground">
                                            Not specified
                                        </span>
                                    ) : interview.would_recommend ? (
                                        <Badge className="mt-1 border-status-success/30 bg-status-success-bg text-status-success">
                                            <ThumbsUp className="mr-1 h-3 w-3" />{' '}
                                            Yes
                                        </Badge>
                                    ) : (
                                        <Badge className="mt-1 border-status-critical/30 bg-status-critical-bg text-status-critical">
                                            <ThumbsDown className="mr-1 h-3 w-3" />{' '}
                                            No
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            {interview.is_confidential && (
                                <div className="mt-4 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Lock className="h-3 w-3" />
                                    This interview is marked as confidential.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Feedback Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Feedback
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <FeedbackSection
                                title="What Went Well"
                                content={interview.what_went_well}
                            />
                            <FeedbackSection
                                title="What Could Improve"
                                content={interview.what_could_improve}
                            />
                            <FeedbackSection
                                title="Management Feedback"
                                content={interview.management_feedback}
                            />
                            <FeedbackSection
                                title="Culture Feedback"
                                content={interview.culture_feedback}
                            />
                            <FeedbackSection
                                title="Additional Comments"
                                content={interview.additional_comments}
                            />

                            {!interview.what_went_well &&
                                !interview.what_could_improve &&
                                !interview.management_feedback &&
                                !interview.culture_feedback &&
                                !interview.additional_comments && (
                                    <p className="text-sm text-muted-foreground">
                                        No detailed feedback was recorded.
                                    </p>
                                )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
