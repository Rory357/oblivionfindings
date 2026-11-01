/* Permanent safety review and compatibility dialog share canonical records and
 * workflow commands. EventDetail mirrors the permission-shaped server payload;
 * a URL selects a view, never grants permission or changes a record. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { HS_LABELS, hsLabel } from '@/lib/health-safety/hs-labels';
import {
    CorrectiveActionHandoverPane,
    type CorrectiveActionHandover,
} from '@/components/health-safety/corrective-action-handover-pane';
import {
    EventTimeline,
    timelineMilestones,
} from '@/components/health-safety/event-timeline';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import { SourceProcedureRequests } from '@/components/health-safety/procedures/source-procedure-requests';
import {
    JourneyGateList,
    type JourneyGateData,
} from '@/components/incidents/journey-gate-list';
import {
    LinkedOperationalEvidence,
    type LinkedOperationalEvidenceData,
} from '@/components/incidents/linked-operational-evidence';
import { JourneyTermHelp } from '@/components/journey-term-help';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import {
    PageHeaderFacts,
    PageHeaderSteps,
    PageHeaderStages,
    type PageHeaderNextStep,
    type PageHeaderStage,
    type PageHeaderStripTone,
} from '@/components/page/page-header-strip';
import type { PageHeaderRailItem } from '@/components/page/page-header';
import {
    CorrectFindingsDialog,
    type InvestigationCorrection,
} from '@/components/health-safety/review-correction';
import {
    REVIEW_STEP_LABELS,
    ReviewHistory,
    useReviewActivity,
    type ReviewStepKey,
} from '@/components/health-safety/review-history';
import {
    AddActionDialog,
    AskCloseAnywayDialog,
    CloseReviewDialog,
    DownloadPdfDialog,
    DecideCloseAnywayDialog,
    ReviewOwnerDialog,
    WorksafeDecisionDialog,
    WorksafeToldDialog,
} from '@/components/health-safety/review-dialogs';
import {
    ClosureReadinessLine,
    agreedInvestigation,
    currentReviewStep,
    reviewNextBar,
    reviewRoundSteps,
    reviewStatusChip,
    reviewStepPane,
} from '@/components/health-safety/review-progress';
import { RecordProgress } from '@/components/signature/record-progress';
import { AddFilesDialog, ReviewFilesSurface, type ReviewFile } from '@/components/health-safety/review-files';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    Checks,
    FactRows,
    JourneyLine,
    KnowList,
    Log,
    PeopleCards,
    PersonChip,
    PlaceCards,
    QuietLine,
    RISK_CONSEQUENCE,
    RISK_LIKELIHOOD,
    Ring,
    RingCard,
    RiskMap,
    RowList,
    SideColumn,
    StageDots,
    Story,
    Surface,
    DutyClock,
    riskBand,
    type CheckItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { EventReviewFlags, type EventReviewFlag } from '@/components/health-safety/event-review-flags';
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
import {
    SafetyFormDraftContext,
    useSafetyForm as useForm,
    useSafetyDraftState,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    formatDate,
    formatDateOnly,
    formatDateTime,
    toDateInput,
} from '@/lib/datetime';
import { correctiveActionHref } from '@/lib/health-safety/navigation';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ChevronRight,
    Clock,
    ExternalLink,
    FileDown,
    FileText,
    History,
    Link2,
    LinkIcon,
    ListChecks,
    Loader2,
    Lock,
    MapPin,
    MoreHorizontal,
    MoreVertical,
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
    TriangleAlert,
    User as UserIcon,
    Wrench,
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
    /** Answer 20 (G3): a correction to agreed findings still waiting for review or approval. */
    correction?: InvestigationCorrection | null;
    can_propose_correction?: boolean;
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
    /** The check's own words (G3 board M5: "Check result: …"). */
    check_notes?: string | null;
    /** Q11 (G3): checked and it didn't work, and no revised action yet. */
    needs_revision?: boolean;
    /** A revised action: the one it replaces. */
    replaces?: { id: number; reference_number: string; title: string } | null;
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
        | { type: 'standalone' }
        | { type: 'restricted' | 'unavailable' };
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
    /** K1a: the server's answer for this viewer (older payloads fall back to the permission). */
    can_decide?: boolean;
    decide_blocked_reason?: string | null;
    can_revoke?: boolean;
};

export type EventDetail = {
    id: number;
    reference_number: string;
    /** B1.1/B7: the server-made title for this viewer (HsReviewTitle); always preferred over the local fallback. */
    title?: string;
    /** Source changes a person must review (job 3): open flags only. */
    review_flags?: EventReviewFlag[];
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
    /** G3 board M5d: photos, videos and documents on the review's steps. */
    files?: ReviewFile[];
    handover: EventHandover;
    lifecycle: EventLifecycle;
    handover_summary: EventHandoverSummary;
    linked_operational_evidence: LinkedOperationalEvidenceData | null;
    linked_access?: {
        incident: 'absent' | 'readable' | 'restricted';
        control_room: 'absent' | 'readable' | 'restricted';
    };
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
    /** B1.4: the next step for every viewer; `who` says who does it when this viewer can't. */
    next_step?: {
        key: string;
        label: string;
        can: boolean;
        who: string;
        /** revised_action: the action whose check said it didn't work. */
        action_id?: number;
    } | null;
    /** K1a: who can close this review (said to viewers who can't). */
    close_authority?: { who: 'owner_or_close_any' | 'anyone_at_site'; text: string };
    journey_state: string;
    /** detail: "Provider manager · Kauri House, Rimu House" (board M8). */
    assignable_staff: Array<{ id: number; name: string; detail?: string | null }>;
    action_handover: CorrectiveActionHandover;
    can: {
        manage: boolean;
        /** Decision 3 + Q2 (G3): choose the first owner, or change it with a reason. */
        change_owner?: boolean;
        /** G3 board M5d: add photos, videos and documents to a step. */
        add_files?: boolean;
        request_procedure_review?: boolean;
        view_procedure_requests?: boolean;
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
    | 'evidence'
    | 'history';
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
    | 'add_action'
    | 'choose_owner';

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
        label: HS_LABELS.review_status.investigating,
        chip: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
        icon: Search,
    },
    corrective_action: {
        label: HS_LABELS.review_status.corrective_action,
        chip: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        icon: ListChecks,
    },
    monitoring: {
        label: HS_LABELS.review_status.monitoring,
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
// B1.1: the shared words (hs-labels.ts), not a local copy.
const INV_STAGE: Record<string, string> = HS_LABELS.investigation_status;

const CA_STATUS: Record<string, { label: string; chip: string }> = {
    open: { label: HS_LABELS.action_status.open, chip: 'bg-status-info-bg text-status-info' },
    in_progress: { label: HS_LABELS.action_status.in_progress, chip: 'bg-primary/10 text-primary' },
    completed: {
        label: HS_LABELS.action_status.completed,
        chip: 'bg-status-warning-bg text-status-warning',
    },
    verified: {
        label: HS_LABELS.action_status.verified,
        chip: 'bg-status-success-bg text-status-success',
    },
    closed: {
        label: HS_LABELS.action_status.closed,
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
    return hsLabel(
        'handover_status',
        status === 'awaiting_hs_acceptance' ? 'awaiting_acceptance' : status,
    );
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
export type SafetyReviewPane =
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
    | { kind: 'ca_add'; replacesActionId?: number }
    | { kind: 'ca_complete'; actionId: number }
    | { kind: 'ca_verify'; actionId: number }
    | { kind: 'ca_return'; actionId: number };

type ActivePane = SafetyReviewPane;

/** UI eligibility mirrors the existing commands; the server reauthorizes writes. */
function canOpenPane(pane: ActivePane, detail: EventDetail): boolean {
    if (detail.status === 'closed') return false;
    if ('investigationId' in pane) {
        const inv = detail.investigations.find(
            (item) => item.id === pane.investigationId,
        );
        if (!detail.can.manage || !inv) return false;
        if (pane.kind === 'inv_findings') return inv.status === 'in_progress';
        if (pane.kind === 'inv_complete')
            return ['under_review', 'reviewed'].includes(inv.status);
        if (pane.kind === 'inv_return') return inv.status === 'under_review';
        return (
            inv.status === 'completed' &&
            !!inv.recommendations?.[pane.recommendationIndex]
        );
    }
    if ('actionId' in pane) {
        const action = detail.corrective_actions.find(
            (item) => item.id === pane.actionId,
        );
        if (
            !detail.can.manage ||
            !detail.can.manage_corrective_action_lifecycle ||
            !action
        )
            return false;
        if (pane.kind === 'ca_complete') return action.status === 'in_progress';
        if (pane.kind === 'ca_return') return action.status === 'completed';
        return (
            action.status === 'completed' &&
            detail.can.verify_corrective_actions &&
            action.can_verify &&
            action.evidence.load_state === 'loaded'
        );
    }
    if (pane.kind === 'inv_start')
        return (
            detail.can.manage &&
            !detail.investigations.some((inv) => inv.status !== 'completed')
        );
    if (pane.kind === 'ca_add') return detail.can.manage;
    return paneFromAction(pane.kind, detail) !== null;
}

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
            return detail.can.manage && detail.status !== 'closed'
                ? { kind: 'inv_start' }
                : null;
        case 'add_action':
            return detail.can.manage && detail.status !== 'closed'
                ? { kind: 'ca_add' }
                : null;
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

type EventWorkspaceProps = {
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
    presentation?: 'dialog' | 'page';
    returnHref?: string;
    onSectionChange?: (section: EventSectionKey) => void;
    initialPane?: SafetyReviewPane | null;
    onPaneChange?: (
        pane: SafetyReviewPane | null,
        section: EventSectionKey,
    ) => void;
};

export function EventDetailDialog(props: EventWorkspaceProps) {
    return <EventWorkspace {...props} />;
}

export function EventRecordWorkspace(
    props: Omit<EventWorkspaceProps, 'open' | 'onClose' | 'presentation'>,
) {
    return (
        <EventWorkspace
            {...props}
            open
            presentation="page"
            onClose={() =>
                router.visit(props.returnHref ?? '/health-safety/events')
            }
        />
    );
}

function EventWorkspace({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    initialActionTarget = null,
    openedFrom = null,
    presentation = 'dialog',
    returnHref = '/health-safety/events',
    onSectionChange,
    initialPane = null,
    onPaneChange,
}: EventWorkspaceProps) {
    const drafts = useSafetyWorkspaceDrafts();
    const [section, setSection] = useState<EventSectionKey>(
        initialActionTarget ? 'actions' : initialSection,
    );
    const [pane, setActivePane] = useState<ActivePane | null>(
        () =>
            initialPane ??
            (initialActionTarget
                ? {
                      kind: CA_TARGET_PANE[initialActionTarget.pane],
                      actionId: initialActionTarget.actionId,
                  }
                : paneFromAction(initialAction, detail)),
    );
    /** Briefly ring the deep-linked action card once its section is on screen. */
    const [highlightActionId, setHighlightActionId] = useState<number | null>(
        initialActionTarget?.actionId ?? null,
    );
    const actionRowRefs = useRef<Record<number, HTMLElement | null>>({});
    const initialPaneKey = JSON.stringify(initialPane);
    const d = detail;
    const finishPane = (next: ActivePane | null = null) => {
        setActivePane(next);
        onPaneChange?.(next, section);
    };
    const setPane = (next: ActivePane | null) =>
        drafts.request(() => finishPane(next));
    const selectSection = (next: EventSectionKey) =>
        drafts.request(() => {
            setActivePane(null);
            if (presentation === 'page' && onSectionChange)
                onSectionChange(next);
            else setSection(next);
        });

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
        if (initialPane) {
            setSection(initialSection);
            setActivePane(initialPane);
        } else if (initialActionTarget) {
            setSection('actions');
            setActivePane({
                kind: CA_TARGET_PANE[initialActionTarget.pane],
                actionId: initialActionTarget.actionId,
            });
            setHighlightActionId(initialActionTarget.actionId);
        } else {
            setSection(initialSection);
            setActivePane(paneFromAction(initialAction, detail));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes; the local setters are stable and intentionally excluded
    }, [
        initialActionTarget?.actionId,
        initialActionTarget?.pane,
        initialSection,
        initialAction,
        // Permission refreshes must not silently reset a form. PaneRenderer
        // checks current authority and gives a clear unavailable state instead.
        initialPaneKey,
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
            blurb:
                d.linked_access?.incident === 'restricted'
                    ? 'Access restricted'
                    : `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`,
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
            {presentation === 'dialog' ? (
                <Link
                    href={`/health-safety/events/${d.id}`}
                    className="frontline-tap frontline-focus inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
                >
                    <ExternalLink className="h-4 w-4" /> Open full page
                </Link>
            ) : null}
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

    const body = pane ? (
        <PaneRenderer
            pane={pane}
            d={d}
            onDone={() => finishPane()}
            onCancel={() => setPane(null)}
        />
    ) : (
        <>
            {openedFrom ? (
                <div className="mb-4">
                    <InfoCard icon={LinkIcon} tone="info">
                        Opened from {openedFrom}. This is the Health &amp;
                        Safety governance record — investigation, corrective
                        actions and closure are managed here.
                    </InfoCard>
                </div>
            ) : null}

            {d.review_flags?.length ? (
                <div className="mb-4">
                    <EventReviewFlags eventId={d.id} flags={d.review_flags} canDecideWorksafe={d.worksafe.can_decide} />
                </div>
            ) : null}
            {section === 'overview' ? (
                <OverviewSection d={d} cat={cat} stage={stage} />
            ) : null}
            {section === 'handover' ? <HandoverSection d={d} /> : null}
            {section === 'investigation' ? (
                <InvestigationSection d={d} canAct={canAct} onPane={setPane} />
            ) : null}
            {section === 'actions' ? (
                <>
                <ActionsSection
                    d={d}
                    openActions={openActions}
                    awaitingVerification={awaitingVerification}
                    canAct={canAct}
                    onPane={setPane}
                    rowRefs={actionRowRefs}
                    highlightActionId={highlightActionId}
                />
                {(d.can.view_procedure_requests || d.can.request_procedure_review) && <SourceProcedureRequests eventId={d.id} requestDiscard={drafts.request} />}
                </>
            ) : null}
            {section === 'risk' ? <RiskSection d={d} /> : null}
            {section === 'timeline' ? <TimelineSection d={d} /> : null}
            {section === 'evidence' ? <EvidenceSection d={d} /> : null}
        </>
    );
    return (
        <SafetyFormDraftContext.Provider value={drafts.register}>
            {presentation === 'page' ? (
                <SafetyReviewPage
                    d={d}
                    section={section}
                    pane={pane}
                    returnHref={returnHref}
                    openActions={openActions}
                    canAct={canAct}
                    canClose={canClose}
                    canReviewClosureExceptions={canReviewClosureExceptions}
                    onSection={selectSection}
                    onPane={setPane}
                    onPaneDone={() => finishPane()}
                    rowRefs={actionRowRefs}
                    highlightActionId={highlightActionId}
                    requestDiscard={drafts.request}
                    openOwnerFirst={initialAction === 'choose_owner'}
                />
            ) : (
                <WizardShell
                    open={open}
                    frontline
                    sequential={false}
                    onClose={() => drafts.request(onClose)}
                    title={`Event ${d.reference_number}`}
                    description={`${cat} — ${stage.label}`}
                    railIcon={ShieldAlert}
                    railTitle={d.reference_number}
                    railSub={`${cat} · ${sev.label}`}
                    steps={SECTIONS as readonly WizardStep[]}
                    stepIndex={stepIndex}
                    onStepClick={(i) => selectSection(SECTIONS[i].key)}
                    headerLabel={SECTIONS[stepIndex]?.label ?? 'Overview'}
                    pct={null}
                    footerStart={footerStart}
                    footerEnd={footerEnd}
                >
                    {body}
                </WizardShell>
            )}
            {drafts.confirmation}
        </SafetyFormDraftContext.Provider>
    );
}

/* ------------------------------------------------------------------ */
/*  Record page — the approved Health & safety drawing pg-hsn-41       */
/*  (Stephan, 23 Oct 2026): fact chips, the stage line with the next   */
/*  step, one rail of tabs, then signature blocks in a main + side     */
/*  layout. The dialog presentation keeps its own sections below; the  */
/*  workflow panes (and what they submit) are shared unchanged.        */
/* ------------------------------------------------------------------ */

const REVIEW_SECTIONS: { key: EventSectionKey; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'handover', label: 'Handover' },
    { key: 'investigation', label: 'Investigation' },
    { key: 'actions', label: 'Corrective actions' },
    { key: 'risk', label: 'Risk' },
    { key: 'evidence', label: 'Evidence' },
    { key: 'timeline', label: 'Timeline' },
];

/**
 * "Injury — Mere Tane": the category with the person (or Site) it concerns — the hurt or exposed worker for a
 * workplace injury or exposure, otherwise the client. The reference stays in the sub-line.
 */
export function safetyReviewTitle(
    d: Pick<EventDetail, 'event_category' | 'client' | 'staff' | 'site'> &
        Partial<Pick<EventDetail, 'title'>>,
): string {
    if (d.title) return d.title;
    const category =
        EVENT_CATEGORY_LABELS[d.event_category] ?? titleCase(d.event_category);
    const worker = ['injury', 'exposure'].includes(d.event_category);
    const who =
        (worker
            ? (d.staff?.name ?? d.client?.name)
            : (d.client?.name ?? d.staff?.name)) ?? d.site?.name;
    return who ? `${category} — ${who}` : category;
}

const LEVEL_VARIANT: Record<string, StatusVariant> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

function levelTone(level: string): PageHeaderStripTone | 'neutral' {
    const variant = LEVEL_VARIANT[level];
    return variant === 'critical' ||
        variant === 'warning' ||
        variant === 'success'
        ? variant
        : 'neutral';
}

/** "17 Oct 2026" — the worker calendar day of an instant. */
function dayLabel(iso: string | null | undefined): string | undefined {
    if (!iso) return undefined;
    return formatDateOnly(toDateInput(iso), '') || undefined;
}

function isAwaitingHandover(status: string): boolean {
    return (
        status === 'awaiting_acceptance' || status === 'awaiting_hs_acceptance'
    );
}

/** The tone of where the review is up to follows the duty it is waiting on. */
function reviewTone(d: EventDetail): PageHeaderStripTone | undefined {
    if (
        d.worksafe.notifiable === true &&
        (!d.worksafe.status || d.worksafe.status === 'pending')
    )
        return 'critical';
    if (
        d.investigations.some(
            (inv) => inv.is_overdue && inv.status !== 'completed',
        )
    )
        return 'critical';
    if (
        isAwaitingHandover(d.handover.status) ||
        d.worksafe.notifiable === null ||
        d.worksafe.decision_signed === false
    )
        return 'warning';
    return undefined;
}

function reviewStages(
    d: EventDetail,
    openActions: number,
): PageHeaderStage[] {
    const rank = Math.max(
        0,
        STAGE_ORDER.indexOf(d.status as (typeof STAGE_ORDER)[number]),
    );
    const startedAt =
        d.investigations
            .map((inv) => inv.started_at)
            .filter((value): value is string => !!value)
            .sort()[0] ?? null;
    const tone = reviewTone(d);
    return STAGE_ORDER.map((key, index) => {
        const state: PageHeaderStage['state'] =
            index < rank || d.status === 'closed'
                ? 'done'
                : index === rank
                  ? 'now'
                  : 'todo';
        const meta =
            key === 'open'
                ? dayLabel(d.reported_at ?? d.occurred_at)
                : key === 'investigating' && startedAt && state !== 'todo'
                  ? `Since ${formatDate(startedAt)}`
                  : key === 'corrective_action' && d.corrective_actions.length
                    ? `${openActions} open`
                    : key === 'closed'
                      ? dayLabel(d.closed_at)
                      : undefined;
        return {
            label: STAGE[key].label,
            meta,
            state,
            tone: state === 'now' ? tone : undefined,
        };
    });
}

/** Who does what next, from the server's permission-shaped next action. */
function reviewNextStep(d: EventDetail): PageHeaderNextStep | null {
    const next = d.handover_summary.next_action;
    if (d.status === 'closed') return null;
    if (!next) {
        // B1.4: a viewer who can't act still sees the step, and who does it (IR3b: no disabled buttons).
        const step = d.next_step;
        if (!step) return null;
        return {
            label: 'Next step',
            text: step.label,
            meta: step.can ? undefined : step.who,
            tone: reviewTone(d),
        };
    }
    const owner = d.handover.owner?.name ?? null;
    const awaiting = isAwaitingHandover(d.handover.status);
    const worksafe = /worksafe/i.test(next.label);
    const tone = reviewTone(d);
    return {
        label:
            awaiting && !owner
                ? `Needs an owner${d.reported_at ? ` · waiting since ${formatDate(d.reported_at)}` : ''}`
                : worksafe
                  ? 'Next step · WorkSafe NZ'
                  : 'Next step',
        text: next.label,
        meta:
            awaiting && d.worksafe.decision_signed === false
                ? 'Then a qualified person makes the WorkSafe decision'
                : [
                      owner
                          ? `${owner} · safety review owner`
                          : 'No safety review owner yet',
                      worksafe
                          ? worksafeLabel(d.worksafe)
                          : hsLabel('review_status', d.status),
                  ].join(' · '),
        tone: awaiting ? 'critical' : tone,
    };
}

type ReviewHeaderAction = {
    key: string;
    label: string;
    icon: LucideIcon;
    pane: SafetyReviewPane;
    title?: string;
    /** Kept, but listed under More (the drawing doesn't show it). */
    more?: boolean;
};

/** Event-level actions in priority order — only those the viewer can run now. */
function reviewHeaderActions(
    d: EventDetail,
    canClose: boolean,
    canReviewClosureExceptions: boolean,
): ReviewHeaderAction[] {
    const items: ReviewHeaderAction[] = [];
    const blockers = d.close_readiness.requirements.filter(
        (requirement) => !requirement.complete,
    );
    if (d.handover.can_accept)
        items.push({
            key: 'accept',
            label: 'Accept handover',
            icon: ShieldCheck,
            pane: { kind: 'accept_handover' },
        });
    if (d.worksafe.can_decide && !d.worksafe.decision_signed)
        items.push({
            key: 'decision',
            label: 'Record WorkSafe decision',
            icon: ShieldAlert,
            pane: { kind: 'worksafe_decision' },
        });
    if (d.worksafe.can_notify)
        items.push({
            key: 'notify',
            label: 'Record WorkSafe notification',
            icon: ShieldAlert,
            pane: { kind: 'worksafe_notify' },
        });
    else if (d.worksafe.can_acknowledge)
        items.push({
            key: 'acknowledge',
            label: 'Record acknowledgement',
            icon: ShieldCheck,
            pane: { kind: 'worksafe_acknowledge' },
        });
    if (d.worksafe.can_release_site_preservation)
        items.push({
            key: 'site-release',
            label: 'Record Site release',
            icon: ShieldCheck,
            pane: { kind: 'worksafe_site_release' },
        });
    else if (d.worksafe.can_review_site_preservation)
        items.push({
            key: 'site-preservation',
            label: 'Review Site preservation',
            icon: ShieldAlert,
            pane: { kind: 'worksafe_site_preservation' },
        });
    if (canClose)
        items.push({
            key: 'close',
            label: 'Close safety review',
            icon: CheckCircle2,
            pane: { kind: 'close' },
            title: blockers.length
                ? `Closure blocked: ${blockers.map((requirement) => requirement.label).join(' ')}`
                : undefined,
        });
    else if (canReviewClosureExceptions)
        items.push({
            key: 'exception',
            label: 'Review closure exception',
            icon: ShieldCheck,
            pane: { kind: 'close' },
        });
    if (d.worksafe.can_decide && d.worksafe.decision_signed)
        items.push({
            key: 'decision-update',
            label: 'Update WorkSafe decision',
            icon: ShieldCheck,
            pane: { kind: 'worksafe_decision' },
            more: true,
        });
    return items;
}

/** Board M5 tabs. Older links keep working: handover → Overview (Assigned step), risk → Actions, timeline → History. */
function reviewTab(section: EventSectionKey): 'overview' | 'investigation' | 'actions' | 'evidence' | 'history' {
    if (section === 'handover') return 'overview';
    if (section === 'risk') return 'actions';
    if (section === 'timeline') return 'history';
    return section;
}

function reviewTabs(d: EventDetail): PageHeaderRailItem<EventSectionKey>[] {
    return [
        { key: 'overview', label: 'Overview' },
        { key: 'investigation', label: 'Investigation' },
        { key: 'actions', label: 'Actions', count: d.corrective_actions.length || undefined },
        { key: 'evidence', label: 'Files', count: d.attachments.length || undefined },
        { key: 'history', label: 'History' },
    ];
}

/** "Incident INC-2026-0392" → "from incident INC-2026-0392". */
function fromLabel(d: EventDetail): string | null {
    if (!d.source) return null;
    const label = d.source.label;
    return `from ${label.charAt(0).toLowerCase()}${label.slice(1)}`;
}

function SafetyReviewPage({
    d,
    section,
    pane,
    returnHref,
    canClose,
    canReviewClosureExceptions,
    onSection,
    onPane,
    onPaneDone,
    rowRefs,
    highlightActionId,
    requestDiscard,
    openOwnerFirst = false,
    canAct,
}: {
    d: EventDetail;
    section: EventSectionKey;
    pane: ActivePane | null;
    returnHref: string;
    openActions: number;
    canAct: boolean;
    canClose: boolean;
    canReviewClosureExceptions: boolean;
    onSection: (section: EventSectionKey) => void;
    onPane: (pane: ActivePane | null) => void;
    onPaneDone: () => void;
    rowRefs: MutableRefObject<Record<number, HTMLElement | null>>;
    highlightActionId: number | null;
    requestDiscard: (action: () => void) => void;
    openOwnerFirst?: boolean;
}) {
    const sev = SEV[d.severity] ?? SEV.low;
    const stage = STAGE[d.status] ?? STAGE.open;
    const owner = d.handover.owner?.name ?? null;
    const tab = reviewTab(section);
    const current = currentReviewStep(d);
    const [picked, setPicked] = useState<ReviewStepKey>(section === 'handover' ? 'assigned' : current);
    // A change on the page (a step done) moves the picker to where the review now is.
    useEffect(() => setPicked(current), [current]);
    const [ownerOpen, setOwnerOpen] = useState(openOwnerFirst && !!d.can.change_owner);
    const [correcting, setCorrecting] = useState<EventInvestigation | null>(null);
    const activity = useReviewActivity(d.id, true, d);
    const viewer = (usePage().props as { auth?: { user?: { id?: number; name?: string } } }).auth?.user;
    const viewerName = viewer?.name ?? null;
    // Board M8: adding an action, telling WorkSafe and an ordinary close open as kit pop-ups over the page; every
    // other step (and a close that still needs "close anyway") keeps its pane.
    // An approved, current "close anyway" that covers every item left (the same test as the close pane).
    const nowMs = Date.now();
    const exceptionalLeft = d.close_readiness.exceptional_blockers;
    const authorising = exceptionalLeft.length
        ? d.closure_exceptions.find(
              (exception) =>
                  exception.status === 'approved' &&
                  !!exception.expires_at &&
                  new Date(exception.expires_at).getTime() > nowMs &&
                  !!exception.review_at &&
                  new Date(exception.review_at).getTime() > nowMs &&
                  exceptionalLeft.every((blocker) => exception.scope.includes(blocker.key)),
          )
        : undefined;
    const closeReady = d.can.close && d.close_readiness.hard_blockers.length === 0 && (exceptionalLeft.length === 0 || !!authorising);
    const dialogPane =
        pane &&
        (pane.kind === 'ca_add' ||
            pane.kind === 'worksafe_notify' ||
            pane.kind === 'worksafe_decision' ||
            (pane.kind === 'close' && closeReady))
            ? pane
            : null;
    // M12: one request covers one item, so "Ask to close anyway" is offered only when exactly one is left.
    const pendingException = d.closure_exceptions.find((exception) => exception.status === 'pending') ?? null;
    const canAskCloseAnyway =
        d.status !== 'closed' &&
        d.can.request_closure_exception &&
        d.close_readiness.hard_blockers.length === 0 &&
        exceptionalLeft.length === 1 &&
        !pendingException &&
        !authorising;
    const [askOpen, setAskOpen] = useState(false);
    const [filesStep, setFilesStep] = useState<ReviewStepKey | null>(null);
    const [pdfOpen, setPdfOpen] = useState(false);
    const [deciding, setDeciding] = useState(false);
    const inlinePane = dialogPane ? null : pane;
    const replaced =
        dialogPane?.kind === 'ca_add' && dialogPane.replacesActionId
            ? (d.corrective_actions.find((action) => action.id === dialogPane.replacesActionId) ?? null)
            : null;
    const chip = reviewStatusChip(d, stage.label);

    // Board M5: the header holds More (Print summary comes with the PDF round); the Progress card's next-step bar
    // holds the page's only filled button. Every action the viewer may take stays in More.
    // The step the next-step bar already offers isn't repeated in More.
    const inBar: Record<string, string> = {
        take_on: 'accept',
        worksafe_decision: 'decision',
        worksafe_notify: 'notify',
        worksafe_acknowledge: 'acknowledge',
        close: 'close',
    };
    const more = reviewHeaderActions(d, canClose, canReviewClosureExceptions).filter(
        (action) => !d.next_step || action.key !== inBar[d.next_step.key],
    );
    const ownerItem =
        d.can.change_owner && d.status !== 'closed'
            ? { key: 'owner', label: owner ? 'Change owner…' : 'Choose owner…', icon: UserIcon, run: () => setOwnerOpen(true) }
            : null;
    const askItem = canAskCloseAnyway
        ? { key: 'ask-close-anyway', label: 'Ask to close anyway…', icon: ShieldCheck, run: () => setAskOpen(true) }
        : null;
    // Board M5c: "Download PDF" once closed (one button, no second one); open records keep "Print summary".
    const pdfButton = (
        <PageHeaderGlassButton icon={FileDown} onClick={() => setPdfOpen(true)}>
            {d.status === 'closed' ? 'Download PDF' : 'Print summary'}
        </PageHeaderGlassButton>
    );
    const moreMenu =
        more.length || ownerItem || askItem ? (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More safety review actions">
                        More
                    </PageHeaderGlassButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    {ownerItem ? (
                        <DropdownMenuItem onSelect={ownerItem.run}>
                            <ownerItem.icon className="size-4" />
                            {ownerItem.label}
                        </DropdownMenuItem>
                    ) : null}
                    {askItem ? (
                        <DropdownMenuItem onSelect={askItem.run}>
                            <askItem.icon className="size-4" />
                            {askItem.label}
                        </DropdownMenuItem>
                    ) : null}
                    {more.map((action) => (
                        <DropdownMenuItem key={action.key} title={action.title} onSelect={() => onPane(action.pane)}>
                            <action.icon className="size-4" />
                            {action.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        ) : null;
    const headerActions = (
        <>
            {pdfButton}
            {moreMenu}
        </>
    );

    const handlers = {
        onPane: (next: SafetyReviewPane) => onPane(next),
        onSection,
        onOwner: () => setOwnerOpen(true),
        onCorrect: (inv: EventInvestigation) => setCorrecting(inv),
    };
    const agreed = agreedInvestigation(d);
    const story = d.handover_summary.narrative ?? d.description;
    const straightAway = d.handover_summary.immediate_controls;

    const overview = (
        <>
            <RecordProgress
                next={reviewNextBar(d, handlers)}
                pane={reviewStepPane(d, picked, activity, {
                    ...handlers,
                    onBack: picked !== current ? () => setPicked(current) : undefined,
                    onHistory: () => onSection('history'),
                    onAddFiles: (step) => setFilesStep(step),
                    onPdf: () => setPdfOpen(true),
                })}
            />
            <EventReviewFlags eventId={d.id} flags={d.review_flags} canDecideWorksafe={d.worksafe.can_decide} />
            {pendingException ? (
                <QuietLine
                    title="“Close anyway” request"
                    text={
                        pendingException.can_decide
                            ? `${pendingException.requester?.name ?? 'Someone'} asked to close this safety review with one item still open. It’s waiting for your decision.`
                            : `${pendingException.requester?.name ?? 'Someone'} asked to close this safety review with one item still open. It’s waiting for a decision${pendingException.decide_blocked_reason ? `: ${pendingException.decide_blocked_reason}` : '.'}`
                    }
                    action={pendingException.can_decide ? { label: 'Decide', onClick: () => setDeciding(true) } : null}
                />
            ) : null}
            {picked === 'assigned' ? <ReviewHandover d={d} /> : null}
            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]">
                <div className="grid min-w-0 content-start gap-5">
                    {story || straightAway ? (
                        <Surface title="Incident summary" aria-label="Incident summary">
                            <dl className="sg-pane-items">
                                {story ? (
                                    <div>
                                        <dt>What happened</dt>
                                        <dd>{story}</dd>
                                        <span />
                                    </div>
                                ) : null}
                                {straightAway ? (
                                    <div>
                                        <dt>What we did straight away</dt>
                                        <dd>{straightAway}</dd>
                                        <span />
                                    </div>
                                ) : null}
                            </dl>
                        </Surface>
                    ) : (
                        <ReviewWhatHappened d={d} />
                    )}
                    {d.worksafe.notifiable === true ? <WorksafeDuty d={d} /> : null}
                    <ReviewActionRows d={d} onPane={onPane} />
                </div>
                <SideColumn>
                    <ClosureReadinessLine d={d}>
                        <Checks items={closureCheckItems(d)} />
                    </ClosureReadinessLine>
                    {d.source ? (
                        <Surface title="Linked incident" aria-label="Linked incident">
                            <div className="grid gap-2 px-[18px] pb-4 text-sm">
                                <p className="font-semibold">
                                    <OriginLink d={d} />
                                </p>
                                <p className="text-caption">
                                    {[d.site?.name, d.occurred_at ? formatDateTime(d.occurred_at) : null]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                <p className="text-muted-foreground">
                                    This safety review is for the incident above. The incident closes separately, after its
                                    manager review.
                                </p>
                            </div>
                        </Surface>
                    ) : null}
                </SideColumn>
            </div>
        </>
    );

    const main = inlinePane ? (
        <Surface aria-label="Current safety review action">
            <div className="p-5">
                <PaneRenderer pane={inlinePane} d={d} onDone={onPaneDone} onCancel={() => onPane(null)} />
            </div>
        </Surface>
    ) : tab === 'investigation' ? (
        <>
            <ReviewInvestigation d={d} canAct={canAct} onPane={onPane} />
            <WorksafeDuty d={d} />
        </>
    ) : tab === 'actions' ? (
        <>
            <ReviewCorrectiveActions
                d={d}
                canAct={canAct}
                onPane={onPane}
                rowRefs={rowRefs}
                highlightActionId={highlightActionId}
            />
            {d.can.view_procedure_requests || d.can.request_procedure_review ? (
                <SourceProcedureRequests eventId={d.id} requestDiscard={requestDiscard} />
            ) : null}
            <ReviewRisk d={d} />
        </>
    ) : tab === 'evidence' ? (
        <>
            <ReviewFilesSurface
                eventId={d.id}
                files={d.files ?? []}
                stepLabel={(step) => REVIEW_STEP_LABELS[step as ReviewStepKey] ?? step}
                canAdd={!!d.can.add_files}
                onAdd={() => setFilesStep(current)}
            />
            <ReviewEvidence d={d} />
        </>
    ) : tab === 'history' ? (
        <ReviewHistory activity={activity} />
    ) : (
        overview
    );

    const worksafeFact = d.worksafe.decision_signed
        ? {
              value: 'WorkSafe decision recorded',
              label: d.worksafe.notifiable ? '· notifiable' : '· not notifiable',
              tone: d.worksafe.notifiable ? ('warning' as const) : undefined,
          }
        : { value: 'WorkSafe decision', label: 'still to record', tone: 'warning' as const };

    return (
        <PageLayout
            hero={
                <PageHeader
                    variant="profile"
                    wrapTitle
                    backHref={returnHref}
                    icon={ShieldAlert}
                    title={safetyReviewTitle(d)}
                    titleChip={<PageHeaderStatusChip variant={chip.tone}>{chip.label}</PageHeaderStatusChip>}
                    subline={[
                        `Safety review ${d.reference_number}`,
                        fromLabel(d),
                        d.site?.name ?? 'Site not recorded',
                        owner ? `Owner ${owner}` : 'No owner yet',
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={inlinePane ? undefined : headerActions}
                    facts={
                        <PageHeaderFacts
                            items={[
                                { value: sev.label, label: 'severity', tone: levelTone(d.severity) },
                                worksafeFact,
                            ]}
                        />
                    }
                    strip={
                        <PageHeaderSteps
                            steps={reviewRoundSteps(d)}
                            selected={!inlinePane && tab === 'overview' ? picked : null}
                            onSelect={(key) => {
                                setPicked(key as ReviewStepKey);
                                if (pane) onPane(null);
                                if (tab !== 'overview') onSection('overview');
                            }}
                            ariaLabel="Safety review steps"
                        />
                    }
                    rail={
                        <PageHeaderRail
                            items={reviewTabs(d)}
                            value={tab}
                            onSelect={onSection}
                            ariaLabel="Safety review sections"
                        />
                    }
                />
            }
        >
            <div
                id="safety-review-panel"
                role="tabpanel"
                aria-label={inlinePane ? 'Current action' : (reviewTabs(d).find((item) => item.key === tab)?.label ?? 'Overview')}
                className="grid min-w-0 gap-5"
            >
                {main}
            </div>
            <ReviewOwnerDialog
                open={ownerOpen}
                onClose={() => setOwnerOpen(false)}
                eventId={d.id}
                reference={d.reference_number}
                title={safetyReviewTitle(d)}
                siteName={d.site?.name ?? null}
                currentOwner={d.handover.owner}
                candidates={d.assignable_staff}
                viewerId={viewer?.id ?? null}
            />
            <AddActionDialog
                key={dialogPane?.kind === 'ca_add' ? `add-${replaced?.id ?? 0}` : 'add'}
                open={dialogPane?.kind === 'ca_add'}
                onClose={() => onPane(null)}
                onDone={onPaneDone}
                d={d}
                replaces={replaced}
            />
            <WorksafeToldDialog
                open={dialogPane?.kind === 'worksafe_notify'}
                onClose={() => onPane(null)}
                onDone={onPaneDone}
                d={d}
                viewerName={viewerName}
            />
            <WorksafeDecisionDialog
                key={dialogPane?.kind === 'worksafe_decision' ? 'decision-open' : 'decision'}
                open={dialogPane?.kind === 'worksafe_decision'}
                onClose={() => onPane(null)}
                onDone={(notifiable) => (notifiable ? onPane({ kind: 'worksafe_notify' }) : onPaneDone())}
                d={d}
            />
            {filesStep ? (
                <AddFilesDialog
                    open
                    onClose={() => setFilesStep(null)}
                    eventId={d.id}
                    step={filesStep}
                    stepLabel={REVIEW_STEP_LABELS[filesStep]}
                    context={[safetyReviewTitle(d), d.site?.name, d.reference_number].filter(Boolean).join(' · ')}
                    viewerName={viewerName}
                />
            ) : null}
            <DownloadPdfDialog open={pdfOpen} onClose={() => setPdfOpen(false)} d={d} />
            <AskCloseAnywayDialog open={askOpen} onClose={() => setAskOpen(false)} onDone={() => setAskOpen(false)} d={d} />
            {pendingException?.can_decide ? (
                <DecideCloseAnywayDialog
                    open={deciding}
                    onClose={() => setDeciding(false)}
                    d={d}
                    exception={pendingException}
                    viewerName={viewerName}
                />
            ) : null}
            <CloseReviewDialog
                open={dialogPane?.kind === 'close'}
                onClose={() => onPane(null)}
                onDone={onPaneDone}
                d={d}
                exceptionId={authorising?.id ?? null}
                incidentCloser={d.source?.type === 'ClientIncident' ? `a manager at ${d.site?.name ?? 'the site'}` : null}
            />
            {correcting ? (
                <CorrectFindingsDialog
                    open
                    onClose={() => setCorrecting(null)}
                    eventId={d.id}
                    investigationId={correcting.id}
                    context={[
                        safetyReviewTitle(d),
                        d.site?.name,
                        d.reference_number,
                        `Investigated${agreed?.approved_by_name ? ` · findings agreed by ${agreed.approved_by_name}` : ''}${agreed?.completed_at ? `, ${formatDate(agreed.completed_at)}` : ''}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    current={correcting.findings_summary ?? ''}
                    agreedAt={correcting.completed_at}
                    ownerName={owner}
                    viewerName={viewerName}
                />
            ) : null}
        </PageLayout>
    );
}

/** Closure checks that can only be met once the WorkSafe decision is recorded. */
const WORKSAFE_DECISION_DEPENDENTS = [
    'worksafe_notification',
    'worksafe_acknowledgement',
    'site_preservation',
];

/** The closure checks as a ring: done of total, every blocker in the server's words (an open one links to its step). */
function ClosureRing({ d }: { d: EventDetail }) {
    const requirements = d.close_gate.requirements;
    const done = requirements.filter((requirement) => requirement.complete)
        .length;
    const checks = closureCheckItems(d);
    return (
        <RingCard
            value={requirements.length ? done : 1}
            of={requirements.length || 1}
            label={
                d.status === 'closed'
                    ? 'Closed'
                    : requirements.length
                      ? `To close: ${done} of ${requirements.length} done`
                      : 'Nothing blocks closing'
            }
            sub={
                // K1a (B1.5): a viewer who can't close is told once, here, who can.
                !d.can.close && d.status !== 'closed' && d.close_authority
                    ? d.close_authority.text
                    : 'Resolving the response, closing the incident and closing this review are separate steps'
            }
            checks={checks}
        />
    );
}

/** The closing checks, each in the server's words (an open one links to its step). */
function closureCheckItems(d: EventDetail): CheckItem[] {
    const requirements = d.close_gate.requirements;
    // While the WorkSafe decision is missing, its three dependent steps say the same thing, so they show as one
    // plain line (MASTER for Stephan, 30 Oct). Every check still counts and still blocks closing exactly as before.
    const decision = requirements.find(
        (requirement) => requirement.key === 'worksafe_decision',
    );
    const waitingOnDecision = !!decision && !decision.complete;
    const shown = waitingOnDecision
        ? requirements
              .filter(
                  (requirement) =>
                      !WORKSAFE_DECISION_DEPENDENTS.includes(requirement.key),
              )
              .map((requirement) =>
                  requirement.key === 'worksafe_decision'
                      ? {
                            ...requirement,
                            label: 'Record the WorkSafe decision first. The other WorkSafe steps depend on it.',
                        }
                      : requirement,
              )
        : requirements;
    const checks: CheckItem[] = shown.map((requirement) => ({
        state: requirement.complete ? 'success' : 'critical',
        label:
            !requirement.complete && requirement.href ? (
                <Link
                    href={requirement.href}
                    className="rounded-sm underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {requirement.label}
                </Link>
            ) : (
                requirement.label
            ),
    }));
    return checks;
}

/* ---- Overview ---- */

function ReviewOverview({ d }: { d: EventDetail }) {
    // A notifiable event leads with its WorkSafe clock; otherwise the story comes first.
    const clockFirst = d.worksafe.notifiable === true && d.worksafe.decision_signed !== false;
    return (
        <>
            {clockFirst ? <WorksafeDuty d={d} /> : null}
            <ReviewWhatHappened d={d} />
            {clockFirst ? null : <WorksafeDuty d={d} />}
            <ReviewHowItGotHere d={d} />
        </>
    );
}

/** The WorkSafe duty: undecided, not notifiable, or a clock since it was decided notifiable (HSWA wording kept). */
function WorksafeDuty({ d }: { d: EventDetail }) {
    const worksafe = d.worksafe;
    const label = worksafeLabel(worksafe);
    const recorded =
        worksafe.decided_by || worksafe.decided_at ? (
            <>
                Recorded
                {worksafe.decided_by ? (
                    <>
                        {' '}
                        by <b>{worksafe.decided_by.name}</b>
                    </>
                ) : null}
                {worksafe.decided_at
                    ? ` · ${formatDateTime(worksafe.decided_at)}`
                    : ''}
            </>
        ) : null;

    if (worksafe.notifiable === null || worksafe.decision_signed === false) {
        return (
            <QuietLine
                title={`WorkSafe decision · ${label}`}
                text="Complete qualified H&S sign-off against the current WorkSafe decision tree before notification or closure."
            />
        );
    }

    if (worksafe.notifiable === false) {
        const source = decisionSourceLabel(worksafe.decision_source);
        return (
            <Story
                title="WorkSafe decision"
                sub={recorded ?? undefined}
                facts={[
                    { icon: ShieldCheck, text: label },
                    ...(source ? [{ icon: FileText, text: source }] : []),
                ]}
                paras={
                    worksafe.decision_reason
                        ? [{ h: 'Why', t: worksafe.decision_reason }]
                        : []
                }
            />
        );
    }

    const notified =
        worksafe.status === 'notified' || worksafe.status === 'acknowledged';
    const acknowledged = worksafe.status === 'acknowledged';
    const statusKnown =
        !worksafe.status ||
        ['pending', 'notified', 'acknowledged'].includes(worksafe.status);
    const methodLabel = worksafe.method
        ? (WORKSAFE_METHOD_LABELS[worksafe.method] ??
          worksafe.method.replace(/_/g, ' '))
        : null;
    const since = worksafe.decided_at
        ? Math.max(
              0,
              Math.floor(
                  (Date.now() - new Date(worksafe.decided_at).getTime()) /
                      86_400_000,
              ),
          )
        : null;
    const sitePreservation = worksafe.site_preservation_status;

    return (
        <DutyClock
            tone={acknowledged ? 'success' : notified ? 'warning' : 'critical'}
            big={since === null ? '—' : String(since)}
            unit={since === null ? undefined : since === 1 ? 'day' : 'days'}
            kicker="WorkSafe NZ notifiable event (HSWA 2015)"
            title={
                acknowledged
                    ? `Acknowledged by WorkSafe${worksafe.acknowledged_at ? ` ${formatDateTime(worksafe.acknowledged_at)}` : ''}${worksafe.reference ? ` · ref ${worksafe.reference}` : ''}.`
                    : notified
                      ? `Notified${worksafe.notified_at ? ` ${formatDateTime(worksafe.notified_at)}` : ''}${methodLabel ? ` by ${methodLabel}` : ''}${worksafe.reference ? ` · ref ${worksafe.reference}` : ''} — awaiting acknowledgement.`
                      : statusKnown
                        ? 'Notification to WorkSafe NZ is still pending.'
                        : 'The stored WorkSafe status is not recognised and needs review before this record can be trusted.'
            }
            sub={
                <>
                    {recorded}
                    {worksafe.decision_reason ? (
                        <>
                            {recorded ? ' – ' : ''}
                            {worksafe.decision_reason}
                        </>
                    ) : null}
                </>
            }
            lines={[
                {
                    state: notified ? 'success' : 'critical',
                    text: 'Notify ASAP',
                },
                {
                    state:
                        sitePreservation === 'released' ||
                        sitePreservation === 'not_required'
                            ? 'success'
                            : 'warning',
                    text:
                        sitePreservation === 'released'
                            ? 'Site release recorded'
                            : sitePreservation === 'not_required'
                              ? 'Site preservation reviewed — not required'
                              : sitePreservation === 'active'
                                ? 'Site preservation active — release required'
                                : 'Review Site preservation',
                },
                { state: 'none', text: 'Keep records ≥ 5 years' },
            ]}
        />
    );
}

function OriginLink({ d }: { d: EventDetail }) {
    const source = d.source;
    if (!source) return null;
    if (source.url && !source.unwired) {
        return (
            <Link
                href={
                    source.type === 'ClientIncident' &&
                    Number.isSafeInteger(source.id) &&
                    source.id > 0 &&
                    source.url === '/incidents/' + source.id
                        ? incidentWorkspaceHref(
                              source.id,
                              '/health-safety/events/' +
                                  d.id +
                                  '?section=overview',
                          )
                        : source.url
                }
                className="rounded-sm text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            >
                From {source.label}
            </Link>
        );
    }
    return (
        <span
            title={
                source.unwired
                    ? 'This category has no originating module yet.'
                    : undefined
            }
        >
            From {source.label}
        </span>
    );
}

function ReviewWhatHappened({ d }: { d: EventDetail }) {
    const facts: StoryFact[] = [];
    if (d.site)
        facts.push({
            icon: MapPin,
            text: (
                <Link
                    href={`/sites/${d.site.id}`}
                    className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {d.site.name}
                </Link>
            ),
        });
    if (d.occurred_at)
        facts.push({ icon: Clock, text: formatDateTime(d.occurred_at) });
    if (d.client)
        facts.push({
            icon: UserIcon,
            text: (
                <Link
                    href={`/operations/clients/${d.client.id}/care`}
                    className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {d.client.name} (client)
                </Link>
            ),
        });
    if (d.staff) facts.push({ icon: UserIcon, text: `${d.staff.name} (staff)` });
    if (d.asset) facts.push({ icon: Wrench, text: d.asset.name });
    if (d.control_room_alert)
        facts.push({
            icon: RadioTower,
            text: d.control_room_alert.url ? (
                <Link
                    href={d.control_room_alert.url}
                    className="rounded-sm outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {d.control_room_alert.reference_number}
                </Link>
            ) : (
                d.control_room_alert.reference_number
            ),
        });
    if (d.source) facts.push({ icon: Link2, text: <OriginLink d={d} /> });

    const paras: { h: string; t: ReactNode }[] = [];
    if (d.description) paras.push({ h: 'What happened', t: d.description });
    if (!d.source)
        paras.push({
            h: 'Where it came from',
            t:
                d.linked_access?.incident === 'restricted'
                    ? 'The originating incident is linked. Your access does not include its details.'
                    : 'No originating record linked.',
        });
    if (d.closed_at)
        paras.push({
            h: 'How it was closed',
            t: `${formatDateTime(d.closed_at)}${d.closure_summary ? ` – ${d.closure_summary}` : ''}`,
        });

    return (
        <Story
            title="What happened"
            sub={[
                d.reported_at
                    ? `Reported ${formatDateTime(d.reported_at)}`
                    : null,
                d.created_by_name ? `logged by ${d.created_by_name}` : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            facts={facts}
            paras={paras}
        />
    );
}

const LIFECYCLE_DONE = [
    'accepted',
    'acknowledged',
    'closed',
    'completed',
    'resolved',
    'reviewed',
];

function ReviewHowItGotHere({ d }: { d: EventDetail }) {
    const life = d.lifecycle;
    if (!life.control_room && !life.incident) return null;
    const stateOf = (status: string | null) =>
        !status
            ? ('todo' as const)
            : LIFECYCLE_DONE.some((value) => status.includes(value))
              ? ('done' as const)
              : ('now' as const);
    return (
        <Surface
            title="How it got here"
            sub="Control room, incident and Health & safety each keep their own status"
        >
            <div className="sg-journey">
                <JourneyLine
                    label="Control room, incident and Health & safety lifecycle"
                    steps={[
                        {
                            label: 'Control room',
                            meta: lifecycleStatusLabel(life.control_room),
                            state: stateOf(life.control_room),
                        },
                        {
                            label: 'Incident',
                            meta: lifecycleStatusLabel(life.incident),
                            state: stateOf(life.incident),
                        },
                        {
                            label: 'Health & safety',
                            meta:
                                STAGE[life.health_safety]?.label ??
                                lifecycleStatusLabel(life.health_safety),
                            state:
                                life.health_safety === 'closed'
                                    ? 'done'
                                    : 'now',
                        },
                    ]}
                />
            </div>
        </Surface>
    );
}

/* ---- Handover ---- */

/** Download rows (plain links — a file is not an Inertia visit). */
function FileRows({
    title,
    sub,
    files,
    empty,
}: {
    title: string;
    sub?: ReactNode;
    files: EventAttachment[];
    empty: ReactNode;
}) {
    return (
        <Surface title={title} sub={sub}>
            {files.length ? (
                <ul className="sg-list mt-2">
                    {files.map((file) => (
                        <li key={file.id}>
                            <a href={file.download_url} className="sg-li">
                                <span className="sg-li-lead" aria-hidden="true">
                                    <FileText className="size-4" />
                                </span>
                                <span className="sg-li-t">
                                    <b>{file.name}</b>
                                    <small>
                                        {[
                                            fmtSize(file.size),
                                            file.uploaded_by,
                                            file.created_at
                                                ? formatDateTime(
                                                      file.created_at,
                                                  )
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </small>
                                </span>
                                <span className="sg-li-time inline-flex items-center gap-1 font-semibold text-primary">
                                    <ExternalLink
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    Open
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>{empty}</span>
                </p>
            )}
        </Surface>
    );
}

/** Rows with their own buttons on the right (row lists whose rows are not links). */
function ActionRows({
    title,
    sub,
    rows,
}: {
    title: string;
    sub?: ReactNode;
    rows: {
        key: string | number;
        title: ReactNode;
        sub?: ReactNode;
        badge?: ReactNode;
        actions?: ReactNode;
    }[];
}) {
    return (
        <Surface title={title} sub={sub}>
            <ul className="sg-list mt-2">
                {rows.map((row) => (
                    <li key={row.key}>
                        <div className="sg-li flex-wrap">
                            <span className="sg-li-t">
                                <b>{row.title}</b>
                                {row.sub ? (
                                    <small className="!whitespace-normal">
                                        {row.sub}
                                    </small>
                                ) : null}
                            </span>
                            {row.badge ?? null}
                            {row.actions ?? null}
                        </div>
                    </li>
                ))}
            </ul>
        </Surface>
    );
}

function splitPeople(value: string | null): string[] {
    if (!value) return [];
    const parts = value
        .split(/[,;]|\band\b/)
        .map((part) => part.trim())
        .filter(Boolean);
    return parts.length <= 4 && parts.every((part) => part.length <= 40)
        ? parts
        : [value];
}

function ReviewHandover({ d }: { d: EventDetail }) {
    const summary = d.handover_summary;
    const handover = d.handover;
    const incidentRestricted = d.linked_access?.incident === 'restricted';
    const responseRestricted = d.linked_access?.control_room === 'restricted';
    const restricted: { tone: SgTone; text: ReactNode }[] = [];
    if (incidentRestricted)
        restricted.push(
            {
                tone: 'none',
                text: (
                    <>
                        <b>Handover notes</b> — A linked incident is recorded.
                        Your access does not include its account or evidence.
                    </>
                ),
            },
            {
                tone: 'none',
                text: (
                    <>
                        <b>Incident files</b> — Incident attachments are
                        restricted.
                    </>
                ),
            },
            {
                tone: 'none',
                text: (
                    <>
                        <b>Incident follow-ups</b> — Incident follow-ups are
                        restricted.
                    </>
                ),
            },
        );
    if (responseRestricted)
        restricted.push(
            {
                tone: 'none',
                text: (
                    <>
                        <b>From the Control room</b> — This incident has a
                        linked Control Room record. Your access does not
                        include its notes or files.
                    </>
                ),
            },
            {
                tone: 'none',
                text: (
                    <>
                        <b>Control room playbook</b> — Control Room response
                        details are restricted.
                    </>
                ),
            },
        );

    const accepted = handover.status === 'accepted';
    const storyFacts: StoryFact[] = [
        summary.incident_reference
            ? { icon: Link2, text: summary.incident_reference }
            : null,
        summary.alert_reference
            ? { icon: RadioTower, text: summary.alert_reference }
            : null,
        summary.source_label
            ? { icon: FileText, text: summary.source_label }
            : null,
        summary.site_name ? { icon: MapPin, text: summary.site_name } : null,
        summary.potential_consequence
            ? {
                  icon: TriangleAlert,
                  text: `Could have caused: ${summary.potential_consequence}`,
              }
            : null,
    ].filter((fact) => fact !== null);
    const storyParas = [
        summary.narrative ? { h: 'What happened', t: summary.narrative } : null,
        summary.immediate_controls
            ? { h: 'Done straight away', t: summary.immediate_controls }
            : null,
        handover.notes ? { h: 'Acceptance notes', t: handover.notes } : null,
    ].filter((para): para is { h: string; t: string } => para !== null);
    const people = [
        ...(summary.reporter
            ? [{ name: summary.reporter, sub: 'Reported it' }]
            : []),
        ...splitPeople(summary.witnesses).map((name) => ({
            name,
            sub: 'Witness',
        })),
    ];

    return (
        <>
            {restricted.length ? (
                <KnowList
                    title="What your access doesn’t include"
                    sub="You can read this review; linked records stay restricted"
                    items={restricted}
                />
            ) : null}
            {!incidentRestricted ? (
                storyFacts.length || storyParas.length ? (
                    <Story
                        title="Handover notes"
                        sub={
                            accepted
                                ? `Accepted by ${handover.accepted_by?.name ?? 'Health & safety'}${handover.accepted_at ? ` · ${formatDateTime(handover.accepted_at)}` : ''}`
                                : handoverStatusLabel(handover.status)
                        }
                        facts={storyFacts}
                        paras={storyParas}
                    />
                ) : (
                    <QuietLine
                        title="Handover notes"
                        text="No incident handover is linked to this review."
                    />
                )
            ) : null}
            {!incidentRestricted && people.length ? (
                <PeopleCards
                    title="People"
                    cols={Math.min(2, people.length)}
                    items={people}
                />
            ) : null}
            {!responseRestricted ? (
                <LinkedOperationalEvidence
                    evidence={d.linked_operational_evidence}
                />
            ) : null}
            {!responseRestricted && summary.playbook ? (
                <FactRows
                    title="Control room playbook"
                    rows={[
                        [
                            'Playbook',
                            summary.playbook.name ?? 'Operational playbook',
                        ],
                        ['Status', titleCase(summary.playbook.status)],
                        ...(summary.playbook.outcome
                            ? ([['Outcome', summary.playbook.outcome]] as [
                                  ReactNode,
                                  ReactNode,
                              ][])
                            : []),
                    ]}
                />
            ) : null}
            {!incidentRestricted ? (
                <RowList
                    title="Incident follow-ups"
                    empty="No incident follow-ups were recorded."
                    items={d.incident_followups.map((followup) => ({
                        key: followup.id,
                        title: followup.notes || 'Incident follow-up',
                        sub: [
                            followup.assigned_to ?? 'Unassigned',
                            followup.due_at
                                ? `due ${formatDateTime(followup.due_at)}`
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                        badge: followup.completed_at ? (
                            <StatusBadge variant="success">
                                Done {formatDateTime(followup.completed_at)}
                            </StatusBadge>
                        ) : (
                            <StatusBadge variant="warning">Open</StatusBadge>
                        ),
                    }))}
                />
            ) : null}
            {!incidentRestricted ? (
                <FileRows
                    title="Incident files"
                    files={summary.attachments}
                    empty="No attachments were handed over."
                />
            ) : null}
        </>
    );
}

/* ---- Investigation ---- */

function ReviewInvestigation({
    d,
    canAct,
    onPane,
}: {
    d: EventDetail;
    canAct: boolean;
    onPane: (pane: ActivePane) => void;
}) {
    if (!d.investigations.length) {
        return (
            <QuietLine
                title="Investigation"
                text={
                    d.investigation_required
                        ? 'An investigation is required for this event.'
                        : 'No investigation has been opened.'
                }
                action={
                    canAct
                        ? {
                              label: 'Start investigation',
                              onClick: () => onPane({ kind: 'inv_start' }),
                          }
                        : null
                }
            />
        );
    }
    return (
        <>
            {d.investigations.map((inv) => (
                <InvestigationBlocks
                    key={inv.id}
                    d={d}
                    inv={inv}
                    canAct={canAct}
                    onPane={onPane}
                />
            ))}
        </>
    );
}

function InvestigationBlocks({
    d,
    inv,
    canAct,
    onPane,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    canAct: boolean;
    onPane: (pane: ActivePane) => void;
}) {
    const rank = INV_ORDER.indexOf(inv.status as (typeof INV_ORDER)[number]);
    const byline = [
        inv.submitted_by_name ? `Submitted by ${inv.submitted_by_name}` : null,
        inv.reviewed_by_name ? `Reviewed by ${inv.reviewed_by_name}` : null,
        inv.approved_by_name ? `Approved by ${inv.approved_by_name}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
    const causeCards = [
        {
            name: 'What went wrong',
            sub: 'Immediate cause',
            causes: inv.immediate_causes,
            tone: 'critical' as const,
        },
        {
            name: 'Why it could happen',
            sub: 'Root causes',
            causes: inv.root_causes,
            tone: 'warning' as const,
        },
        {
            name: 'What made it worse',
            sub: 'Contributing factor',
            causes: inv.contributing_factors,
            tone: 'info' as const,
        },
    ]
        .filter((card) => card.causes?.length)
        .map((card) => ({
            name: card.name,
            sub: card.sub,
            facts: (card.causes ?? []).map((cause) => ({
                tone: card.tone,
                text: cause.description ?? '',
            })),
        }));
    const recommendations = inv.recommendations ?? [];

    return (
        <>
            <Surface
                title={`Investigation ${inv.reference_number}`}
                sub={[
                    methodologyLabel(inv.methodology) ??
                        titleCase(inv.investigation_type),
                    inv.lead_investigator_name
                        ? `lead ${inv.lead_investigator_name}`
                        : null,
                    inv.target_completion_date
                        ? `due ${formatDateOnly(inv.target_completion_date)}`
                        : null,
                    inv.is_overdue ? 'overdue' : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                tone={inv.is_overdue ? 'critical' : undefined}
            >
                <div className="sg-journey">
                    <JourneyLine
                        label="Investigation lifecycle"
                        steps={INV_ORDER.map((key, index) => ({
                            label: INV_STAGE[key],
                            meta:
                                key === 'in_progress' && inv.started_at
                                    ? `From ${formatDate(inv.started_at)}`
                                    : key === 'completed' && inv.completed_at
                                      ? formatDate(inv.completed_at)
                                      : undefined,
                            state:
                                index < rank ||
                                (index === rank && key === 'completed')
                                    ? 'done'
                                    : index === rank
                                      ? 'now'
                                      : 'todo',
                            tone:
                                index === rank && inv.is_overdue
                                    ? 'critical'
                                    : undefined,
                        }))}
                    />
                    {byline ? (
                        <p className="mt-3 text-caption text-muted-foreground">
                            {byline}
                        </p>
                    ) : null}
                    {canAct ? (
                        <InvestigationControls
                            d={d}
                            inv={inv}
                            onPane={onPane}
                        />
                    ) : null}
                </div>
            </Surface>
            {inv.has_findings && causeCards.length ? (
                <PlaceCards
                    title="Findings"
                    sub={inv.findings_summary ?? undefined}
                    cols={causeCards.length}
                    items={causeCards}
                />
            ) : inv.has_findings && inv.findings_summary ? (
                <Story
                    title="Findings"
                    paras={[{ h: 'Summary', t: inv.findings_summary }]}
                />
            ) : null}
            {inv.has_findings && recommendations.length ? (
                <ActionRows
                    title="Recommendations"
                    sub="Each needs an outcome before this review closes"
                    rows={recommendations.map((recommendation, index) => {
                        const disposition = recommendation.disposition;
                        return {
                            key: index,
                            title: recommendation.description ?? 'Recommendation',
                            sub: disposition ? (
                                <>
                                    {recommendationOutcomeLabel(
                                        disposition.disposition,
                                    )}
                                    {disposition.corrective_action ? (
                                        <>
                                            {' · '}
                                            <Link
                                                href={`/health-safety/corrective-actions?event=${d.id}&action=${disposition.corrective_action.id}`}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {
                                                    disposition
                                                        .corrective_action
                                                        .reference_number
                                                }{' '}
                                                ·{' '}
                                                {titleCase(
                                                    disposition
                                                        .corrective_action
                                                        .status,
                                                )}
                                            </Link>
                                        </>
                                    ) : null}
                                    {disposition.reason
                                        ? ` · ${disposition.reason}`
                                        : ''}
                                    {` · Decided by ${disposition.decided_by_name ?? 'H&S team'}`}
                                    {disposition.decided_at
                                        ? ` · ${formatDateTime(disposition.decided_at)}`
                                        : ''}
                                </>
                            ) : (
                                'Outcome needed before H&S closure'
                            ),
                            badge: recommendation.priority ? (
                                <StatusBadge
                                    variant={
                                        LEVEL_VARIANT[
                                            recommendation.priority
                                        ] ?? 'neutral'
                                    }
                                >
                                    {titleCase(recommendation.priority)}
                                </StatusBadge>
                            ) : null,
                            actions:
                                canAct && inv.status === 'completed' ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            onPane({
                                                kind: 'inv_disposition',
                                                investigationId: inv.id,
                                                recommendationIndex: index,
                                            })
                                        }
                                    >
                                        {disposition
                                            ? 'Change outcome'
                                            : 'Choose outcome'}
                                    </Button>
                                ) : null,
                        };
                    })}
                />
            ) : null}
            {inv.has_findings && inv.lessons_learned ? (
                <Story
                    title="Lessons learned"
                    paras={[{ h: 'What we learned', t: inv.lessons_learned }]}
                />
            ) : null}
        </>
    );
}

/* ---- Corrective actions ---- */

/** Where a corrective action is up to: Agreed › Owner › Done › Checked › Closed. */
export function correctiveActionStage(action: {
    status: string;
    owner?: { id: number; name: string } | null;
    assigned_to_name?: string | null;
    is_overdue?: boolean;
}): {
    stage: number;
    label: string;
    tone?: 'critical' | 'warning' | 'success';
} {
    const hasOwner = !!(action.owner ?? action.assigned_to_name);
    switch (action.status) {
        case 'closed':
            return { stage: 5, label: 'Closed', tone: 'success' };
        case 'verified':
            return { stage: 5, label: 'Checked' };
        case 'completed':
            return { stage: 4, label: 'Checked', tone: 'warning' };
        case 'in_progress':
            return {
                stage: 3,
                label: 'In progress',
                tone: action.is_overdue ? 'critical' : undefined,
            };
        default:
            return hasOwner
                ? {
                      stage: 3,
                      label: 'Not started',
                      tone: action.is_overdue ? 'critical' : undefined,
                  }
                : { stage: 2, label: 'Owner', tone: 'critical' };
    }
}

/**
 * One action menu for every place a review lists its actions (the Actions table and the Overview's list): the kebab,
 * right-click and the same guards as the per-action controls. Returns the opener and the menu/confirm nodes to render.
 */
function useActionMenu(d: EventDetail, onPane: (pane: ActivePane) => void) {
    const [menu, setMenu] = useState<ShiftCtxState | null>(null);
    const [confirm, setConfirm] = useState<{
        action: EventCorrectiveAction;
        command: 'start' | 'close';
    } | null>(null);
    const returnTo = `/health-safety/events/${d.id}?section=actions`;
    const strict = d.can.manage_corrective_action_lifecycle;
    const writable = d.can.manage && d.status !== 'closed';
    const open = (action: EventCorrectiveAction) =>
        router.visit(correctiveActionHref(action.id, { returnTo }));

    // The same guards as the per-action controls in the dialog.
    const menuItems = (action: EventCorrectiveAction): ShiftCtxItem[] => {
        const items: ShiftCtxItem[] = [];
        if (writable) {
            if (action.status === 'open')
                items.push({
                    icon: <Play className="h-3.5 w-3.5" />,
                    label: 'Start action…',
                    tone: 'primary',
                    onClick: () => setConfirm({ action, command: 'start' }),
                });
            if (action.status === 'in_progress' && strict)
                items.push({
                    icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                    label: 'Mark complete',
                    tone: 'primary',
                    onClick: () =>
                        onPane({ kind: 'ca_complete', actionId: action.id }),
                });
            if (action.status === 'completed' && strict) {
                if (
                    d.can.verify_corrective_actions &&
                    action.can_verify &&
                    action.evidence.load_state === 'loaded'
                )
                    items.push({
                        icon: <ShieldCheck className="h-3.5 w-3.5" />,
                        label: 'Verify action',
                        tone: 'primary',
                        onClick: () =>
                            onPane({ kind: 'ca_verify', actionId: action.id }),
                    });
                items.push({
                    icon: <RotateCcw className="h-3.5 w-3.5" />,
                    label: 'Return for rework',
                    tone: 'critical',
                    onClick: () =>
                        onPane({ kind: 'ca_return', actionId: action.id }),
                });
            }
            if (action.status === 'verified' && strict)
                items.push({
                    icon: <Lock className="h-3.5 w-3.5" />,
                    label: 'Close action…',
                    onClick: () => setConfirm({ action, command: 'close' }),
                });
            // Q11 (G3): a check that said it didn't work needs a revised action.
            if (action.needs_revision)
                items.push({
                    icon: <Plus className="h-3.5 w-3.5" />,
                    label: 'Add revised action',
                    tone: 'primary',
                    onClick: () =>
                        onPane({ kind: 'ca_add', replacesActionId: action.id }),
                });
        }
        const tail: ShiftCtxItem[] = [
            {
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Open corrective action',
                sub: action.reference_number,
                tone: items.length ? undefined : 'primary',
                onClick: () => open(action),
            },
        ];
        return items.length ? [...items, { sep: true }, ...tail] : tail;
    };
    const showMenu = (
        action: EventCorrectiveAction,
        x: number,
        y: number,
    ) =>
        setMenu({
            x,
            y,
            tag: titleCase(action.priority).toUpperCase(),
            meta: `${action.reference_number} · ${action.title}`,
            items: menuItems(action),
        });
    const nodes = (
        <>
            {menu ? (
                <ShiftContextMenu ctx={menu} onClose={() => setMenu(null)} />
            ) : null}
            <ConfirmDialog
                open={confirm !== null}
                onClose={() => setConfirm(null)}
                variant="default"
                title={
                    confirm?.command === 'start'
                        ? 'Start this action?'
                        : 'Close this verified action?'
                }
                description={
                    confirm?.command === 'start'
                        ? 'Record that work on this corrective action is underway.'
                        : 'This closes the corrective action. Incident and safety review closure remain separate decisions.'
                }
                confirmText={
                    confirm?.command === 'start'
                        ? 'Start action'
                        : 'Close action'
                }
                onConfirm={() => {
                    if (!confirm) return;
                    router.post(
                        `/health-safety/events/${d.id}/corrective-actions/${confirm.action.id}/${confirm.command}`,
                        {},
                        { preserveScroll: true, preserveState: true },
                    );
                    setConfirm(null);
                }}
            />
        </>
    );
    return {
        showMenu,
        nodes,
        open,
        returnTo,
        askClose: (action: EventCorrectiveAction) =>
            setConfirm({ action, command: 'close' }),
    };
}

function ReviewCorrectiveActions({
    d,
    canAct,
    onPane,
    rowRefs,
    highlightActionId,
}: {
    d: EventDetail;
    canAct: boolean;
    onPane: (pane: ActivePane) => void;
    rowRefs: MutableRefObject<Record<number, HTMLElement | null>>;
    highlightActionId: number | null;
}) {
    const { showMenu, nodes, open, returnTo } = useActionMenu(d, onPane);

    if (!d.corrective_actions.length) {
        return (
            <QuietLine
                title="Corrective actions"
                text="Corrective actions are raised from investigation recommendations or added directly, then driven to verified."
                action={
                    canAct
                        ? {
                              label: 'Add corrective action',
                              onClick: () => onPane({ kind: 'ca_add' }),
                          }
                        : null
                }
            />
        );
    }

    return (
        <Surface
            title="Corrective actions"
            sub="Separation of duties: a corrective action must be verified by someone other than its owner or the person who completed it."
            action={
                canAct
                    ? {
                          label: 'Add corrective action',
                          onClick: () => onPane({ kind: 'ca_add' }),
                      }
                    : null
            }
        >
            <div className="mt-3">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {['Action', 'Owner', 'Due', 'Priority'].map(
                                (label) => (
                                    <TableHead
                                        key={label}
                                        className="h-auto bg-muted/60 px-4 py-2.5 font-semibold tracking-wide text-muted-foreground uppercase"
                                    >
                                        <span className="text-caption">{label}</span>
                                    </TableHead>
                                ),
                            )}
                            <TableHead className="h-auto bg-muted/60 px-4 py-2.5 font-semibold tracking-wide text-muted-foreground uppercase">
                                <span className="text-caption">Where it’s up to</span>
                            </TableHead>
                            <TableHead className="h-auto w-12 bg-muted/60 px-2 py-2.5">
                                <span className="sr-only">Options</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {d.corrective_actions.map((action) => {
                            const stage = correctiveActionStage(action);
                            const owner =
                                action.owner?.name ?? action.assigned_to_name;
                            const overdue =
                                action.is_overdue &&
                                action.status !== 'verified' &&
                                action.status !== 'closed';
                            const note =
                                action.status === 'completed'
                                    ? action.evidence.load_state !== 'loaded'
                                        ? 'Completion evidence could not be loaded.'
                                        : action.can_verify
                                          ? 'Waiting for an independent check'
                                          : 'A different person must verify this action.'
                                    : action.verified_at
                                      ? `Verified${action.verified_by_name ? ` by ${action.verified_by_name}` : ''} · ${action.effectiveness_confirmed ? 'effective' : 'not yet effective'}`
                                      : !owner
                                        ? 'Needs an owner'
                                        : overdue
                                          ? 'Overdue'
                                          : undefined;
                            return (
                                <TableRow
                                    key={action.id}
                                    ref={(node) => {
                                        rowRefs.current[action.id] = node;
                                    }}
                                    onClick={() => open(action)}
                                    onContextMenu={(event) => {
                                        event.preventDefault();
                                        showMenu(
                                            action,
                                            event.clientX,
                                            event.clientY,
                                        );
                                    }}
                                    className={cn(
                                        'cursor-pointer align-top',
                                        highlightActionId === action.id &&
                                            'ring-2 ring-ring ring-inset',
                                    )}
                                >
                                    <TableCell className="max-w-[22rem] px-4 py-3 whitespace-normal">
                                        <Link
                                            href={correctiveActionHref(
                                                action.id,
                                                { returnTo },
                                            )}
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                            className="rounded-sm font-semibold [overflow-wrap:anywhere] text-foreground outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            {action.title}
                                        </Link>
                                        <p className="mt-0.5 text-caption text-muted-foreground">
                                            {action.reference_number} ·{' '}
                                            {titleCase(
                                                action.action_type,
                                            ).toLowerCase()}
                                        </p>
                                        {action.rework.latest_reason ? (
                                            <p className="mt-0.5 text-caption text-status-warning-foreground">
                                                Returned for rework:{' '}
                                                {action.rework.latest_reason}
                                            </p>
                                        ) : null}
                                        {action.recommendation ? (
                                            <p className="mt-0.5 text-caption text-muted-foreground">
                                                Recommendation:{' '}
                                                {action.recommendation}
                                            </p>
                                        ) : null}
                                        {action.source.type ===
                                        'control_room_task' ? (
                                            <p className="mt-0.5 text-caption text-muted-foreground">
                                                Transferred from Control Room
                                                task: {action.source.reference}{' '}
                                                · {action.source.title}
                                            </p>
                                        ) : action.source.type ===
                                          'new_responsibility' ? (
                                            <p className="mt-0.5 text-caption text-muted-foreground">
                                                New responsibility:{' '}
                                                {action.source.reason ??
                                                    'Reason not recorded'}
                                            </p>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        {owner ? (
                                            <PersonChip name={owner} />
                                        ) : (
                                            <StatusBadge variant="warning">
                                                Needs an owner
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'px-4 py-3',
                                            overdue &&
                                                'font-semibold text-status-critical',
                                        )}
                                    >
                                        {formatDateOnly(action.due_date)}
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        <StatusBadge
                                            variant={
                                                LEVEL_VARIANT[action.priority] ??
                                                'neutral'
                                            }
                                        >
                                            {titleCase(action.priority)}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        <StageDots
                                            stage={stage.stage}
                                            of={5}
                                            label={stage.label}
                                            note={note}
                                            tone={stage.tone}
                                        />
                                    </TableCell>
                                    <TableCell className="px-2 py-3 text-right">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="frontline-hit size-8 text-muted-foreground"
                                            aria-label={`Options for ${action.reference_number}`}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                const rect =
                                                    event.currentTarget.getBoundingClientRect();
                                                showMenu(
                                                    action,
                                                    rect.left,
                                                    rect.bottom,
                                                );
                                            }}
                                        >
                                            <MoreVertical className="size-4" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            {nodes}
        </Surface>
    );
}

/**
 * The Overview's actions (board M5): each action with its check result and who checked it, one outline button at most
 * (Close action, Add revised action), the kebab and right-click menu, and the row opens the action.
 */
function ReviewActionRows({
    d,
    onPane,
}: {
    d: EventDetail;
    onPane: (pane: ActivePane) => void;
}) {
    const { showMenu, nodes, open, askClose } = useActionMenu(d, onPane);
    const writable = d.can.manage && d.status !== 'closed';
    const actions = d.corrective_actions;
    const closedCount = actions.filter((a) => a.status === 'closed').length;
    if (!actions.length) {
        return (
            <QuietLine
                title="Corrective actions"
                text="No actions yet. They come from the investigation’s recommendations or are added directly."
                action={
                    writable
                        ? {
                              label: 'Add corrective action',
                              onClick: () => onPane({ kind: 'ca_add' }),
                          }
                        : null
                }
            />
        );
    }
    return (
        <Surface
            title="Corrective actions"
            sub={
                closedCount === actions.length
                    ? `${actions.length} ${actions.length === 1 ? 'action' : 'actions'} · all closed`
                    : `${actions.length} ${actions.length === 1 ? 'action' : 'actions'} for this safety review`
            }
        >
            <ul className="grid">
                {actions.map((action) => {
                    const checked = ['verified', 'closed'].includes(action.status);
                    const worked = checked && action.effectiveness_confirmed !== false;
                    const failed = checked && action.effectiveness_confirmed === false;
                    const owner = action.owner?.name ?? action.assigned_to_name;
                    const badge = failed
                        ? { variant: 'critical' as const, label: 'Checked — it didn’t work' }
                        : worked
                          ? { variant: 'success' as const, label: 'Checked — it worked' }
                          : action.status === 'completed'
                            ? { variant: 'warning' as const, label: 'Done — waiting for check' }
                            : action.status === 'in_progress'
                              ? { variant: 'info' as const, label: 'In progress' }
                              : { variant: 'neutral' as const, label: 'Not started' };
                    const button =
                        writable && action.needs_revision ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onPane({ kind: 'ca_add', replacesActionId: action.id });
                                }}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Add revised action
                            </Button>
                        ) : writable && action.status === 'verified' && worked && d.can.manage_corrective_action_lifecycle ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    askClose(action);
                                }}
                            >
                                <Lock className="size-4" aria-hidden="true" />
                                Close action
                            </Button>
                        ) : null;
                    return (
                        <li
                            key={action.id}
                            className="grid cursor-pointer grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-3 border-t border-border px-[18px] py-3.5 first:border-t-0 hover:bg-muted/40"
                            onClick={() => open(action)}
                            onContextMenu={(event) => {
                                event.preventDefault();
                                showMenu(action, event.clientX, event.clientY);
                            }}
                        >
                            <div className="grid min-w-0 gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <b className="font-semibold [overflow-wrap:anywhere]">{action.title}</b>
                                    <StatusBadge variant={badge.variant} size="sm">
                                        {badge.label}
                                    </StatusBadge>
                                </div>
                                <p className="text-caption flex flex-wrap gap-x-3.5">
                                    <span>
                                        {action.completed_at
                                            ? `Done ${formatDateOnly(toDateInput(action.completed_at))}`
                                            : action.due_date
                                              ? `Due ${formatDateOnly(action.due_date)}`
                                              : 'No due date'}
                                    </span>
                                    <span>{owner ?? 'Needs an owner'}</span>
                                    <span>{action.reference_number}</span>
                                    {action.replaces ? <span>Revised action for {action.replaces.reference_number}</span> : null}
                                </p>
                                {checked && action.check_notes ? (
                                    <p className="text-sm">
                                        <b className="font-semibold">Check result:</b> {action.check_notes}
                                    </p>
                                ) : null}
                                {checked && action.verified_by_name ? (
                                    <p className="text-caption">
                                        Checked by: {action.verified_by_name}
                                        {action.verified_at ? ` · ${formatDate(action.verified_at)}` : ''}
                                    </p>
                                ) : null}
                            </div>
                            <div>{button}</div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="frontline-hit size-8 text-muted-foreground"
                                aria-label={`Options for ${action.reference_number}`}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    const rect = event.currentTarget.getBoundingClientRect();
                                    showMenu(action, rect.left, rect.bottom);
                                }}
                            >
                                <MoreVertical className="size-4" />
                            </Button>
                        </li>
                    );
                })}
            </ul>
            {nodes}
        </Surface>
    );
}

/* ---- Risk ---- */

function ReviewRisk({ d }: { d: EventDetail }) {
    const register = {
        label: 'View in register',
        href: `/health-safety/risk-assessments?hs_event_id=${d.id}`,
    };
    if (!d.risk_assessments.length) {
        return (
            <QuietLine
                title="Risk"
                text="5×5 likelihood × consequence assessments linked to this event appear here. Open the register to create one for this event."
                action={register}
            />
        );
    }
    return (
        <>
            {d.risk_assessments.map((assessment) => (
                <RiskAssessmentBlocks
                    key={assessment.id}
                    assessment={assessment}
                    register={register}
                />
            ))}
        </>
    );
}

function riskCell(
    likelihood: number | null | undefined,
    consequence: number | null | undefined,
): [number, number] | null {
    if (!likelihood || !consequence) return null;
    const row = RISK_LIKELIHOOD.length - likelihood;
    const col = consequence - 1;
    return row >= 0 && row < 5 && col >= 0 && col < 5 ? [row, col] : null;
}

function RiskAssessmentBlocks({
    assessment,
    register,
}: {
    assessment: EventRiskAssessment;
    register: { label: string; href: string };
}) {
    const before = riskCell(assessment.likelihood, assessment.consequence);
    const after = riskCell(
        assessment.residual_likelihood,
        assessment.residual_consequence,
    );
    const [view, setView] = useState(0);
    const mark = view === 1 && after ? after : before;
    const cells = Array.from({ length: 5 }, (_, row) =>
        Array.from({ length: 5 }, (_, col) =>
            mark && mark[0] === row && mark[1] === col ? 1 : 0,
        ),
    );
    const words = (cell: [number, number] | null) =>
        cell
            ? `${RISK_LIKELIHOOD[cell[0]]} × ${RISK_CONSEQUENCE[cell[1]]}`
            : null;
    const level = (value: string | null | undefined) =>
        value ? value.toLowerCase() : null;
    const toneOf = (score: number | null): SgTone => {
        if (score === null) return 'none';
        const band = riskBand(score);
        return band === 'extreme' || band === 'high'
            ? 'critical'
            : band === 'medium'
              ? 'warning'
              : 'success';
    };
    const markScore =
        view === 1 && after
            ? assessment.residual_risk_score
            : assessment.risk_score;
    const markLevel =
        view === 1 && after
            ? (assessment.residual_risk_level ?? assessment.risk_level)
            : assessment.risk_level;
    const items = [
        {
            time:
                assessment.likelihood && assessment.consequence
                    ? `${assessment.likelihood} × ${assessment.consequence}`
                    : 'Before',
            tone: toneOf(assessment.risk_score),
            title: `${assessment.risk_score} · ${level(assessment.risk_level)} before controls`,
            sub: words(before) ?? undefined,
        },
        ...(assessment.residual_risk_score != null
            ? [
                  {
                      time:
                          assessment.residual_likelihood &&
                          assessment.residual_consequence
                              ? `${assessment.residual_likelihood} × ${assessment.residual_consequence}`
                              : 'After',
                      tone: toneOf(assessment.residual_risk_score),
                      title: `${assessment.residual_risk_score} · ${level(assessment.residual_risk_level ?? assessment.risk_level)} after controls`,
                      sub:
                          [
                              words(after),
                              assessment.risk_acceptable === true
                                  ? 'acceptable'
                                  : assessment.risk_acceptable === false
                                    ? 'not acceptable'
                                    : null,
                          ]
                              .filter(Boolean)
                              .join(' · ') || undefined,
                  },
              ]
            : []),
        ...(assessment.review_due_at
            ? [
                  {
                      time: 'Review',
                      tone: (assessment.is_due_for_review
                          ? 'warning'
                          : 'info') as SgTone,
                      title: assessment.is_due_for_review
                          ? `Review due ${dayLabel(assessment.review_due_at) ?? ''}`.trim()
                          : `Next review ${dayLabel(assessment.review_due_at) ?? ''}`.trim(),
                      sub: assessment.assessed_by_name ?? undefined,
                  },
              ]
            : []),
    ];
    return (
        <>
            {before ? (
                <RiskMap
                    title={assessment.title}
                    sub={`${assessment.reference_number} · linked risk assessment`}
                    views={after ? ['Before controls', 'After controls'] : ['Before controls']}
                    view={view}
                    onView={setView}
                    cells={cells}
                    mark={mark}
                    note={
                        mark ? (
                            <>
                                <b>{words(mark)}</b> = {markScore},{' '}
                                {level(markLevel)}
                            </>
                        ) : null
                    }
                />
            ) : null}
            <Log
                title={before ? 'Before and after controls' : assessment.title}
                sub={
                    before
                        ? 'Likelihood × consequence, each out of 5'
                        : `${assessment.reference_number} · linked risk assessment`
                }
                action={register}
                items={items}
            />
        </>
    );
}

/* ---- Evidence and timeline ---- */

function ReviewEvidence({ d }: { d: EventDetail }) {
    if (d.linked_access?.incident === 'restricted') {
        return (
            <QuietLine
                title="Incident evidence restricted"
                text="Your access does not include the linked incident's photos, documents or reports."
            />
        );
    }
    return (
        <FileRows
            title="Evidence"
            sub="Photos, documents and reports attached to this event"
            files={d.attachments}
            empty="No evidence attached"
        />
    );
}

function ReviewTimeline({ d }: { d: EventDetail }) {
    const entries = timelineMilestones({
        reportedAt: d.reported_at,
        occurredAt: d.occurred_at,
        closedAt: d.closed_at,
        investigations: d.investigations,
        correctiveActions: d.corrective_actions,
    });
    if (!entries.length) {
        return <QuietLine title="Timeline" text="No timeline events yet." />;
    }
    return (
        <Log
            title="Timeline"
            sub="From the review, its investigation and its corrective actions"
            limit={entries.length}
            items={entries.map((entry) => ({
                time: formatDateTime(entry.date),
                tone: entry.tone,
                title: entry.label,
                sub: entry.detail,
            }))}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Workflow action panes                                              */
/* ------------------------------------------------------------------ */

function CloseEventPane({
    d,
    onDone,
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
}) {
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
                            onClick={onCancel}
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

            {exception.status === 'pending' &&
            exception.can_decide === false &&
            exception.decide_blocked_reason ? (
                <p className="mt-3 border-t pt-3 text-sm text-muted-foreground">
                    {exception.decide_blocked_reason}
                    {exception.expires_at
                        ? ` It needs a decision before ${formatDateTime(exception.expires_at)}.`
                        : null}
                </p>
            ) : null}

            {(exception.can_decide ??
                d.can.approve_closure_exception) &&
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

            {(exception.can_revoke ?? d.can.approve_closure_exception) &&
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                        <li>
                            Confirm the event arose from the conduct of work.
                        </li>
                        <li>
                            Check death, immediate in-patient admission, and
                            every specified serious injury or illness.
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
                        Generic severity alone is not a statutory
                        classification.
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {d.worksafe.decision_support.version} · source effective{' '}
                        {formatDateOnly(
                            d.worksafe.decision_support.source_effective_date,
                        )}{' '}
                        · content owner{' '}
                        {d.worksafe.decision_support.content_owner} · review
                        before{' '}
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
                        <ExternalLink
                            className="h-3.5 w-3.5"
                            aria-hidden="true"
                        />
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    pane: ActivePane;
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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

    if (!canOpenPane(pane, d)) {
        return (
            <div className="space-y-4">
                <InfoCard icon={ShieldAlert} tone="warn">
                    This action is unavailable for the current record or your
                    access. Review its current status before continuing.
                </InfoCard>
                <Button variant="outline" onClick={onCancel}>
                    Return to review
                </Button>
            </div>
        );
    }

    switch (pane.kind) {
        case 'close':
            return <CloseEventPane d={d} onDone={onDone} onCancel={onCancel} />;
        case 'accept_handover':
            return (
                <AcceptHandoverPane d={d} onDone={onDone} onCancel={onCancel} />
            );
        case 'worksafe_decision':
            return d.worksafe.can_decide ? (
                <WorksafeDecisionPane
                    d={d}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'worksafe_notify':
            return d.worksafe.can_notify ? (
                <WorksafeNotifyPane d={d} onDone={onDone} onCancel={onCancel} />
            ) : null;
        case 'worksafe_acknowledge':
            return d.worksafe.can_acknowledge ? (
                <WorksafeAcknowledgePane
                    d={d}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'worksafe_site_preservation':
            return d.worksafe.can_review_site_preservation ? (
                <WorksafeSitePreservationPane
                    d={d}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'worksafe_site_release':
            return d.worksafe.can_release_site_preservation ? (
                <WorksafeSiteReleasePane
                    d={d}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'inv_start':
            return (
                <StartInvestigationPane
                    d={d}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            );
        case 'inv_findings':
            return inv ? (
                <RecordFindingsPane
                    d={d}
                    inv={inv}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'inv_complete':
            return inv ? (
                <CompleteInvestigationPane
                    d={d}
                    inv={inv}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'inv_return':
            return inv ? (
                <ReturnInvestigationPane
                    d={d}
                    inv={inv}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'inv_disposition':
            return inv ? (
                <RecommendationDispositionPane
                    d={d}
                    inv={inv}
                    recommendationIndex={pane.recommendationIndex}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'ca_add':
            return (
                <AddCorrectiveActionPane
                    d={d}
                    replacesActionId={pane.replacesActionId}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            );
        case 'ca_complete':
            return ca ? (
                <CompleteActionPane
                    d={d}
                    ca={ca}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'ca_verify':
            return ca ? (
                <VerifyActionPane
                    d={d}
                    ca={ca}
                    onDone={onDone}
                    onCancel={onCancel}
                />
            ) : null;
        case 'ca_return':
            return ca ? (
                <ReturnActionPane
                    d={d}
                    ca={ca}
                    onDone={onDone}
                    onCancel={onCancel}
                />
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
    onCancel = onDone,
}: {
    d: EventDetail;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
    onCancel = onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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
        ].includes(inv.status)
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
    onCancel = onDone,
}: {
    d: EventDetail;
    inv: EventInvestigation;
    recommendationIndex: number;
    onDone: () => void;
    onCancel?: () => void;
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
                    onCancel={onCancel}
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
                            onClick={onCancel}
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
                    <Button type="button" variant="outline" onClick={onCancel}>
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
    replacesActionId,
    onDone,
    onCancel = onDone,
}: {
    d: EventDetail;
    /** Q11 (G3): a revised action for one whose check said it didn't work. */
    replacesActionId?: number;
    onDone: () => void;
    onCancel?: () => void;
}) {
    const replaces = replacesActionId
        ? (d.corrective_actions.find((action) => action.id === replacesActionId) ?? null)
        : null;
    const form = useForm<{
        title: string;
        description: string;
        priority: string;
        action_type: string;
        assigned_to_user_id: string;
        due_date: string;
        replaces_action_id: number | null;
    }>({
        title: '',
        description: '',
        priority: 'medium',
        action_type: 'corrective',
        assigned_to_user_id: '',
        due_date: '',
        replaces_action_id: replaces?.id ?? null,
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
                title={replaces ? 'Add a revised action' : 'Add corrective action'}
                blurb={
                    replaces
                        ? `For ${replaces.reference_number} “${replaces.title}”: its check said it didn’t work. This review can’t close until the revised action is done and checked.`
                        : 'Raise a remediation action and drive it to verified. The event moves to Corrective action.'
                }
            />
            {replaces?.check_notes ? (
                <p className="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground">
                    <span className="font-semibold">Check result: </span>
                    {replaces.check_notes}
                </p>
            ) : null}
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
                <Button type="button" variant="outline" onClick={onCancel}>
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

export function CompleteActionPane({
    d,
    ca,
    onDone,
    onCancel = onDone,
}: {
    d: Pick<EventDetail, 'id'>;
    ca: EventCorrectiveAction;
    onDone: () => void;
    onCancel?: () => void;
}) {
    const [evidenceBusy, setEvidenceBusy] = useState(false);
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
            <ActionEvidencePanel
                d={d}
                ca={ca}
                allowUpload
                onBusyChange={setEvidenceBusy}
            />
            <InfoCard icon={ShieldCheck} tone="info">
                A different person must verify this action — separation of
                duties.
            </InfoCard>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        evidenceBusy ||
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

export function ActionEvidencePanel({
    d,
    ca,
    allowUpload = false,
    allowRemove = true,
    onBusyChange,
}: {
    d: Pick<EventDetail, 'id'>;
    ca: EventCorrectiveAction;
    allowUpload?: boolean;
    allowRemove?: boolean;
    onBusyChange?: (busy: boolean) => void;
}) {
    const [description, setDescription] = useState('');
    const [uploads, setUploads] = useState<EvidenceUploadState[]>([]);
    const mounted = useRef(true);
    const busy = uploads.some((upload) =>
        ['queued', 'uploading'].includes(upload.status),
    );
    useSafetyDraftState({
        dirty:
            description !== '' ||
            uploads.some((upload) => upload.status !== 'uploaded'),
        busy,
    });
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        onBusyChange?.(busy);
    }, [onBusyChange, busy]);
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
        if (!files.length || busy) return;
        let cancelled = false;
        let batchFailed = false;
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
            if (!mounted.current || cancelled) return;
            const item = queue[index];
            if (!item) {
                if (!batchFailed)
                    setDescription((current) =>
                        current === description ? '' : current,
                    );
                return;
            }
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
                        batchFailed ||= failed;
                        updateUpload(item.key, failed ? 'failed' : 'uploaded');
                    },
                    onError: () => {
                        batchFailed = true;
                        updateUpload(item.key, 'failed');
                    },
                    onCancel: () => {
                        cancelled = true;
                        if (mounted.current)
                            setUploads((current) =>
                                current.map((upload) =>
                                    queue.some(
                                        (item) => item.key === upload.key,
                                    ) &&
                                    ['queued', 'uploading'].includes(
                                        upload.status,
                                    )
                                        ? { ...upload, status: 'failed' }
                                        : upload,
                                ),
                            );
                    },
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
                            className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-background/70 p-2.5"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-semibold [overflow-wrap:anywhere]">
                                    {attachment.original_name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatFileSize(attachment.size_bytes ?? 0)}
                                    {attachment.uploaded_by
                                        ? ` · ${attachment.uploaded_by}`
                                        : ''}
                                </p>
                                {attachment.description ? (
                                    <p className="mt-1 text-xs [overflow-wrap:anywhere] text-foreground">
                                        {attachment.description}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <a
                                    href={attachment.download_url}
                                    aria-label={`Download ${attachment.original_name}`}
                                    className="frontline-tap inline-flex items-center gap-1 rounded-md border border-border px-2 text-xs font-semibold text-primary hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
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
                            disabled={busy}
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

export function VerifyActionPane({
    d,
    ca,
    onDone,
    onCancel = onDone,
}: {
    d: Pick<EventDetail, 'id'> & {
        can: Pick<EventDetail['can'], 'verify_corrective_actions'>;
    };
    ca: EventCorrectiveAction;
    onDone: () => void;
    onCancel?: () => void;
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
                <label className="frontline-tap flex cursor-pointer items-start gap-2 text-sm text-foreground">
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
                        <label className="frontline-tap flex cursor-pointer items-center gap-2 text-sm text-foreground">
                            <input
                                type="radio"
                                name={`corrective-action-${ca.id}-effective`}
                                checked={form.data.effective === true}
                                onChange={() => form.setData('effective', true)}
                                className="h-4 w-4 border-border"
                            />
                            Effective
                        </label>
                        <label className="frontline-tap flex cursor-pointer items-center gap-2 text-sm text-foreground">
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
                <Button type="button" variant="outline" onClick={onCancel}>
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

export function ReturnActionPane({
    d,
    ca,
    onDone,
    onCancel = onDone,
}: {
    d: Pick<EventDetail, 'id'>;
    ca: EventCorrectiveAction;
    onDone: () => void;
    onCancel?: () => void;
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
                <Button type="button" variant="outline" onClick={onCancel}>
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

            <OriginatingRecordCard
                source={d.source}
                eventId={d.id}
                restricted={d.linked_access?.incident === 'restricted'}
            />

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
    const incidentRestricted = d.linked_access?.incident === 'restricted';
    const responseRestricted = d.linked_access?.control_room === 'restricted';
    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <HandoverOverview d={d} />
                <ReviewCard icon={LinkIcon} title="Official references">
                    <ReviewRow
                        label="Incident"
                        value={
                            incidentRestricted
                                ? 'Access restricted'
                                : summary.incident_reference
                        }
                    />
                    <ReviewRow
                        label="Control Room alert"
                        value={
                            responseRestricted
                                ? 'Access restricted'
                                : summary.alert_reference
                        }
                    />
                    <ReviewRow label="Source" value={summary.source_label} />
                    <ReviewRow label="Site" value={summary.site_name} />
                </ReviewCard>
                {!incidentRestricted ? (
                    <ReviewCard icon={UserIcon} title="People & consequence">
                        <ReviewRow
                            label="Reported by"
                            value={summary.reporter}
                        />
                        <ReviewRow
                            label="Witnesses"
                            value={summary.witnesses}
                        />
                        <ReviewRow
                            label="Potential consequence"
                            value={summary.potential_consequence}
                        />
                    </ReviewCard>
                ) : null}
            </div>

            <ReviewCard icon={FileText} title="Handover narrative" span>
                {incidentRestricted ? (
                    <p className="text-sm text-muted-foreground">
                        A linked incident is recorded. Your access does not
                        include its account or evidence.
                    </p>
                ) : (
                    <>
                        <HandoverText
                            label="What happened"
                            value={summary.narrative}
                        />
                        <HandoverText
                            label="Immediate controls"
                            value={summary.immediate_controls}
                        />
                    </>
                )}
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
                        {incidentRestricted
                            ? 'Incident attachments are restricted.'
                            : 'No attachments were handed over.'}
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
                        {incidentRestricted
                            ? 'Incident follow-ups are restricted.'
                            : 'No incident follow-ups were recorded.'}
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
                            {responseRestricted
                                ? 'Control Room response details are restricted.'
                                : 'No playbook run was linked.'}
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
function OriginatingRecordCard({
    source,
    eventId,
    restricted = false,
}: {
    source: EventSource | null;
    eventId: number;
    restricted?: boolean;
}) {
    if (!source) {
        return (
            <div className="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                {restricted
                    ? 'The originating incident is linked. Your access does not include its details.'
                    : 'No originating record linked.'}
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
                href={source.type === 'ClientIncident' && Number.isSafeInteger(source.id)
                    && source.id > 0 && source.url === '/incidents/' + source.id
                    ? incidentWorkspaceHref(source.id, '/health-safety/events/' + eventId + '?section=overview')
                    : source.url}
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
    rowRefs: MutableRefObject<Record<number, HTMLElement | null>>;
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
                                    <Link
                                        href={correctiveActionHref(a.id, {
                                            returnTo: `/health-safety/events/${d.id}?section=actions`,
                                        })}
                                        className="frontline-tap inline-flex items-center text-sm font-medium [overflow-wrap:anywhere] text-primary"
                                    >
                                        {a.reference_number} · {a.title}
                                    </Link>
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
    if (d.linked_access?.incident === 'restricted') {
        return (
            <EmptyState
                icon={Paperclip}
                title="Incident evidence restricted"
                blurb="Your access does not include the linked incident's photos, documents or reports."
            />
        );
    }
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
