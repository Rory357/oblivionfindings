import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ApplicationStatus } from '@/types/job-postings';
import { Head } from '@inertiajs/react';
import { Briefcase, CheckCircle2, Clock, MapPin, Search } from 'lucide-react';

type Props = {
    application: ApplicationStatus;
};

const stageOrder = [
    { key: 'new', label: 'Application Received' },
    { key: 'screening', label: 'Under Review' },
    { key: 'interview_scheduled', label: 'Interview' },
    { key: 'offer_pending', label: 'Decision' },
    { key: 'hired', label: 'Outcome' },
];

const terminalStatuses = ['rejected', 'withdrawn'];

function getStageIndex(status: string): number {
    const map: Record<string, number> = {
        new: 0,
        active: 0,
        screening: 1,
        interview_scheduled: 2,
        interview_completed: 2,
        reference_check: 3,
        offer_pending: 3,
        offer_sent: 3,
        offered: 3,
        offer_accepted: 4,
        onboarding: 4,
        hired: 4,
    };
    return map[status] ?? 0;
}

export default function ApplicationStatus({ application }: Props) {
    const isTerminal = terminalStatuses.includes(application.status);
    const currentStageIndex = getStageIndex(application.status);

    return (
        <>
            <Head title="Application Status" />
            <div className="flex min-h-screen items-center justify-center bg-background px-4 py-16">
                <div className="w-full max-w-lg">
                    <PageLayout
                        padding="none"
                        hero={
                            <PageHero
                                variant="compact"
                                icon={Search}
                                title="Application Status"
                                description="Track the progress of your application."
                            />
                        }
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-lg">
                                    {application.position_title}
                                </CardTitle>
                                {application.posting && (
                                    <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        {application.posting.department && (
                                            <span className="flex items-center gap-1">
                                                <Briefcase className="h-3 w-3" />{' '}
                                                {application.posting.department}
                                            </span>
                                        )}
                                        {application.posting.location && (
                                            <span className="flex items-center gap-1">
                                                <MapPin className="h-3 w-3" />{' '}
                                                {application.posting.location}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Applied on
                                    </span>
                                    <span className="font-medium">
                                        {application.applied_at}
                                    </span>
                                </div>

                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">
                                        Current Status
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className={
                                            isTerminal
                                                ? 'border-status-critical/30 bg-status-critical text-status-critical'
                                                : application.status === 'hired'
                                                  ? 'border-status-success/30 bg-status-success text-status-success'
                                                  : 'border-primary/30 bg-primary/10 text-primary'
                                        }
                                    >
                                        {application.status_label}
                                    </Badge>
                                </div>

                                {/* Progress Steps */}
                                {!isTerminal && (
                                    <div className="space-y-3 pt-2">
                                        {stageOrder.map((stage, idx) => {
                                            const isCompleted =
                                                idx < currentStageIndex;
                                            const isCurrent =
                                                idx === currentStageIndex;
                                            return (
                                                <div
                                                    key={stage.key}
                                                    className="flex items-center gap-3"
                                                >
                                                    <div
                                                        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${
                                                            isCompleted
                                                                ? 'bg-status-success-bg text-status-success'
                                                                : isCurrent
                                                                  ? 'bg-primary/20 text-primary ring-2 ring-primary/30'
                                                                  : 'bg-muted text-muted-foreground'
                                                        }`}
                                                    >
                                                        {isCompleted ? (
                                                            <CheckCircle2 className="h-4 w-4" />
                                                        ) : isCurrent ? (
                                                            <Clock className="h-4 w-4" />
                                                        ) : (
                                                            <span className="text-xs">
                                                                {idx + 1}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <span
                                                        className={`text-sm ${isCurrent ? 'font-medium' : isCompleted ? 'text-muted-foreground' : 'text-muted-foreground/60'}`}
                                                    >
                                                        {stage.label}
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                {isTerminal && (
                                    <p className="py-4 text-center text-sm text-muted-foreground">
                                        {application.status === 'rejected'
                                            ? 'Thank you for your interest. Unfortunately, your application was not successful this time.'
                                            : 'Your application has been withdrawn.'}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <p className="text-center text-xs text-muted-foreground">
                            If you have questions about your application, please
                            contact our recruitment team.
                        </p>
                    </PageLayout>
                </div>
            </div>
        </>
    );
}
