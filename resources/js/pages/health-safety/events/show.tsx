import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs } from '@/components/ui/tabs';
import { StatusBadge } from '@/components/ui/status-badge';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import { EventTimeline } from '@/components/health-safety/event-timeline';
import {
    Shield, AlertTriangle, Clock, FileText, CheckCircle2,
    ShieldAlert, User, MapPin, Calendar, ChevronRight,
    ClipboardList, Search as SearchIcon, History,
} from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface Investigation {
    id: number;
    reference_number: string;
    investigation_type: string;
    status: string;
    methodology: string | null;
    lead_investigator_name: string | null;
    started_at: string | null;
    target_completion_date: string | null;
    completed_at: string | null;
    is_overdue: boolean;
    has_findings: boolean;
    has_recommendations: boolean;
    recommendation_count: number;
    immediate_causes: Array<{ description: string; category?: string }> | null;
    root_causes: Array<{ description: string; category?: string }> | null;
    contributing_factors: Array<{ description: string; factor_type?: string }> | null;
    findings_summary: string | null;
    recommendations: Array<{ description: string; priority?: string; target_area?: string }> | null;
    lessons_learned: string | null;
}

interface CorrectiveAction {
    id: number;
    reference_number: string;
    title: string;
    action_type: string;
    priority: string;
    status: string;
    assigned_to_name: string | null;
    due_date: string | null;
    is_overdue: boolean;
    completed_at: string | null;
    verified_at: string | null;
    effectiveness_confirmed: boolean | null;
}

interface RiskAssessment {
    id: number;
    reference_number: string;
    title: string;
    status: string;
    risk_score: number;
    risk_level: string;
    residual_risk_score: number | null;
    residual_risk_level: string | null;
    risk_acceptable: boolean | null;
    assessed_by_name: string | null;
    review_due_at: string | null;
    is_due_for_review: boolean;
}

interface HsEventDetail {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    site_name: string | null;
    site_id: number | null;
    client_name: string | null;
    client_id: number | null;
    staff_name: string | null;
    staff_id: number | null;
    asset_name: string | null;
    asset_id: number | null;
    shift_id: number | null;
    worksafe_notifiable: boolean;
    worksafe_status: string | null;
    worksafe_reference: string | null;
    investigation_required: boolean;
    control_room_alert: { id: number; severity: string; status: string } | null;
    closed_at: string | null;
    closure_summary: string | null;
    created_by_name: string | null;
    source_type: string;
    source_id: number;
    can_create_investigation: boolean;
    has_open_corrective_actions: boolean;
    all_corrective_actions_resolved: boolean;
}

interface Props {
    event: HsEventDetail;
    investigations: Investigation[];
    corrective_actions: CorrectiveAction[];
    risk_assessments: RiskAssessment[];
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const CATEGORY_LABELS: Record<string, string> = {
    incident: 'Incident', near_miss: 'Near Miss', hazard: 'Hazard',
    injury: 'Injury', exposure: 'Exposure', restraint: 'Restraint',
    safeguarding: 'Safeguarding', vehicle_incident: 'Vehicle Incident',
    drill_failure: 'Drill Failure', inspection_failure: 'Inspection Failure',
    equipment_fault: 'Equipment Fault',
};

const fmtDate = (iso: string | null) => {
    if (!iso) return '-';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
};

const fmtDateTime = (iso: string | null) => {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

const DetailRow = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div className="flex justify-between gap-3 py-1.5">
        <span className="text-muted-foreground shrink-0">{label}</span>
        <span className="text-right">{children}</span>
    </div>
);

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function HsEventShow({ event, investigations, corrective_actions, risk_assessments }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Events', href: '/health-safety/events' },
        { title: event.reference_number, href: `/health-safety/events/${event.id}` },
    ];

    const overdueActions = corrective_actions.filter(a => a.is_overdue);
    const awaitingVerification = corrective_actions.filter(a => a.status === 'completed');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${event.reference_number} - H&S Event`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">

                {/* ── Hero Summary ── */}
                <Card>
                    <CardContent className="p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-bold">{event.reference_number}</h1>
                                    <StatusBadge status={event.severity} />
                                    <StatusBadge status={event.status} />
                                    {event.worksafe_notifiable && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2.5 py-0.5 text-xs font-semibold text-status-critical border border-status-critical/30">
                                            <ShieldAlert className="h-3 w-3" /> WorkSafe Notifiable
                                        </span>
                                    )}
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {CATEGORY_LABELS[event.event_category] ?? event.event_category}
                                    {event.site_name && <> at <Link href={`/sites/${event.site_id}`} className="text-status-info hover:underline">{event.site_name}</Link></>}
                                    {event.client_name && <> involving <Link href={`/clients/${event.client_id}`} className="text-status-info hover:underline">{event.client_name}</Link></>}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground">
                                    {event.occurred_at && <span className="flex items-center gap-1"><Calendar className="h-3 w-3" /> Occurred {fmtDateTime(event.occurred_at)}</span>}
                                    {event.reported_at && <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> Reported {fmtDateTime(event.reported_at)}</span>}
                                    {event.staff_name && <span className="flex items-center gap-1"><User className="h-3 w-3" /> {event.staff_name}</span>}
                                </div>
                            </div>

                            {/* Right side: what needs attention */}
                            <div className="flex shrink-0 flex-col gap-2">
                                {overdueActions.length > 0 && (
                                    <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical">
                                        <AlertTriangle className="mr-1 inline h-4 w-4" />
                                        {overdueActions.length} overdue action{overdueActions.length !== 1 ? 's' : ''}
                                    </div>
                                )}
                                {awaitingVerification.length > 0 && (
                                    <div className="rounded-lg border border-status-info/30 bg-status-info-bg px-3 py-2 text-sm text-status-info">
                                        <CheckCircle2 className="mr-1 inline h-4 w-4" />
                                        {awaitingVerification.length} awaiting verification
                                    </div>
                                )}
                                {event.investigation_required && !investigations.length && (
                                    <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm text-status-warning">
                                        <SearchIcon className="mr-1 inline h-4 w-4" />
                                        Investigation required
                                    </div>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Tabbed Content ── */}
                <Tabs tabs={[
                    {
                        key: 'overview',
                        label: 'Overview',
                        content: (
                            <div className="grid gap-4 md:grid-cols-2">
                                {/* Event Details */}
                                <Card>
                                    <CardHeader><CardTitle className="text-base">Event Details</CardTitle></CardHeader>
                                    <CardContent className="space-y-0 text-sm">
                                        <DetailRow label="Category">{CATEGORY_LABELS[event.event_category] ?? event.event_category}</DetailRow>
                                        <DetailRow label="Severity"><StatusBadge status={event.severity} /></DetailRow>
                                        <DetailRow label="Status"><StatusBadge status={event.status} /></DetailRow>
                                        <DetailRow label="Occurred">{fmtDateTime(event.occurred_at)}</DetailRow>
                                        <DetailRow label="Reported">{fmtDateTime(event.reported_at)}</DetailRow>
                                        <DetailRow label="Source">{event.source_type} #{event.source_id}</DetailRow>
                                        <DetailRow label="Reported by">{event.created_by_name ?? '-'}</DetailRow>
                                    </CardContent>
                                </Card>

                                {/* Context & Linkages */}
                                <Card>
                                    <CardHeader><CardTitle className="text-base">Context</CardTitle></CardHeader>
                                    <CardContent className="space-y-0 text-sm">
                                        <DetailRow label="Site">{event.site_name ? <Link href={`/sites/${event.site_id}`} className="text-status-info hover:underline">{event.site_name}</Link> : '-'}</DetailRow>
                                        <DetailRow label="Client">{event.client_name ? <Link href={`/clients/${event.client_id}`} className="text-status-info hover:underline">{event.client_name}</Link> : '-'}</DetailRow>
                                        <DetailRow label="Staff">{event.staff_name ?? '-'}</DetailRow>
                                        <DetailRow label="Asset">{event.asset_name ?? '-'}</DetailRow>
                                        {event.worksafe_notifiable && (
                                            <>
                                                <DetailRow label="WorkSafe Status"><StatusBadge status={event.worksafe_status ?? 'pending'} /></DetailRow>
                                                <DetailRow label="WorkSafe Ref">{event.worksafe_reference ?? 'Pending'}</DetailRow>
                                            </>
                                        )}
                                        {event.control_room_alert && (
                                            <DetailRow label="CR Alert">
                                                <Link href={`/control-room/alerts/${event.control_room_alert.id}`} className="text-status-info hover:underline">
                                                    Alert #{event.control_room_alert.id}
                                                </Link>
                                            </DetailRow>
                                        )}
                                    </CardContent>
                                </Card>

                                {event.closed_at && (
                                    <Card className="md:col-span-2">
                                        <CardHeader><CardTitle className="text-base">Closure</CardTitle></CardHeader>
                                        <CardContent className="text-sm">
                                            <DetailRow label="Closed">{fmtDateTime(event.closed_at)}</DetailRow>
                                            {event.closure_summary && <p className="mt-2 text-muted-foreground">{event.closure_summary}</p>}
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'investigations',
                        label: `Investigations (${investigations.length})`,
                        content: (
                            <div className="space-y-4">
                                {investigations.map(inv => (
                                    <Card key={inv.id}>
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="min-w-0">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-semibold">{inv.reference_number}</span>
                                                        <StatusBadge status={inv.status} />
                                                        {inv.is_overdue && <StatusBadge status="overdue" />}
                                                        <Badge variant="outline" className="text-xs">{inv.investigation_type.replace(/_/g, ' ')}</Badge>
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                        {inv.lead_investigator_name && <span><User className="mr-0.5 inline h-3 w-3" />{inv.lead_investigator_name}</span>}
                                                        {inv.methodology && <span>Method: {inv.methodology.replace(/_/g, ' ')}</span>}
                                                        {inv.started_at && <span>Started {fmtDate(inv.started_at)}</span>}
                                                        {inv.target_completion_date && <span>Due {fmtDate(inv.target_completion_date)}</span>}
                                                    </div>
                                                </div>
                                            </div>

                                            {inv.has_findings && (
                                                <div className="mt-4 space-y-3 border-t pt-3">
                                                    {inv.findings_summary && (
                                                        <div>
                                                            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Findings</p>
                                                            <p className="mt-1 text-sm">{inv.findings_summary}</p>
                                                        </div>
                                                    )}
                                                    {inv.immediate_causes && inv.immediate_causes.length > 0 && (
                                                        <div>
                                                            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Immediate Causes</p>
                                                            <ul className="mt-1 list-disc pl-4 text-sm space-y-0.5">
                                                                {inv.immediate_causes.map((c, i) => <li key={i}>{c.description}</li>)}
                                                            </ul>
                                                        </div>
                                                    )}
                                                    {inv.root_causes && inv.root_causes.length > 0 && (
                                                        <div>
                                                            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Root Causes</p>
                                                            <ul className="mt-1 list-disc pl-4 text-sm space-y-0.5">
                                                                {inv.root_causes.map((c, i) => <li key={i}>{c.description}</li>)}
                                                            </ul>
                                                        </div>
                                                    )}
                                                    {inv.recommendations && inv.recommendations.length > 0 && (
                                                        <div>
                                                            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Recommendations ({inv.recommendation_count})</p>
                                                            <ul className="mt-1 space-y-1">
                                                                {inv.recommendations.map((r, i) => (
                                                                    <li key={i} className="flex items-start gap-2 text-sm">
                                                                        <StatusBadge status={r.priority ?? 'medium'} className="mt-0.5 shrink-0" />
                                                                        <span>{r.description}</span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                                {investigations.length === 0 && (
                                    <Card>
                                        <CardContent className="py-12 text-center text-muted-foreground">
                                            <SearchIcon className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No investigations</p>
                                            <p className="mt-1 text-sm">{event.investigation_required ? 'An investigation is required for this event.' : 'No investigation has been initiated.'}</p>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'actions',
                        label: `Actions (${corrective_actions.length})`,
                        content: (
                            <div className="space-y-2">
                                {corrective_actions.length > 0 ? (
                                    <Card>
                                        <CardContent className="p-0">
                                            <table className="w-full text-sm">
                                                <thead className="border-b bg-muted/50">
                                                    <tr>
                                                        <th className="px-4 py-3 text-left font-medium">Action</th>
                                                        <th className="px-4 py-3 text-left font-medium">Priority</th>
                                                        <th className="px-4 py-3 text-left font-medium">Status</th>
                                                        <th className="px-4 py-3 text-left font-medium">Owner</th>
                                                        <th className="px-4 py-3 text-left font-medium">Due</th>
                                                        <th className="px-4 py-3 text-left font-medium">Verified</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y">
                                                    {corrective_actions.map(action => (
                                                        <tr key={action.id} className={`hover:bg-muted/30 ${action.is_overdue ? 'bg-status-critical-bg' : ''}`}>
                                                            <td className="px-4 py-3">
                                                                <div className="font-medium">{action.reference_number}</div>
                                                                <div className="text-muted-foreground text-xs mt-0.5 max-w-xs truncate">{action.title}</div>
                                                            </td>
                                                            <td className="px-4 py-3"><StatusBadge status={action.priority} /></td>
                                                            <td className="px-4 py-3">
                                                                <StatusBadge status={action.is_overdue ? 'overdue' : action.status} />
                                                            </td>
                                                            <td className="px-4 py-3 text-muted-foreground">{action.assigned_to_name ?? '-'}</td>
                                                            <td className="px-4 py-3 text-muted-foreground">{fmtDate(action.due_date)}</td>
                                                            <td className="px-4 py-3">
                                                                {action.verified_at ? (
                                                                    <span className="flex items-center gap-1 text-status-success">
                                                                        <CheckCircle2 className="h-4 w-4" />
                                                                        {action.effectiveness_confirmed ? 'Effective' : 'Ineffective'}
                                                                    </span>
                                                                ) : action.completed_at ? (
                                                                    <span className="text-status-info text-xs">Awaiting verification</span>
                                                                ) : (
                                                                    <span className="text-muted-foreground">-</span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <Card>
                                        <CardContent className="py-12 text-center text-muted-foreground">
                                            <ClipboardList className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No corrective actions</p>
                                            <p className="mt-1 text-sm">Actions will be created from investigation recommendations.</p>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'risk',
                        label: `Risk (${risk_assessments.length})`,
                        content: (
                            <div className="space-y-4">
                                {risk_assessments.map(ra => (
                                    <Card key={ra.id}>
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-semibold">{ra.reference_number}</span>
                                                        <StatusBadge status={ra.status} />
                                                        {ra.is_due_for_review && <StatusBadge status="overdue" label="Review due" />}
                                                    </div>
                                                    <p className="mt-1 text-sm text-muted-foreground">{ra.title}</p>
                                                    <div className="mt-3 flex gap-3 text-center">
                                                        <div>
                                                            <div className="text-xs text-muted-foreground">Inherent</div>
                                                            <div className={`mt-1 rounded-md px-3 py-1 text-sm font-bold ${ra.risk_level === 'extreme' || ra.risk_level === 'high' ? 'bg-status-critical-bg text-status-critical' : ra.risk_level === 'medium' ? 'bg-status-warning-bg text-status-warning' : 'bg-status-success-bg text-status-success'}`}>
                                                                {ra.risk_score}
                                                            </div>
                                                            <div className="mt-0.5 text-xs capitalize text-muted-foreground">{ra.risk_level}</div>
                                                        </div>
                                                        {ra.residual_risk_score != null && (
                                                            <>
                                                                <div className="flex items-center text-muted-foreground"><ChevronRight className="h-4 w-4" /></div>
                                                                <div>
                                                                    <div className="text-xs text-muted-foreground">Residual</div>
                                                                    <div className={`mt-1 rounded-md px-3 py-1 text-sm font-bold ${ra.residual_risk_level === 'extreme' || ra.residual_risk_level === 'high' ? 'bg-status-critical-bg text-status-critical' : ra.residual_risk_level === 'medium' ? 'bg-status-warning-bg text-status-warning' : 'bg-status-success-bg text-status-success'}`}>
                                                                        {ra.residual_risk_score}
                                                                    </div>
                                                                    <div className="mt-0.5 text-xs capitalize text-muted-foreground">{ra.residual_risk_level}</div>
                                                                </div>
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                {/* Risk Matrix Visual */}
                                                {'likelihood' in ra && (ra as any).likelihood && (
                                                    <div className="shrink-0">
                                                        <RiskMatrix
                                                            likelihood={(ra as any).likelihood}
                                                            consequence={(ra as any).consequence}
                                                            residualLikelihood={(ra as any).residual_likelihood}
                                                            residualConsequence={(ra as any).residual_consequence}
                                                            compact
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))}
                                {risk_assessments.length === 0 && (
                                    <Card>
                                        <CardContent className="py-12 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No linked risk assessments</p>
                                        </CardContent>
                                    </Card>
                                )}
                            </div>
                        ),
                    },
                    {
                        key: 'timeline',
                        label: 'Timeline',
                        icon: <History className="h-4 w-4" />,
                        content: (
                            <Card>
                                <CardHeader><CardTitle className="text-base">Event Timeline</CardTitle></CardHeader>
                                <CardContent>
                                    <EventTimeline
                                        reportedAt={event.reported_at}
                                        occurredAt={event.occurred_at}
                                        closedAt={event.closed_at}
                                        investigations={investigations}
                                        correctiveActions={corrective_actions}
                                    />
                                </CardContent>
                            </Card>
                        ),
                    },
                ]} />
            </div>
        </AppLayout>
    );
}
