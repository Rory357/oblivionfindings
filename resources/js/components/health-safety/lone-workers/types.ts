import type { PlannedLocationSnapshot } from './planned-location-fields';

export type Person = { id: number; name: string };
export type SessionSection =
    | 'overview'
    | 'check-ins'
    | 'response'
    | 'location'
    | 'history'
    | 'connections';
export type SessionCommand =
    | 'edit'
    | 'check-in'
    | 'end'
    | 'panic'
    | 'locate'
    | 'acknowledge-panic'
    | 'remove';
export type LinkedRecord = {
    id: number;
    href: string;
    name?: string;
    reference_number?: string | null;
    title?: string;
    status?: string;
    display_state?: string;
    owner?: Person | null;
    assigned_to?: Person | string | null;
    triggered_at?: string | null;
    acknowledged_at?: string | null;
    resolved_at?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    due_date?: string | null;
};
export type SessionDelivery = {
    id: number;
    episode_number: number;
    status:
        | 'pending'
        | 'failed'
        | 'delivered'
        | 'suppressed'
        | 'skipped'
        | 'unknown';
    attempts: number;
    occurred_at: string | null;
    failure_code: string | null;
    next_attempt_at: string | null;
    delivered_at: string | null;
};
export type SessionObservation = {
    state: string;
    real_check_in_at: string | null;
    received_at: string | null;
    receipt_source: string | null;
    startup_baseline_at: string | null;
    next_due_at: string | null;
    monitor: {
        state: string;
        checked_at: string | null;
        failed_at: string | null;
        failure_code: string | null;
    };
};
export type SafetySession = {
    id: number;
    reference: string;
    status: string;
    expected_version: number;
    lock_version: number;
    user_id: number;
    site_id: number;
    client_id: number | null;
    shift_id: number | null;
    user: Person | null;
    worker: Person | null;
    site: Person | null;
    client: Person | null;
    started_at: string | null;
    expected_end_at: string | null;
    ended_at: string | null;
    last_check_in_at: string | null;
    next_check_in_at: string | null;
    is_check_in_overdue: boolean;
    check_in_interval_minutes: number;
    activity_description: string | null;
    location: string | null;
    planned_location?: PlannedLocationSnapshot | null;
    location_lat: number | string | null;
    location_lng: number | string | null;
    location_state: string;
    emergency_triggered_at: string | null;
    emergency_notes: string | null;
    created_at: string | null;
    observation: SessionObservation;
    check_ins: {
        id: number;
        checked_in_at: string | null;
        received_at: string | null;
        receipt_source: string;
        status: string;
        notes: string | null;
        recorded_by: Person | null;
        location_lat: number | string | null;
        location_lng: number | string | null;
    }[];
    can: Record<
        | 'view'
        | 'manage'
        | 'edit'
        | 'end'
        | 'remove'
        | 'delete'
        | 'check_in'
        | 'panic'
        | 'locate'
        | 'acknowledge_panic',
        boolean
    >;
    tracker_state: string;
    tracker: null | {
        name: string | null;
        latitude: number | string | null;
        longitude: number | string | null;
        observed_at: string | null;
        received_at: string | null;
        location_source: string | null;
        collection_state: string;
        last_seen_at: string | null;
        battery_level: number | null;
        panic_active: boolean;
    };
    /**
     * The linked Shift's latest GPS ping, read live with its time, for viewers
     * with telemetry access only (null otherwise). Never the session's own
     * position; `is_old` once it is older than `fresh_minutes`.
     */
    shift_gps?: {
        latitude: number;
        longitude: number;
        captured_at: string;
        is_old: boolean;
        fresh_minutes: number;
    } | null;
    source_delivery: SessionDelivery | null;
    current_episode: SessionDelivery | null;
    connections: {
        shift: LinkedRecord | null;
        site: LinkedRecord | null;
        client: LinkedRecord | null;
        control_room: LinkedRecord[];
        incidents: LinkedRecord[];
        safety_reviews: LinkedRecord[];
        corrective_actions: LinkedRecord[];
    };
    history: {
        id: string;
        title: string;
        action: string;
        occurred_at: string | null;
        actor: Person | null;
        note: string | null;
        snapshot: Record<string, unknown>;
        legacy: boolean;
    }[];
    legacy_alert_history: {
        id: string;
        type: string;
        triggered_at: string | null;
        recorded_status: string;
        label: string;
    }[];
};
export type SessionPickers = {
    sites: (Person & {
        address?: string | null;
        latitude?: number | null;
        longitude?: number | null;
    })[];
    can_geocode?: boolean;
    clients: (Person & { site_id: number })[];
    workers: (Person & { site_ids: number[] })[];
    shifts: {
        id: number;
        user_id: number;
        worker: Person | null;
        site_id: number;
        client_id: number | null;
        starts_at: string | null;
        ends_at: string | null;
        actual_starts_at: string | null;
        is_lone_worker: boolean | null;
        location?: string | null;
        address?: string | null;
        latitude?: null;
        longitude?: null;
    }[];
    check_in_statuses: string[];
    check_in_intervals: number[];
};
