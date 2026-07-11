import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FilePlus2, Lock, Star, ThumbsDown, ThumbsUp } from 'lucide-react';
import { useState } from 'react';

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
        : d.toLocaleDateString('en-NZ', {
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
                    className={`h-5 w-5 ${star <= rating ? 'fill-status-warning text-status-warning' : 'text-muted-foreground'}`}
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
    const [addendumOpen, setAddendumOpen] = useState(false);
    const addendumForm = useForm({ note: '' });
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Exit Interviews', href: '/hr/exit-interviews' },
        {
            title: interview.employee_profile?.user?.name ?? 'Interview',
            href: `/hr/exit-interviews/${interview.id}`,
        },
    ];

    const submitAddendum = () => {
        addendumForm.post(`/hr/exit-interviews/${interview.id}/addenda`, {
            preserveScroll: true,
            onSuccess: () => {
                addendumForm.reset();
                setAddendumOpen(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head
                title={`Exit Interview - ${interview.employee_profile?.user?.name}`}
            />

            <PageShell>
                <PageHero
                    category="hr"
                    variant="compact"
                    title={`Exit Interview: ${interview.employee_profile?.user?.name ?? 'Unknown'}`}
                    description={`Conducted on ${formatDate(interview.interview_date)}`}
                />

                <div className="space-y-4">
                    <div className="flex flex-col gap-3 rounded-xl border border-border bg-muted/35 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <Lock className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                            <div>
                                <p className="text-sm font-medium">
                                    Submitted interview — answers locked
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Preserve the original record. Add a dated
                                    addendum when clarification is needed.
                                </p>
                            </div>
                        </div>
                        {can.manage && (
                            <Button
                                variant="outline"
                                onClick={() => setAddendumOpen(true)}
                            >
                                <FilePlus2 className="mr-2 h-4 w-4" />
                                Add addendum
                            </Button>
                        )}
                    </div>

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

            <Dialog open={addendumOpen} onOpenChange={setAddendumOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add interview addendum</DialogTitle>
                        <DialogDescription>
                            This note will be dated and appended. It will not
                            replace any submitted answer.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="exit-interview-addendum">
                            Clarification or correction
                        </Label>
                        <Textarea
                            id="exit-interview-addendum"
                            value={addendumForm.data.note}
                            onChange={(event) =>
                                addendumForm.setData('note', event.target.value)
                            }
                            rows={5}
                            maxLength={5000}
                            placeholder="Record what needs to be clarified…"
                        />
                        {addendumForm.errors.note && (
                            <p className="text-sm text-destructive">
                                {addendumForm.errors.note}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setAddendumOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitAddendum}
                            disabled={
                                addendumForm.processing ||
                                addendumForm.data.note.trim() === ''
                            }
                        >
                            {addendumForm.processing
                                ? 'Appending…'
                                : 'Append addendum'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
