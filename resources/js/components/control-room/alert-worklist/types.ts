export type AlertStatusKey =
    | 'open'
    | 'ack'
    | 'triaging'
    | 'confirmed'
    | 'resolved'
    | 'closed'
    | 'dismissed';
export type AlertSeverityKey = 'low' | 'medium' | 'high' | 'critical';
export type AlertSlaStatusKey =
    | 'on_track'
    | 'at_risk'
    | 'breached'
    | 'resolved'
    | 'not_applicable';

export type AlertWorklistRow = {
    id: number;
    reference_number: string | null;
    summary: string;
    source: { key: string; label: string };
    status: AlertStatusKey;
    severity: AlertSeverityKey;
    priority: { level: string; rank: number; reason: string };
    playbook?: {
        name: string | null;
        status: string;
        completed_steps: number;
        total_steps: number;
    } | null;
    triggered_at: string | null;
    next_deadline_at: string | null;
    sla: { status: AlertSlaStatusKey | null; next_deadline_at: string | null };
    site: { id: number; name: string } | null;
    person: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    queue: { id: number; name: string } | null;
    journey: {
        incident_reference: string | null;
        health_safety_reference: string | null;
        handover_status: string | null;
    };
    next_action: { label: string; href: string };
    actions: {
        can_claim: boolean;
        can_acknowledge: boolean;
        can_move_queue: boolean;
        can_escalate: boolean;
        can_create_incident: boolean;
        can_snooze: boolean;
        can_unsnooze: boolean;
        can_copy_reference: boolean;
        incident_href: string | null;
        health_safety_href: string | null;
    };
    href: string;
};
