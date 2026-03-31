import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { type BreadcrumbItem } from '@/types';
import {
    User, Mail, Phone, Briefcase, Calendar, MessageSquare,
    CheckCircle2, Clock, ArrowRight, FileText, UserCheck,
    Star, Send, Gift, ExternalLink, Shield,
} from 'lucide-react';
import { StatusBadge } from '@/components/recruitment/status-badge';
import { PipelineStepper } from '@/components/recruitment/pipeline-stepper';
import { ActivityItem } from '@/components/recruitment/activity-item';

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

interface ActivityEntry {
    type: 'status_change' | 'interview' | 'offer' | 'note' | 'application';
    description: string;
    timestamp: string;
    actor?: string;
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
    activityLog?: ActivityEntry[];
    totalDaysInPipeline?: number;
    can: { manage: boolean };
}

const interviewStatusVariants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    scheduled: 'outline', completed: 'default', cancelled: 'secondary', no_show: 'destructive',
};

const recColors: Record<string, string> = {
    strong_yes: 'text-green-500', yes: 'text-emerald-500', maybe: 'text-amber-500',
    neutral: 'text-amber-500', no: 'text-orange-500', strong_no: 'text-red-500',
};

export default function CandidateShow({ candidate, activityLog, totalDaysInPipeline, can }: Props) {
    const fullName = `${candidate.first_name} ${candidate.last_name}`;
    const initials = ((candidate.first_name?.[0] ?? '') + (candidate.last_name?.[0] ?? '')).toUpperCase();
    const [noteText, setNoteText] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Recruitment', href: '/hr/recruitment' },
        { title: fullName, href: `/hr/recruitment/candidates/${candidate.id}` },
    ];

    const currentStatus = candidate.applications[0]?.stage ?? 'new';

    function advanceStage(applicationId: number) {
        router.post(`/hr/recruitment/applications/${applicationId}/advance`, {}, { preserveScroll: true });
    }
    function rejectApplication(applicationId: number) {
        if (confirm('Are you sure you want to reject this application?')) {
            router.post(`/hr/recruitment/applications/${applicationId}/reject`, {}, { preserveScroll: true });
        }
    }
    function updateInterviewStatus(interviewId: number, status: 'completed' | 'cancelled' | 'no_show') {
        router.put(`/hr/recruitment/interviews/${interviewId}`, { status }, { preserveScroll: true });
    }
    function updateReferenceStatus(referenceId: number, status: 'contacted' | 'completed') {
        router.put(`/hr/recruitment/references/${referenceId}`, { status }, { preserveScroll: true });
    }
    function approveOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/approve`, {}, { preserveScroll: true });
    }
    function sendOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/send`, {}, { preserveScroll: true });
    }
    function recordOfferResponse(offerId: number, response: 'accepted' | 'declined' | 'withdrawn') {
        const notes = response !== 'accepted' ? (prompt('Optional notes:') ?? '').trim() : '';
        router.post(`/hr/recruitment/offers/${offerId}/respond`, { response, response_notes: notes || null }, { preserveScroll: true });
    }
    function convertOffer(offerId: number) {
        router.post(`/hr/recruitment/offers/${offerId}/convert`, {}, { preserveScroll: true });
    }

    const formatCurrency = (amount: number | null) => {
        if (!amount) return '-';
        return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName} />
            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <Card className="overflow-hidden">
                    <div className="bg-gradient-to-r from-primary/5 via-primary/10 to-transparent p-6">
                        <div className="flex flex-col sm:flex-row items-start gap-5">
                            <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-2xl font-bold text-primary border-2 border-primary/20">
                                {initials}
                            </div>
                            <div className="flex-1 min-w-0">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <h1 className="text-2xl font-bold">{fullName}</h1>
                                        {candidate.preferred_name && (
                                            <p className="text-sm text-muted-foreground">Goes by "{candidate.preferred_name}"</p>
                                        )}
                                        <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                            <a href={`mailto:${candidate.personal_email}`} className="flex items-center gap-1.5 hover:text-primary transition-colors">
                                                <Mail className="h-3.5 w-3.5" />
                                                {candidate.personal_email}
                                            </a>
                                            {candidate.personal_phone && (
                                                <a href={`tel:${candidate.personal_phone}`} className="flex items-center gap-1.5 hover:text-primary transition-colors">
                                                    <Phone className="h-3.5 w-3.5" />
                                                    {candidate.personal_phone}
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                    {can.manage && candidate.applications[0]?.status === 'active' && (
                                        <div className="flex items-center gap-2 shrink-0">
                                            <Button size="sm" onClick={() => advanceStage(candidate.applications[0].id)}>
                                                Advance <ArrowRight className="ml-1 h-3.5 w-3.5" />
                                            </Button>
                                            <Button size="sm" variant="destructive" onClick={() => rejectApplication(candidate.applications[0].id)}>
                                                Reject
                                            </Button>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <StatusBadge status={currentStatus} />
                                    <Badge variant="outline" className="capitalize">{candidate.source.replace(/_/g, ' ')}</Badge>
                                    {candidate.source_detail && <Badge variant="secondary">{candidate.source_detail}</Badge>}
                                    <Badge variant="secondary" className="gap-1">
                                        <Clock className="h-3 w-3" />
                                        {totalDaysInPipeline ?? Math.round((Date.now() - new Date(candidate.created_at).getTime()) / 86400000)}d in pipeline
                                    </Badge>
                                    <Badge variant="secondary">{candidate.applications.length} application{candidate.applications.length !== 1 ? 's' : ''}</Badge>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                {/* Main Content */}
                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <Tabs defaultValue="applications" className="space-y-4">
                        <TabsList>
                            <TabsTrigger value="applications">Applications ({candidate.applications.length})</TabsTrigger>
                            <TabsTrigger value="timeline">Timeline</TabsTrigger>
                            <TabsTrigger value="notes">Notes</TabsTrigger>
                        </TabsList>

                        <TabsContent value="applications" className="space-y-6">
                            {candidate.applications.length === 0 ? (
                                <Card>
                                    <CardContent className="py-8 text-center text-muted-foreground">
                                        <Briefcase className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                        <p>No applications yet.</p>
                                    </CardContent>
                                </Card>
                            ) : (
                                candidate.applications.map((app) => (
                                    <Card key={app.id} className="overflow-hidden">
                                        <CardHeader className="bg-muted/30 border-b">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <CardTitle className="flex items-center gap-2 text-base">
                                                        <Briefcase className="h-4 w-4" />
                                                        {app.position_title}
                                                        {app.position_role && <Badge variant="outline" className="text-xs">{app.position_role}</Badge>}
                                                    </CardTitle>
                                                    <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{app.applied_at}</span>
                                                        {app.target_site && <span>Site: {app.target_site.name}</span>}
                                                        {app.interview_kit && <span>Kit: {app.interview_kit.name}</span>}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={app.status === 'active' ? 'default' : app.status === 'rejected' ? 'destructive' : 'secondary'} className="capitalize">{app.status}</Badge>
                                                    {can.manage && app.status === 'active' && (
                                                        <>
                                                            {app.stage === 'offer_pending' && !app.offer && (
                                                                <Button size="sm" variant="outline" asChild>
                                                                    <Link href={`/hr/recruitment/applications/${app.id}/offer/create`}>
                                                                        <Gift className="mr-1 h-3 w-3" /> Create Offer
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="p-5 space-y-5">
                                            <PipelineStepper currentStage={app.stage} />

                                            {/* Interviews */}
                                            {app.interviews.length > 0 && (
                                                <div>
                                                    <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                        <UserCheck className="h-4 w-4" />
                                                        Interviews ({app.interviews.length})
                                                    </h4>
                                                    <div className="space-y-2">
                                                        {app.interviews.map((interview) => (
                                                            <div key={interview.id} className="rounded-lg border p-3 hover:bg-muted/30 transition-colors">
                                                                <div className="flex items-start justify-between gap-3">
                                                                    <div className="min-w-0">
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
                                                                        {interview.scores.length > 0 && (
                                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                                {interview.scores.map((score) => (
                                                                                    <div key={score.id} className="inline-flex items-center gap-1.5 rounded-md bg-muted/50 px-2 py-1 text-xs">
                                                                                        <Star className="h-3 w-3 fill-amber-400 text-amber-400" />
                                                                                        <span>{score.overall_score ?? '-'}</span>
                                                                                        {score.recommendation && (
                                                                                            <span className={recColors[score.recommendation] ?? 'text-muted-foreground'}>
                                                                                                {score.recommendation.replace('_', ' ')}
                                                                                            </span>
                                                                                        )}
                                                                                        {score.interviewer_name && <span className="text-muted-foreground">- {score.interviewer_name}</span>}
                                                                                    </div>
                                                                                ))}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                    {can.manage && (
                                                                        <div className="flex items-center gap-1 shrink-0">
                                                                            {interview.status === 'scheduled' && (
                                                                                <>
                                                                                    <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => updateInterviewStatus(interview.id, 'completed')}>Complete</Button>
                                                                                    <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => updateInterviewStatus(interview.id, 'no_show')}>No Show</Button>
                                                                                </>
                                                                            )}
                                                                            <Button size="sm" variant="outline" className="h-7 text-xs" asChild>
                                                                                <Link href={`/hr/recruitment/interviews/${interview.id}/scorecard`}>
                                                                                    <Star className="mr-1 h-3 w-3" /> Score
                                                                                </Link>
                                                                            </Button>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {/* References */}
                                            {app.reference_checks.length > 0 && (
                                                <div>
                                                    <h4 className="mb-3 text-sm font-semibold flex items-center gap-1.5">
                                                        <Shield className="h-4 w-4" />
                                                        Reference Checks ({app.reference_checks.length})
                                                    </h4>
                                                    <div className="space-y-2">
                                                        {app.reference_checks.map((ref) => (
                                                            <div key={ref.id} className="flex items-start justify-between gap-3 rounded-lg border p-3">
                                                                <div>
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="font-medium text-sm">{ref.referee_name}</span>
                                                                        <Badge variant={ref.status === 'completed' ? 'default' : 'outline'} className="capitalize text-xs">{ref.status}</Badge>
                                                                    </div>
                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                        {ref.referee_relationship}
                                                                        {ref.referee_phone && <span> - {ref.referee_phone}</span>}
                                                                    </div>
                                                                </div>
                                                                {can.manage && ref.status !== 'completed' && (
                                                                    <div className="flex gap-1 shrink-0">
                                                                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => updateReferenceStatus(ref.id, 'contacted')}>Contacted</Button>
                                                                        <Button size="sm" className="h-7 text-xs" onClick={() => updateReferenceStatus(ref.id, 'completed')}>Complete</Button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {/* Offer */}
                                            {app.offer && (
                                                <div className="rounded-xl border-2 border-emerald-500/20 bg-emerald-500/5 p-4">
                                                    <h4 className="text-sm font-semibold flex items-center gap-2 mb-3">
                                                        <Gift className="h-4 w-4 text-emerald-500" />
                                                        Employment Offer
                                                    </h4>
                                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 text-sm">
                                                        <div><span className="text-xs text-muted-foreground block">Position</span>{app.offer.position_title}</div>
                                                        <div><span className="text-xs text-muted-foreground block">Type</span><span className="capitalize">{app.offer.employment_type.replace('_', ' ')}</span></div>
                                                        <div><span className="text-xs text-muted-foreground block">Start Date</span>{app.offer.proposed_start_date}</div>
                                                        {app.offer.annual_salary && <div><span className="text-xs text-muted-foreground block">Annual Salary</span>{formatCurrency(app.offer.annual_salary)}</div>}
                                                        {app.offer.hourly_rate && <div><span className="text-xs text-muted-foreground block">Hourly Rate</span>{formatCurrency(app.offer.hourly_rate)}</div>}
                                                        {app.offer.hours_per_week && <div><span className="text-xs text-muted-foreground block">Hours/Week</span>{app.offer.hours_per_week}h</div>}
                                                        {app.offer.primary_site && <div><span className="text-xs text-muted-foreground block">Site</span>{app.offer.primary_site.name}</div>}
                                                    </div>
                                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                                        <Badge variant={app.offer.approval_status === 'approved' ? 'default' : 'secondary'} className="capitalize">{app.offer.approval_status}</Badge>
                                                        {app.offer.sent_at && <Badge variant="outline"><Send className="mr-1 h-3 w-3" />Sent {app.offer.sent_at}</Badge>}
                                                        {app.offer.response && (
                                                            <Badge variant={app.offer.response === 'accepted' ? 'default' : 'destructive'} className="capitalize">{app.offer.response}</Badge>
                                                        )}
                                                        {app.offer.portal_url && (
                                                            <a href={app.offer.portal_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                                                                <ExternalLink className="h-3 w-3" /> Candidate Portal
                                                            </a>
                                                        )}
                                                    </div>
                                                    {can.manage && (
                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                            {app.offer.approval_status !== 'approved' && (
                                                                <Button size="sm" variant="outline" onClick={() => approveOffer(app.offer!.id)}>Approve</Button>
                                                            )}
                                                            {app.offer.approval_status === 'approved' && !app.offer.sent_at && (
                                                                <Button size="sm" onClick={() => sendOffer(app.offer!.id)}><Send className="mr-1 h-3.5 w-3.5" />Send Offer</Button>
                                                            )}
                                                            {app.offer.sent_at && !app.offer.response && (
                                                                <>
                                                                    <Button size="sm" onClick={() => recordOfferResponse(app.offer!.id, 'accepted')}>Mark Accepted</Button>
                                                                    <Button size="sm" variant="secondary" onClick={() => recordOfferResponse(app.offer!.id, 'declined')}>Declined</Button>
                                                                    <Button size="sm" variant="ghost" onClick={() => recordOfferResponse(app.offer!.id, 'withdrawn')}>Withdrawn</Button>
                                                                </>
                                                            )}
                                                            {app.offer.response === 'accepted' && (
                                                                <Button size="sm" variant="outline" onClick={() => convertOffer(app.offer!.id)}>
                                                                    <UserCheck className="mr-1 h-3.5 w-3.5" /> Convert to Employee
                                                                </Button>
                                                            )}
                                                        </div>
                                                    )}
                                                    {app.offer.signed_full_name && (
                                                        <p className="mt-2 text-xs text-muted-foreground">Signed by: {app.offer.signed_full_name} {app.offer.signed_at && `on ${app.offer.signed_at}`}</p>
                                                    )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))
                            )}
                        </TabsContent>

                        <TabsContent value="timeline" className="space-y-4">
                            <Card>
                                <CardHeader><CardTitle className="text-base">Activity Timeline</CardTitle></CardHeader>
                                <CardContent>
                                    {activityLog && activityLog.length > 0 ? (
                                        <div className="space-y-0">
                                            {activityLog.map((entry, i) => (
                                                <ActivityItem key={i} type={entry.type} description={entry.description} timestamp={entry.timestamp} actor={entry.actor} />
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No activity recorded yet.</p>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>

                        <TabsContent value="notes" className="space-y-4">
                            <Card>
                                <CardHeader><CardTitle className="text-base">Candidate Notes</CardTitle></CardHeader>
                                <CardContent className="space-y-4">
                                    {candidate.notes && (
                                        <div className="rounded-lg bg-muted/50 p-4 text-sm whitespace-pre-wrap">{candidate.notes}</div>
                                    )}
                                    {can.manage && (
                                        <div className="space-y-2">
                                            <Textarea placeholder="Add a note..." value={noteText} onChange={(e) => setNoteText(e.target.value)} rows={3} />
                                            <Button size="sm" disabled={!noteText.trim()} onClick={() => {
                                                router.put(`/hr/recruitment/candidates/${candidate.id}`, { notes: (candidate.notes ? candidate.notes + '\n\n' : '') + noteText.trim() }, { preserveScroll: true, onSuccess: () => setNoteText('') });
                                            }}>
                                                <MessageSquare className="mr-1 h-3.5 w-3.5" /> Add Note
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </TabsContent>
                    </Tabs>

                    {/* Sidebar */}
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Quick Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <span className="text-xs text-muted-foreground block">Status</span>
                                    <StatusBadge status={currentStatus} />
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Source</span>
                                    <span className="capitalize">{candidate.source.replace(/_/g, ' ')}</span>
                                    {candidate.source_detail && <span className="text-muted-foreground"> ({candidate.source_detail})</span>}
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Created</span>
                                    {new Date(candidate.created_at).toLocaleDateString('en-NZ', { year: 'numeric', month: 'long', day: 'numeric' })}
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Days in Pipeline</span>
                                    {totalDaysInPipeline ?? Math.round((Date.now() - new Date(candidate.created_at).getTime()) / 86400000)} days
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Applications</span>
                                    {candidate.applications.length}
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground block">Interviews</span>
                                    {candidate.applications.reduce((sum, app) => sum + app.interviews.length, 0)}
                                </div>
                            </CardContent>
                        </Card>

                        {can.manage && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">Actions</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                        <Link href={`/hr/recruitment/candidates/${candidate.id}`}>
                                            <Mail className="mr-2 h-3.5 w-3.5" /> Email Candidate
                                        </Link>
                                    </Button>
                                    {candidate.applications[0] && (
                                        <>
                                            <Button variant="outline" size="sm" className="w-full justify-start"
                                                onClick={() => advanceStage(candidate.applications[0].id)}>
                                                <ArrowRight className="mr-2 h-3.5 w-3.5" /> Advance Stage
                                            </Button>
                                            <Button variant="outline" size="sm" className="w-full justify-start" asChild>
                                                <Link href={`/hr/recruitment/applications/${candidate.applications[0].id}/scorecard-summary`}>
                                                    <Star className="mr-2 h-3.5 w-3.5" /> View Scorecards
                                                </Link>
                                            </Button>
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
