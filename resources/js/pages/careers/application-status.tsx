import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { CheckCircle2, Clock, FileText, Briefcase, MapPin } from 'lucide-react';
import type { ApplicationStatus } from '@/types/job-postings';

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
        new: 0, active: 0,
        screening: 1,
        interview_scheduled: 2, interview_completed: 2,
        reference_check: 3, offer_pending: 3, offer_sent: 3, offered: 3,
        offer_accepted: 4, onboarding: 4, hired: 4,
    };
    return map[status] ?? 0;
}

export default function ApplicationStatus({ application }: Props) {
    const isTerminal = terminalStatuses.includes(application.status);
    const currentStageIndex = getStageIndex(application.status);

    return (
        <>
            <Head title="Application Status" />
            <div className="min-h-screen bg-background flex items-center justify-center px-4 py-16">
                <div className="max-w-lg w-full space-y-6">
                    <div className="text-center">
                        <FileText className="mx-auto h-12 w-12 text-primary mb-4" />
                        <h1 className="text-2xl font-bold">Application Status</h1>
                        <p className="text-muted-foreground text-sm mt-1">Track the progress of your application</p>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">{application.position_title}</CardTitle>
                            {application.posting && (
                                <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                    {application.posting.department && (
                                        <span className="flex items-center gap-1"><Briefcase className="h-3 w-3" /> {application.posting.department}</span>
                                    )}
                                    {application.posting.location && (
                                        <span className="flex items-center gap-1"><MapPin className="h-3 w-3" /> {application.posting.location}</span>
                                    )}
                                </div>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Applied on</span>
                                <span className="font-medium">{application.applied_at}</span>
                            </div>

                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Current Status</span>
                                <Badge
                                    variant="outline"
                                    className={
                                        isTerminal
                                            ? 'border-red-500/30 text-red-400 bg-red-500/10'
                                            : application.status === 'hired'
                                                ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10'
                                                : 'border-primary/30 text-primary bg-primary/10'
                                    }
                                >
                                    {application.status_label}
                                </Badge>
                            </div>

                            {/* Progress Steps */}
                            {!isTerminal && (
                                <div className="space-y-3 pt-2">
                                    {stageOrder.map((stage, idx) => {
                                        const isCompleted = idx < currentStageIndex;
                                        const isCurrent = idx === currentStageIndex;
                                        return (
                                            <div key={stage.key} className="flex items-center gap-3">
                                                <div className={`flex items-center justify-center h-8 w-8 rounded-full shrink-0 ${
                                                    isCompleted ? 'bg-emerald-500/20 text-emerald-400' :
                                                    isCurrent ? 'bg-primary/20 text-primary ring-2 ring-primary/30' :
                                                    'bg-muted text-muted-foreground'
                                                }`}>
                                                    {isCompleted ? (
                                                        <CheckCircle2 className="h-4 w-4" />
                                                    ) : isCurrent ? (
                                                        <Clock className="h-4 w-4" />
                                                    ) : (
                                                        <span className="text-xs">{idx + 1}</span>
                                                    )}
                                                </div>
                                                <span className={`text-sm ${isCurrent ? 'font-medium' : isCompleted ? 'text-muted-foreground' : 'text-muted-foreground/60'}`}>
                                                    {stage.label}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {isTerminal && (
                                <p className="text-sm text-muted-foreground text-center py-4">
                                    {application.status === 'rejected'
                                        ? 'Thank you for your interest. Unfortunately, your application was not successful this time.'
                                        : 'Your application has been withdrawn.'}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <p className="text-xs text-center text-muted-foreground">
                        If you have questions about your application, please contact our recruitment team.
                    </p>
                </div>
            </div>
        </>
    );
}
