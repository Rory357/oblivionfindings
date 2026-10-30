/** Server DTOs for Map › Driving insights (VehicleDrivingController). */
import type { TripDriver } from './trip-types';
import type { Person } from './types';

export type DrivingPeriod = 'week' | 'today';

export type DrivingWorkspaceKey = 'analytics' | 'reviews' | 'limits';

export type DrivingPolicyVersion = {
    version: number;
    braking: number;
    acceleration: number;
    overspeed: number;
    idle: number;
    min_coverage_pct: number;
    min_trips: number;
    min_distance_km: number;
    reason: string;
    published_by: string | null;
    published_at: string | null;
};

/** The current scoring policy: fleet settings (v1) or a published version. */
export type DrivingPolicy = {
    version: number;
    source: 'published' | 'fleet_settings';
    weights: {
        braking: number;
        acceleration: number;
        other_harsh: number;
        overspeed: number;
        idle_per_minute: number;
    };
    min_score_coverage_pct: number;
    /** Only a published version sets a person's minimums. */
    min_trips: number | null;
    min_distance_km: number | null;
    speed_threshold_kph: number;
    coverage_gap_seconds: number;
    published_at: string | null;
    published_by: string | null;
    reason: string | null;
    history: DrivingPolicyVersion[];
};

export type EventReviewOutcome = 'confirmed' | 'dismissed' | 'disputed';

export type EventReview = {
    id: number;
    outcome: EventReviewOutcome;
    reason: string;
    review_owner: string | null;
    recorded_by: string | null;
    at: string | null;
    policy_version: number;
    sequence: number;
};

export type DrivingEvent = {
    /** Stable identity of the recorded event within its trip. */
    key: string;
    type: string;
    kind: 'driving' | 'speed' | 'power' | 'journey' | string;
    title: string;
    detail: string;
    at: string;
    peak_kph: number | null;
    seconds: number | null;
    source: 'fleet_threshold' | 'tracker_alarm' | null;
    review: EventReview | null;
    /** Reviews so far; sent back to detect a concurrent review. */
    version: number;
};

export type ScoreState =
    | 'scored'
    | 'disputed'
    | 'coverage'
    | 'personal'
    | 'consent'
    | 'no_samples'
    | 'in_progress';

export type DrivingTrip = {
    id: number;
    reference: string;
    started_at: string | null;
    ended_at: string | null;
    local_date: string | null;
    in_progress: boolean;
    from: string | null;
    to: string | null;
    distance_km: number;
    duration_s: number;
    coverage_pct: number | null;
    partial: boolean;
    /** After review; null when withheld (see score_state). */
    score: number | null;
    score_state: ScoreState;
    original_score: number | null;
    driving_events: number;
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
    idle_minutes: number | null;
    driver: TripDriver;
    all_reviewed: boolean;
};

export type RouteState = {
    signal_id: number;
    /** pending | processing | sent | failed | dead_letter | unroutable */
    delivery: string;
    /** Shown only to people who may read Control Room alerts. */
    response: { id: number; reference: string; status: string } | null;
};

export type DrivingEventRow = DrivingEvent & {
    trip_id: number;
    trip_reference: string;
    local_date: string | null;
    driver: TripDriver;
};

export type OverspeedEpisode = DrivingEventRow & {
    source_key: string;
    route: RouteState | null;
};

export type DrivingCan = {
    review: boolean;
    manage_policy: boolean;
    route: boolean;
    coach: boolean;
    confirm_driver: boolean;
    view_alerts: boolean;
    manage_limits: boolean;
    upload_evidence: boolean;
};

export type DrivingInsights = {
    as_of: string;
    timezone: string;
    vehicle: { id: number; name: string; registration_number: string | null };
    period: { key: DrivingPeriod; from: string; to: string };
    driver: string;
    drivers: Array<{
        id: number;
        name: string;
        trips: number;
        confirmed: number;
    }>;
    unassigned_trips: number;
    has_data: boolean;
    policy: DrivingPolicy;
    score: {
        kind: 'vehicle' | 'driver';
        value: number | null;
        eligible_trips: number;
        eligible_km: number;
        personal_trips: number;
        enough: boolean;
    };
    summary: {
        trips: number;
        scored_trips: number;
        partial_trips: number;
        distance_km: number;
        overspeed_episodes: number;
        overspeed_seconds: number | null;
        idle_minutes: number | null;
        idle_pct: number | null;
        events_per_100km: number | null;
        confirmed_trips: number;
        cornering_events: number;
        withheld: { personal: number; consent: number };
    };
    days: Array<{ day: string; score: number | null; trips: number }>;
    trips: DrivingTrip[];
    events: DrivingEventRow[];
    overspeed: OverspeedEpisode[];
    manual_limits: number;
    can: DrivingCan;
};

export type TripReviewEvent = DrivingEvent & {
    driver_at_event: string | null;
    history: EventReview[];
};

export type TripReview = DrivingTrip & { events: TripReviewEvent[] };

export type DrivingReviews = {
    as_of: string;
    timezone: string;
    trips: Array<{
        id: number;
        reference: string;
        started_at: string | null;
        local_date: string | null;
        from: string | null;
        to: string | null;
        driver: TripDriver | null;
    }>;
    trip: TripReview | null;
    policy: DrivingPolicy;
    people: Person[];
    can: DrivingCan;
};

export type SpeedLimitStatus = 'pending' | 'approved' | 'retired';

export type SpeedLimit = {
    id: number;
    reference: string;
    road_segment: string;
    direction: string;
    limit_kph: number;
    effective_from: string | null;
    expires_at: string | null;
    expired: boolean;
    reason: string;
    status: SpeedLimitStatus;
    lock_version: number;
    proposed_by: string | null;
    proposed_by_me: boolean;
    reviewed_by: string | null;
    files: Array<{
        id: number;
        name: string;
        state: string | null;
        url: string | null;
    }>;
    history: Array<{
        id: number;
        action: 'proposed' | 'approved' | 'retired';
        actor: string | null;
        note: string;
        at: string | null;
    }>;
};

export type SpeedEpisode = {
    source_key: string;
    trip_id: number;
    trip_reference: string;
    local_date: string | null;
    event_key: string;
    at: string;
    peak_kph: number | null;
    seconds: number | null;
    source: 'fleet_threshold' | 'tracker_alarm' | null;
};

/** The overspeed rule an evaluation applies. */
export type SpeedRule = {
    threshold_kph: number;
    tolerance_kph: number;
    min_seconds: number;
    source: 'plan' | 'fleet_setting';
    plan_version: number | null;
};

export type SpeedLimits = {
    as_of: string;
    timezone: string;
    limits: SpeedLimit[];
    episodes: SpeedEpisode[];
    rule: SpeedRule;
    directions: string[];
    can: { manage: boolean; upload_evidence: boolean; route: boolean };
};
