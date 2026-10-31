/* Shared types, token maps and en-NZ formatters for the restraints and behaviour
 * support plan list, detail pages and workflow wizards. Mirrors the
 * RestraintController payloads. Semantic tokens only (no raw oklch/hex). NZ-only. */
import {
    formatDate,
    formatDateLong,
    formatDurationMinutes,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    hsLabel,
    hsVariant,
    humaniseHsValue,
} from '@/lib/health-safety/hs-labels';
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
export type IncidentOption = {
    id: number;
    client_id: number | null;
    reference: string;
    label: string;
};

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
    plan: {
        id: number;
        reference: string;
        title: string;
        status: string;
    } | null;
    related_incident: {
        id: number;
        reference: string;
        type: string | null;
    } | null;
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
    live: {
        events_30d: number;
        out_of_plan: number;
        injuries: number;
        critical: number;
    };
    attention: {
        unreviewed: number;
        plans_review_due: number;
        plans_under_review: number;
        clients_no_active_bsp: number;
    };
    badges: {
        unreviewed: number;
        plans_overdue: number;
        reduction_trend_pct: number;
    };
};

export type RestraintTabCounts = {
    events: {
        all: number;
        unreviewed: number;
        out_of_plan: number;
        injury: number;
        critical: number;
        '30d': number;
    };
    plans: {
        all: number;
        active: number;
        draft: number;
        review_due: number;
        under_review: number;
        archived: number;
    };
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

export type TypeMeta = {
    label: string;
    icon: LucideIcon;
    tone: ChipTone;
    blurb: string;
};

export const RESTRAINT_TYPE_META: Record<string, TypeMeta> = {
    physical: {
        label: 'Physical',
        icon: Hand,
        tone: 'critical',
        blurb: 'Bodily holding or restriction of movement',
    },
    chemical: {
        label: 'Chemical',
        icon: Pill,
        tone: 'warning',
        blurb: 'Medication used to control behaviour',
    },
    mechanical: {
        label: 'Mechanical',
        icon: Link2,
        tone: 'warning',
        blurb: 'Device or equipment restricting movement',
    },
    seclusion: {
        label: 'Seclusion',
        icon: DoorClosed,
        tone: 'critical',
        blurb: 'Confinement alone in a room or area',
    },
    environmental: {
        label: 'Environmental',
        icon: Fence,
        tone: 'info',
        blurb: 'Restricting access to space or items',
    },
};

export function typeMeta(type: string | null | undefined): TypeMeta {
    if (!type)
        return { label: '—', icon: ShieldAlert, tone: 'neutral', blurb: '' };
    return (
        RESTRAINT_TYPE_META[type] ?? {
            label: hsLabel('restraint_type', type),
            icon: ShieldAlert,
            tone: 'neutral',
            blurb: '',
        }
    );
}

export const RESTRAINT_TYPE_OPTIONS = [
    { value: 'physical', label: 'Physical' },
    { value: 'chemical', label: 'Chemical' },
    { value: 'mechanical', label: 'Mechanical' },
    { value: 'seclusion', label: 'Seclusion' },
    { value: 'environmental', label: 'Environmental' },
];

/* ---- severity ---- */

/** One severity scale everywhere (vocabulary.md): Low neutral · Medium warning · High and Critical critical. */
export const SEVERITY_TONE: Record<string, ChipTone> = {
    low: hsVariant('severity', 'low'),
    medium: hsVariant('severity', 'medium'),
    high: hsVariant('severity', 'high'),
    critical: hsVariant('severity', 'critical'),
};

export function severityMeta(s: string | null | undefined): {
    label: string;
    tone: ChipTone;
} {
    if (!s) return { label: '—', tone: 'neutral' };
    return { label: hsLabel('severity', s), tone: hsVariant('severity', s) };
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
    draft: {
        label: hsLabel('plan_status', 'draft'),
        icon: FileEdit,
        tone: 'neutral',
    },
    active: {
        label: hsLabel('plan_status', 'active'),
        icon: CheckCircle2,
        tone: 'success',
    },
    under_review: {
        label: hsLabel('plan_status', 'under_review'),
        icon: Eye,
        tone: 'warning',
    },
    archived: {
        label: hsLabel('plan_status', 'archived'),
        icon: Archive,
        tone: 'neutral',
    },
};

export function planStatusMeta(status: string | null | undefined): StatusMeta {
    if (!status) return PLAN_STATUS_META.draft;
    return (
        PLAN_STATUS_META[status] ?? {
            label: hsLabel('plan_status', status),
            icon: FileEdit,
            tone: 'neutral',
        }
    );
}

export const REVIEW_STATE_META: Record<
    string,
    { label: string; tone: ChipTone }
> = {
    ok: { label: 'Review not due yet', tone: 'neutral' },
    due: { label: 'Review due', tone: 'warning' },
    overdue: { label: 'Review overdue', tone: 'critical' },
};

/* ---- plan review outcome ---- */

export const PLAN_REVIEW_OUTCOME_OPTIONS = [
    { value: 'continued', label: 'Keep the plan as it is' },
    { value: 'modified', label: 'Change the plan' },
    { value: 'reduced', label: 'Reduce the restraint used' },
    { value: 'discontinued', label: 'Stop using the plan' },
    { value: 'escalated', label: 'Ask a specialist to review' },
];

/** Past-tense wording for a review that has already happened (the options above are choices). */
export const PLAN_REVIEW_OUTCOME_RESULT: Record<string, string> = {
    continued: 'Plan kept as it is',
    modified: 'Plan changed',
    reduced: 'Restraint reduced',
    discontinued: 'Plan stopped',
    escalated: 'Sent to a specialist to review',
};

export const PLAN_REVIEW_OUTCOME_LABEL: Record<string, string> =
    Object.fromEntries(
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

export const ATTACHMENT_CATEGORY_LABEL: Record<string, string> =
    Object.fromEntries(
        ATTACHMENT_CATEGORY_OPTIONS.map((o) => [o.value, o.label]),
    );

export const PERIOD_ITEMS = [
    { key: 'week', label: 'This week' },
    { key: '30d', label: 'Last 30 days' },
    { key: 'quarter', label: 'Last 3 months' },
    { key: 'all', label: 'All time' },
];

/* ------------------------------------------------------------------ */
/*  Formatters (en-NZ)                                                 */
/* ------------------------------------------------------------------ */

/** Readable words for a stored value ("under_review" → "Under review"): sentence case, never snake_case. */
export function titleCase(s: string): string {
    return humaniseHsValue(s);
}

export function fmtDate(iso: string | null | undefined): string {
    return formatDate(iso);
}

export function fmtDateFull(iso: string | null | undefined): string {
    return formatDateLong(iso);
}

export function fmtTime(iso: string | null | undefined): string {
    return formatTime(iso, '');
}

export function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return `${fmtDate(iso)}, ${fmtTime(iso)}`;
}

/**
 * Relative "when" phrasing for the events table — "Yesterday, 2:20 pm",
 * "2 days ago, 9:05 am". Days are NZ calendar days, whatever the browser's
 * timezone.
 */
export function whenLabel(iso: string | null | undefined): string {
    const day = iso ? toDateInput(iso) : '';
    if (!day) return '—';
    const days = Math.round(
        (Date.parse(toDateInput(new Date())) - Date.parse(day)) / 86_400_000,
    );
    const time = fmtTime(iso);
    if (days === 0) return `Today, ${time}`;
    if (days === 1) return `Yesterday, ${time}`;
    if (days >= 2 && days <= 6) return `${days} days ago, ${time}`;
    return `${fmtDate(iso)}, ${time}`;
}

export function durationLabel(minutes: number | null | undefined): string {
    if (minutes == null) return '—';
    return formatDurationMinutes(minutes);
}

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes == null) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
