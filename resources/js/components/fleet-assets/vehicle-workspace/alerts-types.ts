/** Server DTOs for Map › Alerts & Control Room (VehicleAlertController). */
import type { RouteState } from './map-driving-types';

export type AlertFilter = 'open' | 'all' | 'resolved';

/** Control Room statuses, plus a vehicle signal Control Room has not received. */
export type AlertStatus =
    | 'open'
    | 'ack'
    | 'triaging'
    | 'confirmed'
    | 'resolved'
    | 'closed'
    | 'dismissed'
    | 'delivery_failed'
    | 'delivery_pending';

export type TriageDecision =
    | 'maintenance'
    | 'monitor'
    | 'escalate'
    | 'no_action';

export type AssessmentOutcome =
    | 'incident_confirmed'
    | 'false_alarm'
    | 'duplicate'
    | 'unable_to_confirm';

export type AlertItem = {
    /** A response id, or "signal:{id}" for an undelivered signal. */
    id: number | string;
    type: 'response' | 'delivery';
    reference: string;
    kind: string;
    kind_key: string;
    severity: string | null;
    priority: string | null;
    status: AlertStatus;
    escalation_level: number;
    owner: string | null;
    observed_at: string | null;
    acknowledged_at: string | null;
    /** Control Room's acknowledgement target (SLA), when one applies. */
    acknowledge_by: string | null;
    acknowledge_breached: boolean;
    duplicates: number;
    decision: { key: TriageDecision; label: string } | null;
    work: { id: number; reference: string; status: string } | null;
    delivery: string | null;
    attempts: number | null;
    signal_id: number | null;
};

export type AlertPlan = {
    version: number;
    status: 'draft';
    owner: { id: number; name: string } | null;
    backup: { id: number; name: string } | null;
    speed_threshold_kph: number;
    speed_tolerance_kph: number;
    speed_duration_s: number;
    speed_cooldown_s: number;
    offline_minutes: number;
    low_voltage_v: number;
    low_voltage_minutes: number;
    notes: string;
    saved_by: string | null;
    saved_at: string | null;
};

export type RecordedEventKind =
    | 'overspeed'
    | 'power_disconnected'
    | 'low_voltage';

export type RecordedEvent = {
    source_key: string;
    kind: RecordedEventKind;
    title: string;
    at: string;
    detail: string;
    trip_id: number | null;
    trip_reference: string | null;
    event_key: string | null;
    event_id: number | null;
    peak_kph: number | null;
    seconds: number | null;
    route: RouteState | null;
};

export type AlertsCan = {
    view: boolean;
    manage: boolean;
    escalate: boolean;
    route: boolean;
    retry: boolean;
    plan: boolean;
    follow_up: boolean;
    view_maintenance: boolean;
};

export type VehicleAlerts = {
    as_of: string;
    timezone: string;
    vehicle: { id: number; name: string; registration_number: string | null };
    tracker: { model: string | null; family: 'gv500cg' | null };
    filter: AlertFilter;
    counts: { open: number; resolved: number; all: number } | null;
    items: AlertItem[];
    plan: AlertPlan | null;
    recorded_events: RecordedEvent[];
    can: AlertsCan;
};

export type AlertDetail = {
    id: number;
    reference: string;
    kind: string;
    kind_key: string;
    severity: string;
    priority: string;
    status: AlertStatus;
    escalation_level: number;
    owner: string | null;
    observed_at: string | null;
    received_at: string | null;
    acknowledged_at: string | null;
    acknowledge_by: string | null;
    acknowledge_breached: boolean;
    decision: {
        key: TriageDecision;
        label: string;
        by: string | null;
        at: string | null;
    } | null;
    resolution: string | null;
    work: {
        id: number;
        reference: string;
        title: string | null;
        status: string;
    } | null;
    follow_ups: Array<{
        id: number;
        title: string;
        due_at: string | null;
        state: string;
        owner: string | null;
    }>;
    vehicle: string;
    /** Tracker model, when the viewer may see vehicle technology. */
    device: string | null;
    location: {
        lat: number;
        lng: number;
        basis: 'recorded_event' | 'vehicle_when_received';
    } | null;
    location_withheld: 'access' | 'consent' | 'personal' | null;
    driver: {
        label: string;
        state: 'confirmed' | 'recorded' | 'hidden' | 'withheld' | 'none';
    };
    source: {
        label: string;
        trip: {
            id: number;
            reference: string;
            local_date: string | null;
        } | null;
        sent_by: string | null;
    };
    correlation: { duplicates: number; signal_id: number | null };
    evidence: string;
    review_source: { trip_id: number; event_key: string } | null;
    history: Array<{ at: string; text: string }>;
    /** Sent back with an action to detect a concurrent change. */
    version: string;
    can: {
        acknowledge: boolean;
        triage: boolean;
        escalate: boolean;
        resolve: boolean;
        maintenance_create: boolean;
        maintenance_link: boolean;
        open_work: boolean;
        follow_up: boolean;
        review_source: boolean;
    };
};
