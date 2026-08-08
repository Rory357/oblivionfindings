/* Shared types, status maps, and option sets for the Worker Participation
 * register (page + all six modals). Keeps the FE contract in one place so the
 * detail dialogs and create wizards stay consistent with each other and with
 * WorkerParticipationController. */
import type { Tone } from '@/pages/health-safety/components/register-row-kit';
import {
    AlertTriangle,
    Boxes,
    ClipboardList,
    FileText,
    MessageSquare,
    ShieldAlert,
    Wrench,
    type LucideIcon,
} from 'lucide-react';

export const WP_BASE = '/health-safety/worker-participation';

/* ------------------------------------------------------------------ */
/*  Row + detail shapes (match controller payloads)                    */
/* ------------------------------------------------------------------ */

export type Ref = { id: number; name: string } | null;

export type RepRow = {
    id: number;
    user: Ref;
    site: Ref;
    work_group: string | null;
    election_method: string | null;
    elected_at: string | null;
    training_days_completed: number;
    status: string;
};

export type MeetingRow = {
    id: number;
    committee: Ref;
    scheduled_at: string | null;
    status: string;
    attendees_count: number;
    minutes_document_path: string | null;
    actions_due_count: number;
};

export type ConsultationRow = {
    id: number;
    title: string;
    consultation_type: string;
    consultation_date: string | null;
    status: string;
    site: Ref;
    document_path: string | null;
    outcome_document_path: string | null;
    worker_feedback_summary: string | null;
    outcome: string | null;
};

export type ActionItem = {
    description: string;
    assigned_to?: number | null;
    due_date?: string | null;
    status?: 'open' | 'in_progress' | 'done' | null;
};

export type AttendeeUser = {
    id: number;
    name: string;
    pivot?: { response?: string; attended?: boolean };
};

export type RepDetail = RepRow & {
    term_expires_at: string | null;
    initial_training_completed_at: string | null;
    notes: string | null;
    creator?: Ref;
    created_at?: string | null;
};

export type MeetingDetail = {
    id: number;
    committee: Ref;
    hs_committee_id: number;
    scheduled_at: string | null;
    ended_at: string | null;
    location: string | null;
    status: string;
    agenda_items: string[] | null;
    minutes: string | null;
    action_items: ActionItem[] | null;
    actions_due_count: number;
    minutes_document_path: string | null;
    minutes_document_name: string | null;
    attendee_users?: AttendeeUser[];
    creator?: Ref;
};

export type ConsultationDetail = ConsultationRow & {
    description: string | null;
    changes_made: string | null;
    document_name: string | null;
    outcome_document_name: string | null;
    workers_consulted: number[] | null;
    initiated_by: number | null;
    initiated_by_name?: string | null;
    stage_index?: number;
};

/** Which sub-action a detail dialog should open straight onto (right-click → pane). */
export type WpDetailAction =
    | 'edit'
    | 'training' // representative
    | 'attendees'
    | 'complete'
    | 'minutes' // meeting
    | 'feedback'
    | 'outcome'
    | 'upload'
    | 'close'; // consultation

export type WpCan = { manage: boolean };

/* ------------------------------------------------------------------ */
/*  Status tone maps                                                    */
/* ------------------------------------------------------------------ */

export const REP_STATUS: Record<string, Tone> = {
    active: 'success',
    inactive: 'neutral',
    resigned: 'critical',
};
export const MEETING_STATUS: Record<string, Tone> = {
    scheduled: 'neutral',
    completed: 'success',
    cancelled: 'critical',
    in_progress: 'warning',
};

export const CONSULT_STATUS: Record<string, { tone: Tone; label: string }> = {
    open: { tone: 'warning', label: 'Open' },
    feedback_received: { tone: 'neutral', label: 'Feedback received' },
    actioned: { tone: 'neutral', label: 'Actioned' },
    closed: { tone: 'success', label: 'Closed' },
};
export const CONSULT_ORDER: Record<string, number> = {
    open: 1,
    feedback_received: 2,
    actioned: 3,
    closed: 4,
};

/** Canonical consultation lifecycle, in order — drives the detail timeline. */
export const CONSULT_STAGES: { key: string; label: string; blurb: string }[] = [
    { key: 'open', label: 'Opened', blurb: 'Consultation raised with kaimahi' },
    {
        key: 'feedback_received',
        label: 'Feedback received',
        blurb: 'Worker feedback captured',
    },
    {
        key: 'actioned',
        label: 'Actioned',
        blurb: 'Outcome decided + changes made',
    },
    { key: 'closed', label: 'Closed', blurb: 'Consultation completed' },
];

/* ------------------------------------------------------------------ */
/*  Option sets (wizards + edit panes)                                  */
/* ------------------------------------------------------------------ */

/** ONE canonical NZ consultation-type set — mirrors StoreConsultationRequest. */
export const CONSULTATION_TYPES: {
    key: string;
    label: string;
    description: string;
    icon: LucideIcon;
}[] = [
    {
        key: 'hazard_review',
        label: 'Hazard review',
        description: 'Identifying or reviewing a workplace hazard',
        icon: AlertTriangle,
    },
    {
        key: 'risk_assessment',
        label: 'Risk assessment',
        description: 'Assessing risk for a task or change',
        icon: ShieldAlert,
    },
    {
        key: 'procedure_change',
        label: 'Procedure change',
        description: 'New or changed way of working',
        icon: ClipboardList,
    },
    {
        key: 'policy_review',
        label: 'Policy review',
        description: 'A H&S policy is being introduced or revised',
        icon: FileText,
    },
    {
        key: 'equipment_change',
        label: 'Equipment change',
        description: 'New plant, equipment or substance',
        icon: Boxes,
    },
    {
        key: 'change_notification',
        label: 'Change notification',
        description: 'Notifying workers of a proposed change',
        icon: MessageSquare,
    },
    {
        key: 'general',
        label: 'General consultation',
        description: 'Any other matter affecting H&S',
        icon: Wrench,
    },
];

export const ELECTION_METHODS: {
    key: string;
    label: string;
    description: string;
}[] = [
    {
        key: 'elected',
        label: 'Elected',
        description: 'Chosen by a vote of the work group',
    },
    {
        key: 'appointed',
        label: 'Appointed',
        description: 'Appointed by agreement (no contest)',
    },
    {
        key: 'volunteered',
        label: 'Volunteered',
        description: 'Stepped forward voluntarily',
    },
];

export const MEETING_FREQUENCIES: { value: string; label: string }[] = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly (HSWA minimum)' },
];

/* ------------------------------------------------------------------ */
/*  Formatters                                                          */
/* ------------------------------------------------------------------ */

export const fmtDate = (d: string | null | undefined) =>
    d
        ? new Date(d).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

export const fmtDateTime = (d: string | null | undefined) =>
    d
        ? new Date(d).toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

export const consultationTypeLabel = (t: string) =>
    CONSULTATION_TYPES.find((x) => x.key === t)?.label ??
    t.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
