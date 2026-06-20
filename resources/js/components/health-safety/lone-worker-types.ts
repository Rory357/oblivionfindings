/* Shared contracts for the Lone Worker Safety register, detail dialog, wizard and
 * action modals. Mirrors LoneWorkerController's Inertia payload. One source of truth
 * so the page and its modals never drift. */
import type { Tone } from '@/pages/health-safety/components/register-row-kit';

export const LONE_WORKER_ROUTE = '/health-safety/lone-workers';

export type Entity = { id: number; name: string };

export type SessionStatus = 'active' | 'overdue' | 'emergency' | 'completed';

export type Session = {
    id: number;
    user: Entity | null;
    site: Entity | null;
    client: Entity | null;
    shift_id: number | null;
    started_at: string | null;
    expected_end_at: string | null;
    ended_at: string | null;
    last_check_in_at: string | null;
    status: SessionStatus;
    activity_description: string | null;
    check_in_interval_minutes: number | null;
    location: string | null;
    // decimal:7 casts arrive as strings over JSON
    location_lat: string | number | null;
    location_lng: string | number | null;
    is_check_in_overdue: boolean;
};

export type CheckInStatus = 'ok' | 'concern' | 'emergency';

export type CheckIn = {
    id: number;
    status: CheckInStatus | string;
    notes: string | null;
    checked_in_at: string | null;
    location_lat: string | number | null;
    location_lng: string | number | null;
};

export type AlertType = 'emergency' | 'overdue_check_in' | 'no_response' | string;
export type AlertStatus = 'active' | 'acknowledged' | 'resolved' | string;
export type AlertSource = 'control_room' | 'legacy';

export type AlertSession = {
    id: number | null;
    user: Entity | null;
    site: Entity | null;
    client: Entity | null;
    started_at: string | null;
    expected_end_at: string | null;
    last_check_in_at: string | null;
    status: string | null;
    activity_description: string | null;
    check_in_interval_minutes: number | null;
    location: string | null;
    location_lat?: string | number | null;
    location_lng?: string | number | null;
};

export type Alert = {
    id: string; // prefixed: cr_<id> / legacy_<id>
    session: AlertSession | null;
    type: AlertType;
    triggered_at: string | null;
    status: AlertStatus;
    source: AlertSource;
    notes: string | null;
};

/** Compact alert reference shown in the session detail's alert history. */
export type SessionAlertRef = {
    id: string;
    type: AlertType;
    triggered_at: string | null;
    status: AlertStatus;
    source: AlertSource;
};

export type LinkedShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string | null;
    is_on_call: boolean;
};

export type SessionDetail = Session & {
    _type: 'session';
    emergency_triggered_at: string | null;
    emergency_notes: string | null;
    check_ins: CheckIn[];
    alerts: SessionAlertRef[];
    shift: LinkedShift | null;
};

export type AlertDetail = Alert & {
    _type: 'alert';
    cr_id: number | null;
    can_view_control_room: boolean;
    incident_id: number | null;
};

export type Detail = SessionDetail | AlertDetail;

export type ShiftOption = {
    id: number;
    worker: Entity | null;
    site: Entity | null;
    client: Entity | null;
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    location_lat: string | number | null;
    location_lng: string | number | null;
    is_on_call: boolean;
    is_lone: boolean;
};

export type Options = {
    sites: Entity[];
    staff: Entity[];
    clients: Entity[];
    shifts: ShiftOption[];
};

export type Can = { manage: boolean; view: boolean; view_control_room: boolean };

export type Hero = {
    clusters: {
        live: { active: number; overdue: number; emergency: number; ending_soon: number };
        alerts: { today: number; awaiting_ack: number; unresolved: number; no_recent_checkin: number };
    };
    badges: {
        checked_in: number;
        monitored_total: number;
        overdue: number;
        emergency_active: boolean;
        after_hours: boolean;
    };
    lone_shifts_unmonitored: number;
};

export type Filters = {
    site_id: number | null;
    status: string | null;
    user_id: number | null;
    period: 'today' | 'week' | '30d' | 'all';
    q: string | null;
};

export type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

/** Lifecycle action launched from the row menu or the detail Options bar. */
export type ActionKind = 'checkin' | 'extend' | 'end' | 'emergency' | 'acknowledge' | 'resolve';

/** Target for an action modal — a session (lifecycle) or a legacy alert (ack/resolve). */
export type ActionTarget =
    | { kind: 'checkin' | 'extend' | 'end' | 'emergency'; session: Session | SessionDetail }
    | { kind: 'acknowledge' | 'resolve'; alert: Alert | AlertDetail };

/* ── Shared display maps (tone + label) ─────────────────────────────── */

export const SESSION_TONE: Record<string, Tone> = {
    active: 'success',
    overdue: 'warning',
    emergency: 'critical',
    completed: 'neutral',
};

export const SESSION_LABEL: Record<string, string> = {
    active: 'Active',
    overdue: 'Overdue',
    emergency: 'Emergency',
    completed: 'Completed',
};

export const ALERT_TYPE_META: Record<string, { tone: Tone; label: string }> = {
    emergency: { tone: 'critical', label: 'Emergency' },
    overdue_check_in: { tone: 'warning', label: 'Overdue check-in' },
    no_response: { tone: 'warning', label: 'No response' },
};

export const ALERT_STATUS_META: Record<string, { tone: Tone; label: string }> = {
    active: { tone: 'critical', label: 'Active' },
    acknowledged: { tone: 'warning', label: 'Acknowledged' },
    resolved: { tone: 'success', label: 'Resolved' },
};

/** Minutes the check-in is overdue, for the "overdue by Xm" row hint (0 if not overdue). */
export function overdueByMinutes(s: Pick<Session, 'last_check_in_at' | 'started_at' | 'check_in_interval_minutes' | 'status'>): number {
    if (s.status !== 'overdue' && s.status !== 'active') return 0;
    const base = s.last_check_in_at ?? s.started_at;
    if (!base || !s.check_in_interval_minutes) return 0;
    const due = new Date(base).getTime() + s.check_in_interval_minutes * 60_000;
    const diffMs = Date.now() - due;
    return diffMs > 0 ? Math.floor(diffMs / 60_000) : 0;
}
