import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';

/** Props sent by ControlRoomDashboardController (the Live response › Active view). */
export type DeskHero = {
    active: number;
    critical: number;
    sla_breached: number;
    unassigned: number;
    oldest_open_at: string | null;
    last_24_hours: {
        alerts: number;
        resolved: number;
        avg_response_minutes: number | null;
    };
};

export type DeskHandover = {
    needs_incident: number;
    awaiting_health_safety: number;
    accepted_in_progress: number;
    operational_complete_governance_open: number;
};

export type DeskFilters = {
    q?: string;
    status?: string;
    severity?: string;
    source?: string;
    queue_id?: number | string | null;
    assigned_to?: string;
    site_id?: number | string | null;
    period?: string;
};

export type DeskWorklist = {
    data: AlertWorklistRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export type DeskQueue = {
    id: number;
    name: string;
    tier: number;
    active: number;
    critical: number;
};

export type DeskActivity = {
    id: number;
    type: string;
    alert_id: number;
    occurred_at: string;
    actor_name: string | null;
    meta?: Record<string, unknown> | null;
};

/** Historical figures — sent only on request; the Reports destination shows them. */
export type DeskAnalytics = {
    period: string;
    volume: Record<string, unknown>;
    sla: Record<string, unknown>;
    escalation: Record<string, unknown>;
    sla_daily_trend?: Array<{ date: string; compliance_pct: number | null }>;
    sites: Array<Record<string, unknown>>;
    cached_for_seconds?: number;
};
