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
    scores: Array<{
        id: number;
        criteria_scores: Array<{ label: string; score: number; weight?: number | null }>;
        overall_score: number | null;
        recommendation: string | null;
        notes: string | null;
        submitted_at: string | null;
        interviewer_name: string | null;
    }>;
}

interface ReferenceCheck {
    id: number;
    referee_name: string;
    referee_relationship: string;
    referee_phone: string | null;
    referee_email: string | null;
    status: 'pending' | 'requested' | 'contacted' | 'completed';
    outcome: string | null;
    checked_at: string | null;
    notes: string | null;
}

interface Offer {
    id: number;
    position_title: string;
    position_role: string | null;
    employment_type: string;
    proposed_start_date: string;
    hours_per_week: number | null;
    hourly_rate: number | null;
    annual_salary: number | null;
    approval_status: string;
    approved_at: string | null;
    approved_by: string | null;
    sent_at: string | null;
    portal_expires_at: string | null;
    response: 'accepted' | 'declined' | 'withdrawn' | null;
    response_at: string | null;
    response_notes: string | null;
    signed_full_name: string | null;
    signed_at: string | null;
    portal_url: string | null;
    primary_site: { id: number; name: string } | null;
}

interface Application {
    id: number;
    position_title: string;
    position_role: string | null;
    stage: string;
    status: 'active' | 'offered' | 'hired' | 'rejected' | 'withdrawn';
    interview_kit: { id: number; name: string; role: string | null; criteria: Array<{ label: string; weight?: number }> } | null;
    applied_at: string;
    target_site: { id: number; name: string } | null;
    interviews: Interview[];
    reference_checks: ReferenceCheck[];
    offer: Offer | null;
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

const stageOrder = [
    'new',
    'screening',
    'interview_scheduled',
    'interview_completed',
    'reference_check',
    'offer_pending',
    'offer_sent',
    'offer_accepted',
    'onboarding',
    'hired',
];

const stageLabels: Record<string, string> = {
    new: 'New',
    screening: 'Screening',
    interview_scheduled: 'Interview Scheduled',
    interview_completed: 'Interview Completed',
    reference_check: 'Reference Check',
    offer_pending: 'Offer Pending',
    offer_sent: 'Offer Sent',
    offer_accepted: 'Offer Accepted',
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

const offerApprovalVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    draft: 'secondary',
    pending: 'outline',
    approved: 'default',
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

    function updateInterviewStatus(interviewId: number, status: 'completed' | 'cancelled' | 'no_show') {
        router.put(
            `/hr/recruitment/interviews/${interviewId}`,
            { status },
            { preserveScroll: true },
        );
    }

    function scoreInterview(interviewId: number) {
        const overallScoreInput = prompt('Overall score (0-100):', '80');
        if (overallScoreInput === null) {
            return;
        }

        const recommendationInput = prompt(
            'Recommendation (strong_yes, yes, maybe, no, strong_no):',
            'yes',
        );
        if (recommendationInput === null) {
            return;
        }

        const notesInput = prompt('Optional scorecard notes:', '') ?? '';

        router.post(
            `/hr/recruitment/interviews/${interviewId}/score`,
            {
                overall_score: overallScoreInput === '' ? null : Number(overallScoreInput),
                recommendation: recommendationInput || null,
                notes: notesInput || null,
            },
            { preserveScroll: true },
        );
    }

    function updateReferenceStatus(referenceId: number, status: 'contacted' | 'completed') {
        router.put(
            `/hr/recruitment/references/${referenceId}`,
            { status },
            { preserveScroll: true },
        );
    }

    function approveOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/approve`, {}, { preserveScroll: true });
    }

    function sendOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/send`, {}, { preserveScroll: true });
    }

    function recordOfferResponse(offerId: number, response: 'accepted' | 'declined' | 'withdrawn') {
        const notes = response === 'accepted'
            ? ''
            : (prompt('Optional notes for this response:') ?? '').trim();

        router.post(
            `/hr/recruitment/offers/${offerId}/respond`,
            { response, response_notes: notes || null },
            { preserveScroll: true },
        );
    }

    function convertOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/convert`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName} />
            <div className="flex flex-col gap-6 p-6">
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
                        {can.manage && <Badge variant="outline">Managed in candidate profile</Badge>}
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
                                        <div className="flex items-start justify-between gap-3">
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
                                                    {app.target_site && <span>Site: {app.target_site.name}</span>}
                                                    {app.interview_kit && <span>Kit: {app.interview_kit.name}</span>}
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
                                                        {app.stage === 'offer_pending' && !app.offer && (
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

                                        {app.offer && (
                                            <div className="rounded-lg border p-3">
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold">Offer</h4>
                                                        <p className="text-xs text-muted-foreground">
                                                            {app.offer.position_title}
                                                            {app.offer.primary_site && ` at ${app.offer.primary_site.name}`}
                                                            {app.offer.proposed_start_date && ` · starts ${app.offer.proposed_start_date}`}
                                                        </p>
                                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                                            <Badge variant={offerApprovalVariant[app.offer.approval_status] || 'outline'} className="capitalize">
                                                                {app.offer.approval_status}
                                                            </Badge>
                                                            {app.offer.response && (
                                                                <Badge variant={app.offer.response === 'accepted' ? 'default' : 'secondary'} className="capitalize">
                                                                    {app.offer.response}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {app.offer.sent_at ? `Sent: ${app.offer.sent_at}` : 'Not sent yet'}
                                                            {app.offer.response_at && ` · Responded: ${app.offer.response_at}`}
                                                        </p>
                                                        {app.offer.portal_url && (
                                                            <p className="mt-1 text-xs">
                                                                Candidate link:{' '}
                                                                <a href={app.offer.portal_url} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                                                    Open Portal
                                                                </a>
                                                            </p>
                                                        )}
                                                    </div>

                                                    {can.manage && (
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            {app.offer.approval_status !== 'approved' && (
                                                                <Button size="sm" variant="outline" onClick={() => approveOffer(app.offer!.id)}>
                                                                    Approve
                                                                </Button>
                                                            )}
                                                            {app.offer.approval_status === 'approved' && !app.offer.sent_at && (
                                                                <Button size="sm" onClick={() => sendOffer(app.offer!.id)}>
                                                                    Send Offer
                                                                </Button>
                                                            )}
                                                            {app.offer.sent_at && !app.offer.response && (
                                                                <>
                                                                    <Button size="sm" onClick={() => recordOfferResponse(app.offer!.id, 'accepted')}>
                                                                        Mark Accepted
                                                                    </Button>
                                                                    <Button size="sm" variant="secondary" onClick={() => recordOfferResponse(app.offer!.id, 'declined')}>
                                                                        Mark Declined
                                                                    </Button>
                                                                    <Button size="sm" variant="outline" onClick={() => recordOfferResponse(app.offer!.id, 'withdrawn')}>
                                                                        Mark Withdrawn
                                                                    </Button>
                                                                </>
                                                            )}
                                                            {app.offer.response === 'accepted' && (
                                                                <Button size="sm" variant="outline" onClick={() => convertOffer(app.offer!.id)}>
                                                                    Convert to Employee
                                                                </Button>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>

                                                {app.offer.response_notes && (
                                                    <p className="mt-2 text-xs text-muted-foreground">Notes: {app.offer.response_notes}</p>
                                                )}
                                                {app.offer.signed_full_name && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        E-signature: {app.offer.signed_full_name}
                                                        {app.offer.signed_at && ` · ${app.offer.signed_at}`}
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                        {app.interviews.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                    <UserCheck className="h-4 w-4" />
                                                    Interviews ({app.interviews.length})
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.interviews.map((interview) => (
                                                        <div key={interview.id} className="flex items-start justify-between gap-3 rounded-lg border p-3">
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="font-medium text-sm capitalize">{interview.type.replace('_', ' ')}</span>
                                                                    <Badge variant={interviewStatusVariants[interview.status] || 'outline'} className="capitalize text-xs">
                                                                        {interview.status.replace('_', ' ')}
                                                                    </Badge>
                                                                </div>
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    <span>{interview.scheduled_at}</span>
                                                                    {interview.interviewer_name && <span> with {interview.interviewer_name}</span>}
                                                                </div>
                                                                {interview.outcome && <p className="mt-1 text-xs">Outcome: {interview.outcome}</p>}
                                                                {interview.notes && <p className="mt-1 text-xs text-muted-foreground">{interview.notes}</p>}
                                                                {interview.scores.length > 0 && (
                                                                    <div className="mt-2 space-y-1">
                                                                        {interview.scores.map((score) => (
                                                                            <div key={score.id} className="text-xs text-muted-foreground">
                                                                                <p>
                                                                                    Score {score.overall_score ?? '-'} · {score.recommendation || 'no recommendation'}
                                                                                    {score.interviewer_name ? ` · by ${score.interviewer_name}` : ''}
                                                                                </p>
                                                                                {score.criteria_scores?.length > 0 && (
                                                                                    <p className="text-[11px]">
                                                                                        Criteria:{' '}
                                                                                        {score.criteria_scores.map((criterion) => (
                                                                                            `${criterion.label} ${criterion.score}${criterion.weight ? ` (${criterion.weight})` : ''}`
                                                                                        )).join(' | ')}
                                                                                    </p>
                                                                                )}
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </div>

                                                            {can.manage && (
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    {interview.status === 'scheduled' && (
                                                                        <>
                                                                            <Button size="sm" variant="outline" onClick={() => updateInterviewStatus(interview.id, 'completed')}>
                                                                                Complete
                                                                            </Button>
                                                                            <Button size="sm" variant="secondary" onClick={() => updateInterviewStatus(interview.id, 'no_show')}>
                                                                                No Show
                                                                            </Button>
                                                                            <Button size="sm" variant="ghost" onClick={() => updateInterviewStatus(interview.id, 'cancelled')}>
                                                                                Cancel
                                                                            </Button>
                                                                        </>
                                                                    )}
                                                                    <Button size="sm" variant="outline" onClick={() => scoreInterview(interview.id)}>
                                                                        Scorecard
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {app.reference_checks.length > 0 && (
                                            <div>
                                                <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                    <FileText className="h-4 w-4" />
                                                    Reference Checks ({app.reference_checks.length})
                                                </h4>
                                                <div className="space-y-2">
                                                    {app.reference_checks.map((ref) => (
                                                        <div key={ref.id} className="flex items-start justify-between gap-3 rounded-lg border p-3">
                                                            <div>
                                                                <div className="flex items-center gap-2">
                                                                    <span className="font-medium text-sm">{ref.referee_name}</span>
                                                                    <Badge variant={ref.status === 'completed' ? 'default' : 'outline'} className="capitalize text-xs">
                                                                        {ref.status}
                                                                    </Badge>
                                                                </div>
                                                                <div className="mt-1 text-xs text-muted-foreground">
                                                                    {ref.referee_relationship}
                                                                    {ref.referee_phone && <span> · {ref.referee_phone}</span>}
                                                                    {ref.referee_email && <span> · {ref.referee_email}</span>}
                                                                </div>
                                                                {ref.outcome && <p className="mt-1 text-xs">Outcome: {ref.outcome}</p>}
                                                                {ref.checked_at && <p className="mt-1 text-xs text-muted-foreground">Checked: {ref.checked_at}</p>}
                                                                {ref.notes && <p className="mt-1 text-xs text-muted-foreground">{ref.notes}</p>}
                                                            </div>

                                                            {can.manage && ref.status !== 'completed' && (
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <Button size="sm" variant="outline" onClick={() => updateReferenceStatus(ref.id, 'contacted')}>
                                                                        Mark Contacted
                                                                    </Button>
                                                                    <Button size="sm" onClick={() => updateReferenceStatus(ref.id, 'completed')}>
                                                                        Mark Completed
                                                                    </Button>
                                                                </div>
                                                            )}
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

