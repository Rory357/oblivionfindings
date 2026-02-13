import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';
import {
    User,
    Mail,
    Phone,
    Briefcase,
    Calendar,
    MessageSquare,
    CheckCircle2,
    Clock,
    ArrowRight,
    FileText,
    UserCheck,
} from 'lucide-react';

interface Interview {
    id: number;
    type: string;
    scheduled_at: string;
    interviewer_name: string | null;
    status: 'scheduled' | 'completed' | 'cancelled' | 'no_show';
    outcome: string | null;
    notes: string | null;
}

interface ReferenceCheck {
    id: number;
    referee_name: string;
    referee_relationship: string;
    referee_phone: string | null;
    referee_email: string | null;
    status: 'pending' | 'contacted' | 'completed';
    outcome: string | null;
    checked_at: string | null;
    notes: string | null;
}

interface Application {
    id: number;
    position_title: string;
    position_role: string | null;
    stage: string;
    status: 'active' | 'offered' | 'hired' | 'rejected' | 'withdrawn';
    applied_at: string;
    target_site: { id: number; name: string } | null;
    interviews: Interview[];
    reference_checks: ReferenceCheck[];
}

interface Candidate {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name: string | null;
    personal_email: string;
    personal_phone: string | null;
    source: string;
    source_detail: string | null;
    notes: string | null;
    created_at: string;
    applications: Application[];
}

interface Props {
    candidate: Candidate;
    can: { manage: boolean };
}

const stageOrder = ['applied', 'screening', 'interview', 'reference_check', 'offer', 'onboarding', 'hired'];

const stageLabels: Record<string, string> = {
    applied: 'Applied',
    screening: 'Screening',
    interview: 'Interview',
    reference_check: 'Reference Check',
    offer: 'Offer',
    onboarding: 'Onboarding',
    hired: 'Hired',
};

const statusVariants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    offered: 'outline',
    hired: 'default',
    rejected: 'destructive',
    withdrawn: 'secondary',
};

const interviewStatusVariants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    scheduled: 'outline',
    completed: 'default',
    cancelled: 'secondary',
    no_show: 'destructive',
};

export default function CandidateShow({ candidate, can }: Props) {
    const fullName = `${candidate.first_name} ${candidate.last_name}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Recruitment', href: '/hr/recruitment/candidates' },
        { title: fullName, href: `/hr/recruitment/candidates/${candidate.id}` },
    ];

    function advanceStage(applicationId: number) {
        router.post(`/hr/recruitment/applications/${applicationId}/advance`, {}, { preserveScroll: true });
    }

    function rejectApplication(applicationId: number) {
        if (confirm('Are you sure you want to reject this application?')) {
            router.post(`/hr/recruitment/applications/${applicationId}/reject`, {}, { preserveScroll: true });
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName} />
            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div className="flex items-center gap-4">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                            <User className="h-7 w-7 text-primary" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold">{fullName}</h1>
                            {candidate.preferred_name && (
                                <p className="text-sm text-muted-foreground">Preferred: {candidate.preferred_name}</p>
                            )}
                            <div className="mt-1 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1">
                                    <Mail className="h-3.5 w-3.5" />
                                    {candidate.personal_email}
                                </span>
                                {candidate.personal_phone && (
                                    <span className="flex items-center gap-1">
                                        <Phone className="h-3.5 w-3.5" />
                                        {candidate.personal_phone}
                                    </span>
                                )}
                                <Badge variant="outline" className="capitalize">{candidate.source.replace('_', ' ')}</Badge>
                                {candidate.source_detail && (
                                    <span className="text-xs">({candidate.source_detail})</span>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {can.manage && (
                            <Button asChild>
                                <Link href={`/hr/recruitment/candidates/${candidate.id}/applications/create`}>New Application</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {candidate.notes && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <MessageSquare className="h-4 w-4" />
                                Notes
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">{candidate.notes}</p>
                        </CardContent>
                    </Card>
                )}

                {/* Applications */}
                <div className="space-y-6">
                    <h2 className="text-lg font-semibold">Applications ({candidate.applications.length})</h2>

                    {candidate.applications.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-muted-foreground">
                                <Briefcase className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No applications yet.</p>
                            </CardContent>
                        </Card>
                    ) : (
                        candidate.applications.map((app) => {
                            const currentStageIndex = stageOrder.indexOf(app.stage);

                            return (
                                <Card key={app.id}>
                                    <CardHeader>
                                        <div className="flex items-start justify-between">
                                            <div>
                                                <CardTitle className="flex items-center gap-2">
                                                    <Briefcase className="h-4 w-4" />
                                                    {app.position_title}
                                                    {app.position_role && (
                                                        <span className="text-sm font-normal text-muted-foreground">({app.position_role})</span>
                                                    )}
                                                </CardTitle>
                                                <div className="mt-1 flex items-center gap-3 text-sm text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3.5 w-3.5" />
                                                        Applied: {app.applied_at}
                                                    </span>
                                                    {app.target_site && (
                                                        <span>Site: {app.target_site.name}</span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge variant={statusVariants[app.status] || 'outline'} className="capitalize">
                                                    {app.status}
                                                </Badge>
                                                {can.manage && app.status === 'active' && (
                                                    <>
                                                        <Button size="sm" onClick={() => advanceStage(app.id)}>
                                                            Advance
                                                            <ArrowRight className="ml-1 h-3.5 w-3.5" />
                                                        </Button>
                                                        {app.stage === 'offer' && (
                                                            <Button size="sm" variant="outline" asChild>
                                                                <Link href={`/hr/recruitment/applications/${app.id}/offer/create`}>
                                                                    Create Offer
                                                                </Link>
                                                            </Button>
                                                        )}
                                                        <Button size="sm" variant="destructive" onClick={() => rejectApplication(app.id)}>
                                                            Reject
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        {/* Stage Pipeline */}
                                        <div className="flex items-center gap-1 overflow-x-auto pb-2">
                                            {stageOrder.map((stage, i) => {
                                                const isActive = i === currentStageIndex;
                                                const isPast = i < currentStageIndex;
                                                return (
                                                    <div key={stage} className="flex items-center">
                                                        <div
                                                            className={`flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium transition-colors ${
                                                                isActive
                                                                    ? 'bg-primary text-primary-foreground'
                                                                    : isPast
                                                                        ? 'bg-primary/20 text-primary'
                                                                        : 'bg-muted text-muted-foreground'
                                                            }`}
                                                        >
                                                            {isPast && <CheckCircle2 className="h-3 w-3" />}
                                                            {isActive && <Clock className="h-3 w-3" />}
                                                            {stageLabels[stage] || stage}
                                                        </div>
                                                        {i < stageOrder.length - 1 && (
                                                            <ArrowRight className={`mx-1 h-3 w-3 ${isPast ? 'text-primary' : 'text-muted-foreground/50'}`} />
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {/* Interviews */}
                                        {app.interviews.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                    <UserCheck className="h-4 w-4" />
                                                    Interviews ({app.interviews.length})
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.interviews.map((interview) => (
                                                        <div key={interview.id} className="flex items-start justify-between rounded-lg border p-3">
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="font-medium text-sm capitalize">{interview.type.replace('_', ' ')}</span>
                                                                    <Badge variant={interviewStatusVariants[interview.status] || 'outline'} className="capitalize text-xs">
                                                                        {interview.status.replace('_', ' ')}
                                                                    </Badge>
                                                                </div>
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    <span>{interview.scheduled_at}</span>
                                                                    {interview.interviewer_name && (
                                                                        <span> with {interview.interviewer_name}</span>
                                                                    )}
                                                                </div>
                                                                {interview.outcome && (
                                                                    <p className="mt-1 text-xs">Outcome: {interview.outcome}</p>
                                                                )}
                                                                {interview.notes && (
                                                                    <p className="mt-1 text-xs text-muted-foreground">{interview.notes}</p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {/* Reference Checks */}
                                        {app.reference_checks.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                    <FileText className="h-4 w-4" />
                                                    Reference Checks ({app.reference_checks.length})
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.reference_checks.map((ref) => (
                                                        <div key={ref.id} className="flex items-start justify-between rounded-lg border p-3">
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="font-medium text-sm">{ref.referee_name}</span>
                                                                    <Badge variant={ref.status === 'completed' ? 'default' : 'outline'} className="capitalize text-xs">
                                                                        {ref.status}
                                                                    </Badge>
                                                                </div>
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    {ref.referee_relationship}
                                                                    {ref.referee_phone && <span> &middot; {ref.referee_phone}</span>}
                                                                    {ref.referee_email && <span> &middot; {ref.referee_email}</span>}
                                                                </div>
                                                                {ref.outcome && (
                                                                    <p className="mt-1 text-xs">Outcome: {ref.outcome}</p>
                                                                )}
                                                                {ref.checked_at && (
                                                                    <p className="mt-1 text-xs text-muted-foreground">Checked: {ref.checked_at}</p>
                                                                )}
                                                                {ref.notes && (
                                                                    <p className="mt-1 text-xs text-muted-foreground">{ref.notes}</p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
