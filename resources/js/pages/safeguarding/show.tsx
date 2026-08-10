import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    ExternalLink,
    FileText,
    MapPin,
    Pencil,
    Plus,
    Scale,
    Search,
    Shield,
    ShieldAlert,
    User,
    UserCheck,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type StaffMember = { id: number; name: string };

type RiskAssessment = {
    id: number;
    overall_risk_level: string | null;
    risk_to_self: string | null;
    risk_to_others: string | null;
    risk_from_others: string | null;
    risk_factors: string | null;
    protective_factors: string | null;
    capacity_assessed: boolean | null;
    mental_capacity: string | null;
    capacity_notes: string | null;
    immediate_actions_required: string | null;
    protective_measures: string | null;
    multi_agency_required: boolean | null;
    assessment_notes: string | null;
    next_review_date: string | null;
    created_at: string | null;
    assessor?: { id: number; name: string } | null;
};

type Investigation = {
    id: number;
    investigation_type: string | null;
    status: string | null;
    started_at: string | null;
    target_completion_date: string | null;
    completed_at: string | null;
    terms_of_reference: string | null;
    methodology: string | null;
    findings: string | null;
    evidence_summary: string | null;
    recommendations: string | null;
    lead_investigator?: { id: number; name: string } | null;
};

type ExternalReport = {
    id: number;
    authority_type: string | null;
    authority_name: string | null;
    authority_contact: string | null;
    reported_at: string | null;
    report_method: string | null;
    report_summary: string | null;
    acknowledgment_received: boolean | null;
    acknowledgment_date: string | null;
    acknowledgment_reference: string | null;
    reported_by?: { id: number; name: string } | null;
};

type ActionPlan = {
    id: number;
    action_description: string | null;
    action_type: string | null;
    status: string | null;
    priority: number | null;
    due_date: string | null;
    completed_at: string | null;
    completion_notes: string | null;
    assigned_to?: { id: number; name: string } | null;
};

type Concern = {
    id: number;
    reference_number: string;
    severity: string;
    status: string;
    concern_type: string;
    abuse_category?: string | null;
    description: string;
    reported_at?: string | null;
    occurred_at?: string | null;
    location?: string | null;
    subject_informed?: boolean | null;
    requires_external_referral?: boolean | null;
    reportedBy?: { name: string } | null;
    assignedTo?: { id: number; name: string } | null;
    closedBy?: { name: string } | null;
    site?: { name: string } | null;
    subject?: { first_name?: string; last_name?: string; name?: string } | null;
    subject_name?: string | null;
    allegedPerpetrator?: { name?: string; first_name?: string; last_name?: string } | null;
    alleged_perpetrator_name?: string | null;
    immediate_actions?: string | null;
    closure_summary?: string | null;
    lessons_learned?: string | null;
    closed_at?: string | null;
    investigations?: Investigation[];
    externalReports?: ExternalReport[];
    riskAssessments?: RiskAssessment[];
    actionPlans?: ActionPlan[];
};

type Props = {
    concern: Concern;
    canUpdate: boolean;
    canInvestigate: boolean;
    canReportExternal: boolean;
    staff: StaffMember[];
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const formatDate = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
};

const formatDateTime = (value?: string | null) => {
    if (!value) return 'Not set';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

const severityColor = (severity: string) => {
    const map: Record<string, string> = {
        critical: 'bg-red-100 text-red-800 border-red-200',
        high: 'bg-orange-100 text-orange-800 border-orange-200',
        medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        low: 'bg-blue-100 text-blue-800 border-blue-200',
    };
    return map[severity] ?? 'bg-slate-100 text-slate-800 border-slate-200';
};

const statusColor = (status: string) => {
    const map: Record<string, string> = {
        closed: 'bg-green-100 text-green-800 border-green-200',
        investigating: 'bg-purple-100 text-purple-800 border-purple-200',
        triaged: 'bg-blue-100 text-blue-800 border-blue-200',
        reported: 'bg-slate-100 text-slate-800 border-slate-200',
        action_plan: 'bg-indigo-100 text-indigo-800 border-indigo-200',
        monitoring: 'bg-cyan-100 text-cyan-800 border-cyan-200',
        referred_external: 'bg-purple-100 text-purple-800 border-purple-200',
    };
    return map[status] ?? 'bg-slate-100 text-slate-800 border-slate-200';
};

const riskColor = (level: string) => {
    const map: Record<string, string> = {
        critical: 'bg-red-100 text-red-800 border-red-200',
        high: 'bg-orange-100 text-orange-800 border-orange-200',
        medium: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        low: 'bg-green-100 text-green-800 border-green-200',
    };
    return map[level] ?? 'bg-slate-100 text-slate-800 border-slate-200';
};

const actionStatusColor = (status: string) => {
    const map: Record<string, string> = {
        completed: 'bg-green-100 text-green-800 border-green-200',
        in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
        pending: 'bg-slate-100 text-slate-800 border-slate-200',
        cancelled: 'bg-red-100 text-red-800 border-red-200',
        overdue: 'bg-red-100 text-red-800 border-red-200',
    };
    return map[status] ?? 'bg-slate-100 text-slate-800 border-slate-200';
};

const investigationStatusColor = (status: string) => {
    const map: Record<string, string> = {
        completed: 'bg-green-100 text-green-800 border-green-200',
        in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
        planned: 'bg-slate-100 text-slate-800 border-slate-200',
        paused: 'bg-amber-100 text-amber-800 border-amber-200',
        abandoned: 'bg-red-100 text-red-800 border-red-200',
        pending: 'bg-slate-100 text-slate-800 border-slate-200',
        cancelled: 'bg-red-100 text-red-800 border-red-200',
        on_hold: 'bg-amber-100 text-amber-800 border-amber-200',
    };
    return map[status] ?? 'bg-slate-100 text-slate-800 border-slate-200';
};

const displayName = (
    value?: { first_name?: string; last_name?: string; name?: string } | null,
    fallback?: string | null,
) => {
    if (value?.name) return value.name;
    const first = value?.first_name ?? '';
    const last = value?.last_name ?? '';
    const combined = `${first} ${last}`.trim();
    return combined || fallback || 'Unknown';
};

const label = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function MetaRow({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{title}</span>
            <span className="text-sm font-medium">{children}</span>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main Component
// ---------------------------------------------------------------------------

export default function SafeguardingShow({ concern, canUpdate, canInvestigate, canReportExternal, staff }: Props) {
    const subjectName = displayName(concern.subject, concern.subject_name);
    const perpName = displayName(concern.allegedPerpetrator, concern.alleged_perpetrator_name);

    // Dialog states
    const [assignOpen, setAssignOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
    const [riskOpen, setRiskOpen] = useState(false);
    const [investigationOpen, setInvestigationOpen] = useState(false);
    const [externalOpen, setExternalOpen] = useState(false);
    const [actionOpen, setActionOpen] = useState(false);
    const [completeActionId, setCompleteActionId] = useState<number | null>(null);
    const [expandedInvestigation, setExpandedInvestigation] = useState<number | null>(null);
    const [ackReportId, setAckReportId] = useState<number | null>(null);

    // Forms
    const assignForm = useForm({ assigned_to_user_id: '' as string });
    const statusForm = useForm({ status: concern.status });
    const closeForm = useForm({ closure_summary: '', lessons_learned: '' });

    const riskForm = useForm({
        overall_risk_level: '' as string,
        risk_to_self: '' as string,
        risk_to_others: '' as string,
        risk_from_others: '' as string,
        risk_factors: '',
        protective_factors: '',
        capacity_assessed: false,
        mental_capacity: '' as string,
        capacity_notes: '',
        immediate_actions_required: '',
        protective_measures: '',
        multi_agency_required: false,
        assessment_notes: '',
        next_review_date: '',
    });

    const investigationForm = useForm({
        investigation_type: '' as string,
        lead_investigator_id: '' as string,
        started_at: '',
        target_completion_date: '',
        terms_of_reference: '',
        methodology: '',
    });

    const externalForm = useForm({
        authority_type: '' as string,
        authority_name: '',
        authority_contact: '',
        reported_at: '',
        report_method: '' as string,
        report_summary: '',
    });

    const actionForm = useForm({
        action_description: '',
        action_type: '' as string,
        assigned_to_user_id: '' as string,
        due_date: '',
        priority: '' as string,
    });

    const completeForm = useForm({ completion_notes: '' });

    const investigationUpdateForm = useForm({
        findings: '',
        evidence_summary: '',
        recommendations: '',
        status: '' as string,
        completed_at: '',
    });

    const ackForm = useForm({
        acknowledgment_received: true,
        acknowledgment_date: '',
        acknowledgment_reference: '',
    });

    // Handlers
    const submitAssign = () => {
        assignForm.post(`/safeguarding/${concern.id}/assign`, {
            preserveScroll: true,
            onSuccess: () => {
                setAssignOpen(false);
                assignForm.reset();
            },
        });
    };

    const submitStatus = () => {
        statusForm.patch(`/safeguarding/${concern.id}/status`, {
            preserveScroll: true,
            onSuccess: () => {
                setStatusOpen(false);
            },
        });
    };

    const submitClose = () => {
        closeForm.post(`/safeguarding/${concern.id}/close`, {
            preserveScroll: true,
            onSuccess: () => {
                setCloseOpen(false);
                closeForm.reset();
            },
        });
    };

    const submitRisk = () => {
        riskForm.post(`/safeguarding/${concern.id}/risk-assessments`, {
            preserveScroll: true,
            onSuccess: () => {
                setRiskOpen(false);
                riskForm.reset();
            },
        });
    };

    const submitInvestigation = () => {
        investigationForm.post(`/safeguarding/${concern.id}/investigations`, {
            preserveScroll: true,
            onSuccess: () => {
                setInvestigationOpen(false);
                investigationForm.reset();
            },
        });
    };

    const submitExternal = () => {
        externalForm.post(`/safeguarding/${concern.id}/external-reports`, {
            preserveScroll: true,
            onSuccess: () => {
                setExternalOpen(false);
                externalForm.reset();
            },
        });
    };

    const submitAction = () => {
        actionForm.post(`/safeguarding/${concern.id}/action-plans`, {
            preserveScroll: true,
            onSuccess: () => {
                setActionOpen(false);
                actionForm.reset();
            },
        });
    };

    const submitComplete = () => {
        if (!completeActionId) return;
        completeForm.post(`/safeguarding/${concern.id}/action-plans/${completeActionId}/complete`, {
            preserveScroll: true,
            onSuccess: () => {
                setCompleteActionId(null);
                completeForm.reset();
            },
        });
    };

    const submitInvestigationUpdate = (investigationId: number) => {
        investigationUpdateForm.put(`/safeguarding/${concern.id}/investigations/${investigationId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setExpandedInvestigation(null);
                investigationUpdateForm.reset();
            },
        });
    };

    const submitAck = () => {
        if (!ackReportId) return;
        ackForm.put(`/safeguarding/${concern.id}/external-reports/${ackReportId}`, {
            preserveScroll: true,
            onSuccess: () => {
                setAckReportId(null);
                ackForm.reset();
            },
        });
    };

    const isClosed = concern.status === 'closed';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Safeguarding', href: '/safeguarding' },
                { title: concern.reference_number, href: `/safeguarding/${concern.id}` },
            ]}
        >
            <Head title={`Safeguarding ${concern.reference_number}`} />

            <div className="space-y-6">
                {/* ------------------------------------------------------------------ */}
                {/* Header                                                              */}
                {/* ------------------------------------------------------------------ */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold flex items-center gap-2">
                            {concern.severity === 'critical' && (
                                <AlertTriangle className="h-5 w-5 text-red-500" />
                            )}
                            {concern.reference_number}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge className={severityColor(concern.severity)}>
                                {label(concern.severity)}
                            </Badge>
                            <Badge className={statusColor(concern.status)}>
                                {label(concern.status)}
                            </Badge>
                            {concern.requires_external_referral && (
                                <Badge
                                    variant="outline"
                                    className="border-purple-200 bg-purple-50 text-purple-700"
                                >
                                    <ExternalLink className="mr-1 h-3 w-3" />
                                    External Referral Required
                                </Badge>
                            )}
                            {concern.subject_informed === false && (
                                <Badge
                                    variant="outline"
                                    className="border-amber-200 bg-amber-50 text-amber-700"
                                >
                                    Subject Not Informed
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/safeguarding"
                            className="inline-flex items-center rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted"
                        >
                            Back to list
                        </Link>
                        {canUpdate && !isClosed && (
                            <>
                                <Link href={`/safeguarding/${concern.id}/edit`}>
                                    <Button size="sm" variant="outline">
                                        <Pencil className="mr-1 h-3.5 w-3.5" />
                                        Edit
                                    </Button>
                                </Link>
                                <Button size="sm" variant="outline" onClick={() => setAssignOpen(true)}>
                                    <UserCheck className="mr-1 h-3.5 w-3.5" />
                                    Assign
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setStatusOpen(true)}>
                                    <Clock className="mr-1 h-3.5 w-3.5" />
                                    Update Status
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => setCloseOpen(true)}
                                >
                                    <XCircle className="mr-1 h-3.5 w-3.5" />
                                    Close
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* ------------------------------------------------------------------ */}
                {/* Concern Details                                                     */}
                {/* ------------------------------------------------------------------ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-5 w-5 text-purple-500" />
                                Concern Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <MetaRow title="Type">
                                    {label(concern.concern_type)}
                                </MetaRow>
                                <MetaRow title="Abuse Category">
                                    {concern.abuse_category ? label(concern.abuse_category) : 'Not specified'}
                                </MetaRow>
                                <MetaRow title="Location">
                                    {concern.location || 'Not recorded'}
                                </MetaRow>
                                <MetaRow title="Site">
                                    {concern.site?.name || 'Not set'}
                                </MetaRow>
                                <MetaRow title="Occurred">
                                    {formatDateTime(concern.occurred_at)}
                                </MetaRow>
                                <MetaRow title="Reported">
                                    {formatDateTime(concern.reported_at)}
                                </MetaRow>
                            </div>
                            <div>
                                <span className="text-xs text-muted-foreground">Description</span>
                                <p className="mt-1 text-sm whitespace-pre-wrap rounded-md bg-muted/50 p-3">
                                    {concern.description}
                                </p>
                            </div>
                            {concern.immediate_actions && (
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Immediate Actions Taken
                                    </span>
                                    <p className="mt-1 text-sm whitespace-pre-wrap rounded-md bg-amber-50 p-3 text-amber-900">
                                        {concern.immediate_actions}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="h-5 w-5 text-blue-500" />
                                People Involved
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <MetaRow title="Subject">{subjectName}</MetaRow>
                            <MetaRow title="Alleged Perpetrator">{perpName}</MetaRow>
                            <MetaRow title="Reported By">
                                {concern.reportedBy?.name || 'Unknown'}
                            </MetaRow>
                            <MetaRow title="Assigned To">
                                {concern.assignedTo?.name || 'Unassigned'}
                            </MetaRow>
                            {concern.closedBy && (
                                <MetaRow title="Closed By">{concern.closedBy.name}</MetaRow>
                            )}
                            {!concern.subject_informed && canUpdate && !isClosed && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="w-full"
                                    onClick={() =>
                                        router.post(`/safeguarding/${concern.id}/subject-informed`)
                                    }
                                >
                                    Mark Subject Informed
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Closure info if closed */}
                {isClosed && (concern.closure_summary || concern.lessons_learned) && (
                    <Card className="border-green-200 bg-green-50/50">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base text-green-800">
                                <CheckCircle2 className="h-5 w-5" />
                                Closure Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {concern.closure_summary && (
                                <div>
                                    <span className="text-xs text-green-700">Summary</span>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {concern.closure_summary}
                                    </p>
                                </div>
                            )}
                            {concern.lessons_learned && (
                                <div>
                                    <span className="text-xs text-green-700">Lessons Learned</span>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {concern.lessons_learned}
                                    </p>
                                </div>
                            )}
                            {concern.closed_at && (
                                <p className="text-xs text-green-600">
                                    Closed on {formatDateTime(concern.closed_at)} by{' '}
                                    {concern.closedBy?.name ?? 'Unknown'}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* ------------------------------------------------------------------ */}
                {/* Risk Assessments                                                    */}
                {/* ------------------------------------------------------------------ */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ShieldAlert className="h-5 w-5 text-red-500" />
                            Risk Assessments
                            <Badge variant="secondary" className="ml-1">
                                {concern.riskAssessments?.length ?? 0}
                            </Badge>
                        </CardTitle>
                        {canUpdate && !isClosed && (
                            <Button size="sm" onClick={() => setRiskOpen(true)}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                New Assessment
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {(!concern.riskAssessments || concern.riskAssessments.length === 0) ? (
                            <p className="text-sm text-muted-foreground py-4 text-center">
                                No risk assessments recorded yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {concern.riskAssessments.map((ra) => (
                                    <div
                                        key={ra.id}
                                        className="rounded-lg border p-4 space-y-2"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <Badge className={riskColor(ra.overall_risk_level ?? 'low')}>
                                                    {label(ra.overall_risk_level ?? 'unknown')}
                                                </Badge>
                                                {ra.multi_agency_required && (
                                                    <Badge variant="outline" className="border-purple-200 text-purple-700">
                                                        Multi-agency
                                                    </Badge>
                                                )}
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                Assessed by {ra.assessor?.name ?? 'Unknown'} on{' '}
                                                {formatDate(ra.created_at)}
                                            </span>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-3 text-sm">
                                            <div>
                                                <span className="text-xs text-muted-foreground">Risk to Self</span>
                                                <p className="font-medium">{label(ra.risk_to_self ?? 'N/A')}</p>
                                            </div>
                                            <div>
                                                <span className="text-xs text-muted-foreground">Risk to Others</span>
                                                <p className="font-medium">{label(ra.risk_to_others ?? 'N/A')}</p>
                                            </div>
                                            <div>
                                                <span className="text-xs text-muted-foreground">Risk from Others</span>
                                                <p className="font-medium">{label(ra.risk_from_others ?? 'N/A')}</p>
                                            </div>
                                        </div>
                                        {ra.risk_factors && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Risk Factors</span>
                                                <p className="whitespace-pre-wrap">{ra.risk_factors}</p>
                                            </div>
                                        )}
                                        {ra.protective_measures && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Protective Measures</span>
                                                <p className="whitespace-pre-wrap">{ra.protective_measures}</p>
                                            </div>
                                        )}
                                        {ra.next_review_date && (
                                            <p className="text-xs text-muted-foreground">
                                                Next review: {formatDate(ra.next_review_date)}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* Investigations                                                      */}
                {/* ------------------------------------------------------------------ */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Search className="h-5 w-5 text-purple-500" />
                            Investigations
                            <Badge variant="secondary" className="ml-1">
                                {concern.investigations?.length ?? 0}
                            </Badge>
                        </CardTitle>
                        {canInvestigate && !isClosed && (
                            <Button size="sm" onClick={() => setInvestigationOpen(true)}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Start Investigation
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {(!concern.investigations || concern.investigations.length === 0) ? (
                            <p className="text-sm text-muted-foreground py-4 text-center">
                                No investigations started yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {concern.investigations.map((inv) => (
                                    <div key={inv.id} className="rounded-lg border p-4 space-y-2">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                            <Badge className={investigationStatusColor(inv.status ?? 'planned')}>
                                {label(inv.status ?? 'planned')}
                            </Badge>
                                                <Badge variant="outline">
                                                    {label(inv.investigation_type ?? 'internal')}
                                                </Badge>
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                Lead: {inv.lead_investigator?.name ?? 'Unassigned'}
                                            </span>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-2 text-sm">
                                            <MetaRow title="Started">
                                                {formatDate(inv.started_at)}
                                            </MetaRow>
                                            <MetaRow title="Target Completion">
                                                {formatDate(inv.target_completion_date)}
                                            </MetaRow>
                                        </div>
                                        {inv.terms_of_reference && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Terms of Reference</span>
                                                <p className="whitespace-pre-wrap">{inv.terms_of_reference}</p>
                                            </div>
                                        )}
                                        {inv.findings && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Findings</span>
                                                <p className="whitespace-pre-wrap">{inv.findings}</p>
                                            </div>
                                        )}
                                        {inv.evidence_summary && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Evidence Summary</span>
                                                <p className="whitespace-pre-wrap">{inv.evidence_summary}</p>
                                            </div>
                                        )}
                                        {inv.recommendations && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Recommendations</span>
                                                <p className="whitespace-pre-wrap">{inv.recommendations}</p>
                                            </div>
                                        )}

                                        {/* Update section */}
                                        {canInvestigate && !['completed', 'abandoned', 'cancelled'].includes(inv.status ?? '') && !isClosed && (
                                            <div>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-xs"
                                                    onClick={() =>
                                                        setExpandedInvestigation(
                                                            expandedInvestigation === inv.id ? null : inv.id,
                                                        )
                                                    }
                                                >
                                                    {expandedInvestigation === inv.id ? (
                                                        <ChevronUp className="mr-1 h-3 w-3" />
                                                    ) : (
                                                        <ChevronDown className="mr-1 h-3 w-3" />
                                                    )}
                                                    Update Investigation
                                                </Button>
                                                {expandedInvestigation === inv.id && (
                                                    <div className="mt-3 space-y-3 rounded-md border bg-muted/30 p-3">
                                                        <div className="grid gap-3 sm:grid-cols-2">
                                                            <div>
                                                                <Label className="text-xs">Status</Label>
                                                                <Select
                                                                    value={investigationUpdateForm.data.status}
                                                                    onValueChange={(v) =>
                                                                        investigationUpdateForm.setData('status', v)
                                                                    }
                                                                >
                                                                    <SelectTrigger>
                                                                        <SelectValue placeholder="Select status" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                <SelectItem value="planned">Planned</SelectItem>
                                                                <SelectItem value="in_progress">In Progress</SelectItem>
                                                                <SelectItem value="paused">Paused</SelectItem>
                                                                <SelectItem value="completed">Completed</SelectItem>
                                                                <SelectItem value="abandoned">Abandoned</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                            <div>
                                                                <Label className="text-xs">Completed At</Label>
                                                                <Input
                                                                    type="date"
                                                                    value={investigationUpdateForm.data.completed_at}
                                                                    onChange={(e) =>
                                                                        investigationUpdateForm.setData(
                                                                            'completed_at',
                                                                            e.target.value,
                                                                        )
                                                                    }
                                                                />
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <Label className="text-xs">Findings</Label>
                                                            <Textarea
                                                                rows={3}
                                                                value={investigationUpdateForm.data.findings}
                                                                onChange={(e) =>
                                                                    investigationUpdateForm.setData(
                                                                        'findings',
                                                                        e.target.value,
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div>
                                                            <Label className="text-xs">Evidence Summary</Label>
                                                            <Textarea
                                                                rows={2}
                                                                value={investigationUpdateForm.data.evidence_summary}
                                                                onChange={(e) =>
                                                                    investigationUpdateForm.setData(
                                                                        'evidence_summary',
                                                                        e.target.value,
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div>
                                                            <Label className="text-xs">Recommendations</Label>
                                                            <Textarea
                                                                rows={2}
                                                                value={investigationUpdateForm.data.recommendations}
                                                                onChange={(e) =>
                                                                    investigationUpdateForm.setData(
                                                                        'recommendations',
                                                                        e.target.value,
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div className="flex justify-end">
                                                            <Button
                                                                size="sm"
                                                                disabled={investigationUpdateForm.processing}
                                                                onClick={() => submitInvestigationUpdate(inv.id)}
                                                            >
                                                                Save Update
                                                            </Button>
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* External Reports                                                    */}
                {/* ------------------------------------------------------------------ */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ExternalLink className="h-5 w-5 text-indigo-500" />
                            External Reports
                            <Badge variant="secondary" className="ml-1">
                                {concern.externalReports?.length ?? 0}
                            </Badge>
                        </CardTitle>
                        {canReportExternal && !isClosed && (
                            <Button size="sm" onClick={() => setExternalOpen(true)}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Log External Report
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {(!concern.externalReports || concern.externalReports.length === 0) ? (
                            <p className="text-sm text-muted-foreground py-4 text-center">
                                No external reports logged yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {concern.externalReports.map((er) => (
                                    <div key={er.id} className="rounded-lg border p-4 space-y-2">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {er.authority_name || label(er.authority_type ?? 'other')}
                                                </span>
                                                <Badge variant="outline">
                                                    {label(er.authority_type ?? 'other')}
                                                </Badge>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {er.acknowledgment_received ? (
                                                    <Badge className="bg-green-100 text-green-800 border-green-200">
                                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                                        Acknowledged
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline" className="border-amber-200 text-amber-700">
                                                        Awaiting Acknowledgment
                                                    </Badge>
                                                )}
                                            </div>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-3 text-sm">
                                            <MetaRow title="Reported">
                                                {formatDate(er.reported_at)}
                                            </MetaRow>
                                            <MetaRow title="Method">
                                                {label(er.report_method ?? 'N/A')}
                                            </MetaRow>
                                            <MetaRow title="Reported By">
                                                {er.reported_by?.name ?? 'Unknown'}
                                            </MetaRow>
                                        </div>
                                        {er.report_summary && (
                                            <div className="text-sm">
                                                <span className="text-xs text-muted-foreground">Summary</span>
                                                <p className="whitespace-pre-wrap">{er.report_summary}</p>
                                            </div>
                                        )}
                                        {er.acknowledgment_received && er.acknowledgment_reference && (
                                            <p className="text-xs text-muted-foreground">
                                                Ref: {er.acknowledgment_reference} - Acknowledged{' '}
                                                {formatDate(er.acknowledgment_date)}
                                            </p>
                                        )}
                                        {!er.acknowledgment_received && canReportExternal && !isClosed && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="text-xs"
                                                onClick={() => setAckReportId(er.id)}
                                            >
                                                <CheckCircle2 className="mr-1 h-3 w-3" />
                                                Record Acknowledgment
                                            </Button>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* Action Plans                                                        */}
                {/* ------------------------------------------------------------------ */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="h-5 w-5 text-amber-500" />
                            Action Plans
                            <Badge variant="secondary" className="ml-1">
                                {concern.actionPlans?.length ?? 0}
                            </Badge>
                        </CardTitle>
                        {canUpdate && !isClosed && (
                            <Button size="sm" onClick={() => setActionOpen(true)}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Add Action
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {(!concern.actionPlans || concern.actionPlans.length === 0) ? (
                            <p className="text-sm text-muted-foreground py-4 text-center">
                                No action plans created yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {concern.actionPlans.map((ap) => {
                                    const isOverdue =
                                        ap.due_date &&
                                        ap.status !== 'completed' &&
                                        ap.status !== 'cancelled' &&
                                        new Date(ap.due_date) < new Date();

                                    return (
                                        <div
                                            key={ap.id}
                                            className={`rounded-lg border p-4 space-y-2 ${isOverdue ? 'border-red-200 bg-red-50/30' : ''}`}
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <div className="flex-1">
                                                    <p className="text-sm font-medium">
                                                        {ap.action_description}
                                                    </p>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge className={actionStatusColor(isOverdue ? 'overdue' : (ap.status ?? 'pending'))}>
                                                        {isOverdue ? 'Overdue' : label(ap.status ?? 'pending')}
                                                    </Badge>
                                                    {ap.priority && (
                                                        <Badge variant="outline" className="text-xs">
                                                            P{ap.priority}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="grid gap-2 sm:grid-cols-3 text-sm">
                                                <MetaRow title="Type">
                                                    {label(ap.action_type ?? 'N/A')}
                                                </MetaRow>
                                                <MetaRow title="Assigned To">
                                                    {ap.assigned_to?.name ?? 'Unassigned'}
                                                </MetaRow>
                                                <MetaRow title="Due Date">
                                                    <span className={isOverdue ? 'text-red-600 font-semibold' : ''}>
                                                        {formatDate(ap.due_date)}
                                                    </span>
                                                </MetaRow>
                                            </div>
                                            {ap.completion_notes && (
                                                <div className="text-sm">
                                                    <span className="text-xs text-muted-foreground">
                                                        Completion Notes
                                                    </span>
                                                    <p className="whitespace-pre-wrap">{ap.completion_notes}</p>
                                                </div>
                                            )}
                                            {ap.status !== 'completed' &&
                                                ap.status !== 'cancelled' &&
                                                canUpdate &&
                                                !isClosed && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="text-xs"
                                                        onClick={() => setCompleteActionId(ap.id)}
                                                    >
                                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                                        Mark Complete
                                                    </Button>
                                                )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ================================================================== */}
            {/* DIALOGS                                                             */}
            {/* ================================================================== */}

            {/* Assign Dialog */}
            <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Assign Concern</DialogTitle>
                        <DialogDescription>
                            Assign this concern to the staff member responsible for follow-up.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Assign To</Label>
                            <Select
                                value={assignForm.data.assigned_to_user_id}
                                onValueChange={(v) => assignForm.setData('assigned_to_user_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select staff member" />
                                </SelectTrigger>
                                <SelectContent>
                                    {staff.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {assignForm.errors.assigned_to_user_id && (
                                <p className="text-xs text-red-600 mt-1">
                                    {assignForm.errors.assigned_to_user_id}
                                </p>
                            )}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAssignOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={assignForm.processing} onClick={submitAssign}>
                            Assign
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Status Dialog */}
            <Dialog open={statusOpen} onOpenChange={setStatusOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Update Status</DialogTitle>
                        <DialogDescription>
                            Move the concern to the correct safeguarding workflow stage.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Status</Label>
                            <Select
                                value={statusForm.data.status}
                                onValueChange={(v) => statusForm.setData('status', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="reported">Reported</SelectItem>
                                    <SelectItem value="triaged">Triaged</SelectItem>
                                    <SelectItem value="investigating">Investigating</SelectItem>
                                    <SelectItem value="action_plan">Action Plan</SelectItem>
                                    <SelectItem value="monitoring">Monitoring</SelectItem>
                                    <SelectItem value="referred_external">Referred External</SelectItem>
                                    <SelectItem value="closed">Closed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setStatusOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={statusForm.processing} onClick={submitStatus}>
                            Update
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Close Dialog */}
            <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Close Concern</DialogTitle>
                        <DialogDescription>
                            Record the closure summary and any lessons learned before closing this concern.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Closure Summary *</Label>
                            <Textarea
                                rows={4}
                                value={closeForm.data.closure_summary}
                                onChange={(e) => closeForm.setData('closure_summary', e.target.value)}
                                placeholder="Summarise the outcome and rationale for closure..."
                            />
                            {closeForm.errors.closure_summary && (
                                <p className="text-xs text-red-600 mt-1">
                                    {closeForm.errors.closure_summary}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label>Lessons Learned</Label>
                            <Textarea
                                rows={3}
                                value={closeForm.data.lessons_learned}
                                onChange={(e) => closeForm.setData('lessons_learned', e.target.value)}
                                placeholder="Any lessons learned or recommendations for future prevention..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCloseOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={closeForm.processing}
                            onClick={submitClose}
                        >
                            Close Concern
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Risk Assessment Dialog */}
            <Dialog open={riskOpen} onOpenChange={setRiskOpen}>
                <DialogContent className="max-w-2xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>New Risk Assessment</DialogTitle>
                        <DialogDescription>
                            Capture current risks, protective factors, and review timing for this concern.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Overall Risk Level *</Label>
                                <Select
                                    value={riskForm.data.overall_risk_level}
                                    onValueChange={(v) => riskForm.setData('overall_risk_level', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select level" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                                {riskForm.errors.overall_risk_level && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {riskForm.errors.overall_risk_level}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Next Review Date</Label>
                                <Input
                                    type="date"
                                    value={riskForm.data.next_review_date}
                                    onChange={(e) => riskForm.setData('next_review_date', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div>
                                <Label>Risk to Self</Label>
                                <Select
                                    value={riskForm.data.risk_to_self}
                                    onValueChange={(v) => riskForm.setData('risk_to_self', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Risk to Others</Label>
                                <Select
                                    value={riskForm.data.risk_to_others}
                                    onValueChange={(v) => riskForm.setData('risk_to_others', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Risk from Others</Label>
                                <Select
                                    value={riskForm.data.risk_from_others}
                                    onValueChange={(v) => riskForm.setData('risk_from_others', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="critical">Critical</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div>
                            <Label>Risk Factors</Label>
                            <Textarea
                                rows={3}
                                value={riskForm.data.risk_factors}
                                onChange={(e) => riskForm.setData('risk_factors', e.target.value)}
                                placeholder="Identify specific risk factors..."
                            />
                        </div>
                        <div>
                            <Label>Protective Factors</Label>
                            <Textarea
                                rows={3}
                                value={riskForm.data.protective_factors}
                                onChange={(e) => riskForm.setData('protective_factors', e.target.value)}
                                placeholder="Identify protective factors..."
                            />
                        </div>

                        <div className="flex items-center gap-4">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="capacity_assessed"
                                    checked={riskForm.data.capacity_assessed}
                                    onCheckedChange={(v) =>
                                        riskForm.setData('capacity_assessed', v === true)
                                    }
                                />
                                <Label htmlFor="capacity_assessed" className="font-normal">
                                    Capacity Assessed
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="multi_agency_required"
                                    checked={riskForm.data.multi_agency_required}
                                    onCheckedChange={(v) =>
                                        riskForm.setData('multi_agency_required', v === true)
                                    }
                                />
                                <Label htmlFor="multi_agency_required" className="font-normal">
                                    Multi-agency Required
                                </Label>
                            </div>
                        </div>

                        {riskForm.data.capacity_assessed && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Mental Capacity</Label>
                                    <Select
                                        value={riskForm.data.mental_capacity}
                                        onValueChange={(v) => riskForm.setData('mental_capacity', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="has_capacity">Has Capacity</SelectItem>
                                            <SelectItem value="lacks_capacity">Lacks Capacity</SelectItem>
                                            <SelectItem value="fluctuating">Fluctuating</SelectItem>
                                            <SelectItem value="not_assessed">Not Assessed</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Capacity Notes</Label>
                                    <Textarea
                                        rows={2}
                                        value={riskForm.data.capacity_notes}
                                        onChange={(e) => riskForm.setData('capacity_notes', e.target.value)}
                                    />
                                </div>
                            </div>
                        )}

                        <div>
                            <Label>Immediate Actions Required</Label>
                            <Textarea
                                rows={2}
                                value={riskForm.data.immediate_actions_required}
                                onChange={(e) =>
                                    riskForm.setData('immediate_actions_required', e.target.value)
                                }
                            />
                        </div>
                        <div>
                            <Label>Protective Measures</Label>
                            <Textarea
                                rows={2}
                                value={riskForm.data.protective_measures}
                                onChange={(e) => riskForm.setData('protective_measures', e.target.value)}
                            />
                        </div>
                        <div>
                            <Label>Assessment Notes</Label>
                            <Textarea
                                rows={3}
                                value={riskForm.data.assessment_notes}
                                onChange={(e) => riskForm.setData('assessment_notes', e.target.value)}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRiskOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={riskForm.processing} onClick={submitRisk}>
                            Save Assessment
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Investigation Dialog */}
            <Dialog open={investigationOpen} onOpenChange={setInvestigationOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Start Investigation</DialogTitle>
                        <DialogDescription>
                            Set the investigation scope, lead investigator, and expected completion date.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Investigation Type *</Label>
                                <Select
                                    value={investigationForm.data.investigation_type}
                                    onValueChange={(v) =>
                                        investigationForm.setData('investigation_type', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="internal">Internal</SelectItem>
                                        <SelectItem value="external">External</SelectItem>
                                        <SelectItem value="joint">Joint</SelectItem>
                                    </SelectContent>
                                </Select>
                                {investigationForm.errors.investigation_type && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {investigationForm.errors.investigation_type}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Lead Investigator *</Label>
                                <Select
                                    value={investigationForm.data.lead_investigator_id}
                                    onValueChange={(v) =>
                                        investigationForm.setData('lead_investigator_id', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select staff" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {investigationForm.errors.lead_investigator_id && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {investigationForm.errors.lead_investigator_id}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Started At</Label>
                                <Input
                                    type="date"
                                    value={investigationForm.data.started_at}
                                    onChange={(e) =>
                                        investigationForm.setData('started_at', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <Label>Target Completion</Label>
                                <Input
                                    type="date"
                                    value={investigationForm.data.target_completion_date}
                                    onChange={(e) =>
                                        investigationForm.setData('target_completion_date', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Terms of Reference</Label>
                            <Textarea
                                rows={3}
                                value={investigationForm.data.terms_of_reference}
                                onChange={(e) =>
                                    investigationForm.setData('terms_of_reference', e.target.value)
                                }
                                placeholder="Define the scope and terms of the investigation..."
                            />
                        </div>
                        <div>
                            <Label>Methodology</Label>
                            <Textarea
                                rows={3}
                                value={investigationForm.data.methodology}
                                onChange={(e) =>
                                    investigationForm.setData('methodology', e.target.value)
                                }
                                placeholder="Describe the investigation methodology..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setInvestigationOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            disabled={investigationForm.processing}
                            onClick={submitInvestigation}
                        >
                            Start Investigation
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* External Report Dialog */}
            <Dialog open={externalOpen} onOpenChange={setExternalOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Log External Report</DialogTitle>
                        <DialogDescription>
                            Record the authority, contact method, and summary of the external report.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Authority Type *</Label>
                                <Select
                                    value={externalForm.data.authority_type}
                                    onValueChange={(v) => externalForm.setData('authority_type', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select authority" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="police">NZ Police</SelectItem>
                                        <SelectItem value="health_nz">Health NZ</SelectItem>
                                        <SelectItem value="worksafe">WorkSafe NZ</SelectItem>
                                        <SelectItem value="privacy_commissioner">
                                            Privacy Commissioner
                                        </SelectItem>
                                        <SelectItem value="hdc">
                                            Health &amp; Disability Commissioner
                                        </SelectItem>
                                        <SelectItem value="oranga_tamariki">Oranga Tamariki</SelectItem>
                                        <SelectItem value="other">Other</SelectItem>
                                    </SelectContent>
                                </Select>
                                {externalForm.errors.authority_type && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {externalForm.errors.authority_type}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Report Method *</Label>
                                <Select
                                    value={externalForm.data.report_method}
                                    onValueChange={(v) => externalForm.setData('report_method', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select method" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="phone">Phone</SelectItem>
                                        <SelectItem value="email">Email</SelectItem>
                                        <SelectItem value="online_form">Online Form</SelectItem>
                                        <SelectItem value="in_person">In Person</SelectItem>
                                        <SelectItem value="letter">Letter</SelectItem>
                                    </SelectContent>
                                </Select>
                                {externalForm.errors.report_method && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {externalForm.errors.report_method}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Authority Name</Label>
                                <Input
                                    value={externalForm.data.authority_name}
                                    onChange={(e) =>
                                        externalForm.setData('authority_name', e.target.value)
                                    }
                                    placeholder="e.g. Counties Manukau Police"
                                />
                            </div>
                            <div>
                                <Label>Authority Contact</Label>
                                <Input
                                    value={externalForm.data.authority_contact}
                                    onChange={(e) =>
                                        externalForm.setData('authority_contact', e.target.value)
                                    }
                                    placeholder="Contact person or reference"
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Reported At</Label>
                            <Input
                                type="date"
                                value={externalForm.data.reported_at}
                                onChange={(e) => externalForm.setData('reported_at', e.target.value)}
                            />
                        </div>
                        <div>
                            <Label>Report Summary</Label>
                            <Textarea
                                rows={4}
                                value={externalForm.data.report_summary}
                                onChange={(e) =>
                                    externalForm.setData('report_summary', e.target.value)
                                }
                                placeholder="Summarise what was reported and any immediate advice received..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setExternalOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={externalForm.processing} onClick={submitExternal}>
                            Log Report
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Action Plan Dialog */}
            <Dialog open={actionOpen} onOpenChange={setActionOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add Action</DialogTitle>
                        <DialogDescription>
                            Create a follow-up action with an owner, priority, and due date.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Action Description *</Label>
                            <Textarea
                                rows={3}
                                value={actionForm.data.action_description}
                                onChange={(e) =>
                                    actionForm.setData('action_description', e.target.value)
                                }
                                placeholder="Describe the action to be taken..."
                            />
                            {actionForm.errors.action_description && (
                                <p className="text-xs text-red-600 mt-1">
                                    {actionForm.errors.action_description}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Action Type *</Label>
                                <Select
                                    value={actionForm.data.action_type}
                                    onValueChange={(v) => actionForm.setData('action_type', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="protective_measure">
                                            Protective Measure
                                        </SelectItem>
                                        <SelectItem value="support_service">
                                            Support Service
                                        </SelectItem>
                                        <SelectItem value="policy_change">Policy Change</SelectItem>
                                        <SelectItem value="training">Training</SelectItem>
                                        <SelectItem value="supervision">Supervision</SelectItem>
                                        <SelectItem value="monitoring">Monitoring</SelectItem>
                                        <SelectItem value="referral">Referral</SelectItem>
                                        <SelectItem value="investigation">Investigation</SelectItem>
                                        <SelectItem value="other">Other</SelectItem>
                                    </SelectContent>
                                </Select>
                                {actionForm.errors.action_type && (
                                    <p className="text-xs text-red-600 mt-1">
                                        {actionForm.errors.action_type}
                                    </p>
                                )}
                            </div>
                            <div>
                                <Label>Priority</Label>
                                <Select
                                    value={actionForm.data.priority}
                                    onValueChange={(v) => actionForm.setData('priority', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select priority" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="1">1 - Highest</SelectItem>
                                        <SelectItem value="2">2 - High</SelectItem>
                                        <SelectItem value="3">3 - Medium</SelectItem>
                                        <SelectItem value="4">4 - Low</SelectItem>
                                        <SelectItem value="5">5 - Lowest</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label>Assign To</Label>
                                <Select
                                    value={actionForm.data.assigned_to_user_id}
                                    onValueChange={(v) =>
                                        actionForm.setData('assigned_to_user_id', v)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select staff" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Due Date</Label>
                                <Input
                                    type="date"
                                    value={actionForm.data.due_date}
                                    onChange={(e) => actionForm.setData('due_date', e.target.value)}
                                />
                            </div>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setActionOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={actionForm.processing} onClick={submitAction}>
                            Add Action
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Complete Action Dialog */}
            <Dialog
                open={completeActionId !== null}
                onOpenChange={(open) => {
                    if (!open) setCompleteActionId(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Complete Action</DialogTitle>
                        <DialogDescription>
                            Confirm the action is complete and note any remaining follow-up.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Completion Notes</Label>
                            <Textarea
                                rows={3}
                                value={completeForm.data.completion_notes}
                                onChange={(e) =>
                                    completeForm.setData('completion_notes', e.target.value)
                                }
                                placeholder="Describe the outcome and any follow-up needed..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCompleteActionId(null)}>
                            Cancel
                        </Button>
                        <Button disabled={completeForm.processing} onClick={submitComplete}>
                            Mark Complete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Acknowledgment Dialog */}
            <Dialog
                open={ackReportId !== null}
                onOpenChange={(open) => {
                    if (!open) setAckReportId(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Record Acknowledgment</DialogTitle>
                        <DialogDescription>
                            Capture when the authority acknowledged the report and any reference number provided.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div>
                            <Label>Acknowledgment Date</Label>
                            <Input
                                type="date"
                                value={ackForm.data.acknowledgment_date}
                                onChange={(e) =>
                                    ackForm.setData('acknowledgment_date', e.target.value)
                                }
                            />
                        </div>
                        <div>
                            <Label>Reference Number</Label>
                            <Input
                                value={ackForm.data.acknowledgment_reference}
                                onChange={(e) =>
                                    ackForm.setData('acknowledgment_reference', e.target.value)
                                }
                                placeholder="Authority reference number"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAckReportId(null)}>
                            Cancel
                        </Button>
                        <Button disabled={ackForm.processing} onClick={submitAck}>
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
