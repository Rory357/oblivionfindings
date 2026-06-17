import { Button } from '@/components/ui/button';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { InfoCard } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    FileText,
    LinkIcon,
    ListTodo,
    Paperclip,
    RadioTower,
    RotateCcw,
    Search,
    Send,
    ShieldAlert,
    User,
    Users,
} from 'lucide-react';
import { useState, type ComponentType } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors IncidentController::buildIncidentDetail()           */
/* ------------------------------------------------------------------ */

type JsonCause = { description?: string; category?: string };
type JsonRec = { description?: string; priority?: string; target_area?: string };

export type IncidentDetail = {
    id: number;
    type: string;
    source: string;
    interactive: boolean;
    severity: string;
    status: string;
    occurred_at: string | null;
    description: string | null;
    immediate_action_taken: string | null;
    witnesses: string | null;
    is_notifiable: boolean;
    worksafe_notification_status: string | null;
    worksafe_notified_at: string | null;
    worksafe_reference: string | null;
    potential_severity: string | null;
    potential_consequence: string | null;
    investigation_status: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    closed_at: string | null;
    closed_outcome: string | null;
    closed_notes: string | null;
    reopened_at: string | null;
    reopened_reason: string | null;
    control_room_alert_id: number | null;
    client: { id: number; first_name: string; last_name: string; site: string | null } | null;
    reporter: { name: string; email: string } | null;
    investigator: string | null;
    attachments: Array<{
        id: number;
        name: string;
        mime: string | null;
        size: number | null;
        portal_visible: boolean;
        notes: string | null;
        uploaded_by: string | null;
        created_at: string | null;
        download_url: string;
    }>;
    followups: Array<{
        id: number;
        notes: string | null;
        assigned_to: string | null;
        due_at: string | null;
        completed_at: string | null;
        created_by: string | null;
        overdue: boolean;
    }>;
    control_room_alert: { id: number; status: string; severity: string; alert_type: string; triggered_at: string | null; resolved_at: string | null } | null;
    hs_event: {
        id: number;
        reference_number: string;
        status: string;
        investigation_required: boolean;
        investigation: {
            reference_number: string;
            status: string;
            methodology: string | null;
            root_causes: JsonCause[] | null;
            contributing_factors: JsonCause[] | null;
            recommendations: JsonRec[] | null;
            lessons_learned: string | null;
        } | null;
        corrective_actions: Array<{ id: number; reference_number: string; title: string; status: string; priority: string; assigned_to: string | null; due_date: string | null }>;
    } | null;
    can: { update: boolean; submit: boolean; review: boolean; close: boolean; reopen: boolean; followupsComplete: boolean };
};

type SectionKey = 'overview' | 'timeline' | 'photos' | 'followups' | 'investigation' | 'linked';

/* ------------------------------------------------------------------ */
/*  Tokens                                                             */
/* ------------------------------------------------------------------ */

const DOT: Record<string, string> = {
    neutral: 'bg-muted-foreground',
    info: 'bg-status-info',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    primary: 'bg-primary',
};

const SEV_LABEL: Record<string, string> = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };
const SEV_TONE: Record<string, string> = { low: 'success', medium: 'warning', high: 'critical', critical: 'critical' };
const STATUS_LABEL: Record<string, string> = { draft: 'Draft', submitted: 'Submitted', reviewed: 'Reviewed', closed: 'Closed' };

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function fmtSize(bytes: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function IncidentDetailDialog({ detail, open, onClose }: { detail: IncidentDetail; open: boolean; onClose: () => void }) {
    const [section, setSection] = useState<SectionKey>('overview');

    const d = detail;
    const clientName = d.client ? `${d.client.first_name} ${d.client.last_name}`.trim() : 'No client linked';
    const isNearMiss = d.type === 'near_miss';
    const openFollowups = d.followups.filter((f) => !f.completed_at).length;

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'What happened', icon: FileText },
        { key: 'timeline', label: 'Timeline', blurb: 'Audit trail', icon: Clock },
        { key: 'photos', label: 'Photos & documents', blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`, icon: Paperclip },
        { key: 'followups', label: 'Follow-ups', blurb: openFollowups > 0 ? `${openFollowups} open` : 'all complete', icon: ListTodo },
        { key: 'investigation', label: 'Investigation', blurb: d.hs_event ? d.hs_event.reference_number : 'no H&S event', icon: Search },
        { key: 'linked', label: 'Linked records', blurb: 'CR · H&S · client', icon: LinkIcon },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    // Both endpoints return back() -> Inertia follows the redirect to the current
    // URL (which still carries ?incident=), so the dialog + list refresh together.
    const submit = () => router.post(`/incidents/${d.id}/submit`, {}, { preserveScroll: true });
    const completeFollowup = (fid: number) => router.post(`/incidents/${d.id}/followups/${fid}/complete`, {}, { preserveScroll: true });

    const footerEnd = (
        <div className="flex flex-wrap items-center gap-2">
            <Link href={`/incidents/${d.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.can.submit && d.status === 'draft' ? (
                <Button size="sm" onClick={submit}>
                    <Send className="mr-1.5 h-4 w-4" /> Submit for review
                </Button>
            ) : null}
        </div>
    );

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className={`inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium`}>
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[d.severity] ?? 'neutral']}`} />
                {SEV_LABEL[d.severity] ?? d.severity}
            </span>
            <span className="text-muted-foreground">{STATUS_LABEL[d.status] ?? d.status}</span>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Incident INC-${d.id}`}
            description={`${titleCase(d.type)} — ${clientName}`}
            railIcon={isNearMiss ? ShieldAlert : AlertTriangle}
            railTitle={clientName}
            railSub={`INC-${d.id} · ${titleCase(d.type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {section === 'overview' ? <OverviewSection d={d} isNearMiss={isNearMiss} /> : null}
            {section === 'timeline' ? <TimelineSection d={d} /> : null}
            {section === 'photos' ? <PhotosSection d={d} /> : null}
            {section === 'followups' ? <FollowupsSection d={d} onComplete={completeFollowup} /> : null}
            {section === 'investigation' ? <InvestigationSection d={d} /> : null}
            {section === 'linked' ? <LinkedSection d={d} clientName={clientName} /> : null}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, isNearMiss }: { d: IncidentDetail; isNearMiss: boolean }) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {d.is_notifiable ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="crit">
                        <span className="font-semibold">WorkSafe NZ notifiable event.</span>{' '}
                        {d.worksafe_notification_status === 'notified'
                            ? `Notified${d.worksafe_notified_at ? ` ${formatDateTime(d.worksafe_notified_at)}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''}.`
                            : 'Notification to WorkSafe NZ is still pending — notify from the full page.'}
                    </InfoCard>
                </div>
            ) : null}

            <ReviewCard icon={FileText} title="What happened" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.description || '—'}</p>
            </ReviewCard>

            <ReviewCard icon={Users} title="People">
                <ReviewRow label="Client" value={d.client ? `${d.client.first_name} ${d.client.last_name}` : undefined} />
                <ReviewRow label="Reported by" value={d.reporter?.name} />
                <ReviewRow label="Witnesses" value={d.witnesses} />
            </ReviewCard>

            <ReviewCard icon={Search} title="Classification">
                <ReviewRow label="Source" value={titleCase(d.source)} />
                <ReviewRow label={isNearMiss ? 'Potential severity' : 'Severity'} value={isNearMiss ? (d.potential_severity ? SEV_LABEL[d.potential_severity] : undefined) : SEV_LABEL[d.severity]} />
                {isNearMiss ? <ReviewRow label="Could have caused" value={d.potential_consequence} /> : null}
                <ReviewRow label="H&S event" value={d.hs_event?.reference_number} />
            </ReviewCard>

            <ReviewCard icon={CheckCircle2} title="Immediate actions" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.immediate_action_taken || '—'}</p>
            </ReviewCard>
        </div>
    );
}

function TimelineSection({ d }: { d: IncidentDetail }) {
    type TLEvent = { at: string; label: string; tone: string; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (d.occurred_at) events.push({ at: d.occurred_at, label: 'Incident occurred', tone: 'neutral', icon: AlertTriangle });
    if (d.control_room_alert?.triggered_at) events.push({ at: d.control_room_alert.triggered_at, label: 'Control Room alert raised', tone: 'critical', icon: RadioTower });
    if (d.submitted_at) events.push({ at: d.submitted_at, label: 'Submitted for review', tone: 'info', icon: Send });
    if (d.reviewed_at) events.push({ at: d.reviewed_at, label: 'Reviewed', tone: 'primary', icon: CheckCircle2 });
    if (d.reopened_at) events.push({ at: d.reopened_at, label: `Reopened${d.reopened_reason ? ` · ${d.reopened_reason}` : ''}`, tone: 'warning', icon: RotateCcw });
    if (d.closed_at) events.push({ at: d.closed_at, label: `Closed${d.closed_outcome ? ` · ${d.closed_outcome}` : ''}`, tone: 'success', icon: CheckCircle2 });
    events.sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime());

    if (!events.length) return <p className="text-sm text-muted-foreground">No timeline events yet.</p>;

    return (
        <ol className="relative ml-2 border-l border-border">
            {events.map((e, i) => {
                const Icon = e.icon;
                return (
                    <li key={i} className="mb-5 ml-5">
                        <span className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${DOT[e.tone] ?? DOT.neutral}`} />
                        <div className="flex items-center gap-2">
                            <Icon className="h-4 w-4 text-muted-foreground" />
                            <span className="text-sm font-medium text-foreground">{e.label}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">{formatDateTime(e.at)}</p>
                    </li>
                );
            })}
        </ol>
    );
}

function PhotosSection({ d }: { d: IncidentDetail }) {
    if (!d.attachments.length) {
        return (
            <div className="rounded-xl border border-dashed border-border py-12 text-center">
                <Paperclip className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                <p className="text-sm text-muted-foreground">No photos or documents attached.</p>
                <p className="mt-1 text-xs text-muted-foreground/70">Upload from the full page while the incident is a draft.</p>
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-2">
            {d.attachments.map((a) => (
                <div key={a.id} className="flex items-center gap-3 rounded-lg border border-border p-3">
                    <FileText className="h-5 w-5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">{a.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {fmtSize(a.size)}
                            {a.uploaded_by ? ` · ${a.uploaded_by}` : ''}
                            {a.created_at ? ` · ${formatDateTime(a.created_at)}` : ''}
                            {a.portal_visible ? ' · shared to portal' : ''}
                        </p>
                        {a.notes ? <p className="mt-0.5 text-xs text-muted-foreground">{a.notes}</p> : null}
                    </div>
                    <a href={a.download_url} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                        <Download className="h-3.5 w-3.5" /> Download
                    </a>
                </div>
            ))}
        </div>
    );
}

function FollowupsSection({ d, onComplete }: { d: IncidentDetail; onComplete: (id: number) => void }) {
    if (!d.followups.length) {
        return (
            <div className="rounded-xl border border-dashed border-border py-12 text-center">
                <ListTodo className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                <p className="text-sm text-muted-foreground">No follow-ups on this incident.</p>
                <p className="mt-1 text-xs text-muted-foreground/70">Add one from the full page.</p>
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-2">
            {d.followups.map((f) => (
                <div key={f.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                    <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${f.completed_at ? 'text-status-success' : f.overdue ? 'text-status-critical' : 'text-status-warning'}`} />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm text-foreground">{f.notes || 'Follow-up task'}</p>
                        <p className="text-xs text-muted-foreground">
                            {f.assigned_to ? `${f.assigned_to}` : 'Unassigned'}
                            {f.due_at ? ` · due ${formatDateTime(f.due_at)}` : ''}
                            {f.completed_at ? ` · completed ${formatDateTime(f.completed_at)}` : f.overdue ? ' · overdue' : ''}
                        </p>
                    </div>
                    {!f.completed_at && d.can.followupsComplete ? (
                        <Button variant="outline" size="sm" onClick={() => onComplete(f.id)}>
                            <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Complete
                        </Button>
                    ) : f.completed_at ? (
                        <span className="inline-flex items-center gap-1 text-xs font-medium text-status-success">
                            <CheckCircle2 className="h-3.5 w-3.5" /> Done
                        </span>
                    ) : null}
                </div>
            ))}
        </div>
    );
}

function InvestigationSection({ d }: { d: IncidentDetail }) {
    if (!d.hs_event) {
        return (
            <div className="rounded-xl border border-dashed border-border py-12 text-center">
                <Search className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                <p className="text-sm text-muted-foreground">No Health &amp; Safety event recorded for this incident.</p>
            </div>
        );
    }
    const ev = d.hs_event;
    const inv = ev.investigation;
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                <div>
                    <p className="text-sm font-semibold text-foreground">{ev.reference_number}</p>
                    <p className="text-xs text-muted-foreground">H&amp;S event · {titleCase(ev.status)}{ev.investigation_required ? ' · investigation required' : ''}</p>
                </div>
                <Link href={`/health-safety/events/${ev.id}`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                    <ExternalLink className="h-3.5 w-3.5" /> Open in Health &amp; Safety
                </Link>
            </div>

            {inv ? (
                <ReviewCard icon={Search} title={`Investigation ${inv.reference_number}`} span>
                    <ReviewRow label="Status" value={titleCase(inv.status)} />
                    <ReviewRow label="Methodology" value={inv.methodology ? inv.methodology.replace(/_/g, '-') : undefined} />
                    {inv.root_causes?.length ? <ReviewRow label="Root causes" value={inv.root_causes.map((c) => c.description).filter(Boolean).join('; ')} /> : null}
                    {inv.recommendations?.length ? <ReviewRow label="Recommendations" value={inv.recommendations.map((r) => r.description).filter(Boolean).join('; ')} /> : null}
                    {inv.lessons_learned ? <ReviewRow label="Lessons learned" value={inv.lessons_learned} /> : null}
                </ReviewCard>
            ) : (
                <p className="text-sm text-muted-foreground">No investigation has been opened yet.</p>
            )}

            <div>
                <div className="mb-2 flex items-center justify-between">
                    <p className="text-sm font-semibold text-foreground">Corrective actions</p>
                    <Link href="/health-safety/corrective-actions" className="text-xs font-medium text-primary hover:underline">
                        Open register
                    </Link>
                </div>
                {ev.corrective_actions.length ? (
                    <div className="flex flex-col gap-2">
                        {ev.corrective_actions.map((ca) => (
                            <div key={ca.id} className="flex items-center justify-between rounded-lg border border-border p-2.5 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-foreground">{ca.reference_number} · {ca.title}</p>
                                    <p className="text-xs text-muted-foreground">{titleCase(ca.status)}{ca.assigned_to ? ` · ${ca.assigned_to}` : ''}{ca.due_date ? ` · due ${formatDateTime(ca.due_date)}` : ''}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">No corrective actions raised. Formal remediation is raised and governed in the Health &amp; Safety register.</p>
                )}
            </div>
        </div>
    );
}

function LinkedSection({ d, clientName }: { d: IncidentDetail; clientName: string }) {
    return (
        <div className="flex flex-col gap-2">
            {d.control_room_alert ? (
                <LinkedRow
                    icon={RadioTower}
                    title="Control Room alert"
                    sub={`${titleCase(d.control_room_alert.alert_type)} · ${titleCase(d.control_room_alert.status)}${d.control_room_alert.triggered_at ? ` · ${formatDateTime(d.control_room_alert.triggered_at)}` : ''}`}
                    href={`/control-room/alerts/${d.control_room_alert.id}`}
                />
            ) : null}
            {d.hs_event ? (
                <LinkedRow icon={ShieldAlert} title="Health & Safety event" sub={`${d.hs_event.reference_number} · ${titleCase(d.hs_event.status)}`} href={`/health-safety/events/${d.hs_event.id}`} />
            ) : null}
            {d.client ? <LinkedRow icon={User} title="Client record" sub={clientName} href={`/operations/clients/${d.client.id}/care`} /> : null}
            {!d.control_room_alert && !d.hs_event && !d.client ? <p className="text-sm text-muted-foreground">No linked records.</p> : null}
        </div>
    );
}

function LinkedRow({ icon: Icon, title, sub, href }: { icon: ComponentType<{ className?: string }>; title: string; sub: string; href: string }) {
    return (
        <Link href={href} className="flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
            <ExternalLink className="h-4 w-4 text-muted-foreground" />
        </Link>
    );
}
