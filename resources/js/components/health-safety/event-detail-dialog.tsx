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
import { EventTimeline } from '@/components/health-safety/event-timeline';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm, usePage } from '@inertiajs/react';
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
    Loader2,
    Paperclip,
    Play,
    Plus,
    RadioTower,
    RotateCcw,
    Search,
    Send,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    User as UserIcon,
    X,
    type LucideIcon,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type ComponentType,
    type FormEvent,
    type MutableRefObject,
    type ReactNode,
} from 'react';

/* ------------------------------------------------------------------ */
/*  Contract — mirrors HsEventController::buildEventDetail()            */
/* ------------------------------------------------------------------ */

type JsonCause = {
    description?: string;
    category?: string;
    factor_type?: string;
};
type JsonRec = {
    description?: string;
    priority?: string;
    target_area?: string;
    disposition?: {
        disposition: string;
        reason: string | null;
        corrective_action: {
            id: number;
            reference_number: string;
            status: string;
        } | null;
        decided_by_name: string | null;
        decided_at: string | null;
    } | null;
};

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
    completed_by_user_id: number | null;
    completed_by_name: string | null;
    /** manage && status==='completed' && completer !== current viewer — gates Verify. */
    can_verify: boolean;
    verified_at: string | null;
    verified_by_name?: string | null;
    effectiveness_confirmed: boolean | null;
    hs_investigation_id: number | null;
    recommendation_index: number | null;
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

export type EventHandover = {
    status: string;
    owner: { id: number; name: string } | null;
    accepted_by: { id: number; name: string } | null;
    accepted_at: string | null;
    notes: string | null;
    can_accept: boolean;
};

export type EventLifecycle = {
    control_room: string | null;
    incident: string | null;
    health_safety: string;
};

export type EventHandoverSummary = {
    incident_reference: string | null;
    alert_reference: string | null;
    narrative: string | null;
    immediate_controls: string | null;
    witnesses: string | null;
    potential_consequence: string | null;
    reporter: string | null;
    source_label: string | null;
    site_name: string | null;
    attachments: EventAttachment[];
    control_room_evidence: Array<{
        id: number;
        title: string;
        status: string;
        items: Array<{
            id: number;
            title: string;
            description: string | null;
            download_url: string | null;
        }>;
    }>;
    playbook: {
        name: string | null;
        status: string;
        outcome: string | null;
    } | null;
    communications: Array<{
        id: number;
        channel: string;
        purpose: string | null;
        content: string | null;
        status: string;
        sent_at: string | null;
    }>;
    operational_tasks: Array<{
        id: number;
        title: string;
        status: string;
        priority: string;
        assignee: string | null;
        due_at: string | null;
    }>;
    next_action: { label: string; href: string | null } | null;
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
    control_room_alert: {
        id: number;
        reference_number: string;
        severity: string;
        status: string;
        url: string | null;
    } | null;
    closed_at: string | null;
    closure_summary: string | null;
    created_by_name: string | null;
    source: EventSource | null;
    investigations: EventInvestigation[];
    corrective_actions: EventCorrectiveAction[];
    risk_assessments: EventRiskAssessment[];
    attachments: EventAttachment[];
    handover: EventHandover;
    lifecycle: EventLifecycle;
    handover_summary: EventHandoverSummary;
    close_gate: {
        acceptance_ok: boolean;
        worksafe_ok: boolean;
        investigation_ok: boolean;
        recommendations_ok: boolean;
        actions_ok: boolean;
        blockers: string[];
    };
    assignable_staff: Array<{ id: number; name: string }>;
    can: { manage: boolean; override_closure: boolean };
};

export type EventSectionKey =
    | 'overview'
    | 'handover'
    | 'investigation'
    | 'actions'
    | 'risk'
    | 'timeline'
    | 'evidence';
/** Event-level launchers (Options bar / row menu). Per-item workflow panes are
 *  opened from inside the Investigation / Corrective-actions sections. */
export type EventActionKey =
    | 'close'
    | 'worksafe_notify'
    | 'worksafe_acknowledge'
    | 'investigation'
    | 'add_action';

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
    low: {
        label: 'Low',
        chip: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
    },
    medium: {
        label: 'Medium',
        chip: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
    },
    high: {
        label: 'High',
        chip: 'bg-status-critical-bg text-status-critical',
        dot: 'bg-status-critical',
    },
    critical: {
        label: 'Critical',
        chip: 'bg-status-critical-bg text-status-critical',
        dot: 'bg-status-critical',
    },
};

/** The five governance stages, in lifecycle order. monitoring uses the `--live`
 *  teal token so it reads distinctly from open (info) and closed (success). */
const STAGE_ORDER = [
    'open',
    'investigating',
    'corrective_action',
    'monitoring',
    'closed',
] as const;
const STAGE: Record<
    string,
    { label: string; chip: string; dot: string; icon: LucideIcon }
> = {
    open: {
        label: 'Open',
        chip: 'bg-status-info-bg text-status-info',
        dot: 'bg-status-info',
        icon: AlertTriangle,
    },
    investigating: {
        label: 'Investigating',
        chip: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
        icon: Search,
    },
    corrective_action: {
        label: 'Corrective action',
        chip: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        icon: ListChecks,
    },
    monitoring: {
        label: 'Monitoring',
        chip: 'bg-[var(--live-bg)] text-[var(--live)]',
        dot: 'bg-[var(--live)]',
        icon: Activity,
    },
    closed: {
        label: 'Closed',
        chip: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
        icon: CheckCircle2,
    },
};

/** Investigation lifecycle gate (draft → … → completed). */
const INV_ORDER = [
    'draft',
    'in_progress',
    'findings_recorded',
    'under_review',
    'completed',
] as const;
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
    completed: {
        label: 'Awaiting verification',
        chip: 'bg-status-warning-bg text-status-warning',
    },
    verified: {
        label: 'Verified',
        chip: 'bg-status-success-bg text-status-success',
    },
    closed: {
        label: 'Closed',
        chip: 'bg-status-success-bg text-status-success',
    },
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
function handoverStatusLabel(status: string): string {
    if (status === 'accepted') return 'Accepted into H&S';
    if (status === 'awaiting_acceptance' || status === 'awaiting_hs_acceptance')
        return 'Awaiting H&S acceptance';
    return titleCase(status).replace(/\bHs\b/g, 'H&S');
}
function lifecycleStatusLabel(status: string | null): string {
    if (!status) return 'Not linked';
    if (status === 'awaiting_hs_acceptance') return 'Awaiting acceptance';
    return titleCase(status).replace(/\bHs\b/g, 'H&S');
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

/** A workflow form that replaces the body + owns its buttons. Event-level panes
 *  are launched from the Options bar / row menu; per-item ones from inside the
 *  Investigation / Corrective-actions sections. */
type ActivePane =
    | { kind: 'close' }
    | { kind: 'accept_handover' }
    | { kind: 'worksafe_notify' }
    | { kind: 'worksafe_acknowledge' }
    | { kind: 'inv_start' }
    | { kind: 'inv_findings'; investigationId: number }
    | { kind: 'inv_complete'; investigationId: number }
    | { kind: 'inv_return'; investigationId: number }
    | {
          kind: 'inv_disposition';
          investigationId: number;
          recommendationIndex: number;
      }
    | { kind: 'ca_add' }
    | { kind: 'ca_complete'; actionId: number }
    | { kind: 'ca_verify'; actionId: number }
    | { kind: 'ca_return'; actionId: number };

function paneFromAction(action: EventActionKey | null): ActivePane | null {
    switch (action) {
        case 'close':
            return { kind: 'close' };
        case 'worksafe_notify':
            return { kind: 'worksafe_notify' };
        case 'worksafe_acknowledge':
            return { kind: 'worksafe_acknowledge' };
        case 'investigation':
            return { kind: 'inv_start' };
        case 'add_action':
            return { kind: 'ca_add' };
        default:
            return null;
    }
}

/** Deep-link straight to a specific corrective action's workflow pane (prompt E).
 *  Maps `pane` → the `ca_*` ActivePane and scrolls/highlights the matching card. */
const CA_TARGET_PANE = {
    complete: 'ca_complete',
    verify: 'ca_verify',
    return: 'ca_return',
} as const;

export function EventDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    initialActionTarget = null,
    openedFrom = null,
}: {
    detail: EventDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: EventSectionKey;
    initialAction?: EventActionKey | null;
    /** Deep-link to a single corrective action's workflow pane (Complete / Verify /
     *  Return), opened from the register row menu (prompt E). */
    initialActionTarget?: {
        actionId: number;
        pane: 'complete' | 'verify' | 'return';
    } | null;
    /** Set when arrived via a source module's "Open in Health & Safety" jump. */
    openedFrom?: string | null;
}) {
    const [section, setSection] = useState<EventSectionKey>(
        initialActionTarget ? 'actions' : initialSection,
    );
    const [pane, setPane] = useState<ActivePane | null>(() =>
        initialActionTarget
            ? {
                  kind: CA_TARGET_PANE[initialActionTarget.pane],
                  actionId: initialActionTarget.actionId,
              }
            : paneFromAction(initialAction),
    );
    /** Briefly ring the deep-linked action card once its section is on screen. */
    const [highlightActionId, setHighlightActionId] = useState<number | null>(
        initialActionTarget?.actionId ?? null,
    );
    const actionRowRefs = useRef<Record<number, HTMLDivElement | null>>({});
    const d = detail;

    useEffect(() => {
        const targetId = initialActionTarget?.actionId;
        if (targetId == null) return;
        const node = actionRowRefs.current[targetId];
        if (!node) return;
        node.scrollIntoView({ block: 'center', behavior: 'smooth' });
        setHighlightActionId(targetId);
        const timer = window.setTimeout(() => setHighlightActionId(null), 2200);
        return () => window.clearTimeout(timer);
        // Re-run when the deep-link target changes or the pane closes back to the section.
    }, [initialActionTarget?.actionId, pane]);

    // Re-sync the derived section/pane when the register re-targets the SAME
    // already-open event. Both registers key the dialog by event id, so it does
    // NOT remount when you open a different corrective action that shares the
    // parent event (the common case) — without this, the deep-linked pane/section
    // would stay stale and the lifecycle action would silently do nothing. Depends
    // only on incoming prop VALUES, so it never overrides the user's in-dialog nav.
    useEffect(() => {
        if (initialActionTarget) {
            setSection('actions');
            setPane({
                kind: CA_TARGET_PANE[initialActionTarget.pane],
                actionId: initialActionTarget.actionId,
            });
            setHighlightActionId(initialActionTarget.actionId);
        } else {
            setSection(initialSection);
            setPane(paneFromAction(initialAction));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes; the local setters are stable and intentionally excluded
    }, [
        initialActionTarget?.actionId,
        initialActionTarget?.pane,
        initialSection,
        initialAction,
    ]);

    const cat =
        EVENT_CATEGORY_LABELS[d.event_category] ?? titleCase(d.event_category);
    const sev = SEV[d.severity] ?? SEV.low;
    const stage = STAGE[d.status] ?? STAGE.open;
    const activeInv =
        d.investigations.find((i) => i.status !== 'completed') ??
        d.investigations[0] ??
        null;
    const openActions = d.corrective_actions.filter(
        (a) => a.status !== 'verified' && a.status !== 'closed',
    ).length;
    const awaitingVerification = d.corrective_actions.filter(
        (a) => a.status === 'completed',
    ).length;

    const SECTIONS: {
        key: EventSectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Governance & origin',
            icon: FileText,
        },
        {
            key: 'handover',
            label: 'Handover',
            blurb: handoverStatusLabel(d.handover.status),
            icon: RadioTower,
        },
        {
            key: 'investigation',
            label: 'Investigation',
            blurb: activeInv ? titleCase(activeInv.status) : 'none yet',
            icon: Search,
        },
        {
            key: 'actions',
            label: 'Corrective actions',
            blurb:
                openActions > 0
                    ? `${openActions} open`
                    : `${d.corrective_actions.length} total`,
            icon: ListChecks,
        },
        {
            key: 'risk',
            label: 'Risk',
            blurb: d.risk_assessments.length
                ? `${d.risk_assessments.length} assessed`
                : 'none',
            icon: Activity,
        },
        {
            key: 'timeline',
            label: 'Timeline',
            blurb: 'Audit trail',
            icon: History,
        },
        {
            key: 'evidence',
            label: 'Evidence',
            blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
            icon: Paperclip,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${sev.chip}`}
            >
                <span className={`h-1.5 w-1.5 rounded-full ${sev.dot}`} />{' '}
                {sev.label}
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${stage.chip}`}
            >
                <stage.icon className="h-3 w-3" /> {stage.label}
            </span>
            {d.worksafe_notifiable ? (
                <span
                    className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 font-medium text-status-critical"
                    title="WorkSafe NZ notifiable event"
                >
                    <ShieldAlert className="h-3 w-3" /> WorkSafe{' '}
                    {d.worksafe_status
                        ? titleCase(d.worksafe_status)
                        : 'pending'}
                </span>
            ) : null}
        </div>
    );

    const canAct = d.can.manage && d.status !== 'closed';
    const blockers = d.close_gate?.blockers ?? [];

    // Options bar — suppressed while a pane owns the body + its own buttons. Write
    // actions appear only when they can run (no stubs). Investigation / corrective-
    // action workflows live inline in their sections (and the row menu).
    const footerEnd = pane ? null : (
        <div className="flex flex-wrap items-center gap-2">
            <Link
                href={`/health-safety/events/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.handover.can_accept ? (
                <Button
                    size="sm"
                    onClick={() => setPane({ kind: 'accept_handover' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Accept handover
                </Button>
            ) : null}
            {d.can.manage &&
            d.worksafe_notifiable &&
            d.worksafe_status !== 'acknowledged' ? (
                d.worksafe_status === 'notified' ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            setPane({ kind: 'worksafe_acknowledge' })
                        }
                    >
                        <ShieldCheck className="mr-1.5 h-4 w-4" /> Record
                        acknowledgement
                    </Button>
                ) : (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPane({ kind: 'worksafe_notify' })}
                        className="border-status-critical/40 text-status-critical hover:text-status-critical"
                    >
                        <ShieldAlert className="mr-1.5 h-4 w-4" /> Record
                        WorkSafe notification
                    </Button>
                )
            ) : null}
            {canAct ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'close' })}
                    title={
                        blockers.length
                            ? `Closure blocked: ${blockers.join(' ')}`
                            : undefined
                    }
                    className={
                        blockers.length
                            ? 'border-status-critical/40 text-status-critical hover:text-status-critical'
                            : ''
                    }
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
            headerLabel={SECTIONS[stepIndex]?.label ?? 'Overview'}
            pct={null}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {pane ? (
                <PaneRenderer pane={pane} d={d} onDone={() => setPane(null)} />
            ) : (
                <>
                    {openedFrom ? (
                        <div className="mb-4">
                            <InfoCard icon={LinkIcon} tone="info">
                                Opened from {openedFrom}. This is the Health
                                &amp; Safety governance record — investigation,
                                corrective actions and closure are managed here.
                            </InfoCard>
                        </div>
                    ) : null}

                    {section === 'overview' ? (
                        <OverviewSection d={d} cat={cat} stage={stage} />
                    ) : null}
                    {section === 'handover' ? <HandoverSection d={d} /> : null}
                    {section === 'investigation' ? (
                        <InvestigationSection
                            d={d}
                            canAct={canAct}
                            onPane={setPane}
                        />
                    ) : null}
                    {section === 'actions' ? (
                        <ActionsSection
                            d={d}
                            openActions={openActions}
                            awaitingVerification={awaitingVerification}
                            canAct={canAct}
                            onPane={setPane}
                            rowRefs={actionRowRefs}
                            highlightActionId={highlightActionId}
                        />
                    ) : null}
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
    const form = useForm<{ closure_summary: string; override_reason: string }>({
        closure_summary: '',
        override_reason: '',
    });
    // A rejected close comes back as a 302 + flash.error (pane stays open) —
    // show WHY, or the click looks like it did nothing.
    const [attempted, setAttempted] = useState(false);
    const flashError = (usePage().props as { flash?: { error?: string } }).flash
        ?.error;
    const canSubmit =
        form.data.closure_summary.trim() !== '' &&
        (!blocked ||
            (d.can.override_closure &&
                form.data.override_reason.trim() !== ''));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (blocked && !d.can.override_closure) return;
        setAttempted(true);
        // A blocked closure comes back on a 302 as flash.error (not 422) — keep the
        // pane open so the user can record an override reason.
        form.post(`/health-safety/events/${d.id}/close`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Close event"
                blurb="H&S ownership, WorkSafe, investigation decisions and corrective actions must all be complete. A closure summary is always required."
            />

            {attempted && flashError ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    <span className="font-semibold">
                        Couldn't close this event.
                    </span>{' '}
                    {flashError}
                </InfoCard>
            ) : null}

            {/* eslint-disable-next-line no-restricted-syntax -- closure gate checklist surface */}
            <div className="flex flex-col gap-2 rounded-xl border border-border bg-card/70 p-3">
                <GateRow
                    ok={gate?.acceptance_ok ?? true}
                    label="H&S handover accepted where required"
                />
                <GateRow
                    ok={gate?.worksafe_ok ?? true}
                    label="WorkSafe notification recorded where required"
                />
                <GateRow
                    ok={gate?.investigation_ok ?? true}
                    label="Required investigation complete"
                />
                <GateRow
                    ok={gate?.recommendations_ok ?? true}
                    label="Every recommendation has a recorded outcome"
                />
                <GateRow
                    ok={gate?.actions_ok ?? true}
                    label="All corrective actions verified or closed"
                />
            </div>

            {blocked ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    <p className="font-semibold">Closure is blocked</p>
                    <ul className="mt-1 list-disc space-y-1 pl-4">
                        {gate.blockers.map((blocker) => (
                            <li key={blocker}>{blocker}</li>
                        ))}
                    </ul>
                    <p className="mt-2">
                        {d.can.override_closure
                            ? 'You have the separate override permission. Record the formal reason below; the actor, reason and blockers will be audited.'
                            : 'Complete the listed work before closing. Only a separately authorised manager can override these gates.'}
                    </p>
                </InfoCard>
            ) : null}

            <Field
                label="Closure summary"
                required
                error={form.errors.closure_summary}
            >
                <Textarea
                    rows={4}
                    value={form.data.closure_summary}
                    onChange={(e) =>
                        form.setData('closure_summary', e.target.value)
                    }
                    placeholder="How was this event resolved? What did the investigation and corrective actions conclude?"
                />
            </Field>

            {blocked && d.can.override_closure ? (
                <Field
                    label="Override reason"
                    required
                    hint="Logged"
                    error={form.errors.override_reason}
                >
                    <Textarea
                        rows={3}
                        value={form.data.override_reason}
                        onChange={(e) =>
                            form.setData('override_reason', e.target.value)
                        }
                        placeholder="Why is this event being closed despite the open gate?"
                    />
                </Field>
            ) : null}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !canSubmit}>
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
            <span className={ok ? 'text-foreground' : 'text-status-critical'}>
                <span className="font-semibold">
                    {ok ? 'Complete' : 'Blocked'}:
                </span>{' '}
                {label}
            </span>
        </div>
    );
}

function AcceptHandoverPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm<{ owner_user_id: string; acceptance_notes: string }>({
        owner_user_id: '',
        acceptance_notes: d.handover.notes ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/accept-handover`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };
    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ShieldCheck}
                title="Accept H&S handover"
                blurb="Confirm that Health & Safety has taken ownership of this event and record the acceptance context for the audit trail."
            />
            <InfoCard icon={RadioTower} tone="info">
                Acceptance records that H&amp;S has taken responsibility. The
                operational, incident-review, and H&amp;S governance statuses
                remain separate and the original evidence stays linked.
            </InfoCard>
            <Field
                label="H&S owner"
                hint="Optional — leave blank to assign yourself if you work at this site"
                error={form.errors.owner_user_id}
            >
                <StaffSelect
                    value={form.data.owner_user_id}
                    onChange={(value) => form.setData('owner_user_id', value)}
                    staff={d.assignable_staff}
                    placeholder="Assign me"
                />
            </Field>
            <Field
                label="Acceptance notes"
                hint="Optional"
                error={form.errors.acceptance_notes}
            >
                <Textarea
                    rows={4}
                    value={form.data.acceptance_notes}
                    onChange={(e) =>
                        form.setData('acceptance_notes', e.target.value)
                    }
                    placeholder="What H&S has accepted, any immediate priorities, and what happens next"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Accept handover
                </Button>
            </div>
        </form>
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
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}

function WorksafeNotifyPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        notified_at: string;
        method: string;
        reference: string;
        site_preserved: boolean;
    }>({
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
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
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
                Notify WorkSafe ASAP — phone 0800 030 040 or notify online.
                Preserve the site until an inspector releases it, and keep
                records for at least 5 years.
            </InfoCard>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label="Notified at"
                    required
                    error={form.errors.notified_at}
                >
                    <Input
                        type="date"
                        value={form.data.notified_at}
                        onChange={(e) =>
                            form.setData('notified_at', e.target.value)
                        }
                    />
                </Field>
                <Field label="Method" required error={form.errors.method}>
                    <SelectInput
                        value={form.data.method}
                        onChange={(v) => form.setData('method', v)}
                        placeholder="How was WorkSafe notified?"
                        options={WORKSAFE_METHODS}
                    />
                </Field>
            </div>
            <Field
                label="WorkSafe reference"
                hint="If provided"
                error={form.errors.reference}
            >
                <Input
                    value={form.data.reference}
                    onChange={(e) => form.setData('reference', e.target.value)}
                    placeholder="e.g. WS-2026-0099"
                />
            </Field>
            <label className="flex items-center gap-2 text-sm text-foreground">
                <input
                    type="checkbox"
                    checked={form.data.site_preserved}
                    onChange={(e) =>
                        form.setData('site_preserved', e.target.checked)
                    }
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

function WorksafeAcknowledgePane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm<{ acknowledged_at: string }>({
        acknowledged_at: todayInput(),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/worksafe/acknowledge`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ShieldCheck}
                title="Record WorkSafe acknowledgement"
                blurb="Record the date WorkSafe NZ acknowledged the notification."
            />
            <Field
                label="Acknowledged at"
                required
                error={form.errors.acknowledged_at}
            >
                <Input
                    type="date"
                    value={form.data.acknowledged_at}
                    onChange={(e) =>
                        form.setData('acknowledged_at', e.target.value)
                    }
                />
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

/** Routes an ActivePane to its form. Corrective-action panes land in Step 4b-ii. */
function PaneRenderer({
    pane,
    d,
    onDone,
}: {
    pane: ActivePane;
    d: EventDetail;
    onDone: () => void;
}) {
    const inv =
        'investigationId' in pane
            ? (d.investigations.find((i) => i.id === pane.investigationId) ??
              null)
            : null;
    const ca =
        'actionId' in pane
            ? (d.corrective_actions.find((a) => a.id === pane.actionId) ?? null)
            : null;

    switch (pane.kind) {
        case 'close':
            return <CloseEventPane d={d} onDone={onDone} />;
        case 'accept_handover':
            return <AcceptHandoverPane d={d} onDone={onDone} />;
        case 'worksafe_notify':
            return <WorksafeNotifyPane d={d} onDone={onDone} />;
        case 'worksafe_acknowledge':
            return <WorksafeAcknowledgePane d={d} onDone={onDone} />;
        case 'inv_start':
            return <StartInvestigationPane d={d} onDone={onDone} />;
        case 'inv_findings':
            return inv ? (
                <RecordFindingsPane d={d} inv={inv} onDone={onDone} />
            ) : null;
        case 'inv_complete':
            return inv ? (
                <CompleteInvestigationPane d={d} inv={inv} onDone={onDone} />
            ) : null;
        case 'inv_return':
            return inv ? (
                <ReturnInvestigationPane d={d} inv={inv} onDone={onDone} />
            ) : null;
        case 'inv_disposition':
            return inv ? (
                <RecommendationDispositionPane
                    d={d}
                    inv={inv}
                    recommendationIndex={pane.recommendationIndex}
                    onDone={onDone}
                />
            ) : null;
        case 'ca_add':
            return <AddCorrectiveActionPane d={d} onDone={onDone} />;
        case 'ca_complete':
            return ca ? (
                <CompleteActionPane d={d} ca={ca} onDone={onDone} />
            ) : null;
        case 'ca_verify':
            return ca ? (
                <VerifyActionPane d={d} ca={ca} onDone={onDone} />
            ) : null;
        case 'ca_return':
            return ca ? (
                <ReturnActionPane d={d} ca={ca} onDone={onDone} />
            ) : null;
    }
}

/* ---- shared form bits ---- */

const METHODOLOGIES = [
    { value: '5_whys', label: '5 Whys' },
    { value: 'fishbone', label: 'Fishbone (Ishikawa)' },
    { value: 'bow_tie', label: 'Bow-tie' },
    { value: 'icam', label: 'ICAM' },
    { value: 'taproot', label: 'TapRooT' },
    { value: 'other', label: 'Other' },
];

function methodologyLabel(value: string | null | undefined): string | null {
    if (!value) return null;
    return (
        METHODOLOGIES.find((m) => m.value === value)?.label ??
        value.replace(/_/g, ' ')
    );
}

const PRIORITY_OPTS = ['low', 'medium', 'high', 'critical'];

function StaffSelect({
    value,
    onChange,
    staff,
    placeholder = 'Select…',
}: {
    value: string;
    onChange: (v: string) => void;
    staff: EventDetail['assignable_staff'];
    placeholder?: string;
}) {
    return (
        <SelectInput
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            options={staff.map((s) => ({ value: String(s.id), label: s.name }))}
        />
    );
}

function CauseListEditor({
    label,
    items,
    onChange,
    placeholder,
}: {
    label: string;
    items: { description: string }[];
    onChange: (v: { description: string }[]) => void;
    placeholder: string;
}) {
    return (
        <Field label={label}>
            <div className="flex flex-col gap-1.5">
                {items.map((it, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                        <Input
                            value={it.description}
                            onChange={(e) =>
                                onChange(
                                    items.map((x, idx) =>
                                        idx === i
                                            ? { description: e.target.value }
                                            : x,
                                    ),
                                )
                            }
                            placeholder={placeholder}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="shrink-0 text-muted-foreground"
                            onClick={() =>
                                onChange(items.filter((_, idx) => idx !== i))
                            }
                            aria-label="Remove"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={() => onChange([...items, { description: '' }])}
                >
                    <Plus className="mr-1 h-3.5 w-3.5" /> Add
                </Button>
            </div>
        </Field>
    );
}

function RecommendationEditor({
    items,
    onChange,
}: {
    items: { description: string; priority: string }[];
    onChange: (v: { description: string; priority: string }[]) => void;
}) {
    return (
        <Field label="Recommendations" hint="Required to complete">
            <div className="flex flex-col gap-1.5">
                {items.map((it, i) => (
                    <div key={i} className="flex items-center gap-1.5">
                        <Input
                            value={it.description}
                            onChange={(e) =>
                                onChange(
                                    items.map((x, idx) =>
                                        idx === i
                                            ? {
                                                  ...x,
                                                  description: e.target.value,
                                              }
                                            : x,
                                    ),
                                )
                            }
                            placeholder="Recommended action"
                        />
                        <select
                            value={it.priority}
                            onChange={(e) =>
                                onChange(
                                    items.map((x, idx) =>
                                        idx === i
                                            ? { ...x, priority: e.target.value }
                                            : x,
                                    ),
                                )
                            }
                            className="shrink-0 rounded-md border border-border bg-background px-2 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&>option]:text-foreground"
                            aria-label="Priority"
                        >
                            {PRIORITY_OPTS.map((p) => (
                                <option key={p} value={p}>
                                    {titleCase(p)}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="shrink-0 text-muted-foreground"
                            onClick={() =>
                                onChange(items.filter((_, idx) => idx !== i))
                            }
                            aria-label="Remove"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="self-start"
                    onClick={() =>
                        onChange([
                            ...items,
                            { description: '', priority: 'medium' },
                        ])
                    }
                >
                    <Plus className="mr-1 h-3.5 w-3.5" /> Add recommendation
                </Button>
            </div>
        </Field>
    );
}

/* ---- investigation panes ---- */

function StartInvestigationPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        methodology: string;
        lead_investigator_id: string;
        target_completion_date: string;
    }>({
        methodology: '',
        lead_investigator_id: '',
        target_completion_date: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/investigations`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={Search}
                title="Start investigation"
                blurb="Pick a root-cause methodology and assign a lead. The event moves to Investigating."
            />
            <Field label="Methodology" required error={form.errors.methodology}>
                <SelectInput
                    value={form.data.methodology}
                    onChange={(v) => form.setData('methodology', v)}
                    placeholder="Choose a method"
                    options={METHODOLOGIES}
                />
            </Field>
            <Field
                label="Lead investigator"
                required
                error={form.errors.lead_investigator_id}
            >
                <StaffSelect
                    value={form.data.lead_investigator_id}
                    onChange={(v) => form.setData('lead_investigator_id', v)}
                    staff={d.assignable_staff}
                    placeholder="Assign a lead"
                />
            </Field>
            <Field
                label="Target completion"
                hint="Optional"
                error={form.errors.target_completion_date}
            >
                <Input
                    type="date"
                    value={form.data.target_completion_date}
                    onChange={(e) =>
                        form.setData('target_completion_date', e.target.value)
                    }
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Start investigation
                </Button>
            </div>
        </form>
    );
}

function RecordFindingsPane({
    d,
    inv,
    onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
}) {
    const form = useForm<{
        findings_summary: string;
        immediate_causes: { description: string }[];
        root_causes: { description: string }[];
        contributing_factors: { description: string }[];
        recommendations: { description: string; priority: string }[];
        lessons_learned: string;
    }>({
        findings_summary: inv.findings_summary ?? '',
        immediate_causes:
            inv.immediate_causes?.map((c) => ({
                description: c.description ?? '',
            })) ?? [],
        root_causes:
            inv.root_causes?.map((c) => ({
                description: c.description ?? '',
            })) ?? [],
        contributing_factors:
            inv.contributing_factors?.map((c) => ({
                description: c.description ?? '',
            })) ?? [],
        recommendations:
            inv.recommendations?.map((r) => ({
                description: r.description ?? '',
                priority: r.priority ?? 'medium',
            })) ?? [],
        lessons_learned: inv.lessons_learned ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            immediate_causes: data.immediate_causes.filter((c) =>
                c.description.trim(),
            ),
            root_causes: data.root_causes.filter((c) => c.description.trim()),
            contributing_factors: data.contributing_factors.filter((c) =>
                c.description.trim(),
            ),
            recommendations: data.recommendations.filter((r) =>
                r.description.trim(),
            ),
        }));
        form.post(
            `/health-safety/events/${d.id}/investigations/${inv.id}/findings`,
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={FileText}
                title="Record findings"
                blurb="Capture causes and recommendations. At least one cause or a summary is required; recommendations are needed before you can complete."
            />
            <Field
                label="Findings summary"
                error={form.errors.findings_summary}
            >
                <Textarea
                    rows={3}
                    value={form.data.findings_summary}
                    onChange={(e) =>
                        form.setData('findings_summary', e.target.value)
                    }
                    placeholder="What did the investigation establish?"
                />
            </Field>
            <CauseListEditor
                label="Immediate causes"
                items={form.data.immediate_causes}
                onChange={(v) => form.setData('immediate_causes', v)}
                placeholder="Immediate cause"
            />
            <CauseListEditor
                label="Root causes"
                items={form.data.root_causes}
                onChange={(v) => form.setData('root_causes', v)}
                placeholder="Root cause"
            />
            <CauseListEditor
                label="Contributing factors"
                items={form.data.contributing_factors}
                onChange={(v) => form.setData('contributing_factors', v)}
                placeholder="Contributing factor"
            />
            <RecommendationEditor
                items={form.data.recommendations}
                onChange={(v) => form.setData('recommendations', v)}
            />
            <Field
                label="Lessons learned"
                hint="Optional"
                error={form.errors.lessons_learned}
            >
                <Textarea
                    rows={2}
                    value={form.data.lessons_learned}
                    onChange={(e) =>
                        form.setData('lessons_learned', e.target.value)
                    }
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save findings
                </Button>
            </div>
        </form>
    );
}

function CompleteInvestigationPane({
    d,
    inv,
    onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
}) {
    const form = useForm<{ approved_by_id: string }>({ approved_by_id: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/investigations/${inv.id}/complete`,
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Complete investigation"
                blurb="Approve the investigation. Each recommendation must then receive an explicit outcome; only recommendations needing remediation become corrective actions."
            />
            <InfoCard icon={CheckCircle2} tone="info">
                Completing requires recorded recommendations. You sign off as
                the approver unless you nominate someone else.
            </InfoCard>
            <Field
                label="Approver"
                hint="Defaults to you"
                error={form.errors.approved_by_id}
            >
                <StaffSelect
                    value={form.data.approved_by_id}
                    onChange={(v) => form.setData('approved_by_id', v)}
                    staff={d.assignable_staff}
                    placeholder="You"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Complete investigation
                </Button>
            </div>
        </form>
    );
}

function ReturnInvestigationPane({
    d,
    inv,
    onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
}) {
    const form = useForm<{ review_notes: string }>({ review_notes: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/investigations/${inv.id}/return`,
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={RotateCcw}
                title="Return for rework"
                blurb="Send the investigation back to in-progress with reviewer notes."
            />
            <Field
                label="Review notes"
                required
                error={form.errors.review_notes}
            >
                <Textarea
                    rows={4}
                    value={form.data.review_notes}
                    onChange={(e) =>
                        form.setData('review_notes', e.target.value)
                    }
                    placeholder="What needs more work?"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Return for rework
                </Button>
            </div>
        </form>
    );
}

/** Per-investigation workflow buttons, driven by status. */
function InvestigationControls({
    d,
    inv,
    onPane,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onPane: (p: ActivePane) => void;
}) {
    const base = `/health-safety/events/${d.id}/investigations/${inv.id}`;
    if (
        !['in_progress', 'findings_recorded', 'under_review'].includes(
            inv.status,
        )
    )
        return null;

    return (
        <div className="mt-3 flex flex-wrap gap-2 border-t border-border pt-3">
            {inv.status === 'in_progress' ? (
                <Button
                    size="sm"
                    onClick={() =>
                        onPane({
                            kind: 'inv_findings',
                            investigationId: inv.id,
                        })
                    }
                >
                    <FileText className="mr-1.5 h-4 w-4" /> Record findings
                </Button>
            ) : null}
            {inv.status === 'findings_recorded' ? (
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `${base}/submit`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Send className="mr-1.5 h-4 w-4" /> Submit for review
                </Button>
            ) : null}
            {inv.status === 'under_review' ? (
                <>
                    <Button
                        size="sm"
                        onClick={() =>
                            onPane({
                                kind: 'inv_complete',
                                investigationId: inv.id,
                            })
                        }
                    >
                        <CheckCircle2 className="mr-1.5 h-4 w-4" /> Complete
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            onPane({
                                kind: 'inv_return',
                                investigationId: inv.id,
                            })
                        }
                    >
                        <RotateCcw className="mr-1.5 h-4 w-4" /> Return for
                        rework
                    </Button>
                </>
            ) : null}
        </div>
    );
}

const RECOMMENDATION_OUTCOMES = [
    { value: 'corrective_action', label: 'Raise a corrective action' },
    { value: 'accepted_risk', label: 'Accept the residual risk' },
    { value: 'duplicate', label: 'Covered by another recommendation' },
    { value: 'no_action', label: 'No further action' },
];

function recommendationOutcomeLabel(value: string): string {
    return (
        {
            corrective_action: 'Corrective action',
            accepted_risk: 'Accepted risk',
            duplicate: 'Duplicate',
            no_action: 'No action',
        }[value] ?? titleCase(value)
    );
}

function RecommendationDispositionPane({
    d,
    inv,
    recommendationIndex,
    onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    recommendationIndex: number;
    onDone: () => void;
}) {
    const recommendation = inv.recommendations?.[recommendationIndex];
    const current = recommendation?.disposition;
    const form = useForm<{ disposition: string; reason: string }>({
        disposition: current?.disposition ?? '',
        reason: current?.reason ?? '',
    });
    const raisesAction = form.data.disposition === 'corrective_action';
    const needsReason = form.data.disposition !== '' && !raisesAction;

    if (!recommendation) return null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/investigations/${inv.id}/recommendations/${recommendationIndex}/disposition`,
            { preserveScroll: true, onSuccess: onDone },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ListChecks}
                title={`Recommendation ${recommendationIndex + 1} outcome`}
                blurb="Choose what will happen next. Every recommendation needs one clear, auditable outcome before H&S can close the event."
            />
            <ReviewCard icon={ListChecks} title="Recommendation">
                <ReviewRow
                    label="Recommendation"
                    value={recommendation.description ?? 'Recommendation'}
                />
                <ReviewRow
                    label="Priority"
                    value={
                        recommendation.priority
                            ? titleCase(recommendation.priority)
                            : 'Not set'
                    }
                />
            </ReviewCard>
            <Field label="Outcome" required error={form.errors.disposition}>
                <SelectInput
                    value={form.data.disposition}
                    onChange={(value) => form.setData('disposition', value)}
                    placeholder="Choose an outcome"
                    ariaLabel="Outcome"
                    options={RECOMMENDATION_OUTCOMES}
                />
            </Field>
            {raisesAction ? (
                <InfoCard icon={ListChecks} tone="info">
                    Recording this outcome creates or reuses one linked
                    corrective action. The action must then be completed and
                    independently verified before closure.
                </InfoCard>
            ) : form.data.disposition ? (
                <Field
                    label="Reason"
                    required
                    error={form.errors.reason}
                    hint="Recorded in the audit trail"
                >
                    <Textarea
                        rows={4}
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        placeholder="Explain why this recommendation does not need a new corrective action."
                    />
                </Field>
            ) : null}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.disposition ||
                        (needsReason && !form.data.reason.trim())
                    }
                >
                    Record outcome
                </Button>
            </div>
        </form>
    );
}

/* ---- corrective-action panes ---- */

const ACTION_TYPES = [
    { value: 'corrective', label: 'Corrective' },
    { value: 'preventive', label: 'Preventive' },
    { value: 'improvement', label: 'Improvement' },
];

function AddCorrectiveActionPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        action_type: string;
        assigned_to_user_id: string;
        due_date: string;
    }>({
        title: '',
        description: '',
        priority: 'medium',
        action_type: 'corrective',
        assigned_to_user_id: '',
        due_date: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/events/${d.id}/corrective-actions`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (
                    !(page.props as { flash?: { error?: string } }).flash?.error
                )
                    onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ListChecks}
                title="Add corrective action"
                blurb="Raise a remediation action and drive it to verified. The event moves to Corrective action."
            />
            <Field label="Action" required error={form.errors.title}>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="e.g. Install a grab rail in the bathroom"
                />
            </Field>
            <Field
                label="Detail"
                hint="Optional"
                error={form.errors.description}
            >
                <Textarea
                    rows={2}
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Priority" required error={form.errors.priority}>
                    <SelectInput
                        value={form.data.priority}
                        onChange={(v) => form.setData('priority', v)}
                        placeholder="Priority"
                        options={PRIORITY_OPTS.map((p) => ({
                            value: p,
                            label: titleCase(p),
                        }))}
                    />
                </Field>
                <Field label="Type" error={form.errors.action_type}>
                    <SelectInput
                        value={form.data.action_type}
                        onChange={(v) => form.setData('action_type', v)}
                        placeholder="Type"
                        options={ACTION_TYPES}
                    />
                </Field>
                <Field label="Due" error={form.errors.due_date}>
                    <Input
                        type="date"
                        value={form.data.due_date}
                        onChange={(e) =>
                            form.setData('due_date', e.target.value)
                        }
                    />
                </Field>
            </div>
            <Field
                label="Owner"
                hint="Optional"
                error={form.errors.assigned_to_user_id}
            >
                <StaffSelect
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    staff={d.assignable_staff}
                    placeholder="Unassigned"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Add action
                </Button>
            </div>
        </form>
    );
}

function CompleteActionPane({
    d,
    ca,
    onDone,
}: {
    d: EventDetail;
    ca: EventCorrectiveAction;
    onDone: () => void;
}) {
    const form = useForm<{ completion_notes: string }>({
        completion_notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/corrective-actions/${ca.id}/complete`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Complete action"
                blurb={`${ca.reference_number} · ${ca.title}`}
            />
            <Field
                label="What was done"
                required
                error={form.errors.completion_notes}
            >
                <Textarea
                    rows={4}
                    value={form.data.completion_notes}
                    onChange={(e) =>
                        form.setData('completion_notes', e.target.value)
                    }
                    placeholder="Describe the evidence that this action is complete."
                />
            </Field>
            <InfoCard icon={ShieldCheck} tone="info">
                A different person must verify this action — separation of
                duties.
            </InfoCard>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Mark complete
                </Button>
            </div>
        </form>
    );
}

function VerifyActionPane({
    d,
    ca,
    onDone,
}: {
    d: EventDetail;
    ca: EventCorrectiveAction;
    onDone: () => void;
}) {
    const form = useForm<{
        effectiveness_confirmed: boolean;
        verification_notes: string;
    }>({ effectiveness_confirmed: true, verification_notes: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/corrective-actions/${ca.id}/verify`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={ShieldCheck}
                title="Verify action"
                blurb={`${ca.reference_number} · ${ca.title}`}
            />
            <InfoCard icon={ShieldCheck} tone="warn">
                Separation of duties — the verifier must be a different person
                than whoever completed this action
                {ca.completed_by_name ? ` (${ca.completed_by_name})` : ''}.
            </InfoCard>
            <label className="flex items-center gap-2 text-sm text-foreground">
                <input
                    type="checkbox"
                    checked={form.data.effectiveness_confirmed}
                    onChange={(e) =>
                        form.setData(
                            'effectiveness_confirmed',
                            e.target.checked,
                        )
                    }
                    className="h-4 w-4 rounded border-border"
                />
                The action is effective
            </label>
            <Field
                label="Verification notes"
                hint="Optional"
                error={form.errors.verification_notes}
            >
                <Textarea
                    rows={3}
                    value={form.data.verification_notes}
                    onChange={(e) =>
                        form.setData('verification_notes', e.target.value)
                    }
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Verify
                </Button>
            </div>
        </form>
    );
}

function ReturnActionPane({
    d,
    ca,
    onDone,
}: {
    d: EventDetail;
    ca: EventCorrectiveAction;
    onDone: () => void;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/corrective-actions/${ca.id}/return`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    )
                        onDone();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={RotateCcw}
                title="Return for rework"
                blurb={`${ca.reference_number} · ${ca.title}`}
            />
            <Field label="Reason" required error={form.errors.reason}>
                <Textarea
                    rows={4}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Why is this action being returned?"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Return for rework
                </Button>
            </div>
        </form>
    );
}

/** Two-phase button: first click arms, second confirms — no single-click lifecycle moves. */
function ArmedButton({
    label,
    icon: Icon,
    onConfirm,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    onConfirm: () => void;
}) {
    const [arming, setArming] = useState(false);
    if (!arming) {
        return (
            <Button size="sm" variant="outline" onClick={() => setArming(true)}>
                <Icon className="mr-1.5 h-3.5 w-3.5" /> {label}
            </Button>
        );
    }
    return (
        <span className="inline-flex items-center gap-1">
            <Button
                size="sm"
                onClick={() => {
                    onConfirm();
                    setArming(false);
                }}
            >
                <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> {label}?
            </Button>
            <Button
                size="sm"
                variant="ghost"
                onClick={() => setArming(false)}
                aria-label="Cancel"
            >
                <X className="h-3.5 w-3.5" />
            </Button>
        </span>
    );
}

/** Per-action workflow buttons, driven by status. */
function CorrectiveActionControls({
    d,
    ca,
    onPane,
}: {
    d: EventDetail;
    ca: EventCorrectiveAction;
    onPane: (p: ActivePane) => void;
}) {
    const base = `/health-safety/events/${d.id}/corrective-actions/${ca.id}`;
    // Write controls require manage AND a live event — no lifecycle moves once closed.
    if (!d.can.manage || d.status === 'closed') return null;
    if (!['open', 'in_progress', 'completed', 'verified'].includes(ca.status))
        return null;

    return (
        <div className="mt-2 flex flex-col gap-2 border-t border-border pt-2">
            <div className="flex flex-wrap gap-2">
                {ca.status === 'open' ? (
                    <ArmedButton
                        label="Start"
                        icon={Play}
                        onConfirm={() =>
                            router.post(
                                `${base}/start`,
                                {},
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                    />
                ) : null}
                {ca.status === 'in_progress' ? (
                    <Button
                        size="sm"
                        onClick={() =>
                            onPane({ kind: 'ca_complete', actionId: ca.id })
                        }
                    >
                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Mark
                        complete
                    </Button>
                ) : null}
                {ca.status === 'completed' ? (
                    <>
                        <Button
                            size="sm"
                            disabled={!ca.can_verify}
                            onClick={() =>
                                onPane({ kind: 'ca_verify', actionId: ca.id })
                            }
                            title={
                                ca.can_verify
                                    ? undefined
                                    : 'A different person must verify this action.'
                            }
                        >
                            <ShieldCheck className="mr-1.5 h-3.5 w-3.5" />{' '}
                            Verify
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                onPane({ kind: 'ca_return', actionId: ca.id })
                            }
                        >
                            <RotateCcw className="mr-1.5 h-3.5 w-3.5" /> Return
                            for rework
                        </Button>
                    </>
                ) : null}
                {ca.status === 'verified' ? (
                    <ArmedButton
                        label="Close"
                        icon={CheckCircle2}
                        onConfirm={() =>
                            router.post(
                                `${base}/close`,
                                {},
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                    />
                ) : null}
            </div>
            {ca.status === 'completed' ? (
                <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
                    <ShieldCheck className="mt-0.5 h-3 w-3 shrink-0" />A
                    different person must verify this action than whoever
                    completed it
                    {ca.completed_by_name ? ` (${ca.completed_by_name})` : ''}.
                </p>
            ) : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Governance stage tracker                                           */
/* ------------------------------------------------------------------ */

function StageTracker({ status }: { status: string }) {
    const currentRank = STAGE_ORDER.indexOf(
        status as (typeof STAGE_ORDER)[number],
    );
    return (
        <ol
            className="flex flex-wrap items-center gap-1.5"
            aria-label="Governance stage"
        >
            {STAGE_ORDER.map((key, i) => {
                const s = STAGE[key];
                const done = i < currentRank;
                const current = i === currentRank;
                return (
                    <li key={key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold ${
                                current
                                    ? s.chip
                                    : done
                                      ? 'bg-muted text-foreground'
                                      : 'bg-muted/50 text-muted-foreground'
                            }`}
                            aria-current={current ? 'step' : undefined}
                            title={
                                current
                                    ? `Current stage: ${s.label}`
                                    : done
                                      ? `${s.label} — done`
                                      : s.label
                            }
                        >
                            {done ? (
                                <CheckCircle2 className="h-3 w-3" />
                            ) : (
                                <s.icon className="h-3 w-3" />
                            )}
                            {s.label}
                        </span>
                        {i < STAGE_ORDER.length - 1 ? (
                            <ChevronRight className="h-3 w-3 text-muted-foreground/50" />
                        ) : null}
                    </li>
                );
            })}
        </ol>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function HandoverOverview({ d }: { d: EventDetail }) {
    const handover = d.handover;
    const accepted = handover.status === 'accepted';
    return (
        <>
            <ReviewCard icon={RadioTower} title="H&S handover" span>
                <span
                    className={`mb-2 inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold ${accepted ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning'}`}
                >
                    {accepted ? (
                        <CheckCircle2 className="h-3.5 w-3.5" />
                    ) : (
                        <Clock className="h-3.5 w-3.5" />
                    )}
                    {handoverStatusLabel(handover.status)}
                </span>
                <ReviewRow
                    label="Owner"
                    value={handover.owner?.name ?? 'No H&S owner assigned'}
                />
                {handover.accepted_by ? (
                    <ReviewRow
                        label="Accepted by"
                        value={handover.accepted_by.name}
                    />
                ) : null}
                {handover.accepted_at ? (
                    <ReviewRow
                        label="Accepted"
                        value={formatDateTime(handover.accepted_at)}
                    />
                ) : null}
                {handover.notes ? (
                    <p className="mt-3 text-sm whitespace-pre-wrap text-muted-foreground">
                        {handover.notes}
                    </p>
                ) : null}
            </ReviewCard>
            <CrossModuleLifecycle lifecycle={d.lifecycle} />
        </>
    );
}

function CrossModuleLifecycle({ lifecycle }: { lifecycle: EventLifecycle }) {
    const stages = [
        {
            label: 'Control Room',
            status: lifecycle.control_room,
            icon: RadioTower,
        },
        { label: 'Incident', status: lifecycle.incident, icon: AlertTriangle },
        {
            label: 'Health & Safety',
            status: lifecycle.health_safety,
            icon: ShieldCheck,
        },
    ];
    return (
        // eslint-disable-next-line no-restricted-syntax -- custom three-system lifecycle surface within the governance dialog
        <div className="rounded-xl border border-border bg-card/70 p-4 sm:col-span-2">
            <p className="mb-3 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                Connected lifecycle
            </p>
            <ol
                className="grid gap-2 sm:grid-cols-3"
                aria-label="Control Room, incident and Health & Safety lifecycle"
            >
                {stages.map(({ label, status, icon: EmptyIcon }, index) => {
                    const waiting = Boolean(
                        status?.includes('awaiting') ||
                        status?.includes('pending'),
                    );
                    const complete = Boolean(
                        status &&
                        [
                            'accepted',
                            'acknowledged',
                            'closed',
                            'completed',
                            'resolved',
                        ].some((value) => status.includes(value)),
                    );
                    const StateIcon =
                        !status || (!waiting && !complete)
                            ? EmptyIcon
                            : waiting
                              ? Clock
                              : CheckCircle2;
                    return (
                        <li
                            key={label}
                            className="relative rounded-lg border border-border bg-background/70 p-3"
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
                                <StateIcon
                                    className={`h-4 w-4 ${!status ? 'text-muted-foreground' : waiting ? 'text-status-warning' : complete ? 'text-status-success' : 'text-primary'}`}
                                />
                                {label}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {lifecycleStatusLabel(status)}
                            </p>
                            {index < stages.length - 1 ? (
                                <ChevronRight className="absolute top-1/2 -right-3 z-10 hidden h-4 w-4 -translate-y-1/2 text-muted-foreground/60 sm:block" />
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function OverviewSection({
    d,
    cat,
    stage,
}: {
    d: EventDetail;
    cat: string;
    stage: { label: string };
}) {
    return (
        <div className="flex flex-col gap-4">
            {/* eslint-disable-next-line no-restricted-syntax -- custom governance layout surface */}
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <p className="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    Governance stage
                </p>
                <StageTracker status={d.status} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <HandoverOverview d={d} />
            </div>

            {d.worksafe_notifiable ? <WorkSafeBanner d={d} /> : null}

            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewCard icon={FileText} title="Event">
                    <ReviewRow label="Reference" value={d.reference_number} />
                    <ReviewRow label="Category" value={cat} />
                    <ReviewRow
                        label="Severity"
                        value={SEV[d.severity]?.label ?? d.severity}
                    />
                    <ReviewRow label="Stage" value={stage.label} />
                    <ReviewRow
                        label="Occurred"
                        value={
                            d.occurred_at
                                ? formatDateTime(d.occurred_at)
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Reported"
                        value={
                            d.reported_at
                                ? formatDateTime(d.reported_at)
                                : undefined
                        }
                    />
                    <ReviewRow label="Logged by" value={d.created_by_name} />
                </ReviewCard>

                <ReviewCard icon={UserIcon} title="Context">
                    <ReviewRow
                        label="Site"
                        value={
                            d.site ? (
                                <Link
                                    href={`/sites/${d.site.id}`}
                                    className="text-primary hover:underline"
                                >
                                    {d.site.name}
                                </Link>
                            ) : undefined
                        }
                    />
                    <ReviewRow
                        label="Client"
                        value={
                            d.client ? (
                                <Link
                                    href={`/operations/clients/${d.client.id}/care`}
                                    className="text-primary hover:underline"
                                >
                                    {d.client.name}
                                </Link>
                            ) : undefined
                        }
                    />
                    <ReviewRow label="Staff" value={d.staff?.name} />
                    <ReviewRow label="Asset" value={d.asset?.name} />
                    <ReviewRow
                        label="Control Room"
                        value={
                            d.control_room_alert?.url ? (
                                <Link
                                    href={d.control_room_alert.url}
                                    className="inline-flex items-center gap-1 text-primary hover:underline"
                                >
                                    <RadioTower className="h-3 w-3" />
                                    {d.control_room_alert.reference_number}
                                </Link>
                            ) : d.control_room_alert ? (
                                d.control_room_alert.reference_number
                            ) : undefined
                        }
                    />
                </ReviewCard>
            </div>

            <OriginatingRecordCard source={d.source} />

            {d.description ? (
                <ReviewCard icon={FileText} title="What happened" span>
                    <p className="text-sm whitespace-pre-wrap text-foreground">
                        {d.description}
                    </p>
                </ReviewCard>
            ) : null}

            {d.closed_at ? (
                <ReviewCard icon={CheckCircle2} title="Closure" span>
                    <ReviewRow
                        label="Closed"
                        value={formatDateTime(d.closed_at)}
                    />
                    {d.closure_summary ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {d.closure_summary}
                        </p>
                    ) : null}
                </ReviewCard>
            ) : null}
        </div>
    );
}

function HandoverSection({ d }: { d: EventDetail }) {
    const summary = d.handover_summary;
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <HandoverOverview d={d} />
                <ReviewCard icon={LinkIcon} title="Official references">
                    <ReviewRow
                        label="Incident"
                        value={summary.incident_reference}
                    />
                    <ReviewRow
                        label="Control Room alert"
                        value={summary.alert_reference}
                    />
                    <ReviewRow label="Source" value={summary.source_label} />
                    <ReviewRow label="Site" value={summary.site_name} />
                </ReviewCard>
                <ReviewCard icon={UserIcon} title="People & consequence">
                    <ReviewRow label="Reported by" value={summary.reporter} />
                    <ReviewRow label="Witnesses" value={summary.witnesses} />
                    <ReviewRow
                        label="Potential consequence"
                        value={summary.potential_consequence}
                    />
                </ReviewCard>
            </div>

            <ReviewCard icon={FileText} title="Handover narrative" span>
                <HandoverText label="What happened" value={summary.narrative} />
                <HandoverText
                    label="Immediate controls"
                    value={summary.immediate_controls}
                />
            </ReviewCard>

            {summary.next_action ? (
                <InfoCard icon={ChevronRight} tone="info">
                    <span className="font-semibold">Next action:</span>{' '}
                    {summary.next_action.href ? (
                        <Link
                            href={summary.next_action.href}
                            className="font-medium text-primary hover:underline"
                        >
                            {summary.next_action.label}
                        </Link>
                    ) : (
                        summary.next_action.label
                    )}
                </InfoCard>
            ) : null}

            <ReviewCard icon={Paperclip} title="Handover attachments" span>
                {summary.attachments.length ? (
                    <div className="flex flex-col gap-2">
                        {summary.attachments.map((attachment) => (
                            <AttachmentRow
                                key={attachment.id}
                                attachment={attachment}
                            />
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No attachments were handed over.
                    </p>
                )}
            </ReviewCard>

            <ReviewCard icon={RadioTower} title="Control Room evidence" span>
                {summary.control_room_evidence.length ? (
                    <div className="flex flex-col gap-3">
                        {summary.control_room_evidence.map((evidence) => (
                            <div
                                key={evidence.id}
                                className="rounded-lg border border-border p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-sm font-semibold text-foreground">
                                        {evidence.title}
                                    </p>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                        <CheckCircle2 className="h-3 w-3" />{' '}
                                        {titleCase(evidence.status)}
                                    </span>
                                </div>
                                {evidence.items.map((item) => (
                                    <div
                                        key={item.id}
                                        className="mt-2 rounded-md bg-muted/40 p-2.5"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-medium text-foreground">
                                                    {item.title}
                                                </p>
                                                {item.description ? (
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {item.description}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {item.download_url ? (
                                                <a
                                                    href={item.download_url}
                                                    className="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline"
                                                >
                                                    <ExternalLink className="h-3.5 w-3.5" />{' '}
                                                    Open
                                                </a>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No Control Room evidence was attached.
                    </p>
                )}
            </ReviewCard>

            <div className="grid gap-4 sm:grid-cols-2">
                <ReviewCard icon={Play} title="Control Room playbook">
                    {summary.playbook ? (
                        <>
                            <ReviewRow
                                label="Playbook"
                                value={
                                    summary.playbook.name ??
                                    'Operational playbook'
                                }
                            />
                            <ReviewRow
                                label="Status"
                                value={titleCase(summary.playbook.status)}
                            />
                            {summary.playbook.outcome ? (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {summary.playbook.outcome}
                                </p>
                            ) : null}
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No playbook run was linked.
                        </p>
                    )}
                </ReviewCard>
                <ReviewCard icon={Send} title="Communications">
                    {summary.communications.length ? (
                        summary.communications.map((communication) => (
                            <div
                                key={communication.id}
                                className="border-b border-border py-2 last:border-0"
                            >
                                <p className="text-sm font-medium text-foreground">
                                    {communication.purpose ??
                                        'Operational update'}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {titleCase(communication.channel)} ·{' '}
                                    {titleCase(communication.status)}
                                    {communication.sent_at
                                        ? ` · ${formatDateTime(communication.sent_at)}`
                                        : ''}
                                </p>
                                {communication.content ? (
                                    <p className="mt-1 text-xs text-foreground">
                                        {communication.content}
                                    </p>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No communications were recorded.
                        </p>
                    )}
                </ReviewCard>
            </div>

            <ReviewCard icon={ListChecks} title="Operational tasks" span>
                {summary.operational_tasks.length ? (
                    summary.operational_tasks.map((task) => (
                        <div
                            key={task.id}
                            className="flex flex-wrap items-start justify-between gap-3 border-b border-border py-2 last:border-0"
                        >
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    {task.title}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {task.assignee ?? 'Unassigned'}
                                    {task.due_at
                                        ? ` · due ${formatDateTime(task.due_at)}`
                                        : ''}
                                </p>
                            </div>
                            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                <ListChecks className="h-3 w-3" />{' '}
                                {titleCase(task.status)} ·{' '}
                                {titleCase(task.priority)}
                            </span>
                        </div>
                    ))
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No operational tasks were handed over.
                    </p>
                )}
            </ReviewCard>
        </div>
    );
}

function HandoverText({
    label,
    value,
}: {
    label: string;
    value: string | null;
}) {
    return (
        <div className="border-b border-border py-2 last:border-0">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                {value ?? '—'}
            </p>
        </div>
    );
}

function AttachmentRow({ attachment }: { attachment: EventAttachment }) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-border p-3">
            <FileText className="h-5 w-5 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">
                    {attachment.name}
                </p>
                <p className="text-xs text-muted-foreground">
                    {fmtSize(attachment.size)}
                    {attachment.uploaded_by
                        ? ` · ${attachment.uploaded_by}`
                        : ''}
                    {attachment.created_at
                        ? ` · ${formatDateTime(attachment.created_at)}`
                        : ''}
                </p>
            </div>
            <a
                href={attachment.download_url}
                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-3.5 w-3.5" /> Open
            </a>
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
                <p className="text-sm font-medium text-foreground">
                    Originating record
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {source.label}
                </p>
            </div>
            {source.url && !source.unwired ? (
                <ExternalLink className="h-4 w-4 text-muted-foreground" />
            ) : null}
        </>
    );
    if (source.url && !source.unwired) {
        return (
            <Link
                href={source.url}
                className="flex items-center gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-muted/50"
            >
                {body}
            </Link>
        );
    }
    return (
        <div
            className="flex items-center gap-3 rounded-xl border border-dashed border-border p-3"
            title={
                source.unwired
                    ? 'This category has no originating module yet.'
                    : undefined
            }
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
    const notified =
        d.worksafe_status === 'notified' ||
        d.worksafe_status === 'acknowledged';
    const acknowledged = d.worksafe_status === 'acknowledged';
    const methodLabel = d.worksafe_method
        ? (WORKSAFE_METHOD_LABELS[d.worksafe_method] ??
          d.worksafe_method.replace(/_/g, ' '))
        : null;
    return (
        <InfoCard icon={ShieldAlert} tone="crit">
            <span className="font-semibold">
                WorkSafe NZ notifiable event (HSWA 2015).
            </span>{' '}
            {acknowledged
                ? `Acknowledged by WorkSafe${d.worksafe_acknowledged_at ? ` ${formatDateTime(d.worksafe_acknowledged_at)}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''}.`
                : notified
                  ? `Notified${d.worksafe_notified_at ? ` ${formatDateTime(d.worksafe_notified_at)}` : ''}${methodLabel ? ` by ${methodLabel}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''} — awaiting acknowledgement.`
                  : 'Notification to WorkSafe NZ is still pending.'}
            <span className="mt-2 flex flex-wrap gap-1.5">
                <DutyChip label="Notify ASAP" done={notified} />
                <DutyChip
                    label={
                        d.worksafe_site_preserved
                            ? 'Site preserved'
                            : 'Preserve the site until released'
                    }
                    done={d.worksafe_site_preserved}
                />
                <DutyChip label="Keep records ≥ 5 years" />
            </span>
        </InfoCard>
    );
}

function DutyChip({ label, done = false }: { label: string; done?: boolean }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium ${
                done
                    ? 'border-status-success/40 bg-status-success-bg text-status-success'
                    : 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
            }`}
        >
            {done ? (
                <CheckCircle2 className="h-3 w-3" />
            ) : (
                <ShieldCheck className="h-3 w-3" />
            )}{' '}
            {label}
        </span>
    );
}

function InvestigationGate({ status }: { status: string }) {
    const rank = INV_ORDER.indexOf(status as (typeof INV_ORDER)[number]);
    return (
        <ol
            className="flex flex-wrap items-center gap-1.5"
            aria-label="Investigation lifecycle"
        >
            {INV_ORDER.map((key, i) => {
                const done = i < rank;
                const current = i === rank;
                return (
                    <li key={key} className="flex items-center gap-1.5">
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold ${
                                current
                                    ? 'bg-primary/10 text-primary'
                                    : done
                                      ? 'bg-muted text-foreground'
                                      : 'bg-muted/50 text-muted-foreground'
                            }`}
                            aria-current={current ? 'step' : undefined}
                        >
                            {done ? <CheckCircle2 className="h-3 w-3" /> : null}
                            {INV_STAGE[key]}
                        </span>
                        {i < INV_ORDER.length - 1 ? (
                            <ChevronRight className="h-3 w-3 text-muted-foreground/50" />
                        ) : null}
                    </li>
                );
            })}
        </ol>
    );
}

function InvestigationSection({
    d,
    canAct,
    onPane,
}: {
    d: EventDetail;
    canAct: boolean;
    onPane: (p: ActivePane) => void;
}) {
    if (!d.investigations.length) {
        return (
            <div className="flex flex-col gap-4">
                <EmptyState
                    icon={Search}
                    title="No investigation yet"
                    blurb={
                        d.investigation_required
                            ? 'An investigation is required for this event.'
                            : 'No investigation has been opened.'
                    }
                />
                {canAct ? (
                    <Button
                        className="self-start"
                        size="sm"
                        onClick={() => onPane({ kind: 'inv_start' })}
                    >
                        <Plus className="mr-1.5 h-4 w-4" /> Start investigation
                    </Button>
                ) : null}
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-4">
            {d.investigations.map((inv) => (
                // eslint-disable-next-line no-restricted-syntax -- custom governance layout surface
                <div
                    key={inv.id}
                    className="rounded-xl border border-border bg-card/70 p-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span className="font-semibold text-foreground">
                                {inv.reference_number}
                            </span>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                {titleCase(inv.investigation_type)}
                            </span>
                            {inv.is_overdue ? (
                                <span
                                    className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-medium text-status-critical"
                                    title="Investigation overdue"
                                >
                                    <Clock className="h-3 w-3" /> Overdue
                                </span>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                            {inv.lead_investigator_name ? (
                                <span>
                                    <UserIcon className="mr-0.5 inline h-3 w-3" />
                                    {inv.lead_investigator_name}
                                </span>
                            ) : null}
                            {inv.methodology ? (
                                <span>{methodologyLabel(inv.methodology)}</span>
                            ) : null}
                            {inv.target_completion_date ? (
                                <span>
                                    Due{' '}
                                    {formatDateTime(inv.target_completion_date)}
                                </span>
                            ) : null}
                        </div>
                    </div>

                    <div className="mt-3">
                        <InvestigationGate status={inv.status} />
                    </div>

                    {inv.has_findings ? (
                        <div className="mt-4 space-y-3 border-t border-border pt-3">
                            {inv.findings_summary ? (
                                <Finding
                                    label="Findings"
                                    text={inv.findings_summary}
                                />
                            ) : null}
                            <CauseList
                                label="Immediate causes"
                                causes={inv.immediate_causes}
                            />
                            <CauseList
                                label="Root causes"
                                causes={inv.root_causes}
                            />
                            <CauseList
                                label="Contributing factors"
                                causes={inv.contributing_factors}
                            />
                            {inv.recommendations &&
                            inv.recommendations.length > 0 ? (
                                <div>
                                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        Recommendations (
                                        {inv.recommendation_count})
                                    </p>
                                    <ul className="mt-1 space-y-1">
                                        {inv.recommendations.map((r, i) => (
                                            <li
                                                key={i}
                                                className="rounded-lg border border-border bg-background/70 p-3 text-sm"
                                            >
                                                <div className="flex items-start gap-2">
                                                    {r.priority ? (
                                                        <span
                                                            className={`mt-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-medium ${PRIORITY[r.priority] ?? PRIORITY.medium}`}
                                                        >
                                                            {titleCase(
                                                                r.priority,
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    <span className="min-w-0 flex-1 text-foreground">
                                                        {r.description}
                                                    </span>
                                                    {canAct &&
                                                    inv.status ===
                                                        'completed' ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="shrink-0"
                                                            onClick={() =>
                                                                onPane({
                                                                    kind: 'inv_disposition',
                                                                    investigationId:
                                                                        inv.id,
                                                                    recommendationIndex:
                                                                        i,
                                                                })
                                                            }
                                                        >
                                                            {r.disposition
                                                                ? 'Change outcome'
                                                                : 'Choose outcome'}
                                                        </Button>
                                                    ) : null}
                                                </div>
                                                {r.disposition ? (
                                                    <div className="mt-2 border-t border-border pt-2 text-xs">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="inline-flex items-center gap-1 rounded-full bg-status-success-bg px-2 py-0.5 font-medium text-status-success">
                                                                <CheckCircle2 className="h-3 w-3" />
                                                                {recommendationOutcomeLabel(
                                                                    r
                                                                        .disposition
                                                                        .disposition,
                                                                )}
                                                            </span>
                                                            {r.disposition
                                                                .corrective_action ? (
                                                                <Link
                                                                    href={`/health-safety/corrective-actions?event=${d.id}&action=${r.disposition.corrective_action.id}`}
                                                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                                                >
                                                                    {
                                                                        r
                                                                            .disposition
                                                                            .corrective_action
                                                                            .reference_number
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {titleCase(
                                                                        r
                                                                            .disposition
                                                                            .corrective_action
                                                                            .status,
                                                                    )}
                                                                </Link>
                                                            ) : null}
                                                        </div>
                                                        {r.disposition
                                                            .reason ? (
                                                            <p className="mt-1 text-foreground">
                                                                {
                                                                    r
                                                                        .disposition
                                                                        .reason
                                                                }
                                                            </p>
                                                        ) : null}
                                                        <p className="mt-1 text-muted-foreground">
                                                            Decided by{' '}
                                                            {r.disposition
                                                                .decided_by_name ??
                                                                'H&S team'}
                                                            {r.disposition
                                                                .decided_at
                                                                ? ` · ${formatDateTime(r.disposition.decided_at)}`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <p className="mt-2 text-xs font-medium text-status-warning">
                                                        Outcome needed before
                                                        H&S closure
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                            {inv.lessons_learned ? (
                                <Finding
                                    label="Lessons learned"
                                    text={inv.lessons_learned}
                                />
                            ) : null}
                        </div>
                    ) : null}

                    {canAct ? (
                        <InvestigationControls
                            d={d}
                            inv={inv}
                            onPane={onPane}
                        />
                    ) : null}
                </div>
            ))}
        </div>
    );
}

function Finding({ label, text }: { label: string; text: string }) {
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                {text}
            </p>
        </div>
    );
}

function CauseList({
    label,
    causes,
}: {
    label: string;
    causes: JsonCause[] | null;
}) {
    if (!causes || causes.length === 0) return null;
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-sm text-foreground">
                {causes.map((c, i) => (
                    <li key={i}>{c.description}</li>
                ))}
            </ul>
        </div>
    );
}

function ActionsSection({
    d,
    openActions,
    awaitingVerification,
    canAct,
    onPane,
    rowRefs,
    highlightActionId,
}: {
    d: EventDetail;
    openActions: number;
    awaitingVerification: number;
    canAct: boolean;
    onPane: (p: ActivePane) => void;
    /** Refs keyed by action id so a deep-linked card can be scrolled into view. */
    rowRefs: MutableRefObject<Record<number, HTMLDivElement | null>>;
    /** The action id to ring transiently after a deep-link (prompt E). */
    highlightActionId: number | null;
}) {
    if (!d.corrective_actions.length) {
        return (
            <div className="flex flex-col gap-4">
                <EmptyState
                    icon={ListChecks}
                    title="No corrective actions"
                    blurb="Corrective actions are raised from investigation recommendations or added directly, then driven to verified."
                />
                {canAct ? (
                    <Button
                        className="self-start"
                        size="sm"
                        onClick={() => onPane({ kind: 'ca_add' })}
                    >
                        <Plus className="mr-1.5 h-4 w-4" /> Add corrective
                        action
                    </Button>
                ) : null}
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-3">
            {canAct ? (
                <Button
                    className="self-start"
                    size="sm"
                    variant="outline"
                    onClick={() => onPane({ kind: 'ca_add' })}
                >
                    <Plus className="mr-1.5 h-4 w-4" /> Add corrective action
                </Button>
            ) : null}
            <div className="flex flex-wrap gap-2 text-xs">
                {openActions > 0 ? (
                    <span className="rounded-full bg-status-warning-bg px-2 py-0.5 font-medium text-status-warning">
                        {openActions} open
                    </span>
                ) : null}
                {awaitingVerification > 0 ? (
                    <span className="rounded-full bg-status-info-bg px-2 py-0.5 font-medium text-status-info">
                        {awaitingVerification} awaiting verification
                    </span>
                ) : null}
            </div>

            <div className="flex flex-col gap-2">
                {d.corrective_actions.map((a) => {
                    const st = CA_STATUS[a.status] ?? CA_STATUS.open;
                    const highlighted = highlightActionId === a.id;
                    return (
                        <div
                            key={a.id}
                            ref={(node) => {
                                rowRefs.current[a.id] = node;
                            }}
                            className={`rounded-lg border p-3 transition-shadow duration-300 ${
                                highlighted
                                    ? 'ring-2 ring-ring ring-offset-2 ring-offset-background'
                                    : ''
                            } ${a.is_overdue && a.status !== 'verified' && a.status !== 'closed' ? 'border-status-critical/40 bg-status-critical-bg/40' : 'border-border'}`}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-foreground">
                                        {a.reference_number} · {a.title}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {a.assigned_to_name ?? 'Unassigned'}
                                        {a.due_date
                                            ? ` · due ${formatDateTime(a.due_date)}`
                                            : ''}
                                        {a.is_overdue &&
                                        a.status !== 'verified' &&
                                        a.status !== 'closed'
                                            ? ' · overdue'
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${PRIORITY[a.priority] ?? PRIORITY.medium}`}
                                    >
                                        {titleCase(a.priority)}
                                    </span>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${st.chip}`}
                                    >
                                        {st.label}
                                    </span>
                                </div>
                            </div>
                            {a.verified_at ? (
                                <p className="mt-2 flex items-center gap-1 text-xs text-status-success">
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                    Verified
                                    {a.verified_by_name
                                        ? ` by ${a.verified_by_name}`
                                        : ''}{' '}
                                    ·{' '}
                                    {a.effectiveness_confirmed
                                        ? 'effective'
                                        : 'not yet effective'}
                                </p>
                            ) : null}

                            {canAct ? (
                                <CorrectiveActionControls
                                    d={d}
                                    ca={a}
                                    onPane={onPane}
                                />
                            ) : null}
                        </div>
                    );
                })}
            </div>

            <p className="mt-1 flex items-start gap-1.5 text-xs text-muted-foreground">
                <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Separation of duties: a corrective action must be verified by
                someone other than the person who completed it.
            </p>
        </div>
    );
}

function RiskSection({ d }: { d: EventDetail }) {
    const registerLink = (
        <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted-foreground">
                Risk assessments linked to this event
            </span>
            <Link
                href={`/health-safety/risk-assessments?hs_event_id=${d.id}`}
                className="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
            >
                View in register <ChevronRight className="h-3.5 w-3.5" />
            </Link>
        </div>
    );
    if (!d.risk_assessments.length) {
        return (
            <div className="flex flex-col gap-3">
                {registerLink}
                <EmptyState
                    icon={Activity}
                    title="No linked risk assessments"
                    blurb="5×5 likelihood × consequence assessments linked to this event appear here. Open the register to create one for this event."
                />
            </div>
        );
    }
    return (
        <div className="flex flex-col gap-4">
            {registerLink}
            {d.risk_assessments.map((ra) => (
                // eslint-disable-next-line no-restricted-syntax -- custom governance layout surface
                <div
                    key={ra.id}
                    className="rounded-xl border border-border bg-card/70 p-4"
                >
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold text-foreground">
                                    {ra.reference_number}
                                </span>
                                {ra.is_due_for_review ? (
                                    <span
                                        className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-medium text-status-warning"
                                        title="Review due"
                                    >
                                        <Clock className="h-3 w-3" /> Review due
                                    </span>
                                ) : null}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {ra.title}
                            </p>
                            <div className="mt-3 flex items-center gap-3">
                                <RiskScore
                                    label="Inherent"
                                    score={ra.risk_score}
                                    level={ra.risk_level}
                                />
                                {ra.residual_risk_score != null ? (
                                    <>
                                        <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                        <RiskScore
                                            label="Residual"
                                            score={ra.residual_risk_score}
                                            level={
                                                ra.residual_risk_level ??
                                                ra.risk_level
                                            }
                                        />
                                    </>
                                ) : null}
                            </div>
                        </div>
                        {ra.likelihood ? (
                            <div className="shrink-0">
                                <RiskMatrix
                                    likelihood={ra.likelihood}
                                    consequence={ra.consequence ?? 1}
                                    residualLikelihood={
                                        ra.residual_likelihood ?? undefined
                                    }
                                    residualConsequence={
                                        ra.residual_consequence ?? undefined
                                    }
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

function RiskScore({
    label,
    score,
    level,
}: {
    label: string;
    score: number;
    level: string;
}) {
    const cls =
        level === 'extreme' || level === 'high'
            ? 'bg-status-critical-bg text-status-critical'
            : level === 'medium'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-status-success-bg text-status-success';
    return (
        <div className="text-center">
            <div className="text-[11px] text-muted-foreground">{label}</div>
            <div
                className={`mt-1 rounded-md px-3 py-1 text-sm font-bold ${cls}`}
            >
                {score}
            </div>
            <div className="mt-0.5 text-[11px] text-muted-foreground capitalize">
                {level}
            </div>
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
        return (
            <EmptyState
                icon={Paperclip}
                title="No evidence attached"
                blurb="Photos, documents and reports attached to this event appear here."
            />
        );
    }
    return (
        <div className="flex flex-col gap-2">
            {d.attachments.map((a) => (
                <div
                    key={a.id}
                    className="flex items-center gap-3 rounded-lg border border-border p-3"
                >
                    <FileText className="h-5 w-5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">
                            {a.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {fmtSize(a.size)}
                            {a.uploaded_by ? ` · ${a.uploaded_by}` : ''}
                            {a.created_at
                                ? ` · ${formatDateTime(a.created_at)}`
                                : ''}
                        </p>
                    </div>
                    <a
                        href={a.download_url}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted"
                    >
                        <ExternalLink className="h-3.5 w-3.5" /> Open
                    </a>
                </div>
            ))}
        </div>
    );
}

function EmptyState({
    icon: Icon,
    title,
    blurb,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    blurb: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-dashed border-border py-12 text-center">
            <Icon className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm font-medium text-foreground">{title}</p>
            <p className="mt-1 text-xs text-muted-foreground">{blurb}</p>
        </div>
    );
}

export default EventDetailDialog;
