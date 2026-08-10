/* Shared types, token maps and en-NZ formatters for the Emergency Drills register,
 * detail modal and workflow wizards. Semantic tokens only (no raw oklch/hex). */
import {
    Activity,
    AlarmClock,
    CalendarClock,
    CheckCircle2,
    Flame,
    FlaskConical,
    HeartPulse,
    Loader,
    Lock,
    Siren,
    Waves,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types (mirror EmergencyDrillController payloads)                   */
/* ------------------------------------------------------------------ */

export type DrillSite = { id: number; name: string; region: string | null };

export type DrillRow = {
    id: number;
    reference: string;
    drill_type: string;
    type_label: string;
    title: string;
    scheduled_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    status: string; // includes derived 'overdue'
    raw_status: string;
    outcome: string | null;
    site: DrillSite | null;
    participants_count: number;
    total_participants: number | null;
    people_label: string | null;
    findings_open: number;
    findings_count: number;
    flags: {
        overdue: boolean;
        running: boolean;
        finding_overdue: boolean;
        open_findings: number;
    };
};

export type DrillParticipant = {
    id: number;
    user_id: number;
    name: string;
    role: string | null;
    attended: boolean;
    notes: string | null;
};

export type DrillFinding = {
    id: number;
    finding_type: string;
    description: string;
    severity: string | null;
    status: string;
    corrective_action: string | null;
    assigned_to: number | null;
    assignee_name: string | null;
    due_date: string | null;
    resolved_at: string | null;
    resolution_notes: string | null;
    is_overdue: boolean;
};

export type DrillAttachment = {
    id: number;
    original_name: string;
    mime: string | null;
    size: number | null;
    kind: string | null;
    notes: string | null;
    alt_text: string | null;
    is_image: boolean;
    uploaded_by_name: string | null;
    created_at: string | null;
    url: string;
};

export type DrillTimelineItem = {
    key: string;
    label: string;
    icon: string;
    at: string | null;
    meta: string | null;
};

export type DrillDetail = {
    id: number;
    reference: string;
    drill_type: string;
    type_label: string;
    title: string;
    status: string;
    outcome: string | null;
    scheduled_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_minutes: number | null;
    evacuation_time_seconds: number | null;
    weather_conditions: string | null;
    total_participants: number | null;
    residents_evacuated: number | null;
    all_areas_checked: boolean;
    assembly_point_reached: boolean;
    roll_call_completed: boolean;
    scenario_description: string | null;
    is_unannounced: boolean;
    assembly_point: string | null;
    evacuation_scheme: string | null;
    observer_notes: string | null;
    improvements_identified: string | null;
    site: DrillSite | null;
    coordinator_name: string | null;
    conducted_by: number | null;
    created_by_name: string | null;
    created_at: string | null;
    participants: DrillParticipant[];
    findings: DrillFinding[];
    attachments: DrillAttachment[];
    timeline: DrillTimelineItem[];
    hs_event: {
        id: number;
        reference_number: string;
        status: string;
        severity: string;
        url: string;
    } | null;
    assignable_staff: { id: number; name: string }[];
    can: { manage: boolean };
};

export type DrillFilters = {
    q: string | null;
    tab: string;
    period: string;
    drill_type: string | null;
    outcome: string | null;
    site_id: number | null;
};

export type DrillHero = {
    live: {
        scheduled: number;
        overdue: number;
        in_progress: number;
        completed: number;
    };
    attention: {
        sites_overdue: number;
        findings_open: number;
        findings_overdue: number;
        awaiting_writeup: number;
    };
    badges: {
        sites_drilled_pct: number;
        drills_overdue: number;
        sites_overdue: number;
        fenz_reviews_due: number;
        nga_paerewa_certified: boolean;
    };
};

export type StaffOption = { id: number; name: string };

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

export type DrillTypeMeta = { label: string; icon: LucideIcon; tone: ChipTone };

export const DRILL_TYPE_META: Record<string, DrillTypeMeta> = {
    fire_evacuation: {
        label: 'Fire evacuation',
        icon: Flame,
        tone: 'critical',
    },
    earthquake: { label: 'Earthquake', icon: Activity, tone: 'warning' },
    lockdown: { label: 'Lockdown', icon: Lock, tone: 'info' },
    tsunami: { label: 'Tsunami', icon: Waves, tone: 'info' },
    chemical_spill: {
        label: 'Chemical spill',
        icon: FlaskConical,
        tone: 'warning',
    },
    medical_emergency: {
        label: 'Medical emergency',
        icon: HeartPulse,
        tone: 'critical',
    },
    other: { label: 'Other', icon: Siren, tone: 'neutral' },
};

export function typeMeta(type: string): DrillTypeMeta {
    return (
        DRILL_TYPE_META[type] ?? {
            label: titleCase(type),
            icon: Siren,
            tone: 'neutral',
        }
    );
}

export type StatusMeta = { label: string; icon: LucideIcon; tone: ChipTone };

export const STATUS_META: Record<string, StatusMeta> = {
    scheduled: { label: 'Scheduled', icon: CalendarClock, tone: 'info' },
    overdue: { label: 'Overdue', icon: AlarmClock, tone: 'critical' },
    in_progress: { label: 'In progress', icon: Loader, tone: 'warning' },
    completed: { label: 'Completed', icon: CheckCircle2, tone: 'success' },
    cancelled: { label: 'Cancelled', icon: XCircle, tone: 'neutral' },
};

export function statusMeta(status: string): StatusMeta {
    return STATUS_META[status] ?? STATUS_META.scheduled;
}

export const OUTCOME_META: Record<string, { label: string; tone: ChipTone }> = {
    passed: { label: 'Passed', tone: 'success' },
    passed_actions: { label: 'Passed with actions', tone: 'warning' },
    failed: { label: 'Failed', tone: 'critical' },
};

export function outcomeMeta(
    outcome: string | null,
): { label: string; tone: ChipTone } | null {
    if (!outcome) return null;
    return (
        OUTCOME_META[outcome] ?? { label: titleCase(outcome), tone: 'neutral' }
    );
}

export const SEVERITY_TONE: Record<string, ChipTone> = {
    critical: 'critical',
    high: 'critical',
    medium: 'warning',
    low: 'neutral',
};

export const FINDING_TYPE_LABEL: Record<string, string> = {
    observation: 'Observation',
    non_conformance: 'Non-conformance',
    improvement: 'Improvement',
    positive: 'Positive',
};

export const FINDING_STATUS_META: Record<
    string,
    { label: string; tone: ChipTone }
> = {
    open: { label: 'Open', tone: 'warning' },
    in_progress: { label: 'In progress', tone: 'info' },
    resolved: { label: 'Resolved', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
};

/** Drill types offered as pickable tiles in the Schedule wizard (design: 4 tiles). */
export const SCHEDULE_TYPE_KEYS = [
    'fire_evacuation',
    'earthquake',
    'lockdown',
    'tsunami',
] as const;

/** Full drill-type options for the filter <select> and edit form. */
export const DRILL_TYPE_OPTIONS = [
    { value: 'fire_evacuation', label: 'Fire evacuation' },
    { value: 'earthquake', label: 'Earthquake' },
    { value: 'lockdown', label: 'Lockdown' },
    { value: 'tsunami', label: 'Tsunami' },
    { value: 'chemical_spill', label: 'Chemical spill' },
    { value: 'medical_emergency', label: 'Medical emergency' },
    { value: 'other', label: 'Other' },
];

export const PARTICIPANT_ROLE_OPTIONS = [
    { value: 'participant', label: 'Participant' },
    { value: 'observer', label: 'Observer' },
    { value: 'warden', label: 'Fire warden' },
    { value: 'first_aider', label: 'First aider' },
    { value: 'coordinator', label: 'Coordinator' },
];

/* ------------------------------------------------------------------ */
/*  Formatters (en-NZ)                                                 */
/* ------------------------------------------------------------------ */

export function titleCase(s: string): string {
    return s.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

export function fmtDateFull(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function fmtTime(iso: string | null | undefined): string {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function fmtDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';
    return `${fmtDate(iso)} · ${fmtTime(iso)}`;
}

/** Compact "When" cell — relative phrasing for overdue/recent, with the ref under it. */
export function whenLabel(row: DrillRow): string {
    const iso = row.completed_at ?? row.started_at ?? row.scheduled_at;
    if (!iso) return '—';
    if (row.status === 'overdue')
        return `Was ${fmtDate(iso)} · ${fmtTime(iso)}`;
    return `${fmtDate(iso)} · ${fmtTime(iso)}`;
}

export function fmtEvacTime(seconds: number | null | undefined): string {
    if (seconds == null) return '—';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
}

/**
 * Convert a naive datetime-local value ("YYYY-MM-DDTHH:mm", interpreted in the
 * browser's local tz) to a UTC ISO instant for the server. Without this the server
 * stores the naive string as UTC, so an NZ-entered 9:30am displays back as 9:30pm.
 */
export function localToUtcIso(local: string | null | undefined): string {
    if (!local) return '';
    const d = new Date(local);
    return Number.isNaN(d.getTime()) ? '' : d.toISOString();
}

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes == null) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
