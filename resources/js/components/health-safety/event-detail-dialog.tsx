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
import {
    CorrectiveActionHandoverPane,
    type CorrectiveActionHandover,
} from '@/components/health-safety/corrective-action-handover-pane';
import { EventTimeline } from '@/components/health-safety/event-timeline';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import {
    JourneyGateList,
    type JourneyGateData,
} from '@/components/incidents/journey-gate-list';
import {
    LinkedOperationalEvidence,
    type LinkedOperationalEvidenceData,
} from '@/components/incidents/linked-operational-evidence';
import { JourneyTermHelp } from '@/components/journey-term-help';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/components/ui/file-dropzone';
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
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
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
    submitted_by_name?: string | null;
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
    owner: { id: number; name: string } | null;
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
    recommendation: string | null;
    source:
        | {
              type: 'control_room_task';
              id: number;
              reference: string;
              title: string;
          }
        | { type: 'new_responsibility'; reason: string | null }
        | { type: 'standalone' };
    source_task: {
        id: number;
        reference: string;
        title: string;
    } | null;
    evidence: {
        can_upload: boolean;
        completion_notes: string | null;
        legacy_paths: string[];
        completed_by: { id: number; name: string } | null;
        completed_at: string | null;
        load_state: 'loaded' | 'unavailable';
        attachments: Array<{
            id: number;
            original_name: string;
            mime_type: string | null;
            size_bytes: number | null;
            description: string | null;
            uploaded_by: string | null;
            created_at: string | null;
            download_url: string;
            can_remove: boolean;
        }>;
    };
    rework: { latest_reason: string | null };
    history: Array<{
        label: string;
        actor: string | null;
        occurred_at: string | null;
        detail?: string | null;
    }>;
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

export type WorksafeState = {
    notifiable: boolean | null;
    status: string | null;
    decision_signed?: boolean;
};

export type EventWorksafe = WorksafeState & {
    decision_reason: string | null;
    decision_source: string | null;
    decision_signed: boolean;
    decision_tree_version?: string | null;
    source_effective_date?: string | null;
    decision_support?: {
        version: string;
        source_effective_date: string;
        source_reviewed_date: string;
        next_mandatory_review_date: string;
        source_url: string;
        content_owner: string;
        specified_injury_or_illness: string[];
        specified_injury_or_illness_labels: string[];
        dangerous_incidents: string[];
        dangerous_incident_labels: string[];
    };
    decided_at: string | null;
    decided_by: { id: number; name: string } | null;
    reference: string | null;
    notified_at: string | null;
    acknowledged_at: string | null;
    method: string | null;
    site_preserved: boolean;
    site_preservation_status: 'active' | 'released' | 'not_required' | null;
    site_preservation_decided_at: string | null;
    site_preservation_decided_by: { id: number; name: string } | null;
    site_preservation_decision_reference: string | null;
    site_preservation_released_at: string | null;
    site_preservation_released_by: { id: number; name: string } | null;
    site_preservation_release_reference: string | null;
    can_decide: boolean;
    can_notify: boolean;
    can_acknowledge: boolean;
    can_review_site_preservation: boolean;
    can_release_site_preservation: boolean;
};

export type ClosureRequirement = {
    key: string;
    complete: boolean;
    label: string;
    href: string;
    classification: 'hard' | 'exceptional';
};

export type ClosureException = {
    id: number;
    status:
        | 'pending'
        | 'approved'
        | 'rejected'
        | 'revoked'
        | 'expired'
        | 'review_due';
    category: string;
    reason: string;
    evidence_reference: string;
    scope: string[];
    requester: { id: number; name: string } | null;
    approver: { id: number; name: string } | null;
    decision_reason: string | null;
    created_at: string | null;
    requested_at: string | null;
    decided_at: string | null;
    expires_at: string | null;
    review_at: string | null;
    provenance_hash: string;
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
    worksafe: EventWorksafe;
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
    linked_operational_evidence: LinkedOperationalEvidenceData | null;
    incident_followups: Array<{
        id: number;
        notes: string | null;
        assigned_to: string | null;
        due_at: string | null;
        completed_at: string | null;
    }>;
    close_gate: JourneyGateData;
    close_readiness: {
        ordinary_allowed: boolean;
        requirements: ClosureRequirement[];
        hard_blockers: ClosureRequirement[];
        exceptional_blockers: ClosureRequirement[];
    };
    closure_exceptions: ClosureException[];
    journey_state: string;
    assignable_staff: Array<{ id: number; name: string }>;
    action_handover: CorrectiveActionHandover;
    can: {
        manage: boolean;
        close: boolean;
        request_closure_exception: boolean;
        approve_closure_exception: boolean;
        manage_corrective_action_lifecycle: boolean;
        verify_corrective_actions: boolean;
    };
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
    | 'accept_handover'
    | 'worksafe_decision'
    | 'worksafe_notify'
    | 'worksafe_acknowledge'
    | 'worksafe_site_preservation'
    | 'worksafe_site_release'
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
    'reviewed',
    'completed',
] as const;
const INV_STAGE: Record<string, string> = {
    draft: 'Draft',
    in_progress: 'In progress',
    findings_recorded: 'Findings recorded',
    under_review: 'Under review',
    reviewed: 'Reviewed',
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

export function worksafeLabel(worksafe: WorksafeState): string {
    if (worksafe.notifiable === null) return 'Decision not recorded';
    if (worksafe.decision_signed === false)
        return 'Decision needs qualified sign-off';
    if (worksafe.notifiable === false)
        return 'Not notifiable — decision recorded';
    if (!worksafe.status || worksafe.status === 'pending')
        return 'Notification pending';
    if (worksafe.status === 'notified')
        return 'Notified — acknowledgement pending';
    if (worksafe.status === 'acknowledged') return 'Acknowledged';
    return 'WorkSafe status needs review';
}

function worksafeChipClass(worksafe: WorksafeState): string {
    if (worksafe.notifiable === null) return 'bg-muted text-muted-foreground';
    if (worksafe.decision_signed === false)
        return 'bg-status-warning-bg text-status-warning';
    if (worksafe.notifiable === false || worksafe.status === 'acknowledged')
        return 'bg-status-success-bg text-status-success';
    if (!worksafe.status || worksafe.status === 'pending')
        return 'bg-status-critical-bg text-status-critical';
    return 'bg-status-warning-bg text-status-warning';
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
    | { kind: 'worksafe_decision' }
    | { kind: 'worksafe_notify' }
    | { kind: 'worksafe_acknowledge' }
    | { kind: 'worksafe_site_preservation' }
    | { kind: 'worksafe_site_release' }
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

function paneFromAction(
    action: EventActionKey | null,
    detail: EventDetail,
): ActivePane | null {
    switch (action) {
        case 'close':
            return detail.can.close ||
                detail.can.request_closure_exception ||
                detail.can.approve_closure_exception
                ? { kind: 'close' }
                : null;
        case 'accept_handover':
            return detail.handover.can_accept
                ? { kind: 'accept_handover' }
                : null;
        case 'worksafe_decision':
            return detail.worksafe.can_decide
                ? { kind: 'worksafe_decision' }
                : null;
        case 'worksafe_notify':
            return detail.worksafe.can_notify
                ? { kind: 'worksafe_notify' }
                : null;
        case 'worksafe_acknowledge':
            return detail.worksafe.can_acknowledge
                ? { kind: 'worksafe_acknowledge' }
                : null;
        case 'worksafe_site_preservation':
            return detail.worksafe.can_review_site_preservation
                ? { kind: 'worksafe_site_preservation' }
                : null;
        case 'worksafe_site_release':
            return detail.worksafe.can_release_site_preservation
                ? { kind: 'worksafe_site_release' }
                : null;
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
            : paneFromAction(initialAction, detail),
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
            setPane(paneFromAction(initialAction, detail));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes; the local setters are stable and intentionally excluded
    }, [
        initialActionTarget?.actionId,
        initialActionTarget?.pane,
        initialSection,
        initialAction,
        detail.worksafe.can_decide,
        detail.worksafe.can_notify,
        detail.worksafe.can_acknowledge,
        detail.worksafe.can_review_site_preservation,
        detail.worksafe.can_release_site_preservation,
        detail.handover.can_accept,
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
                <stage.icon className="h-3 w-3" /> {d.journey_state}
            </span>
            <span
                className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${worksafeChipClass(d.worksafe)}`}
                title={`WorkSafe: ${worksafeLabel(d.worksafe)}`}
            >
                <ShieldAlert className="h-3 w-3" /> WorkSafe ·{' '}
                {worksafeLabel(d.worksafe)}
            </span>
        </div>
    );

    const canAct = d.can.manage && d.status !== 'closed';
    const canClose = d.can.close && d.status !== 'closed';
    const blockers = d.close_readiness.requirements.filter(
        (requirement) => !requirement.complete,
    );
    const canReviewClosureExceptions =
        d.status !== 'closed' &&
        d.can.approve_closure_exception &&
        (d.closure_exceptions.length > 0 ||
            d.close_readiness.exceptional_blockers.length > 0);

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
            {d.worksafe.can_decide ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'worksafe_decision' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" />{' '}
                    {!d.worksafe.decision_signed
                        ? 'Record WorkSafe decision'
                        : 'Update WorkSafe decision'}
                </Button>
            ) : null}
            {d.worksafe.can_notify ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'worksafe_notify' })}
                    className="border-status-critical/40 text-status-critical hover:text-status-critical"
                >
                    <ShieldAlert className="mr-1.5 h-4 w-4" /> Record WorkSafe
                    notification
                </Button>
            ) : d.worksafe.can_acknowledge ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'worksafe_acknowledge' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Record
                    acknowledgement
                </Button>
            ) : null}
            {d.worksafe.can_release_site_preservation ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'worksafe_site_release' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Record Site
                    release
                </Button>
            ) : d.worksafe.can_review_site_preservation ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        setPane({ kind: 'worksafe_site_preservation' })
                    }
                >
                    <ShieldAlert className="mr-1.5 h-4 w-4" /> Review Site
                    preservation
                </Button>
            ) : null}
            {canClose ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'close' })}
                    title={
                        blockers.length
                            ? `Closure blocked: ${blockers.map((requirement) => requirement.label).join(' ')}`
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
            {!canClose && canReviewClosureExceptions ? (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'close' })}
                >
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Review closure
                    exception
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
    const hardBlockers = d.close_readiness.hard_blockers;
    const exceptionalBlockers = d.close_readiness.exceptional_blockers;
    const now = Date.now();
    const authorisingException = d.closure_exceptions.find(
        (exception) =>
            exception.status === 'approved' &&
            exception.expires_at !== null &&
            new Date(exception.expires_at).getTime() > now &&
            exception.review_at !== null &&
            new Date(exception.review_at).getTime() > now &&
            exceptionalBlockers.every((blocker) =>
                exception.scope.includes(blocker.key),
            ),
    );
    const form = useForm<{ closure_summary: string; exception_id: string }>({
        closure_summary: '',
        exception_id: authorisingException
            ? String(authorisingException.id)
            : '',
    });
    const [attempted, setAttempted] = useState(false);
    const flashError = (usePage().props as { flash?: { error?: string } }).flash
        ?.error;
    const canSubmit =
        d.can.close &&
        form.data.closure_summary.trim() !== '' &&
        hardBlockers.length === 0 &&
        (exceptionalBlockers.length === 0 ||
            authorisingException !== undefined);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        setAttempted(true);
        form.transform((data) => ({
            ...data,
            exception_id: authorisingException
                ? String(authorisingException.id)
                : '',
        }));
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
        <div className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title={d.can.close ? 'Close event' : 'Review closure exception'}
                blurb={
                    d.can.close
                        ? 'The statutory and protective checks are hard gates. An independently approved, current exception can cover only the named internal-governance blocker.'
                        : 'Review the request, evidence, scope and time limit independently. This authority does not grant ordinary closure permission.'
                }
            />

            {attempted && flashError ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    <span className="font-semibold">
                        Couldn't close this event.
                    </span>{' '}
                    {flashError}
                </InfoCard>
            ) : null}

            <JourneyGateList gate={d.close_gate} />

            {hardBlockers.length > 0 ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    <p className="font-semibold">Hard safety blockers</p>
                    <p className="mt-2">
                        These WorkSafe, Site-preservation, Site-scope,
                        active-alert or protective-work checks cannot be
                        bypassed by an exception.
                    </p>
                </InfoCard>
            ) : null}

            {exceptionalBlockers.length > 0 ? (
                <InfoCard icon={ShieldCheck} tone="warn">
                    <p className="font-semibold">
                        Exceptional internal blocker
                    </p>
                    <p className="mt-2">
                        {authorisingException
                            ? `Approved exception #${authorisingException.id} covers the current blocker until ${authorisingException.expires_at ? formatDateTime(authorisingException.expires_at) : 'its recorded expiry'}. It will be rechecked when you close.`
                            : 'A separate requester and approver must record a current, evidence-backed exception for the exact blocker. Free text cannot authorise closure.'}
                    </p>
                </InfoCard>
            ) : null}

            {d.can.close ? (
                <form onSubmit={submit} className="flex flex-col gap-4">
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
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !canSubmit}
                        >
                            Close event
                        </Button>
                    </div>
                </form>
            ) : null}

            {exceptionalBlockers.length > 0 ||
            d.closure_exceptions.length > 0 ? (
                <ClosureExceptionPanel d={d} blockers={exceptionalBlockers} />
            ) : null}
        </div>
    );
}

const EXCEPTION_CATEGORY_BY_SCOPE: Record<string, string> = {
    hs_acceptance: 'handover_record',
    hs_investigation: 'investigation_record',
    recommendation_dispositions: 'recommendation_decision',
    corrective_actions: 'corrective_action_monitoring',
};

function futureDateTimeInput(days: number): string {
    const date = new Date(Date.now() + days * 24 * 60 * 60 * 1000);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
}

function ClosureExceptionPanel({
    d,
    blockers,
}: {
    d: EventDetail;
    blockers: ClosureRequirement[];
}) {
    const firstScope = blockers[0]?.key ?? '';
    const request = useForm({
        category: EXCEPTION_CATEGORY_BY_SCOPE[firstScope] ?? '',
        reason: '',
        evidence_reference: '',
        scope: firstScope ? [firstScope] : ([] as string[]),
        review_at: futureDateTimeInput(3),
        expires_at: futureDateTimeInput(7),
    });

    const submitRequest = (e: FormEvent) => {
        e.preventDefault();
        request.post(`/health-safety/events/${d.id}/closure-exceptions`, {
            preserveScroll: true,
        });
    };

    return (
        <section
            aria-label="Closure exceptions"
            className="space-y-3 rounded-xl border border-border bg-card/70 p-3"
        >
            <p className="text-sm font-semibold">Closure exception record</p>

            {d.closure_exceptions.map((exception) => (
                <ClosureExceptionRecord
                    key={exception.id}
                    d={d}
                    exception={exception}
                />
            ))}

            {d.can.request_closure_exception && blockers.length > 0 ? (
                <form
                    onSubmit={submitRequest}
                    className="space-y-3 border-t pt-3"
                >
                    <Field label="Exceptional blocker" required>
                        <SelectInput
                            value={request.data.scope[0] ?? ''}
                            placeholder="Choose an exceptional blocker"
                            onChange={(scope) => {
                                request.setData('scope', [scope]);
                                request.setData(
                                    'category',
                                    EXCEPTION_CATEGORY_BY_SCOPE[scope] ?? '',
                                );
                            }}
                            options={blockers.map((blocker) => ({
                                value: blocker.key,
                                label: blocker.label,
                            }))}
                        />
                    </Field>
                    <Field
                        label="Narrow exception reason"
                        required
                        error={request.errors.reason}
                    >
                        <Textarea
                            rows={3}
                            value={request.data.reason}
                            onChange={(e) =>
                                request.setData('reason', e.target.value)
                            }
                            placeholder="Why this named internal record cannot be completed before closure"
                        />
                    </Field>
                    <Field
                        label="Evidence or reference"
                        required
                        error={request.errors.evidence_reference}
                    >
                        <Input
                            value={request.data.evidence_reference}
                            onChange={(e) =>
                                request.setData(
                                    'evidence_reference',
                                    e.target.value,
                                )
                            }
                            placeholder="Decision, document, meeting or case reference"
                        />
                    </Field>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Review due" required>
                            <Input
                                type="datetime-local"
                                value={request.data.review_at}
                                onChange={(e) =>
                                    request.setData('review_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Expires" required>
                            <Input
                                type="datetime-local"
                                value={request.data.expires_at}
                                onChange={(e) =>
                                    request.setData(
                                        'expires_at',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" disabled={request.processing}>
                            Send for independent decision
                        </Button>
                    </div>
                </form>
            ) : null}
        </section>
    );
}

function ClosureExceptionRecord({
    d,
    exception,
}: {
    d: EventDetail;
    exception: ClosureException;
}) {
    const decision = useForm({ reason: '', decision: 'approved' });
    const revoke = useForm({ reason: '' });
    const decide = (value: 'approved' | 'rejected') => {
        decision.transform((data) => ({ ...data, decision: value }));
        decision.post(
            `/health-safety/events/${d.id}/closure-exceptions/${exception.id}/decision`,
            { preserveScroll: true },
        );
    };

    return (
        <div className="rounded-lg border border-border/70 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-semibold">
                    Exception #{exception.id} · {titleCase(exception.status)}
                </span>
                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                    {exception.status === 'approved' ? (
                        <CheckCircle2 className="h-3.5 w-3.5 text-status-success" />
                    ) : (
                        <Clock className="h-3.5 w-3.5" />
                    )}
                    {exception.scope.join(', ').replace(/_/g, ' ')}
                </span>
            </div>
            <p className="mt-2 text-muted-foreground">{exception.reason}</p>
            <p className="mt-1 text-xs text-muted-foreground">
                Requested by {exception.requester?.name ?? 'Unknown'} · evidence{' '}
                {exception.evidence_reference}
                {exception.approver
                    ? ` · decided by ${exception.approver.name}`
                    : ''}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
                Requested{' '}
                {exception.requested_at
                    ? formatDateTime(exception.requested_at)
                    : 'time unavailable'}
                {exception.review_at
                    ? ` · review ${formatDateTime(exception.review_at)}`
                    : ''}
                {exception.expires_at
                    ? ` · expires ${formatDateTime(exception.expires_at)}`
                    : ''}
            </p>
            {exception.decision_reason ? (
                <p className="mt-1 text-xs text-muted-foreground">
                    Decision: {exception.decision_reason}
                </p>
            ) : null}
            <p className="mt-1 font-mono text-[10px] text-muted-foreground">
                Provenance {exception.provenance_hash.slice(0, 16)}
            </p>

            {d.can.approve_closure_exception &&
            exception.status === 'pending' ? (
                <div className="mt-3 space-y-2 border-t pt-3">
                    <Field label="Independent decision reason" required>
                        <Textarea
                            rows={2}
                            value={decision.data.reason}
                            onChange={(e) =>
                                decision.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={decision.processing}
                            onClick={() => decide('rejected')}
                        >
                            Reject
                        </Button>
                        <Button
                            type="button"
                            disabled={decision.processing}
                            onClick={() => decide('approved')}
                        >
                            Approve exception
                        </Button>
                    </div>
                </div>
            ) : null}

            {d.can.approve_closure_exception &&
            exception.status === 'approved' ? (
                <form
                    className="mt-3 space-y-2 border-t pt-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        revoke.post(
                            `/health-safety/events/${d.id}/closure-exceptions/${exception.id}/revoke`,
                            { preserveScroll: true },
                        );
                    }}
                >
                    <Field label="Revocation reason" required>
                        <Input
                            value={revoke.data.reason}
                            onChange={(e) =>
                                revoke.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={revoke.processing}
                        >
                            Revoke exception
                        </Button>
                    </div>
                </form>
            ) : null}
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

function WorksafeDecisionPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const revising = d.worksafe.decision_signed;
    const completedNotification =
        d.worksafe.status === 'notified' ||
        d.worksafe.status === 'acknowledged';
    const [hasSelected, setHasSelected] = useState(revising);
    const form = useForm<{
        notifiable: boolean;
        reason: string;
        source: string;
    }>({
        notifiable: d.worksafe.notifiable ?? false,
        reason: d.worksafe.decision_reason ?? '',
        source: 'manual',
    });
    const canSubmit =
        hasSelected && form.data.reason.trim().length >= 10 && !form.processing;

    const choose = (notifiable: boolean) => {
        setHasSelected(true);
        form.setData('notifiable', notifiable);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!canSubmit) return;
        form.post(`/health-safety/events/${d.id}/worksafe/decision`, {
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
                title={
                    revising
                        ? 'Update WorkSafe decision'
                        : 'Record WorkSafe decision'
                }
                blurb="Record whether this event meets the WorkSafe NZ notifiable-event threshold and why."
            />

            {revising && d.worksafe.decided_by ? (
                <InfoCard icon={History} tone="info">
                    Current decision recorded by{' '}
                    <span className="font-semibold">
                        {d.worksafe.decided_by.name}
                    </span>
                    {d.worksafe.decided_at
                        ? ` · ${formatDateTime(d.worksafe.decided_at)}`
                        : ''}
                    {d.worksafe.decision_tree_version
                        ? ` · ${d.worksafe.decision_tree_version}`
                        : ''}
                    {d.worksafe.source_effective_date
                        ? ` · source effective ${formatDateOnly(d.worksafe.source_effective_date)}`
                        : ''}
                    .
                </InfoCard>
            ) : null}

            {d.worksafe.decision_support ? (
                <InfoCard icon={ListChecks} tone="warn">
                    <span className="font-semibold">
                        Preliminary decision support — qualified H&S sign-off is
                        still required.
                    </span>
                    <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm">
                        <li>Confirm the event arose from the conduct of work.</li>
                        <li>
                            Check death, immediate in-patient admission, and every
                            specified serious injury or illness.
                        </li>
                        <li>
                            Check every listed dangerous incident and confirm it
                            was unplanned or uncontrolled and exposed someone to
                            serious risk from immediate or imminent exposure.
                        </li>
                    </ol>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        <details className="rounded-md border border-border bg-background px-3">
                            <summary className="min-h-11 cursor-pointer py-3 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                {`Specified injury / illness matrix (${d.worksafe.decision_support.specified_injury_or_illness_labels.length})`}
                            </summary>
                            <ul className="mb-3 list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                {d.worksafe.decision_support.specified_injury_or_illness_labels.map(
                                    (label) => (
                                        <li key={label}>{label}</li>
                                    ),
                                )}
                            </ul>
                        </details>
                        <details className="rounded-md border border-border bg-background px-3">
                            <summary className="min-h-11 cursor-pointer py-3 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                {`Dangerous-incident matrix (${d.worksafe.decision_support.dangerous_incident_labels.length})`}
                            </summary>
                            <ul className="mb-3 list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                {d.worksafe.decision_support.dangerous_incident_labels.map(
                                    (label) => (
                                        <li key={label}>{label}</li>
                                    ),
                                )}
                            </ul>
                        </details>
                    </div>
                    <p className="mt-2 text-sm">
                        If any fact or category is uncertain, do not record a
                        final decision; leave this event for qualified review.
                        Generic severity alone is not a statutory classification.
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {d.worksafe.decision_support.version} · source effective{' '}
                        {formatDateOnly(
                            d.worksafe.decision_support.source_effective_date,
                        )}{' '}
                        · content owner{' '}
                        {d.worksafe.decision_support.content_owner}{' '}
                        · review before{' '}
                        {formatDateOnly(
                            d.worksafe.decision_support
                                .next_mandatory_review_date,
                        )}
                    </p>
                    <a
                        href={d.worksafe.decision_support.source_url}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-2 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Review the official WorkSafe criteria
                        <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                </InfoCard>
            ) : null}

            <fieldset className="min-w-0">
                <legend className="mb-1.5 text-sm font-medium text-foreground">
                    WorkSafe decision{' '}
                    <span className="text-status-critical">*</span>
                </legend>
                <div className="grid gap-2 sm:grid-cols-2">
                    <label
                        className={`flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 ${
                            hasSelected && form.data.notifiable
                                ? 'border-status-critical bg-status-critical-bg'
                                : 'border-border bg-background hover:bg-muted/50'
                        }`}
                    >
                        <input
                            type="radio"
                            name="worksafe-decision"
                            aria-label="Notifiable"
                            checked={hasSelected && form.data.notifiable}
                            onChange={() => choose(true)}
                            className="h-4 w-4 border-border text-primary focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <span>
                            <span className="block text-sm font-semibold text-foreground">
                                Notifiable
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Starts the WorkSafe notification duty.
                            </span>
                        </span>
                    </label>
                    <label
                        className={`flex min-h-11 items-center gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 ${
                            completedNotification
                                ? 'cursor-not-allowed opacity-60'
                                : 'cursor-pointer'
                        } ${
                            hasSelected && !form.data.notifiable
                                ? 'border-status-success bg-status-success-bg'
                                : 'border-border bg-background hover:bg-muted/50'
                        }`}
                    >
                        <input
                            type="radio"
                            name="worksafe-decision"
                            aria-label="Not notifiable"
                            checked={hasSelected && !form.data.notifiable}
                            onChange={() => choose(false)}
                            disabled={completedNotification}
                            className="h-4 w-4 border-border text-primary focus-visible:ring-2 focus-visible:ring-ring"
                        />
                        <span>
                            <span className="block text-sm font-semibold text-foreground">
                                Not notifiable
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Records that the statutory threshold is not met.
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            {completedNotification ? (
                <InfoCard icon={ShieldAlert} tone="warn">
                    A completed WorkSafe notification cannot be changed to not
                    notifiable. The existing notification record is preserved.
                </InfoCard>
            ) : null}

            <Field
                label="Decision rationale"
                required
                hint="At least 10 characters"
                error={form.errors.reason}
            >
                <Textarea
                    required
                    aria-label="Decision rationale"
                    rows={5}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="What facts and threshold assessment support this decision?"
                />
            </Field>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={!canSubmit}>
                    {revising
                        ? 'Update WorkSafe decision'
                        : 'Record WorkSafe decision'}
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
        reference: d.worksafe.reference ?? '',
        site_preserved: d.worksafe.site_preserved,
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

function WorksafeSitePreservationPane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm({
        required:
            d.worksafe.site_preservation_status === 'active' ? 'active' : '',
        evidence_reference:
            d.worksafe.site_preservation_decision_reference ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            required: data.required === 'active',
            evidence_reference: data.evidence_reference,
        }));
        form.post(`/health-safety/events/${d.id}/worksafe/site-preservation`, {
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
                title="Review Site preservation"
                blurb="Record the event-specific product decision and its evidence. The application does not infer whether preservation applies."
            />
            <Field label="Site-preservation decision" required>
                <SelectInput
                    value={form.data.required}
                    placeholder="Choose a Site-preservation decision"
                    onChange={(value) => form.setData('required', value)}
                    options={[
                        {
                            value: 'active',
                            label: 'Required — preservation remains active',
                        },
                        {
                            value: 'not_required',
                            label: 'Reviewed — not required for this event',
                        },
                    ]}
                />
            </Field>
            <Field label="Evidence or decision reference" required>
                <Input
                    value={form.data.evidence_reference}
                    onChange={(e) =>
                        form.setData('evidence_reference', e.target.value)
                    }
                    placeholder="WorkSafe communication, decision or internal review reference"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.required ||
                        form.data.evidence_reference.trim().length < 5
                    }
                >
                    Record decision
                </Button>
            </div>
        </form>
    );
}

function WorksafeSiteReleasePane({
    d,
    onDone,
}: {
    d: EventDetail;
    onDone: () => void;
}) {
    const form = useForm({
        released_at: todayInput(),
        evidence_reference:
            d.worksafe.site_preservation_release_reference ?? '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(
            `/health-safety/events/${d.id}/worksafe/site-preservation/release`,
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
                icon={ShieldCheck}
                title="Record Site-preservation release"
                blurb="Record when the active preservation work was released and the evidence that supports that decision."
            />
            <Field label="Released at" required>
                <Input
                    type="date"
                    value={form.data.released_at}
                    onChange={(e) =>
                        form.setData('released_at', e.target.value)
                    }
                />
            </Field>
            <Field label="Release evidence or reference" required>
                <Input
                    value={form.data.evidence_reference}
                    onChange={(e) =>
                        form.setData('evidence_reference', e.target.value)
                    }
                    placeholder="Release communication, inspector or decision reference"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        form.data.evidence_reference.trim().length < 5
                    }
                >
                    Record release
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
        case 'worksafe_decision':
            return d.worksafe.can_decide ? (
                <WorksafeDecisionPane d={d} onDone={onDone} />
            ) : null;
        case 'worksafe_notify':
            return d.worksafe.can_notify ? (
                <WorksafeNotifyPane d={d} onDone={onDone} />
            ) : null;
        case 'worksafe_acknowledge':
            return d.worksafe.can_acknowledge ? (
                <WorksafeAcknowledgePane d={d} onDone={onDone} />
            ) : null;
        case 'worksafe_site_preservation':
            return d.worksafe.can_review_site_preservation ? (
                <WorksafeSitePreservationPane d={d} onDone={onDone} />
            ) : null;
        case 'worksafe_site_release':
            return d.worksafe.can_release_site_preservation ? (
                <WorksafeSiteReleasePane d={d} onDone={onDone} />
            ) : null;
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
    const form = useForm({});
    const isReviewDecision = inv.status === 'under_review';

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
                title={
                    isReviewDecision
                        ? 'Review investigation'
                        : 'Approve and complete investigation'
                }
                blurb={
                    isReviewDecision
                        ? 'Accept the investigation review. A different approved H&S staff member must then provide final approval.'
                        : 'Approve the reviewed investigation. Each recommendation must then receive an explicit outcome; only recommendations needing remediation become corrective actions.'
                }
            />
            <InfoCard icon={CheckCircle2} tone="info">
                {isReviewDecision
                    ? 'Review records your authenticated decision. You must be different from the investigation submitter and team.'
                    : 'Approval records your authenticated decision. You must be different from the investigation submitter, team and recorded reviewer.'}
            </InfoCard>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {isReviewDecision
                        ? 'Record review'
                        : 'Approve and complete'}
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
        ![
            'in_progress',
            'findings_recorded',
            'under_review',
            'reviewed',
        ].includes(
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
            {['under_review', 'reviewed'].includes(inv.status) ? (
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
                        <CheckCircle2 className="mr-1.5 h-4 w-4" />
                        {inv.status === 'under_review'
                            ? 'Review'
                            : 'Approve and complete'}
                    </Button>
                    {inv.status === 'under_review' ? (
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
                    ) : null}
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
        <div className="flex flex-col gap-4">
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
            {raisesAction && current?.corrective_action ? (
                <InfoCard icon={ListChecks} tone="info">
                    <p className="font-semibold">
                        This recommendation is already handed over.
                    </p>
                    <p className="mt-1">
                        {current.corrective_action.reference_number} is{' '}
                        {titleCase(current.corrective_action.status)}. Open the
                        corrective-actions section to continue the work.
                    </p>
                </InfoCard>
            ) : raisesAction ? (
                <CorrectiveActionHandoverPane
                    eventId={d.id}
                    investigationId={inv.id}
                    recommendationIndex={recommendationIndex}
                    recommendation={recommendation}
                    handover={d.action_handover}
                    onDone={onDone}
                />
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-4">
                    {form.data.disposition ? (
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
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
            )}
            {raisesAction && current?.corrective_action ? (
                <div className="flex justify-end">
                    <Button type="button" variant="outline" onClick={onDone}>
                        Done
                    </Button>
                </div>
            ) : null}
        </div>
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
        if (!form.data.title.trim()) {
            form.setError('title', 'Give the corrective action a title.');
            return;
        }
        if (!form.data.assigned_to_user_id) {
            form.setError(
                'assigned_to_user_id',
                'Choose the person responsible.',
            );
            return;
        }
        if (!form.data.due_date) {
            form.setError('due_date', 'Set the date this action is due.');
            return;
        }
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
                <Field label="Due" required error={form.errors.due_date}>
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
                required
                error={form.errors.assigned_to_user_id}
            >
                <StaffSelect
                    value={form.data.assigned_to_user_id}
                    onChange={(v) => form.setData('assigned_to_user_id', v)}
                    staff={d.assignable_staff}
                    placeholder="Choose owner"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.title.trim() ||
                        !form.data.assigned_to_user_id ||
                        !form.data.due_date
                    }
                >
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
                hint="Required when no completion file is retained"
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
            <ActionEvidencePanel d={d} ca={ca} allowUpload />
            <InfoCard icon={ShieldCheck} tone="info">
                A different person must verify this action — separation of
                duties.
            </InfoCard>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        (!form.data.completion_notes.trim() &&
                            ca.evidence.attachments.length === 0 &&
                            ca.evidence.legacy_paths.length === 0)
                    }
                >
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Mark complete
                </Button>
            </div>
        </form>
    );
}

type EvidenceUploadState = {
    key: string;
    name: string;
    status: 'queued' | 'uploading' | 'uploaded' | 'failed';
};

function ActionEvidencePanel({
    d,
    ca,
    allowUpload = false,
    allowRemove = true,
}: {
    d: EventDetail;
    ca: EventCorrectiveAction;
    allowUpload?: boolean;
    allowRemove?: boolean;
}) {
    const [description, setDescription] = useState('');
    const [uploads, setUploads] = useState<EvidenceUploadState[]>([]);
    const evidence = ca.evidence;
    const base = `/health-safety/events/${d.id}/corrective-actions/${ca.id}/evidence`;

    const updateUpload = (
        key: string,
        status: EvidenceUploadState['status'],
    ) => {
        setUploads((current) =>
            current.map((upload) =>
                upload.key === key ? { ...upload, status } : upload,
            ),
        );
    };

    const uploadFiles = (files: File[]) => {
        const selectionId = `${Date.now()}-${Math.random()}`;
        const queue = files.map((file, index) => ({
            file,
            key: `${file.name}-${file.lastModified}-${selectionId}-${index}`,
        }));
        setUploads((current) => [
            ...current,
            ...queue.map(({ file, key }, index) => ({
                key,
                name: file.name,
                status:
                    index === 0 ? ('uploading' as const) : ('queued' as const),
            })),
        ]);

        const uploadNext = (index: number) => {
            const item = queue[index];
            if (!item) return;
            updateUpload(item.key, 'uploading');

            router.post(
                base,
                {
                    file: item.file,
                    description: description.trim() || null,
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        const failed = Boolean(
                            (page.props as { flash?: { error?: string } }).flash
                                ?.error,
                        );
                        updateUpload(item.key, failed ? 'failed' : 'uploaded');
                    },
                    onError: () => updateUpload(item.key, 'failed'),
                    onFinish: () => uploadNext(index + 1),
                },
            );
        };

        uploadNext(0);
    };

    if (evidence.load_state === 'unavailable') {
        return ca.status === 'completed' ? (
            <InfoCard icon={ShieldAlert} tone="warn">
                Completion evidence could not be loaded. Verification is
                unavailable.
            </InfoCard>
        ) : null;
    }

    if (
        evidence.attachments.length === 0 &&
        (!allowUpload || !evidence.can_upload)
    ) {
        return null;
    }

    return (
        <div className="rounded-xl border border-border bg-muted/20 p-3">
            <div className="flex items-center justify-between gap-2">
                <p className="flex items-center gap-1.5 text-sm font-bold">
                    <Paperclip className="h-4 w-4 text-primary" />
                    Completion evidence
                </p>
                {evidence.attachments.length ? (
                    <span className="text-xs text-muted-foreground">
                        {evidence.attachments.length}{' '}
                        {evidence.attachments.length === 1 ? 'file' : 'files'}
                    </span>
                ) : null}
            </div>

            {evidence.attachments.length ? (
                <ul className="mt-2 space-y-2">
                    {evidence.attachments.map((attachment) => (
                        <li
                            key={attachment.id}
                            className="flex items-start justify-between gap-3 rounded-lg border border-border bg-background/70 p-2.5"
                        >
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold">
                                    {attachment.original_name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatFileSize(attachment.size_bytes ?? 0)}
                                    {attachment.uploaded_by
                                        ? ` · ${attachment.uploaded_by}`
                                        : ''}
                                </p>
                                {attachment.description ? (
                                    <p className="mt-1 text-xs text-foreground">
                                        {attachment.description}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                <a
                                    href={attachment.download_url}
                                    aria-label={`Download ${attachment.original_name}`}
                                    className="inline-flex h-8 items-center gap-1 rounded-md border border-border px-2 text-xs font-semibold text-primary hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                    Download
                                </a>
                                {allowRemove && attachment.can_remove ? (
                                    <ArmedButton
                                        label="Remove evidence"
                                        icon={Trash2}
                                        ariaLabel={`Remove evidence ${attachment.original_name}`}
                                        onConfirm={() =>
                                            router.delete(
                                                attachment.download_url,
                                                {
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                },
                                            )
                                        }
                                    />
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-2 text-xs text-muted-foreground">
                    No completion evidence uploaded yet.
                </p>
            )}

            {allowUpload && evidence.can_upload ? (
                <div className="mt-3 grid gap-2 border-t border-border pt-3">
                    <Field
                        label="Evidence description"
                        hint="Optional — applied to selected files"
                    >
                        <Input
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                            placeholder="e.g. After photo and contractor sign-off"
                        />
                    </Field>
                    <label className="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-primary/50 bg-primary/5 px-3 py-3 text-sm font-semibold text-primary focus-within:outline-2 focus-within:-outline-offset-2 focus-within:outline-ring hover:bg-primary/10">
                        <Paperclip className="h-4 w-4" />
                        Add completion evidence
                        <input
                            aria-label="Add completion evidence"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                            multiple
                            className="sr-only"
                            onChange={(event) =>
                                uploadFiles(
                                    Array.from(event.target.files ?? []),
                                )
                            }
                        />
                    </label>
                    {uploads.length ? (
                        <ul
                            className="space-y-1 text-xs"
                            aria-label="Evidence upload status"
                        >
                            {uploads.map((upload) => (
                                <li
                                    key={upload.key}
                                    className={
                                        upload.status === 'failed'
                                            ? 'text-status-critical'
                                            : upload.status === 'uploaded'
                                              ? 'text-status-success'
                                              : 'text-muted-foreground'
                                    }
                                >
                                    {upload.status === 'queued'
                                        ? `Queued ${upload.name}`
                                        : upload.status === 'uploading'
                                          ? `Uploading ${upload.name}`
                                          : upload.status === 'uploaded'
                                            ? `Uploaded ${upload.name}`
                                            : `Upload failed for ${upload.name}`}
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </div>
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
        evidence_reviewed: boolean;
        effective: boolean | null;
        verification_notes: string;
    }>({
        evidence_reviewed: false,
        effective: null,
        verification_notes: '',
    });
    const canVerify =
        ca.evidence.load_state === 'loaded' &&
        form.data.evidence_reviewed &&
        form.data.effective !== null &&
        d.can.verify_corrective_actions &&
        ca.can_verify;

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
                than the action owner and whoever completed it
                {ca.completed_by_name ? ` (${ca.completed_by_name})` : ''}.
            </InfoCard>
            <VerificationSection title="What was required">
                <p className="text-sm font-semibold text-foreground">
                    {ca.recommendation ?? ca.title}
                </p>
                <dl className="mt-2 grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                    <div>
                        <dt className="font-semibold text-foreground">Owner</dt>
                        <dd>
                            {ca.owner?.name ??
                                ca.assigned_to_name ??
                                'Unassigned'}
                        </dd>
                    </div>
                    <div>
                        <dt className="font-semibold text-foreground">
                            Due date
                        </dt>
                        <dd>
                            {ca.due_date
                                ? formatDateOnly(ca.due_date)
                                : 'Not recorded'}
                        </dd>
                    </div>
                </dl>
                {ca.source_task ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                        Source: {ca.source_task.reference} ·{' '}
                        {ca.source_task.title}
                    </p>
                ) : null}
            </VerificationSection>
            <VerificationSection title="What the owner submitted">
                {ca.evidence.load_state === 'loaded' ? (
                    <>
                        <p className="text-sm text-foreground">
                            {ca.evidence.completion_notes ??
                                'No completion notes were entered.'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {ca.evidence.completed_by?.name
                                ? `Submitted by ${ca.evidence.completed_by.name}`
                                : 'Submitter not recorded'}
                            {ca.evidence.completed_at
                                ? ` · ${formatDateTime(ca.evidence.completed_at)}`
                                : ''}
                        </p>
                        <ActionEvidencePanel
                            d={d}
                            ca={ca}
                            allowRemove={false}
                        />
                        {ca.evidence.legacy_paths.length ? (
                            <ul className="mt-2 space-y-1 text-xs text-muted-foreground">
                                {ca.evidence.legacy_paths.map((path) => (
                                    <li key={path}>Legacy evidence: {path}</li>
                                ))}
                            </ul>
                        ) : null}
                    </>
                ) : (
                    <InfoCard icon={ShieldAlert} tone="warn">
                        Completion evidence could not be loaded. Verification is
                        unavailable.
                    </InfoCard>
                )}
            </VerificationSection>
            <VerificationSection title="Prior rework and resubmission">
                {ca.rework.latest_reason ? (
                    <p className="text-sm text-foreground">
                        {ca.rework.latest_reason}
                    </p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No prior rework was recorded.
                    </p>
                )}
                {ca.history.length ? (
                    <ol className="mt-2 space-y-1 text-xs text-muted-foreground">
                        {ca.history.map((entry, index) => (
                            <li
                                key={`${entry.label}-${entry.occurred_at}-${index}`}
                            >
                                <span className="font-semibold text-foreground">
                                    {entry.label}
                                </span>
                                {entry.actor ? ` · ${entry.actor}` : ''}
                                {entry.occurred_at
                                    ? ` · ${formatDateTime(entry.occurred_at)}`
                                    : ''}
                            </li>
                        ))}
                    </ol>
                ) : null}
            </VerificationSection>
            <VerificationSection title="Verifier decision">
                <label className="flex items-start gap-2 text-sm text-foreground">
                    <input
                        type="checkbox"
                        checked={form.data.evidence_reviewed}
                        onChange={(e) =>
                            form.setData('evidence_reviewed', e.target.checked)
                        }
                        className="mt-0.5 h-4 w-4 rounded border-border"
                    />
                    I reviewed the owner submission and retained evidence
                </label>
                <fieldset className="mt-3">
                    <legend className="text-sm font-semibold text-foreground">
                        Is the action effective?
                    </legend>
                    <div className="mt-2 flex flex-wrap gap-3">
                        <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                                type="radio"
                                name={`corrective-action-${ca.id}-effective`}
                                checked={form.data.effective === true}
                                onChange={() => form.setData('effective', true)}
                                className="h-4 w-4 border-border"
                            />
                            Effective
                        </label>
                        <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                                type="radio"
                                name={`corrective-action-${ca.id}-effective`}
                                checked={form.data.effective === false}
                                onChange={() =>
                                    form.setData('effective', false)
                                }
                                className="h-4 w-4 border-border"
                            />
                            Not effective
                        </label>
                    </div>
                </fieldset>
                <div className="mt-3">
                    <Field
                        label="Verification notes"
                        hint="Optional"
                        error={form.errors.verification_notes}
                    >
                        <Textarea
                            rows={3}
                            value={form.data.verification_notes}
                            onChange={(e) =>
                                form.setData(
                                    'verification_notes',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                </div>
            </VerificationSection>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !canVerify}>
                    {form.processing ? (
                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                    ) : null}
                    Verify action
                </Button>
            </div>
        </form>
    );
}

function VerificationSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border border-border bg-muted/20 p-3">
            <h3 className="text-sm font-bold text-foreground">{title}</h3>
            <div className="mt-2">{children}</div>
        </section>
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
    ariaLabel,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    onConfirm: () => void;
    ariaLabel?: string;
}) {
    const [arming, setArming] = useState(false);
    if (!arming) {
        return (
            <Button
                type="button"
                size="sm"
                variant="outline"
                aria-label={ariaLabel}
                onClick={() => setArming(true)}
            >
                <Icon className="mr-1.5 h-3.5 w-3.5" /> {label}
            </Button>
        );
    }
    return (
        <span className="inline-flex items-center gap-1">
            <Button
                type="button"
                size="sm"
                aria-label={ariaLabel ? `Confirm ${ariaLabel}` : undefined}
                onClick={() => {
                    onConfirm();
                    setArming(false);
                }}
            >
                <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> {label}?
            </Button>
            <Button
                type="button"
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
    const canManageStrictLifecycle = d.can.manage_corrective_action_lifecycle;
    // Write controls require manage AND a live event — no lifecycle moves once closed.
    if (!d.can.manage || d.status === 'closed') return null;
    if (!['open', 'in_progress', 'completed', 'verified'].includes(ca.status))
        return null;
    if (ca.status !== 'open' && !canManageStrictLifecycle) return null;

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
                {ca.status === 'in_progress' && canManageStrictLifecycle ? (
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
                {ca.status === 'completed' && canManageStrictLifecycle ? (
                    <>
                        <Button
                            size="sm"
                            disabled={
                                !ca.can_verify ||
                                ca.evidence.load_state !== 'loaded'
                            }
                            onClick={() =>
                                onPane({ kind: 'ca_verify', actionId: ca.id })
                            }
                            title={
                                ca.evidence.load_state !== 'loaded'
                                    ? 'Completion evidence could not be loaded.'
                                    : ca.can_verify
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
                {ca.status === 'verified' && canManageStrictLifecycle ? (
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
            {ca.status === 'completed' && canManageStrictLifecycle ? (
                <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
                    <ShieldCheck className="mt-0.5 h-3 w-3 shrink-0" />A
                    different person must verify this action than its owner or
                    whoever completed it
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
                <div className="mb-2 flex items-center gap-1">
                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        Governance stage
                    </p>
                    <JourneyTermHelp
                        terms={['governance_stage', 'status']}
                        label="Explain governance stage"
                    />
                </div>
                <StageTracker status={d.status} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <HandoverOverview d={d} />
            </div>

            <WorkSafeGovernanceCard d={d} />

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

            <ReviewCard
                icon={Paperclip}
                title="Official incident attachments"
                span
            >
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

            <LinkedOperationalEvidence
                evidence={d.linked_operational_evidence}
            />

            <ReviewCard icon={ListChecks} title="Incident follow-ups" span>
                {d.incident_followups.length ? (
                    d.incident_followups.map((followup) => (
                        <div
                            key={followup.id}
                            className="flex flex-wrap items-start justify-between gap-3 border-b border-border py-2 last:border-0"
                        >
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    {followup.notes || 'Incident follow-up'}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {followup.assigned_to ?? 'Unassigned'}
                                    {followup.due_at
                                        ? ` · due ${formatDateTime(followup.due_at)}`
                                        : ''}
                                </p>
                            </div>
                            <span className="text-xs font-semibold text-muted-foreground">
                                {followup.completed_at
                                    ? `Completed ${formatDateTime(followup.completed_at)}`
                                    : 'Open'}
                            </span>
                        </div>
                    ))
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No incident follow-ups were recorded.
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
            </div>
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

function decisionSourceLabel(source: string | null): string | null {
    if (!source) return null;
    return (
        {
            manual: 'Manual decision',
            incident_report: 'Incident report',
            classifier: 'Source classifier',
        }[source] ?? titleCase(source)
    );
}

function WorkSafeGovernanceCard({ d }: { d: EventDetail }) {
    const worksafe = d.worksafe;
    const label = worksafeLabel(worksafe);
    const source = decisionSourceLabel(worksafe.decision_source);

    return (
        // eslint-disable-next-line no-restricted-syntax -- compact governance status surface with custom icon, provenance and statutory-duty content.
        <div className="rounded-xl border border-border bg-card/70 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                    <span
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${worksafeChipClass(worksafe)}`}
                    >
                        {worksafe.notifiable === null ||
                        worksafe.decision_signed === false ? (
                            <Clock className="h-4 w-4" />
                        ) : worksafe.notifiable === false ||
                          worksafe.status === 'acknowledged' ? (
                            <ShieldCheck className="h-4 w-4" />
                        ) : (
                            <ShieldAlert className="h-4 w-4" />
                        )}
                    </span>
                    <div>
                        <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            WorkSafe decision
                        </p>
                        <p className="mt-0.5 text-sm font-semibold text-foreground">
                            {label}
                        </p>
                    </div>
                </div>
                {source ? (
                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                        {source}
                    </span>
                ) : null}
            </div>

            {worksafe.notifiable === null ||
            worksafe.decision_signed === false ? (
                <p className="mt-3 text-sm text-muted-foreground">
                    Complete qualified H&S sign-off against the current WorkSafe
                    decision tree before notification or closure.
                </p>
            ) : (
                <>
                    {worksafe.decision_reason ? (
                        <p className="mt-3 text-sm whitespace-pre-wrap text-foreground">
                            {worksafe.decision_reason}
                        </p>
                    ) : null}
                    {worksafe.decided_by || worksafe.decided_at ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Recorded
                            {worksafe.decided_by
                                ? ` by ${worksafe.decided_by.name}`
                                : ''}
                            {worksafe.decided_at
                                ? ` · ${formatDateTime(worksafe.decided_at)}`
                                : ''}
                        </p>
                    ) : null}
                </>
            )}

            {worksafe.notifiable === true ? (
                <div className="mt-3">
                    <WorkSafeBanner d={d} />
                </div>
            ) : null}
        </div>
    );
}

function WorkSafeBanner({ d }: { d: EventDetail }) {
    const notified =
        d.worksafe.status === 'notified' ||
        d.worksafe.status === 'acknowledged';
    const acknowledged = d.worksafe.status === 'acknowledged';
    const statusKnown =
        !d.worksafe.status ||
        ['pending', 'notified', 'acknowledged'].includes(d.worksafe.status);
    const methodLabel = d.worksafe.method
        ? (WORKSAFE_METHOD_LABELS[d.worksafe.method] ??
          d.worksafe.method.replace(/_/g, ' '))
        : null;
    return (
        <InfoCard icon={ShieldAlert} tone="crit">
            <span className="font-semibold">
                WorkSafe NZ notifiable event (HSWA 2015).
            </span>{' '}
            {acknowledged
                ? `Acknowledged by WorkSafe${d.worksafe.acknowledged_at ? ` ${formatDateTime(d.worksafe.acknowledged_at)}` : ''}${d.worksafe.reference ? ` · ref ${d.worksafe.reference}` : ''}.`
                : notified
                  ? `Notified${d.worksafe.notified_at ? ` ${formatDateTime(d.worksafe.notified_at)}` : ''}${methodLabel ? ` by ${methodLabel}` : ''}${d.worksafe.reference ? ` · ref ${d.worksafe.reference}` : ''} — awaiting acknowledgement.`
                  : statusKnown
                    ? 'Notification to WorkSafe NZ is still pending.'
                    : 'The stored WorkSafe status is not recognised and needs review before this record can be trusted.'}
            <span className="mt-2 flex flex-wrap gap-1.5">
                <DutyChip label="Notify ASAP" done={notified} />
                <DutyChip
                    label={
                        d.worksafe.site_preservation_status === 'released'
                            ? 'Site release recorded'
                            : d.worksafe.site_preservation_status ===
                                'not_required'
                              ? 'Site preservation reviewed — not required'
                              : d.worksafe.site_preservation_status === 'active'
                                ? 'Site preservation active — release required'
                                : 'Review Site preservation'
                    }
                    done={
                        d.worksafe.site_preservation_status === 'released' ||
                        d.worksafe.site_preservation_status === 'not_required'
                    }
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
                                    {formatDateOnly(inv.target_completion_date)}
                                </span>
                            ) : null}
                        </div>
                    </div>

                    <div className="mt-3">
                        <InvestigationGate status={inv.status} />
                    </div>

                    {inv.submitted_by_name ||
                    inv.reviewed_by_name ||
                    inv.approved_by_name ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {inv.submitted_by_name
                                ? `Submitted by ${inv.submitted_by_name}`
                                : 'Submitter not recorded'}
                            {inv.reviewed_by_name
                                ? ` · Reviewed by ${inv.reviewed_by_name}`
                                : ''}
                            {inv.approved_by_name
                                ? ` · Approved by ${inv.approved_by_name}`
                                : ''}
                        </p>
                    ) : null}

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
                                            ? ` · due ${formatDateOnly(a.due_date)}`
                                            : ''}
                                        {a.is_overdue &&
                                        a.status !== 'verified' &&
                                        a.status !== 'closed'
                                            ? ' · overdue'
                                            : ''}
                                    </p>
                                    {a.recommendation ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Recommendation: {a.recommendation}
                                        </p>
                                    ) : null}
                                    {a.source.type === 'control_room_task' ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Transferred from Control Room task:{' '}
                                            {a.source.reference} ·{' '}
                                            {a.source.title}
                                        </p>
                                    ) : a.source.type ===
                                      'new_responsibility' ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            New responsibility:{' '}
                                            {a.source.reason ??
                                                'Reason not recorded'}
                                        </p>
                                    ) : null}
                                    {a.rework.latest_reason ? (
                                        <p className="mt-1 text-xs text-status-warning">
                                            Returned for rework:{' '}
                                            {a.rework.latest_reason}
                                        </p>
                                    ) : null}
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

                            <ActionEvidencePanel
                                d={d}
                                ca={a}
                                allowUpload={a.evidence.can_upload}
                            />

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
                someone other than its owner or the person who completed it.
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
