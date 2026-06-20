/* Shared types, token maps and en-NZ formatters for the Restraints & Behaviour
 * Support register, detail modals and workflow wizards. Mirrors the
 * RestraintController payloads. Semantic tokens only (no raw oklch/hex). NZ-only. */
import {
    Archive,
    CheckCircle2,
    DoorClosed,
    Eye,
    Fence,
    FileEdit,
    Hand,
    Link2,
    Pill,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Lookups                                                            */
/* ------------------------------------------------------------------ */

export type ClientOption = { id: number; name: string; site_id: number | null };
export type SiteOption = { id: number; name: string };
export type StaffOption = { id: number; name: string };
export type IncidentOption = { id: number; client_id: number | null; reference: string; label: string };

/* ------------------------------------------------------------------ */
/*  Row + detail types (mirror RestraintController serializers)        */
/* ------------------------------------------------------------------ */

export type EntityRef = { id: number; name: string };

export type EventFlags = {
    unreviewed: boolean;
    out_of_plan: boolean;
    injury: boolean;
    linked_incident: boolean;
};

export type EventRow = {
    id: number;
    reference: string;
    client: EntityRef | null;
    site: EntityRef | null;
    restraint_type: string;
    severity: string;
    started_at: string | null;
    ended_at: string | null;
    duration_minutes: number | null;
    within_support_plan: boolean;
    injury_occurred: boolean;
    reviewed_at: string | null;
    behaviour_support_plan_id: number | null;
    related_incident_id: number | null;
    flags: EventFlags;
};

export type PlanRow = {
    id: number;
    reference: string;
    title: string;
    client: EntityRef | null;
    status: string;
    restrictive_practice_type: string | null;
    review_date: string | null;
    review_state: 'ok' | 'due' | 'overdue';
};

export type EventStaff = { id: number | null; name: string };

export type EventAttachment = {
    id: number;
    name: string;
    mime: string | null;
    size: number | null;
    category: string | null;
    notes: string | null;
    uploaded_by: string | null;
    created_at: string | null;
    download_url: string;
};

export type EventDetail = {
    kind: 'event';
    id: number;
    reference: string;
    client: EntityRef | null;
    site: EntityRef | null;
    restraint_type: string;
    severity: string;
    started_at: string | null;
    ended_at: string | null;
    duration_minutes: number | null;
    trigger_description: string | null;
    de_escalation_attempted: string | null;
    restraint_description: string | null;
    person_response: string | null;
    post_incident_support: string | null;
    injury_occurred: boolean;
    injury_details: string | null;
    within_support_plan: boolean;
    deviation_reason: string | null;
    staff_involved: EventStaff[];
    authorised_by: EntityRef | null;
    plan: { id: number; reference: string; title: string; status: string } | null;
    related_incident: { id: number; reference: string; type: string | null } | null;
    reviewed_at: string | null;
    reviewed_by: EntityRef | null;
    review_notes: string | null;
    lessons_learned: string | null;
    flags: EventFlags;
    attachments: EventAttachment[];
    can: { review: boolean; manage: boolean };
};

export type PlanReview = {
    id: number;
    outcome: string;
    reviewed_by: string | null;
    reviewed_at: string | null;
    next_review_date: string | null;
    resulting_status: string | null;
    notes: string | null;
};

export type PlanDetail = {
    kind: 'plan';
    id: number;
    reference: string;
    title: string;
    client: EntityRef | null;
    status: string;
    restrictive_practice_type: string | null;
    triggers: string | null;
    de_escalation_strategies: string | null;
    approved_interventions: string[];
    prohibited_interventions: string[];
    notes: string | null;
    review_date: string | null;
    review_state: 'ok' | 'due' | 'overdue';
    developed_by: EntityRef | null;
    developed_at: string | null;
    status_changed_at: string | null;
    status_changed_by: EntityRef | null;
    events_count: number;
    reviews: PlanReview[];
    can: { review: boolean; manage: boolean };
};

export type RestraintDetail = EventDetail | PlanDetail;

export type RestraintFilters = {
    q: string | null;
    client_id: number | null;
    site_id: number | null;
    restraint_type: string | null;
    severity: string | null;
    within_plan: string | null;
    review_state: string | null;
    period: string | null;
    from: string | null;
    to: string | null;
};

export type RestraintHero = {
    live: { events_30d: number; out_of_plan: number; injuries: number; critical: number };
    attention: { unreviewed: number; plans_review_due: number; plans_under_review: number; clients_no_active_bsp: number };
    badges: { unreviewed: number; plans_overdue: number; nga_paerewa_certified: boolean; reduction_trend_pct: number };
};

export type RestraintTabCounts = {
    events: { all: number; unreviewed: number; out_of_plan: number; injury: number; critical: number; '30d': number };
    plans: { all: number; active: number; draft: number; review_due: number; under_review: number; archived: number };
};

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                        */
/* ------------------------------------------------------------------ */

export type ChipTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

export const CHIP: Record<ChipTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-foreground',
};

export const DOT: Record<ChipTone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-status-info',
    neutral: 'bg-muted-foreground',
};

export const ICON_TEXT: Record<ChipTone, string> = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    info: 'text-status-info',
    neutral: 'text-muted-foreground',
};

/* ---- restraint / restrictive-practice type ---- */

export type TypeMeta = { label: string; icon: LucideIcon; tone: ChipTone; blurb: string };

export const RESTRAINT_TYPE_META: Record<string, TypeMeta> = {
    physical: { label: 'Physical', icon: Hand, tone: 'critical', blurb: 'Bodily holding or restriction of movement' },
    chemical: { label: 'Chemical', icon: Pill, tone: 'warning', blurb: 'Medication used to control behaviour' },
    mechanical: { label: 'Mechanical', icon: Link2, tone: 'warning', blurb: 'Device or equipment restricting movement' },
    seclusion: { label: 'Seclusion', icon: DoorClosed, tone: 'critical', blurb: 'Confinement alone in a room or area' },
    environmental: { label: 'Environmental', icon: Fence, tone: 'info', blurb: 'Restricting access to space or items' },
};

export function typeMeta(type: string | null | undefined): TypeMeta {
    if (!type) return { label: '—', icon: ShieldAlert, tone: 'neutral', blurb: '' };
    return RESTRAINT_TYPE_META[type] ?? { label: titleCase(type), icon: ShieldAlert, tone: 'neutral', blurb: '' };
}

export const RESTRAINT_TYPE_OPTIONS = [
    { value: 'physical', label: 'Physical' },
    { value: 'chemical', label: 'Chemical' },
    { value: 'mechanical', label: 'Mechanical' },
    { value: 'seclusion', label: 'Seclusion' },
    { value: 'environmental', label: 'Environmental' },
];

/* ---- severity ---- */

export const SEVERITY_TONE: Record<string, ChipTone> = {
    low: 'neutral',
    medium: 'info',
    high: 'warning',
    critical: 'critical',
};

export function severityMeta(s: string | null | undefined): { label: string; tone: ChipTone } {
    if (!s) return { label: '—', tone: 'neutral' };
    return { label: titleCase(s), tone: SEVERITY_TONE[s] ?? 'neutral' };
}

export const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

/* ---- plan status ---- */

export type StatusMeta = { label: string; icon: LucideIcon; tone: ChipTone };

export const PLAN_STATUS_META: Record<string, StatusMeta> = {
    draft: { label: 'Draft', icon: FileEdit, tone: 'neutral' },
    active: { label: 'Active', icon: CheckCircle2, tone: 'success' },
    under_review: { label: 'Under review', icon: Eye, tone: 'warning' },
    archived: { label: 'Archived', icon: Archive, tone: 'neutral' },
};

export function planStatusMeta(status: string | null | undefined): StatusMeta {
    if (!status) return PLAN_STATUS_META.draft;
    return PLAN_STATUS_META[status] ?? { label: titleCase(status), icon: FileEdit, tone: 'neutral' };
}

export const REVIEW_STATE_META: Record<string, { label: string; tone: ChipTone }> = {
    ok: { label: 'On track', tone: 'success' },
    due: { label: 'Review due', tone: 'warning' },
    overdue: { label: 'Review overdue', tone: 'critical' },
};

/* ---- plan review outcome ---- */

export const PLAN_REVIEW_OUTCOME_OPTIONS = [
    { value: 'continued', label: 'Continue unchanged' },
    { value: 'modified', label: 'Modify plan' },
    { value: 'reduced', label: 'Reduce restrictive practice' },
    { value: 'discontinued', label: 'Discontinue plan' },
    { value: 'escalated', label: 'Escalate for specialist review' },
];

export const PLAN_REVIEW_OUTCOME_LABEL: Record<string, string> = Object.fromEntries(
    PLAN_REVIEW_OUTCOME_OPTIONS.map((o) => [o.value, o.label]),
);

/* ---- attachment categories (premium evidence upload) ---- */

export const ATTACHMENT_CATEGORY_OPTIONS = [
    { value: 'body_map', label: 'Body map' },
    { value: 'injury_photo', label: 'Injury photo' },
    { value: 'authorisation', label: 'Authorisation form' },
    { value: 'debrief', label: 'Debrief notes' },
    { value: 'other', label: 'Other' },
];

export const ATTACHMENT_CATEGORY_LABEL: Record<string, string> = Object.fromEntries(
    ATTACHMENT_CATEGORY_OPTIONS.map((o) => [o.value, o.label]),
);

export const PERIOD_ITEMS = [
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'all', label: 'All' },
];

/* ------------------------------------------------------------------ */
/*  Formatters (en-NZ)                                                 */
/* ------------------------------------------------------------------ */

export function titleCase(s: string): string {
    return s.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short' });
}

export function fmtDateFull(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function fmtTime(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}

export function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return `${fmtDate(iso)} · ${fmtTime(iso)}`;
}

/** Relative "when" phrasing for the events table — "Yesterday 14:20", "2d ago 09:05". */
export function whenLabel(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    const yest = new Date(now);
    yest.setDate(now.getDate() - 1);
    const isYest = d.toDateString() === yest.toDateString();
    const days = Math.floor((now.getTime() - d.getTime()) / 86_400_000);
    if (sameDay) return `Today ${fmtTime(iso)}`;
    if (isYest) return `Yesterday ${fmtTime(iso)}`;
    if (days >= 0 && days <= 6) return `${days}d ago ${fmtTime(iso)}`;
    return `${fmtDate(iso)} ${fmtTime(iso)}`;
}

export function durationLabel(minutes: number | null | undefined): string {
    if (minutes == null) return '—';
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes == null) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
