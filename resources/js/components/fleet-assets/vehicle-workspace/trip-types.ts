/** Server DTOs for vehicle trip history (VehicleTripHistoryService). */

import type { Person } from './types';

export type TripEventFilter = 'all' | 'overspeed' | 'faults' | 'partial';

export type TripFilters = {
    q: string;
    from: string | null;
    to: string | null;
    /** 'all', 'unassigned' or a person id. */
    driver: string;
    event: TripEventFilter;
};

export type TripVehicle = {
    id: number;
    name: string;
    registration_number: string | null;
    asset_tag: string | null;
};

/**
 * Who drove, as far as the records say. Only `confirmed` names the actual
 * driver; `recorded` is a booking or a driver sign-in; `hidden` means a
 * driver is recorded but their name is limited to their own sites.
 */
export type TripDriverState = 'confirmed' | 'recorded' | 'hidden' | 'none';

export type TripDriver = {
    state: TripDriverState;
    source: 'confirmed' | 'session' | 'booking' | null;
    id: number | null;
    name: string | null;
    /** How a confirmed driver relates to the booking. */
    attribution: 'booking' | 'handover' | 'manual' | null;
    /** The checked-out booking's driver, when their name may be shown. */
    booked_driver_id: number | null;
    booked_driver: string | null;
    booking_reference: string | null;
};

export type TripFacts = {
    id: number;
    reference: string;
    started_at: string | null;
    ended_at: string | null;
    /** Pacific/Auckland calendar day the trip started (YYYY-MM-DD). */
    local_date: string | null;
    in_progress: boolean;
    distance_km: number;
    duration_s: number;
    from: string | null;
    to: string | null;
    is_personal: boolean;
    consent_blocked: boolean;
};

export type TripListItem = TripFacts & {
    coverage_pct: number | null;
    partial: boolean;
    driving_events: number;
    overspeed_episodes: number;
    faults: number;
    driver: TripDriver;
};

export type TripPolicy = {
    speed_threshold_kph: number;
    idle_speed_kph: number;
    idle_after_minutes: number;
    max_idle_increment_minutes: number;
    coverage_gap_seconds: number;
    min_score_coverage_pct: number;
    weights: {
        braking: number;
        acceleration: number;
        other_harsh: number;
        overspeed: number;
        idle_per_minute: number;
    };
};

export type TripSummary = {
    trips: number;
    distance_km: number;
    duration_s: number;
    driving_events: number;
    personal_trips: number;
    restricted_trips: number;
    business_trips: number;
    business_distance_km: number;
    /** First and last Pacific/Auckland day with a matching trip. */
    first_day: string | null;
    last_day: string | null;
};

export type TripDriverOption = {
    id: number;
    name: string;
    trips: number;
    confirmed: number;
};

export type TripListResponse = {
    vehicle: TripVehicle;
    summary: TripSummary;
    filters: TripFilters;
    timezone: string;
    data: TripListItem[];
    trip_ids: number[];
    meta: {
        page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
    drivers: TripDriverOption[];
    unassigned_trips: number;
    recent_days: string[];
    has_trips: boolean;
    tracker_linked: boolean;
    policy: TripPolicy;
};

export type TripSummaryResponse = Pick<
    TripListResponse,
    'vehicle' | 'summary' | 'filters' | 'timezone'
>;

export type TripPoint = {
    at: string;
    lat: number;
    lng: number;
    speed_kph: number | null;
    heading: number | null;
    /** Last ignition state the tracker reported; null when never reported. */
    ignition: boolean | null;
    /** Looked-up address for the position, when one was recorded. */
    address: string | null;
};

export type TripEventKind = 'driving' | 'speed' | 'power' | 'journey';

export type TripEvent = {
    key: string;
    type: string;
    kind: TripEventKind;
    title: string;
    detail: string;
    at: string;
    /** Index into the trip's points: the last position at or before it. */
    point: number;
    peak_kph: number | null;
    seconds: number | null;
    source: 'fleet_threshold' | 'tracker_alarm' | null;
};

export type TripScoreState =
    | 'scored'
    | 'coverage'
    | 'personal'
    | 'consent'
    | 'no_samples'
    | 'in_progress'
    // An event is disputed in Driving insights' review.
    | 'disputed';

export type TripBehaviour = {
    samples: number;
    coverage_pct: number | null;
    partial: boolean;
    max_speed_kph: number | null;
    overspeed_episodes: number;
    overspeed_seconds: number | null;
    harsh: {
        braking: number;
        acceleration: number;
        cornering: number;
        unclassified: number;
        total: number;
    };
    faults: number;
    driving_events: number;
    idle_minutes: number | null;
    events_per_100km: number | null;
    score: number | null;
    score_state: TripScoreState;
};

export type TripEnd = {
    address: string | null;
    lat: number | null;
    lng: number | null;
    trigger: 'ignition' | 'movement' | 'stopped' | 'in_progress';
};

export type TripDetail = {
    vehicle: TripVehicle;
    trip: TripFacts & {
        start: TripEnd;
        end: TripEnd;
        stop_after_minutes: number;
    };
    points: TripPoint[];
    recorded_points: number;
    downsampled: boolean;
    events: TripEvent[];
    behaviour: TripBehaviour;
    driver: TripDriver & {
        confirmed_at: string | null;
        confirmed_by: string | null;
        /** Confirmations so far; sent back to detect a concurrent change. */
        version: number;
    };
    source: {
        vendors: string[];
        recorded_points: number;
        booking: {
            id: number;
            reference: string;
            checked_out_at: string | null;
            returned_at: string | null;
            odometer_out: number | null;
            odometer_in: number | null;
        } | null;
    };
    driver_candidates: Person[];
    driver_history: Array<{
        id: number;
        driver: string | null;
        source: 'booking' | 'handover' | 'manual';
        reason: string;
        confirmed_by: string | null;
        confirmed_at: string | null;
    }>;
    can: { confirm_driver: boolean };
    policy: TripPolicy;
    timezone: string;
};
