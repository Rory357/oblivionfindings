import type {
    DrillAttachment,
    DrillDetail,
    DrillFinding,
    DrillParticipant,
} from '@/pages/health-safety/drills/shared';

export type DrillActor = { id: number; name: string };
export type DrillLinkedRecord = {
    id: number;
    reference_number: string | null;
    title?: string | null;
    status: string;
    href: string;
    display_state?: string | null;
};
export type DrillCorrectiveAction = DrillLinkedRecord & {
    assigned_to: DrillActor | null;
    due_date: string | null;
    effectiveness_confirmed: boolean | null;
    verified_at: string | null;
    closed_at: string | null;
};
export type DrillSummary = {
    id: number;
    reference: string;
    title: string;
    status: string;
    outcome: string | null;
    scheduled_at: string | null;
    href: string;
};
export type DrillWorkspaceFinding = DrillFinding & {
    resolution_evidence_ids: number[];
    corrective_action_record: DrillCorrectiveAction | null;
    resolve_blocked_reason: string | null;
    corrective_action_options_href: string | null;
    can: {
        edit: boolean;
        resolve: boolean;
        create_action: boolean;
        link_action: boolean;
    };
};
export type DrillWorkspaceDetail = Omit<
    DrillDetail,
    | 'can'
    | 'participants'
    | 'findings'
    | 'attachments'
    | 'timeline'
    | 'hs_event'
    | 'assignable_staff'
> & {
    expected_version: number;
    lock_version: number;
    coordinator: DrillActor | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    participants: (DrillParticipant & {
        accommodations: string | null;
        can: { edit: boolean };
    })[];
    findings: DrillWorkspaceFinding[];
    attachments: (DrillAttachment & { can: { remove: boolean } })[];
    counts: {
        assigned: number;
        attended: number;
        not_attended: number;
        total_recorded: number | null;
        residents_evacuated: number | null;
        findings_open: number;
        findings_overdue: number;
    };
    history: {
        id: string | number;
        action: string;
        title: string;
        actor: DrillActor | null;
        occurred_at: string | null;
        note: string | null;
        snapshot: Record<string, unknown>;
        legacy: boolean;
    }[];
    connections: {
        safety_reviews: DrillLinkedRecord[];
        corrective_actions: DrillCorrectiveAction[];
        control_room: DrillLinkedRecord[];
        previous_drill: DrillSummary | null;
        next_drills: DrillSummary[];
    };
    exports: { participants_href: string | null; evidence_href: string | null };
    options_href: string;
    source_delivery: null | {
        event_status: 'pending' | 'failed' | 'delivered' | 'skipped' | null;
        signal_status:
            | 'pending'
            | 'failed'
            | 'delivered'
            | 'suppressed'
            | 'skipped'
            | null;
        event_delivered_at: string | null;
        signal_delivered_at: string | null;
        attempts: number;
        failure_code:
            | 'source_inactive'
            | 'event_delivery_failed'
            | 'signal_delivery_failed'
            | 'queue_unavailable'
            | null;
        next_attempt_at: string | null;
    };
    can: {
        view: boolean;
        manage: boolean;
        edit: boolean;
        start: boolean;
        complete: boolean;
        cancel: boolean;
        add_participant: boolean;
        add_finding: boolean;
        add_evidence: boolean;
        remove_evidence: boolean;
        schedule_next: boolean;
        export_participants: boolean;
        export_evidence: boolean;
    };
};
export type DrillPickers = {
    sites: { id: number; name: string }[];
    staff: DrillActor[];
    drill_types: string[];
    finding_types: string[];
    severities: string[];
    outcomes: string[];
};
