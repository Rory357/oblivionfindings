import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { InfoCard } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    ExternalLink,
    FileText,
    Landmark,
    LinkIcon,
    ListTodo,
    Lock,
    RadioTower,
    Search,
    Shield,
    ShieldAlert,
    User as UserIcon,
    Users,
} from 'lucide-react';
import { useState, type ComponentType } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors SafeguardingConcernController::buildConcernDetail() */
/* ------------------------------------------------------------------ */

type Person = { name: string; href: string | null; type: string } | null;

export type ConcernDetail = {
    id: number;
    reference_number: string;
    restricted: boolean;
    severity: string;
    status: string;
    status_label: string;
    stage_index: number;
    occurred_at: string | null;
    reported_at: string | null;
    // Present only when not restricted:
    concern_type?: string;
    abuse_category?: string | null;
    location?: string | null;
    description?: string | null;
    immediate_actions?: string | null;
    subject_informed?: boolean;
    subject_informed_at?: string | null;
    requires_external_referral?: boolean;
    current_risk_level?: string | null;
    triage?: { at: string | null; by: string | null; substantiation: string | null; decision: string | null; notes: string | null } | null;
    closure?: { at: string | null; by: string | null; summary: string | null; lessons: string | null } | null;
    people?: { subject: Person; reported_by: string | null; assigned_to: string | null; alleged_perpetrator: string | null };
    risk_assessments?: Array<{
        id: number;
        assessed_at: string | null;
        assessor: string | null;
        risk_to_self: string | null;
        risk_to_others: string | null;
        risk_from_others: string | null;
        overall_risk_level: string | null;
        mental_capacity: string | null;
        protective_measures: string | null;
        next_review_date: string | null;
        notes: string | null;
    }>;
    investigations?: Array<{ id: number; type: string; status: string; lead: string | null; started_at: string | null; completed_at: string | null; outcome: string | null; findings: string | null; recommendations: string | null }>;
    external_reports?: Array<{ id: number; authority_type: string; authority_name: string; reported_at: string | null; method: string; summary: string | null; ack_received: boolean; acknowledged_at: string | null; ack_reference: string | null; authority_action: string | null }>;
    action_plans?: Array<{ id: number; description: string; type: string; assigned_to: string | null; due_date: string | null; status: string; completed_at: string | null; overdue: boolean }>;
    alerts?: Array<{ id: number; alert_type: string; summary: string; severity: string; active: boolean }>;
    related_incident_id?: number | null;
    hs_event?: { id: number; reference_number: string; status: string } | null;
    control_room_alert_id?: number | null;
    can?: { update: boolean; investigate: boolean; report_external: boolean };
};

type SectionKey = 'overview' | 'timeline' | 'risk' | 'investigation' | 'reports' | 'actions' | 'linked';

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

const STAGES = ['Reported', 'Triaged', 'Investigating', 'Action plan', 'Monitoring', 'Closed'];

function titleCase(s: string | null | undefined): string {
    return (s ?? '').replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function SafeguardingConcernDialog({ detail, open, onClose }: { detail: ConcernDetail; open: boolean; onClose: () => void }) {
    const [section, setSection] = useState<SectionKey>('overview');
    const d = detail;

    if (d.restricted) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title={`Concern ${d.reference_number}`}
                description="Restricted · need-to-know"
                railIcon={Lock}
                railTitle="Restricted"
                railSub={d.reference_number}
                steps={[{ key: 'restricted', label: 'Restricted', blurb: 'need-to-know', icon: Lock }]}
                stepIndex={0}
                onStepClick={() => {}}
            >
                <div className="flex flex-col items-center justify-center py-16 text-center">
                    <Lock className="mb-3 h-10 w-10 text-muted-foreground/50" />
                    <p className="text-base font-semibold text-foreground">Restricted · need-to-know</p>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                        This is a sensitive safeguarding allegation. It is visible only to the assigned lead, the reporter, and staff cleared to view sensitive concerns.
                    </p>
                </div>
            </WizardShell>
        );
    }

    const subject = d.people?.subject ?? null;
    const subjectName = subject?.name ?? 'Subject withheld';

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Stage & people', icon: FileText },
        { key: 'timeline', label: 'Timeline', blurb: 'Audit trail', icon: Clock },
        { key: 'risk', label: 'Risk', blurb: `${d.risk_assessments?.length ?? 0} assessment${(d.risk_assessments?.length ?? 0) === 1 ? '' : 's'}`, icon: Activity },
        { key: 'investigation', label: 'Investigation', blurb: d.hs_event ? d.hs_event.reference_number : `${d.investigations?.length ?? 0} record${(d.investigations?.length ?? 0) === 1 ? '' : 's'}`, icon: Search },
        { key: 'reports', label: 'External reports', blurb: `${d.external_reports?.length ?? 0} logged`, icon: Landmark },
        { key: 'actions', label: 'Action plan', blurb: `${d.action_plans?.length ?? 0} item${(d.action_plans?.length ?? 0) === 1 ? '' : 's'}`, icon: ListTodo },
        { key: 'linked', label: 'Linked records', blurb: 'incident · H&S · alerts', icon: LinkIcon },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[d.severity] ?? 'neutral']}`} />
                {SEV_LABEL[d.severity] ?? d.severity}
            </span>
            <span className="text-muted-foreground">{d.status_label}</span>
            <span className="hidden items-center gap-1 text-muted-foreground/70 sm:inline-flex">
                <Lock className="h-3 w-3" /> Viewing is logged
            </span>
        </div>
    );

    const footerEnd = (
        <Link href={`/safeguarding/${d.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
            <ExternalLink className="h-4 w-4" /> Open full page
        </Link>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Concern ${d.reference_number}`}
            description={`${titleCase(d.concern_type)} — ${subjectName}`}
            railIcon={d.severity === 'critical' ? ShieldAlert : Shield}
            railTitle={subjectName}
            railSub={`${d.reference_number} · ${titleCase(d.concern_type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {section === 'overview' ? <OverviewSection d={d} subjectName={subjectName} /> : null}
            {section === 'timeline' ? <TimelineSection d={d} /> : null}
            {section === 'risk' ? <RiskSection d={d} /> : null}
            {section === 'investigation' ? <InvestigationSection d={d} /> : null}
            {section === 'reports' ? <ReportsSection d={d} /> : null}
            {section === 'actions' ? <ActionsSection d={d} /> : null}
            {section === 'linked' ? <LinkedSection d={d} subject={subject} /> : null}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Lifecycle stage tracker                                            */
/* ------------------------------------------------------------------ */

function LifecycleTracker({ d }: { d: ConcernDetail }) {
    const idx = d.stage_index;
    const branch = d.status === 'referred_external' ? 'Referred external' : d.status === 'no_action_required' ? 'No further action' : null;
    return (
        <div className="sm:col-span-2">
            <p className="mb-3 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Lifecycle stage</p>
            <div className="flex flex-wrap items-center gap-1.5">
                {STAGES.map((label, i) => {
                    const done = i < idx;
                    const active = i === idx;
                    return (
                        <div key={label} className="flex items-center gap-1.5">
                            <span
                                className={
                                    active
                                        ? 'inline-flex items-center gap-1.5 rounded-full bg-primary px-2.5 py-1 text-[11px] font-semibold text-primary-foreground'
                                        : done
                                          ? 'inline-flex items-center gap-1.5 rounded-full bg-status-success-bg px-2.5 py-1 text-[11px] font-medium text-status-success'
                                          : 'inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground'
                                }
                            >
                                {done ? <CheckCircle2 className="h-3 w-3" /> : null}
                                {label}
                            </span>
                            {i < STAGES.length - 1 ? <span className="h-px w-3 bg-border" /> : null}
                        </div>
                    );
                })}
                {branch ? (
                    <span className="ml-1 inline-flex items-center gap-1.5 rounded-full bg-status-critical-bg px-2.5 py-1 text-[11px] font-semibold text-status-critical">
                        <Landmark className="h-3 w-3" /> {branch}
                    </span>
                ) : null}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, subjectName }: { d: ConcernDetail; subjectName: string }) {
    const informedLabel = d.subject_informed ? `Yes${d.subject_informed_at ? ` · ${formatDateTime(d.subject_informed_at)}` : ''}` : 'Not yet';
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <LifecycleTracker d={d} />

            {d.status === 'reported' ? (
                <InfoCard icon={ClipboardCheck} tone="warn">
                    <span className="font-semibold">Awaiting triage.</span> Triage decides the path — investigate, refer externally, or no further action.
                </InfoCard>
            ) : null}

            {d.alerts?.some((a) => a.active) ? (
                <InfoCard icon={RadioTower} tone="crit">
                    <span className="font-semibold">Active protective alert</span> on the subject — see Linked records.
                </InfoCard>
            ) : null}

            <ReviewCard icon={FileText} title="What was raised" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.description || '—'}</p>
            </ReviewCard>

            <ReviewCard icon={Users} title="People">
                <ReviewRow label="Subject" value={subjectName} />
                <ReviewRow label="Reported by" value={d.people?.reported_by} />
                <ReviewRow label="Alleged person" value={d.people?.alleged_perpetrator} />
                <ReviewRow label="Lead" value={d.people?.assigned_to ?? 'Unassigned'} />
            </ReviewCard>

            <ReviewCard icon={Search} title="Classification">
                <ReviewRow label="Type" value={titleCase(d.concern_type)} />
                <ReviewRow label="Category" value={d.abuse_category ? titleCase(d.abuse_category) : undefined} />
                <ReviewRow label="Current risk" value={d.current_risk_level ? titleCase(d.current_risk_level) : undefined} />
                <ReviewRow label="Occurred" value={d.occurred_at ? formatDateTime(d.occurred_at) : undefined} />
                <ReviewRow label="Subject informed" value={informedLabel} />
                <ReviewRow label="External referral" value={d.requires_external_referral ? 'Indicated' : 'Not indicated'} />
            </ReviewCard>

            <ReviewCard icon={CheckCircle2} title="Immediate response" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.immediate_actions || '—'}</p>
            </ReviewCard>

            {d.closure ? (
                <ReviewCard icon={CheckCircle2} title="Closure" span>
                    <ReviewRow label="Closed by" value={d.closure.by} />
                    <ReviewRow label="Summary" value={d.closure.summary} />
                    <ReviewRow label="Lessons learned" value={d.closure.lessons} />
                </ReviewCard>
            ) : null}
        </div>
    );
}

function TimelineSection({ d }: { d: ConcernDetail }) {
    type TLEvent = { at: string; label: string; tone: string; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (d.reported_at) events.push({ at: d.reported_at, label: 'Concern raised — created as Awaiting triage', tone: 'primary', icon: ShieldAlert });
    if (d.triage?.at) events.push({ at: d.triage.at, label: `Triaged${d.triage.decision ? ` · ${titleCase(d.triage.decision)}` : ''}${d.triage.by ? ` by ${d.triage.by}` : ''}`, tone: 'info', icon: ClipboardCheck });
    (d.investigations ?? []).forEach((i) => {
        if (i.started_at) events.push({ at: i.started_at, label: 'Investigation opened — required to enter Investigating', tone: 'primary', icon: Search });
        if (i.completed_at) events.push({ at: i.completed_at, label: `Investigation completed${i.outcome ? ` · ${titleCase(i.outcome)}` : ''}`, tone: 'success', icon: CheckCircle2 });
    });
    (d.external_reports ?? []).forEach((r) => {
        if (r.reported_at) events.push({ at: r.reported_at, label: `Reported to ${r.authority_name}`, tone: 'warning', icon: Landmark });
        if (r.acknowledged_at) events.push({ at: r.acknowledged_at, label: `${r.authority_name} acknowledged`, tone: 'success', icon: CheckCircle2 });
    });
    if (d.closure?.at) events.push({ at: d.closure.at, label: `Closed${d.closure.by ? ` by ${d.closure.by}` : ''}`, tone: 'success', icon: CheckCircle2 });
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

function RiskSection({ d }: { d: ConcernDetail }) {
    if (!d.risk_assessments?.length) {
        return <EmptyState icon={Activity} text="No risk assessment recorded yet." />;
    }
    return (
        <div className="flex flex-col gap-3">
            {d.risk_assessments.map((r) => (
                <ReviewCard key={r.id} icon={Activity} title={`Risk assessment${r.assessed_at ? ` · ${formatDateTime(r.assessed_at)}` : ''}`} span>
                    <ReviewRow label="Overall risk" value={r.overall_risk_level ? titleCase(r.overall_risk_level) : undefined} />
                    <ReviewRow label="Risk to self" value={titleCase(r.risk_to_self)} />
                    <ReviewRow label="Risk to others" value={titleCase(r.risk_to_others)} />
                    <ReviewRow label="Risk from others" value={titleCase(r.risk_from_others)} />
                    <ReviewRow label="Mental capacity" value={r.mental_capacity ? titleCase(r.mental_capacity) : undefined} />
                    <ReviewRow label="Protective measures" value={r.protective_measures} />
                    <ReviewRow label="Assessor" value={r.assessor} />
                    <ReviewRow label="Next review" value={r.next_review_date ? formatDateTime(r.next_review_date) : undefined} />
                </ReviewCard>
            ))}
        </div>
    );
}

function InvestigationSection({ d }: { d: ConcernDetail }) {
    return (
        <div className="flex flex-col gap-4">
            {d.hs_event ? (
                <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                    <div>
                        <p className="text-sm font-semibold text-foreground">{d.hs_event.reference_number}</p>
                        <p className="text-xs text-muted-foreground">H&amp;S event · {titleCase(d.hs_event.status)}</p>
                    </div>
                    <Link href={`/health-safety/events/${d.hs_event.id}`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                        <ExternalLink className="h-3.5 w-3.5" /> Open in Health &amp; Safety
                    </Link>
                </div>
            ) : null}

            {d.investigations?.length ? (
                d.investigations.map((i) => (
                    <ReviewCard key={i.id} icon={Search} title={`Investigation · ${titleCase(i.status)}`} span>
                        <ReviewRow label="Type" value={titleCase(i.type)} />
                        <ReviewRow label="Lead" value={i.lead} />
                        <ReviewRow label="Started" value={i.started_at ? formatDateTime(i.started_at) : undefined} />
                        <ReviewRow label="Completed" value={i.completed_at ? formatDateTime(i.completed_at) : undefined} />
                        <ReviewRow label="Outcome" value={i.outcome ? titleCase(i.outcome) : undefined} />
                        {i.findings ? <ReviewRow label="Findings" value={i.findings} /> : null}
                        {i.recommendations ? <ReviewRow label="Recommendations" value={i.recommendations} /> : null}
                    </ReviewCard>
                ))
            ) : (
                <EmptyState icon={Search} text="No investigation opened. Completing an investigation auto-advances the concern." />
            )}
        </div>
    );
}

function ReportsSection({ d }: { d: ConcernDetail }) {
    if (!d.external_reports?.length) {
        return (
            <EmptyState
                icon={Landmark}
                text={d.requires_external_referral ? 'Referral indicated at triage — no report logged yet.' : 'No external reports logged.'}
                tone={d.requires_external_referral ? 'warn' : 'neutral'}
            />
        );
    }
    return (
        <div className="flex flex-col gap-3">
            {d.external_reports.map((r) => (
                <ReviewCard key={r.id} icon={Landmark} title={r.authority_name} span>
                    <ReviewRow label="Method" value={titleCase(r.method)} />
                    <ReviewRow label="Reported" value={r.reported_at ? formatDateTime(r.reported_at) : undefined} />
                    <ReviewRow label="Summary" value={r.summary} />
                    <ReviewRow label="Acknowledged" value={r.ack_received ? `Yes${r.acknowledged_at ? ` · ${formatDateTime(r.acknowledged_at)}` : ''}${r.ack_reference ? ` · ${r.ack_reference}` : ''}` : 'Awaiting'} />
                    <ReviewRow label="Authority outcome" value={r.authority_action ? titleCase(r.authority_action) : undefined} />
                </ReviewCard>
            ))}
        </div>
    );
}

function ActionsSection({ d }: { d: ConcernDetail }) {
    if (!d.action_plans?.length) {
        return <EmptyState icon={ListTodo} text="No action-plan items yet." />;
    }
    return (
        <div className="flex flex-col gap-2">
            {d.action_plans.map((a) => (
                <div key={a.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                    <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${a.completed_at ? 'text-status-success' : a.overdue ? 'text-status-critical' : 'text-status-warning'}`} />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm text-foreground">{a.description}</p>
                        <p className="text-xs text-muted-foreground">
                            {a.assigned_to ?? 'Unassigned'}
                            {a.due_date ? ` · due ${formatDateTime(a.due_date)}` : ''}
                            {a.completed_at ? ` · completed ${formatDateTime(a.completed_at)}` : a.overdue ? ' · overdue' : ''}
                        </p>
                    </div>
                    <span className="text-xs text-muted-foreground">{titleCase(a.status)}</span>
                </div>
            ))}
        </div>
    );
}

function LinkedSection({ d, subject }: { d: ConcernDetail; subject: Person }) {
    const hasAny = d.related_incident_id || d.hs_event || subject?.href || d.alerts?.length || d.control_room_alert_id;
    if (!hasAny) return <p className="text-sm text-muted-foreground">No linked records.</p>;
    return (
        <div className="flex flex-col gap-2">
            {d.control_room_alert_id ? <LinkedRow icon={RadioTower} title="Control Room alert" sub="Active alert" href={`/control-room/alerts/${d.control_room_alert_id}`} /> : null}
            {d.related_incident_id ? <LinkedRow icon={ShieldAlert} title="Originating incident" sub={`INC-${d.related_incident_id}`} href={`/incidents/${d.related_incident_id}`} /> : null}
            {d.hs_event ? <LinkedRow icon={Shield} title="Health & Safety event" sub={`${d.hs_event.reference_number} · ${titleCase(d.hs_event.status)}`} href={`/health-safety/events/${d.hs_event.id}`} /> : null}
            {subject?.href ? <LinkedRow icon={UserIcon} title="Subject record" sub={subject.name} href={subject.href} /> : null}
            {d.alerts?.length ? (
                <div className="rounded-lg border border-border p-3">
                    <p className="mb-1 text-sm font-medium text-foreground">Protective alerts</p>
                    {d.alerts.map((a) => (
                        <p key={a.id} className="text-xs text-muted-foreground">
                            {titleCase(a.alert_type)} · {a.summary}
                            {a.active ? '' : ' (inactive)'}
                        </p>
                    ))}
                </div>
            ) : null}
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

function EmptyState({ icon: Icon, text, tone = 'neutral' }: { icon: ComponentType<{ className?: string }>; text: string; tone?: 'neutral' | 'warn' }) {
    return (
        <div className={`rounded-xl border border-dashed py-12 text-center ${tone === 'warn' ? 'border-status-warning/40 bg-status-warning-bg/30' : 'border-border'}`}>
            <Icon className={`mx-auto mb-2 h-8 w-8 ${tone === 'warn' ? 'text-status-warning' : 'text-muted-foreground/40'}`} />
            <p className={`text-sm ${tone === 'warn' ? 'text-status-warning' : 'text-muted-foreground'}`}>{text}</p>
        </div>
    );
}
