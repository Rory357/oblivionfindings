/* H&S Event detail — the governance workspace MODAL (HsEventDialog).
 *
 * The Events register's detail-as-modal, built on the Add-Client `WizardShell`
 * read-only chrome (rail = sections, footer = Options bar) — the governance twin
 * of the Incidents / Safeguarding / Fleet detail dialogs. Opens *over* the
 * register (and from a source module's "Open in Health & Safety") and never
 * navigates away; `/health-safety/events/{id}` renders the same content on a thin
 * shell as a deep-link fallback.
 *
 * The `EventDetail` type is the contract — it mirrors
 * `HsEventController::buildEventDetail()`. Tokens are semantic only; every flag
 * pairs an icon + `title` (never colour-only); WCAG AA + keyboard + focus-trap
 * come from `WizardShell`. NZ-only (WorkSafe NZ / HSWA 2015). Web-only.
 *
 * Write actions (close / WorkSafe / investigation / corrective actions) are added
 * to the Options bar as their backend lands — an action appears only when it can
 * actually run (no stubs). */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { EventTimeline } from '@/components/health-safety/event-timeline';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import { formatDateTime } from '@/lib/datetime';
import { Link, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    Clock,
    ExternalLink,
    FileText,
    History,
    LinkIcon,
    ListChecks,
    Paperclip,
    RadioTower,
    Search,
    ShieldAlert,
    ShieldCheck,
    User as UserIcon,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Contract — mirrors HsEventController::buildEventDetail()            */
/* ------------------------------------------------------------------ */

type JsonCause = { description?: string; category?: string; factor_type?: string };
type JsonRec = { description?: string; priority?: string; target_area?: string };

export type EventInvestigation = {
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
    immediate_causes: JsonCause[] | null;
    root_causes: JsonCause[] | null;
    contributing_factors: JsonCause[] | null;
    findings_summary: string | null;
    recommendations: JsonRec[] | null;
    lessons_learned: string | null;
    reviewed_by_name?: string | null;
    approved_by_name?: string | null;
};

export type EventCorrectiveAction = {
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
    completed_by_name?: string | null;
    verified_at: string | null;
    verified_by_name?: string | null;
    effectiveness_confirmed: boolean | null;
};

export type EventRiskAssessment = {
    id: number;
    reference_number: string;
    title: string;
    status: string;
    likelihood?: number | null;
    consequence?: number | null;
    risk_score: number;
    risk_level: string;
    residual_likelihood?: number | null;
    residual_consequence?: number | null;
    residual_risk_score: number | null;
    residual_risk_level: string | null;
    risk_acceptable: boolean | null;
    assessed_by_name: string | null;
    review_due_at: string | null;
    is_due_for_review: boolean;
};

export type EventAttachment = {
    id: number;
    name: string;
    mime: string | null;
    size: number | null;
    uploaded_by: string | null;
    created_at: string | null;
    download_url: string;
};

/** Two-way convergence: the resolved originating record (E-Gap 4 — Step 5). */
export type EventSource = {
    type: string;
    id: number;
    label: string;
    url: string | null;
    /** Orphan category with no creator — the jump is disabled + explained. */
    unwired: boolean;
};

export type EventDetail = {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    description: string | null;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    staff: { id: number; name: string } | null;
    asset: { id: number; name: string } | null;
    worksafe_notifiable: boolean;
    worksafe_status: string | null;
    worksafe_reference: string | null;
    worksafe_notified_at: string | null;
    worksafe_acknowledged_at: string | null;
    worksafe_method: string | null;
    worksafe_site_preserved: boolean;
    worksafe_reason: string | null;
    investigation_required: boolean;
    control_room_alert: { id: number; severity: string; status: string } | null;
    closed_at: string | null;
    closure_summary: string | null;
    created_by_name: string | null;
    source: EventSource | null;
    investigations: EventInvestigation[];
    corrective_actions: EventCorrectiveAction[];
    risk_assessments: EventRiskAssessment[];
    attachments: EventAttachment[];
    close_gate: { investigation_ok: boolean; actions_ok: boolean; blockers: string[] };
    can: { manage: boolean };
};

export type EventSectionKey = 'overview' | 'investigation' | 'actions' | 'risk' | 'timeline' | 'evidence';
/** Workflow action panes that replace the body + own their buttons. Grows as backend lands. */
export type EventActionKey = 'close' | 'worksafe_notify' | 'worksafe_acknowledge';

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                         */
/* ------------------------------------------------------------------ */

export const EVENT_CATEGORY_LABELS: Record<string, string> = {
    incident: 'Incident',
    near_miss: 'Near miss',
    hazard: 'Hazard',
    injury: 'Injury',
    exposure: 'Exposure',
    restraint: 'Restraint',
    safeguarding: 'Safeguarding',
    vehicle_incident: 'Vehicle incident',
    drill_failure: 'Drill failure',
    inspection_failure: 'Inspection failure',
    equipment_fault: 'Equipment fault',
};

const SEV: Record<string, { label: string; chip: string; dot: string }> = {
    low: { label: 'Low', chip: 'bg-status-success-bg text-status-success', dot: 'bg-status-success' },
    medium: { label: 'Medium', chip: 'bg-status-warning-bg text-status-warning', dot: 'bg-status-warning' },
    high: { label: 'High', chip: 'bg-status-critical-bg text-status-critical', dot: 'bg-status-critical' },
    critical: { label: 'Critical', chip: 'bg-status-critical-bg text-status-critical', dot: 'bg-status-critical' },
};

/** The five governance stages, in lifecycle order. monitoring uses the `--live`
 *  teal token so it reads distinctly from open (info) and closed (success). */
const STAGE_ORDER = ['open', 'investigating', 'corrective_action', 'monitoring', 'closed'] as const;
const STAGE: Record<string, { label: string; chip: string; dot: string; icon: LucideIcon }> = {
    open: { label: 'Open', chip: 'bg-status-info-bg text-status-info', dot: 'bg-status-info', icon: AlertTriangle },
    investigating: { label: 'Investigating', chip: 'bg-primary/10 text-primary', dot: 'bg-primary', icon: Search },
    corrective_action: { label: 'Corrective action', chip: 'bg-status-warning-bg text-status-warning', dot: 'bg-status-warning', icon: ListChecks },
    monitoring: { label: 'Monitoring', chip: 'bg-[var(--live-bg)] text-[var(--live)]', dot: 'bg-[var(--live)]', icon: Activity },
    closed: { label: 'Closed', chip: 'bg-status-success-bg text-status-success', dot: 'bg-status-success', icon: CheckCircle2 },
};

/** Investigation lifecycle gate (draft → … → completed). */
const INV_ORDER = ['draft', 'in_progress', 'findings_recorded', 'under_review', 'completed'] as const;
const INV_STAGE: Record<string, string> = {
    draft: 'Draft',
    in_progress: 'In progress',
    findings_recorded: 'Findings recorded',
    under_review: 'Under review',
    completed: 'Completed',
};

const CA_STATUS: Record<string, { label: string; chip: string }> = {
    open: { label: 'Open', chip: 'bg-status-info-bg text-status-info' },
    in_progress: { label: 'In progress', chip: 'bg-primary/10 text-primary' },
    completed: { label: 'Awaiting verification', chip: 'bg-status-warning-bg text-status-warning' },
    verified: { label: 'Verified', chip: 'bg-status-success-bg text-status-success' },
    closed: { label: 'Closed', chip: 'bg-status-success-bg text-status-success' },
};

const PRIORITY: Record<string, string> = {
    low: 'bg-status-success-bg text-status-success',
    medium: 'bg-status-info-bg text-status-info',
    high: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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

export function EventDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    openedFrom = null,
}: {
    detail: EventDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: EventSectionKey;
    initialAction?: EventActionKey | null;
    /** Set when arrived via a source module's "Open in Health & Safety" jump. */
    openedFrom?: string | null;
}) {
    const [section, setSection] = useState<EventSectionKey>(initialSection);
    const [action, setAction] = useState<EventActionKey | null>(initialAction);
    const d = detail;

    const cat = EVENT_CATEGORY_LABELS[d.event_category] ?? titleCase(d.event_category);
    const sev = SEV[d.severity] ?? SEV.low;
    const stage = STAGE[d.status] ?? STAGE.open;
    const activeInv = d.investigations.find((i) => i.status !== 'completed') ?? d.investigations[0] ?? null;
    const openActions = d.corrective_actions.filter((a) => a.status !== 'verified' && a.status !== 'closed').length;
    const awaitingVerification = d.corrective_actions.filter((a) => a.status === 'completed').length;

    const SECTIONS: { key: EventSectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Governance & origin', icon: FileText },
        { key: 'investigation', label: 'Investigation', blurb: activeInv ? titleCase(activeInv.status) : 'none yet', icon: Search },
        { key: 'actions', label: 'Corrective actions', blurb: openActions > 0 ? `${openActions} open` : `${d.corrective_actions.length} total`, icon: ListChecks },
        { key: 'risk', label: 'Risk', blurb: d.risk_assessments.length ? `${d.risk_assessments.length} assessed` : 'none', icon: Activity },
        { key: 'timeline', label: 'Timeline', blurb: 'Audit trail', icon: History },
        { key: 'evidence', label: 'Evidence', blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`, icon: Paperclip },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${sev.chip}`}>
                <span className={`h-1.5 w-1.5 rounded-full ${sev.dot}`} /> {sev.label}
            </span>
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${stage.chip}`}>
                <stage.icon className="h-3 w-3" /> {stage.label}
            </span>
            {d.worksafe_notifiable ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 font-medium text-status-critical" title="WorkSafe NZ notifiable event">
                    <ShieldAlert className="h-3 w-3" /> WorkSafe {d.worksafe_status ? titleCase(d.worksafe_status) : 'pending'}
                </span>
            ) : null}
        </div>
    );

    const canClose = d.can.manage && d.status !== 'closed';
    const blockers = d.close_gate?.blockers ?? [];

    // Options bar — suppressed while an action pane owns the body + its own buttons.
    // Write actions appear only when they can run (no stubs); more land per backend step.
    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center gap-2">
            <Link
                href={`/health-safety/events/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.can.manage && d.worksafe_notifiable && d.worksafe_status !== 'acknowledged' ? (
                d.worksafe_status === 'notified' ? (
                    <Button size="sm" variant="outline" onClick={() => setAction('worksafe_acknowledge')}>
                        <ShieldCheck className="mr-1.5 h-4 w-4" /> Record acknowledgement
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setAction('worksafe_notify')}
                        className="border-status-critical/40 text-status-critical hover:text-status-critical"
                    >
                        <ShieldAlert className="mr-1.5 h-4 w-4" /> Record WorkSafe notification
                    </Button>
                )
            ) : null}
            {canClose ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setAction('close')}
                    title={blockers.length ? `Closure blocked: ${blockers.join(' ')}` : undefined}
                    className={blockers.length ? 'border-status-critical/40 text-status-critical hover:text-status-critical' : ''}
                >
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close event
                </Button>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Event ${d.reference_number}`}
            description={`${cat} — ${stage.label}`}
            railIcon={ShieldAlert}
            railTitle={d.reference_number}
            railSub={`${cat} · ${sev.label}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action === 'close' ? (
                <CloseEventPane d={d} onDone={() => setAction(null)} />
            ) : action === 'worksafe_notify' ? (
                <WorksafeNotifyPane d={d} onDone={() => setAction(null)} />
            ) : action === 'worksafe_acknowledge' ? (
                <WorksafeAcknowledgePane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {openedFrom ? (
                        <div className="mb-4">
                            <InfoCard icon={LinkIcon} tone="info">
                                Opened from {openedFrom}. This is the Health &amp; Safety governance record — investigation, corrective actions and closure are managed here.
                            </InfoCard>
                        </div>
                    ) : null}

                    {section === 'overview' ? <OverviewSection d={d} cat={cat} stage={stage} /> : null}
                    {section === 'investigation' ? <InvestigationSection d={d} /> : null}
                    {section === 'actions' ? <ActionsSection d={d} openActions={openActions} awaitingVerification={awaitingVerification} /> : null}
                    {section === 'risk' ? <RiskSection d={d} /> : null}
                    {section === 'timeline' ? <TimelineSection d={d} /> : null}
                    {section === 'evidence' ? <EvidenceSection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Workflow action panes                                              */
/* ------------------------------------------------------------------ */

function CloseEventPane({ d, onDone }: { d: EventDetail; onDone: () => void }) {
    const gate = d.close_gate;
    const blocked = (gate?.blockers.length ?? 0) > 0;
    const form = useForm<{ closure_summary: string; override_reason: string }>({ closure_summary: '', override_reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // A blocked closure comes back on a 302 as flash.error (not 422) — keep the
        // pane open so the user can record an override reason.
        form.post(`/health-safety/events/${d.id}/close`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Close event"
                blurb="A required investigation must be complete and every corrective action verified — or close with a logged override. A closure summary is always required."
            />

            {/* eslint-disable-next-line no-restricted-syntax -- closure gate checklist surface */}
            <div className="flex flex-col gap-2 rounded-xl border border-border bg-card/70 p-3">
                <GateRow ok={gate?.investigation_ok ?? true} label="Required investigation complete" />
                <GateRow ok={gate?.actions_ok ?? true} label="All corrective actions verified or closed" />
            </div>

            {blocked ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    This event does not meet the closure gate yet. You can still close it by recording an override reason — the override is logged for the audit trail.
                </InfoCard>
            ) : null}

            <Field label="Closure summary" required error={form.errors.closure_summary}>
                <Textarea
                    rows={4}
                    value={form.data.closure_summary}
                    onChange={(e) => form.setData('closure_summary', e.target.value)}
                    placeholder="How was this event resolved? What did the investigation and corrective actions conclude?"
                />
            </Field>

            {blocked ? (
                <Field label="Override reason" required hint="Logged" error={form.errors.override_reason}>
                    <Textarea
                        rows={3}
                        value={form.data.override_reason}
                        onChange={(e) => form.setData('override_reason', e.target.value)}
                        placeholder="Why is this event being closed despite the open gate?"
                    />
                </Field>
            ) : null}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Close event
                </Button>
            </div>
        </form>
    );
}

function GateRow({ ok, label }: { ok: boolean; label: string }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            {ok ? (
                <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
            ) : (
                <AlertTriangle className="h-4 w-4 shrink-0 text-status-critical" />
            )}
            <span className={ok ? 'text-foreground' : 'text-status-critical'}>{label}</span>
        </div>
    );
}

const WORKSAFE_METHODS = [
    { value: 'phone', label: 'Phone — 0800 030 040' },
    { value: 'online', label: 'Online notification form' },
    { value: 'email', label: 'Email' },
    { value: 'in_person', label: 'In person' },
];

/** Today as a yyyy-mm-dd value for date inputs (browser-local). */
function todayInput(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

function WorksafeNotifyPane({ d, onDone }: { d: EventDetail; onDone: () => void }) {
    const form = useForm<{ notified_at: string; method: string; reference: string; site_preserved: boolean }>({
        notified_at: todayInput(),
        method: '',
        reference: d.worksafe_reference ?? '',
        site_preserved: d.worksafe_site_preserved,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/worksafe/notify`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ShieldAlert}
                title="Record WorkSafe notification"
                blurb="A notifiable event must be reported to WorkSafe NZ as soon as possible (HSWA 2015). Record when and how you notified."
            />

            <InfoCard icon={ShieldCheck} tone="warn">
                Notify WorkSafe ASAP — phone 0800 030 040 or notify online. Preserve the site until an inspector releases it, and keep records for at least 5 years.
            </InfoCard>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Notified at" required error={form.errors.notified_at}>
                    <Input type="date" value={form.data.notified_at} onChange={(e) => form.setData('notified_at', e.target.value)} />
                </Field>
                <Field label="Method" required error={form.errors.method}>
                    <SelectInput value={form.data.method} onChange={(v) => form.setData('method', v)} placeholder="How was WorkSafe notified?" options={WORKSAFE_METHODS} />
                </Field>
            </div>
            <Field label="WorkSafe reference" hint="If provided" error={form.errors.reference}>
                <Input value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} placeholder="e.g. WS-2026-0099" />
            </Field>
            <label className="flex items-center gap-2 text-sm text-foreground">
                <input
                    type="checkbox"
                    checked={form.data.site_preserved}
                    onChange={(e) => form.setData('site_preserved', e.target.checked)}
                    className="h-4 w-4 rounded border-border"
                />
                The site has been preserved until WorkSafe releases it
            </label>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Record notification
                </Button>
            </div>
        </form>
    );
}

function WorksafeAcknowledgePane({ d, onDone }: { d: EventDetail; onDone: () => void }) {
    const form = useForm<{ acknowledged_at: string }>({ acknowledged_at: todayInput() });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/worksafe/acknowledge`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={ShieldCheck} title="Record WorkSafe acknowledgement" blurb="Record the date WorkSafe NZ acknowledged the notification." />
            <Field label="Acknowledged at" required error={form.errors.acknowledged_at}>
                <Input type="date" value={form.data.acknowledged_at} onChange={(e) => form.setData('acknowledged_at', e.target.value)} />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Record acknowledgement
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Governance stage tracker                                           */
/* ------------------------------------------------------------------ */

function StageTracker({ status }: { status: string }) {
    const currentRank = STAGE_ORDER.indexOf(status as (typeof STAGE_ORDER)[number]);
    return (
        <ol className="flex flex-wrap items-center gap-1.5" aria-label="Governance stage">
            {STAGE_ORDER.map((key, i) => {
                const s = STAGE[key];
                const done = i < currentRank;
                const current = i === currentRank;
                return (
                    <li key={key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold ${
                                current ? s.chip : done ? 'bg-muted text-foreground' : 'bg-muted/50 text-muted-foreground'
                            }`}
                            aria-current={current ? 'step' : undefined}
                            title={current ? `Current stage: ${s.label}` : done ? `${s.label} — done` : s.label}
                        >
                            {done ? <CheckCircle2 className="h-3 w-3" /> : <s.icon className="h-3 w-3" />}
                            {s.label}
                        </span>
                        {i < STAGE_ORDER.length - 1 ? <ChevronRight className="h-3 w-3 text-muted-foreground/50" /> : null}
                    </li>
                );
            })}
        </ol>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, cat, stage }: { d: EventDetail; cat: string; stage: { label: string } }) {
    return (
        <div className="flex flex-col gap-4">
            {/* eslint-disable-next-line no-restricted-syntax -- custom governance layout surface */}
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <p className="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Governance stage</p>
                <StageTracker status={d.status} />
            </div>

            {d.worksafe_notifiable ? <WorkSafeBanner d={d} /> : null}

            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewCard icon={FileText} title="Event">
                    <ReviewRow label="Reference" value={d.reference_number} />
                    <ReviewRow label="Category" value={cat} />
                    <ReviewRow label="Severity" value={SEV[d.severity]?.label ?? d.severity} />
                    <ReviewRow label="Stage" value={stage.label} />
                    <ReviewRow label="Occurred" value={d.occurred_at ? formatDateTime(d.occurred_at) : undefined} />
                    <ReviewRow label="Reported" value={d.reported_at ? formatDateTime(d.reported_at) : undefined} />
                    <ReviewRow label="Logged by" value={d.created_by_name} />
                </ReviewCard>

                <ReviewCard icon={UserIcon} title="Context">
                    <ReviewRow
                        label="Site"
                        value={d.site ? <Link href={`/sites/${d.site.id}`} className="text-primary hover:underline">{d.site.name}</Link> : undefined}
                    />
                    <ReviewRow
                        label="Client"
                        value={d.client ? <Link href={`/operations/clients/${d.client.id}/care`} className="text-primary hover:underline">{d.client.name}</Link> : undefined}
                    />
                    <ReviewRow label="Staff" value={d.staff?.name} />
                    <ReviewRow label="Asset" value={d.asset?.name} />
                    <ReviewRow
                        label="Control Room"
                        value={
                            d.control_room_alert ? (
                                <Link href={`/control-room/alerts/${d.control_room_alert.id}`} className="inline-flex items-center gap-1 text-primary hover:underline">
                                    <RadioTower className="h-3 w-3" /> Alert #{d.control_room_alert.id}
                                </Link>
                            ) : undefined
                        }
                    />
                </ReviewCard>
            </div>

            <OriginatingRecordCard source={d.source} />

            {d.description ? (
                <ReviewCard icon={FileText} title="What happened" span>
                    <p className="text-sm whitespace-pre-wrap text-foreground">{d.description}</p>
                </ReviewCard>
            ) : null}

            {d.closed_at ? (
                <ReviewCard icon={CheckCircle2} title="Closure" span>
                    <ReviewRow label="Closed" value={formatDateTime(d.closed_at)} />
                    {d.closure_summary ? <p className="mt-2 text-sm text-muted-foreground">{d.closure_summary}</p> : null}
                </ReviewCard>
            ) : null}
        </div>
    );
}

/** E-Gap 4 — two-way convergence. The resolver lands in Step 5; until then the
 *  card shows the origin reference as read-only information (never a broken jump). */
function OriginatingRecordCard({ source }: { source: EventSource | null }) {
    if (!source) {
        return (
            <div className="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                No originating record linked.
            </div>
        );
    }
    const body = (
        <>
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <LinkIcon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">Originating record</p>
                <p className="truncate text-xs text-muted-foreground">{source.label}</p>
            </div>
            {source.url && !source.unwired ? <ExternalLink className="h-4 w-4 text-muted-foreground" /> : null}
        </>
    );
    if (source.url && !source.unwired) {
        return (
            <Link href={source.url} className="flex items-center gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-muted/50">
                {body}
            </Link>
        );
    }
    return (
        <div
            className="flex items-center gap-3 rounded-xl border border-dashed border-border p-3"
            title={source.unwired ? 'This category has no originating module yet.' : undefined}
        >
            {body}
        </div>
    );
}

const WORKSAFE_METHOD_LABELS: Record<string, string> = {
    phone: 'phone',
    online: 'online form',
    email: 'email',
    in_person: 'in person',
};

function WorkSafeBanner({ d }: { d: EventDetail }) {
    const notified = d.worksafe_status === 'notified' || d.worksafe_status === 'acknowledged';
    const acknowledged = d.worksafe_status === 'acknowledged';
    const methodLabel = d.worksafe_method ? (WORKSAFE_METHOD_LABELS[d.worksafe_method] ?? d.worksafe_method.replace(/_/g, ' ')) : null;
    return (
        <InfoCard icon={ShieldAlert} tone="crit">
            <span className="font-semibold">WorkSafe NZ notifiable event (HSWA 2015).</span>{' '}
            {acknowledged
                ? `Acknowledged by WorkSafe${d.worksafe_acknowledged_at ? ` ${formatDateTime(d.worksafe_acknowledged_at)}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''}.`
                : notified
                  ? `Notified${d.worksafe_notified_at ? ` ${formatDateTime(d.worksafe_notified_at)}` : ''}${methodLabel ? ` by ${methodLabel}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''} — awaiting acknowledgement.`
                  : 'Notification to WorkSafe NZ is still pending.'}
            <span className="mt-2 flex flex-wrap gap-1.5">
                <DutyChip label="Notify ASAP" done={notified} />
                <DutyChip label={d.worksafe_site_preserved ? 'Site preserved' : 'Preserve the site until released'} done={d.worksafe_site_preserved} />
                <DutyChip label="Keep records ≥ 5 years" />
            </span>
        </InfoCard>
    );
}

function DutyChip({ label, done = false }: { label: string; done?: boolean }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium ${
                done ? 'border-status-success/40 bg-status-success-bg text-status-success' : 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
            }`}
        >
            {done ? <CheckCircle2 className="h-3 w-3" /> : <ShieldCheck className="h-3 w-3" />} {label}
        </span>
    );
}

function InvestigationGate({ status }: { status: string }) {
    const rank = INV_ORDER.indexOf(status as (typeof INV_ORDER)[number]);
    return (
        <ol className="flex flex-wrap items-center gap-1.5" aria-label="Investigation lifecycle">
            {INV_ORDER.map((key, i) => {
                const done = i < rank;
                const current = i === rank;
                return (
                    <li key={key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold ${
                                current ? 'bg-primary/10 text-primary' : done ? 'bg-muted text-foreground' : 'bg-muted/50 text-muted-foreground'
                            }`}
                            aria-current={current ? 'step' : undefined}
                        >
                            {done ? <CheckCircle2 className="h-3 w-3" /> : null}
                            {INV_STAGE[key]}
                        </span>
                        {i < INV_ORDER.length - 1 ? <ChevronRight className="h-3 w-3 text-muted-foreground/50" /> : null}
                    </li>
                );
            })}
        </ol>
    );
}

function InvestigationSection({ d }: { d: EventDetail }) {
    if (!d.investigations.length) {
        return (
            <EmptyState
                icon={Search}
                title="No investigation yet"
                blurb={d.investigation_required ? 'An investigation is required for this event.' : 'No investigation has been opened.'}
            />
        );
    }
    return (
        <div className="flex flex-col gap-4">
            {d.investigations.map((inv) => (
                // eslint-disable-next-line no-restricted-syntax -- custom governance layout surface
                <div key={inv.id} className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span className="font-semibold text-foreground">{inv.reference_number}</span>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">{titleCase(inv.investigation_type)}</span>
                            {inv.is_overdue ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-medium text-status-critical" title="Investigation overdue">
                                    <Clock className="h-3 w-3" /> Overdue
                                </span>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                            {inv.lead_investigator_name ? <span><UserIcon className="mr-0.5 inline h-3 w-3" />{inv.lead_investigator_name}</span> : null}
                            {inv.methodology ? <span>{inv.methodology.replace(/_/g, '-')}</span> : null}
                            {inv.target_completion_date ? <span>Due {formatDateTime(inv.target_completion_date)}</span> : null}
                        </div>
                    </div>

                    <div className="mt-3">
                        <InvestigationGate status={inv.status} />
                    </div>

                    {inv.has_findings ? (
                        <div className="mt-4 space-y-3 border-t border-border pt-3">
                            {inv.findings_summary ? <Finding label="Findings" text={inv.findings_summary} /> : null}
                            <CauseList label="Immediate causes" causes={inv.immediate_causes} />
                            <CauseList label="Root causes" causes={inv.root_causes} />
                            <CauseList label="Contributing factors" causes={inv.contributing_factors} />
                            {inv.recommendations && inv.recommendations.length > 0 ? (
                                <div>
                                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        Recommendations ({inv.recommendation_count})
                                    </p>
                                    <ul className="mt-1 space-y-1">
                                        {inv.recommendations.map((r, i) => (
                                            <li key={i} className="flex items-start gap-2 text-sm">
                                                {r.priority ? <span className={`mt-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-medium ${PRIORITY[r.priority] ?? PRIORITY.medium}`}>{titleCase(r.priority)}</span> : null}
                                                <span className="text-foreground">{r.description}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                            {inv.lessons_learned ? <Finding label="Lessons learned" text={inv.lessons_learned} /> : null}
                        </div>
                    ) : null}
                </div>
            ))}
        </div>
    );
}

function Finding({ label, text }: { label: string; text: string }) {
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">{text}</p>
        </div>
    );
}

function CauseList({ label, causes }: { label: string; causes: JsonCause[] | null }) {
    if (!causes || causes.length === 0) return null;
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</p>
            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-sm text-foreground">
                {causes.map((c, i) => (
                    <li key={i}>{c.description}</li>
                ))}
            </ul>
        </div>
    );
}

function ActionsSection({ d, openActions, awaitingVerification }: { d: EventDetail; openActions: number; awaitingVerification: number }) {
    if (!d.corrective_actions.length) {
        return (
            <EmptyState
                icon={ListChecks}
                title="No corrective actions"
                blurb="Corrective actions are raised from investigation recommendations or added directly, then driven to verified."
            />
        );
    }
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap gap-2 text-xs">
                {openActions > 0 ? <span className="rounded-full bg-status-warning-bg px-2 py-0.5 font-medium text-status-warning">{openActions} open</span> : null}
                {awaitingVerification > 0 ? <span className="rounded-full bg-status-info-bg px-2 py-0.5 font-medium text-status-info">{awaitingVerification} awaiting verification</span> : null}
            </div>

            <div className="flex flex-col gap-2">
                {d.corrective_actions.map((a) => {
                    const st = CA_STATUS[a.status] ?? CA_STATUS.open;
                    return (
                        <div key={a.id} className={`rounded-lg border p-3 ${a.is_overdue && a.status !== 'verified' && a.status !== 'closed' ? 'border-status-critical/40 bg-status-critical-bg/40' : 'border-border'}`}>
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-foreground">{a.reference_number} · {a.title}</p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {a.assigned_to_name ?? 'Unassigned'}
                                        {a.due_date ? ` · due ${formatDateTime(a.due_date)}` : ''}
                                        {a.is_overdue && a.status !== 'verified' && a.status !== 'closed' ? ' · overdue' : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${PRIORITY[a.priority] ?? PRIORITY.medium}`}>{titleCase(a.priority)}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${st.chip}`}>{st.label}</span>
                                </div>
                            </div>
                            {a.verified_at ? (
                                <p className="mt-2 flex items-center gap-1 text-xs text-status-success">
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                    Verified{a.verified_by_name ? ` by ${a.verified_by_name}` : ''} · {a.effectiveness_confirmed ? 'effective' : 'not yet effective'}
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            <p className="mt-1 flex items-start gap-1.5 text-xs text-muted-foreground">
                <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Separation of duties: a corrective action must be verified by someone other than the person who completed it.
            </p>
        </div>
    );
}

function RiskSection({ d }: { d: EventDetail }) {
    if (!d.risk_assessments.length) {
        return <EmptyState icon={Activity} title="No linked risk assessments" blurb="5×5 likelihood × consequence assessments linked to this event appear here." />;
    }
    return (
        <div className="flex flex-col gap-4">
            {d.risk_assessments.map((ra) => (
                // eslint-disable-next-line no-restricted-syntax -- custom governance layout surface
                <div key={ra.id} className="rounded-xl border border-border bg-card/70 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold text-foreground">{ra.reference_number}</span>
                                {ra.is_due_for_review ? (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-medium text-status-warning" title="Review due">
                                        <Clock className="h-3 w-3" /> Review due
                                    </span>
                                ) : null}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">{ra.title}</p>
                            <div className="mt-3 flex items-center gap-3">
                                <RiskScore label="Inherent" score={ra.risk_score} level={ra.risk_level} />
                                {ra.residual_risk_score != null ? (
                                    <>
                                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                        <RiskScore label="Residual" score={ra.residual_risk_score} level={ra.residual_risk_level ?? ra.risk_level} />
                                    </>
                                ) : null}
                            </div>
                        </div>
                        {ra.likelihood ? (
                            <div className="shrink-0">
                                <RiskMatrix
                                    likelihood={ra.likelihood}
                                    consequence={ra.consequence ?? 1}
                                    residualLikelihood={ra.residual_likelihood ?? undefined}
                                    residualConsequence={ra.residual_consequence ?? undefined}
                                    compact
                                />
                            </div>
                        ) : null}
                    </div>
                </div>
            ))}
        </div>
    );
}

function RiskScore({ label, score, level }: { label: string; score: number; level: string }) {
    const cls = level === 'extreme' || level === 'high' ? 'bg-status-critical-bg text-status-critical' : level === 'medium' ? 'bg-status-warning-bg text-status-warning' : 'bg-status-success-bg text-status-success';
    return (
        <div className="text-center">
            <div className="text-[11px] text-muted-foreground">{label}</div>
            <div className={`mt-1 rounded-md px-3 py-1 text-sm font-bold ${cls}`}>{score}</div>
            <div className="mt-0.5 text-[11px] text-muted-foreground capitalize">{level}</div>
        </div>
    );
}

function TimelineSection({ d }: { d: EventDetail }) {
    return (
        <EventTimeline
            reportedAt={d.reported_at}
            occurredAt={d.occurred_at}
            closedAt={d.closed_at}
            investigations={d.investigations}
            correctiveActions={d.corrective_actions}
        />
    );
}

function EvidenceSection({ d }: { d: EventDetail }) {
    if (!d.attachments.length) {
        return <EmptyState icon={Paperclip} title="No evidence attached" blurb="Photos, documents and reports attached to this event appear here." />;
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
                        </p>
                    </div>
                    <a href={a.download_url} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                        <ExternalLink className="h-3.5 w-3.5" /> Open
                    </a>
                </div>
            ))}
        </div>
    );
}

function EmptyState({ icon: Icon, title, blurb }: { icon: ComponentType<{ className?: string }>; title: string; blurb: ReactNode }) {
    return (
        <div className="rounded-xl border border-dashed border-border py-12 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm font-medium text-foreground">{title}</p>
            <p className="mt-1 text-xs text-muted-foreground">{blurb}</p>
        </div>
    );
}

export default EventDetailDialog;
