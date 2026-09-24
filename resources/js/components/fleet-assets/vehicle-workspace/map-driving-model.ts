import type { StatusVariant } from '@/components/ui/status-badge';
import type {
    DrivingInsights,
    DrivingPolicy,
    DrivingWorkspaceKey,
    EventReview,
    RouteState,
    ScoreState,
    SpeedEpisode,
    SpeedLimit,
    SpeedRule,
} from './map-driving-types';
import type { VehicleReminder } from './types';

/** The approved design's three driving workspaces. */
export const DRIVING_WORKSPACES: Array<{
    key: DrivingWorkspaceKey;
    label: string;
}> = [
    { key: 'analytics', label: 'Analytics' },
    { key: 'reviews', label: 'Reviews & coaching' },
    { key: 'limits', label: 'Speed limits' },
];

export const PERIOD_OPTIONS = [
    { value: 'week', label: 'Last 7 days' },
    { value: 'today', label: 'Today' },
] as const;

export const EVENT_TYPE_FILTERS = [
    { value: 'all', label: 'All driving events' },
    { value: 'overspeed', label: 'Overspeed threshold' },
    { value: 'harsh-braking', label: 'Harsh braking' },
    { value: 'harsh-acceleration', label: 'Harsh acceleration' },
] as const;

export type EventTypeFilter = (typeof EVENT_TYPE_FILTERS)[number]['value'];

export const REVIEW_OUTCOMES: Array<{
    value: EventReview['outcome'];
    label: string;
    detail: string;
}> = [
    {
        value: 'confirmed',
        label: 'Confirmed',
        detail: 'The event happened as recorded; it keeps its deduction.',
    },
    {
        value: 'dismissed',
        label: 'Dismissed',
        detail: 'A false event; it no longer deducts points.',
    },
    {
        value: 'disputed',
        label: 'Disputed',
        detail: 'Unresolved; the trip score is withheld until resolved.',
    },
];

export const LIMIT_PRESETS = ['20', '30', '40', '50', '60', '80', '100'];

const number = (value: number, digits = 1) =>
    value.toLocaleString('en-NZ', { maximumFractionDigits: digits });

export const km = (value: number) => `${number(value)} km`;

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count.toLocaleString('en-NZ')} ${count === 1 ? one : many}`;
}

export function eventMatches(
    event: { type: string },
    filter: EventTypeFilter,
): boolean {
    return filter === 'all' || event.type === filter;
}

/** The score ring's conic sweep: 0–100 points around the circle. */
export function scoreRingDegrees(score: number | null): string {
    const value = score === null ? 0 : Math.max(0, Math.min(100, score));
    return `${value * 3.6}deg`;
}

export function scoreHeadline(insights: DrivingInsights): string {
    if (insights.score.kind === 'driver' && insights.score.value === null)
        return 'Identity & sample needed';
    if (insights.score.value === null) return 'More coverage needed';
    return 'Know what shaped the score';
}

export function scoreCaption(insights: DrivingInsights): string {
    const { score, summary, policy } = insights;
    if (score.kind === 'vehicle')
        return `${score.eligible_trips} of ${summary.trips} trips scored · ${km(score.eligible_km)} eligible. Partial trips excluded.`;
    if (policy.min_trips === null || policy.min_distance_km === null)
        return `${plural(score.personal_trips, 'reviewed, confirmed trip')}. A person's score needs a published scoring policy with minimum trips and distance; partial or unconfirmed trips are excluded.`;
    return `${plural(score.personal_trips, 'reviewed, confirmed trip')}. Minimum ${policy.min_trips} trips / ${number(policy.min_distance_km)} km; partial or unconfirmed trips excluded.`;
}

export function policyBadge(policy: DrivingPolicy): string {
    return policy.source === 'published'
        ? `Score policy v${policy.version}`
        : `Score policy v${policy.version} · fleet settings`;
}

/** "100 − braking×5 − acceleration×3 − overspeed episodes×4 − round(idle minutes×0.5)" */
export function policyFormula(policy: DrivingPolicy): string {
    const w = policy.weights;
    return `100 − braking×${number(w.braking, 2)} − acceleration×${number(w.acceleration, 2)} − overspeed episodes×${number(w.overspeed, 2)} − round(idle minutes×${number(w.idle_per_minute, 2)})`;
}

export type KpiTile = {
    key: 'distance' | 'overspeed' | 'idle' | 'rate';
    label: string;
    value: string;
    hint: string;
};

export function kpiTiles(insights: DrivingInsights): KpiTile[] {
    const { summary, score, policy } = insights;
    const small =
        policy.min_distance_km !== null &&
        score.eligible_km < policy.min_distance_km;
    return [
        {
            key: 'distance',
            label: 'Recorded distance',
            value: km(summary.distance_km),
            hint: `${plural(summary.trips, 'trip')} · estimated`,
        },
        {
            key: 'overspeed',
            label: 'Overspeed episodes',
            value: String(summary.overspeed_episodes),
            hint:
                summary.overspeed_seconds === null
                    ? 'Duration not fully recorded'
                    : `${summary.overspeed_seconds}s over threshold`,
        },
        {
            key: 'idle',
            label: 'Idle time',
            value:
                summary.idle_minutes === null
                    ? 'Not reported'
                    : `${number(summary.idle_minutes)} min`,
            hint:
                summary.idle_pct === null
                    ? 'Ignition not reported'
                    : `${summary.idle_pct}% of recorded time`,
        },
        {
            key: 'rate',
            label: 'Driving events / 100 km',
            value:
                summary.events_per_100km === null
                    ? '—'
                    : summary.events_per_100km.toFixed(1),
            hint: `${km(score.eligible_km)} eligible${small ? ' · small sample' : ''}`,
        },
    ];
}

export function reviewBadge(review: EventReview | null): {
    label: string;
    variant: StatusVariant;
} {
    switch (review?.outcome) {
        case 'confirmed':
            return { label: 'Confirmed', variant: 'success' };
        case 'dismissed':
            return { label: 'Dismissed', variant: 'neutral' };
        case 'disputed':
            return { label: 'Disputed', variant: 'warning' };
        default:
            return { label: 'Unreviewed', variant: 'warning' };
    }
}

export function scoreStateLabel(state: ScoreState): string {
    switch (state) {
        case 'scored':
            return 'Scored';
        case 'disputed':
            return 'Withheld while an event is disputed';
        case 'coverage':
            return 'Partial trail · below minimum coverage';
        case 'in_progress':
            return 'Trip in progress';
        case 'no_samples':
            return 'Too few recorded samples';
        default:
            return 'Not scored';
    }
}

/** The design's per-trip flags: driving events, then overspeed or faults. */
export function tripFlags(trip: {
    driving_events: number;
    overspeed_episodes: number;
    faults: number;
}): [string, string] {
    return [
        plural(trip.driving_events, 'driving event'),
        trip.overspeed_episodes
            ? plural(trip.overspeed_episodes, 'overspeed episode')
            : plural(trip.faults, 'vehicle fault'),
    ];
}

export function overspeedExcess(
    peak: number | null,
    threshold: number,
): number | null {
    return peak === null ? null : Math.max(0, Math.round(peak - threshold));
}

export function routeSummary(route: RouteState | null): string | null {
    if (!route) return null;
    if (['failed', 'dead_letter', 'unroutable'].includes(route.delivery))
        return 'Sent · Control Room delivery failed';
    if (route.response)
        return `Sent to Control Room · ${route.response.reference}`;
    return 'Sent to Control Room · awaiting receipt';
}

export type CoachingRecord = {
    reminder: VehicleReminder;
    tripId: number;
    tripReference: string;
    status: { label: string; variant: StatusVariant };
    next: { action: 'acknowledge' | 'complete'; label: string } | null;
};

/** Trip coaching follow-ups are vehicle reminders titled "Coaching review · Trip #N". */
export const COACHING_TITLE = /^Coaching review · Trip #(\d+)\b/;

export function coachingRecords(
    reminders: VehicleReminder[],
): CoachingRecord[] {
    return reminders.flatMap((reminder) => {
        const match = COACHING_TITLE.exec(reminder.title);
        if (!match) return [];
        const status =
            reminder.state === 'completed'
                ? { label: 'Completed', variant: 'success' as const }
                : reminder.state === 'acknowledged'
                  ? { label: 'Acknowledged', variant: 'info' as const }
                  : reminder.state === 'paused'
                    ? { label: 'Paused', variant: 'neutral' as const }
                    : { label: 'Assigned', variant: 'info' as const };
        const next =
            reminder.state === 'scheduled'
                ? { action: 'acknowledge' as const, label: 'Acknowledge' }
                : reminder.state === 'acknowledged'
                  ? { action: 'complete' as const, label: 'Record outcome' }
                  : null;
        return [
            {
                reminder,
                tripId: Number(match[1]),
                tripReference: `Trip #${match[1]}`,
                status,
                next,
            },
        ];
    });
}

const normalise = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLowerCase();

export type ResolvedLimit = {
    road: number | null;
    limit: number;
    source: string;
    confidence: string;
    record: string;
    limitId: number | null;
};

/**
 * Which limit applies (mirrors VehicleSpeedLimitService::resolve): exactly
 * one approved, unexpired manual limit for the road and direction at that
 * time, otherwise the road limit is unknown and the fleet threshold applies.
 */
export function resolveSpeedLimit(
    limits: SpeedLimit[],
    at: string,
    segment: string,
    direction: string,
    fleet: number,
): ResolvedLimit {
    const moment = Date.parse(at);
    const matches = limits.filter(
        (limit) =>
            limit.status === 'approved' &&
            normalise(limit.road_segment) === normalise(segment) &&
            normalise(limit.direction) === normalise(direction) &&
            !!limit.effective_from &&
            !!limit.expires_at &&
            Date.parse(limit.effective_from) <= moment &&
            moment < Date.parse(limit.expires_at),
    );
    if (matches.length > 1)
        return {
            road: null,
            limit: fleet,
            source: 'Conflicting manual records · fleet threshold only',
            confidence: 'Needs review',
            record: 'Conflict',
            limitId: null,
        };
    const manual = matches[0];
    if (manual)
        return {
            road: manual.limit_kph,
            limit: Math.min(manual.limit_kph, fleet),
            source: 'Approved manual limit',
            confidence: 'Evidence reviewed',
            record: manual.reference,
            limitId: manual.id,
        };
    return {
        road: null,
        limit: fleet,
        source: 'No approved manual limit for this road, direction and time · fleet threshold only',
        confidence: 'Road limit unknown',
        record: 'No eligible road-limit source',
        limitId: null,
    };
}

export type Evaluation = ResolvedLimit & {
    trigger: number;
    qualifies: boolean;
};

export function evaluateEpisode(
    episode: SpeedEpisode,
    limits: SpeedLimit[],
    rule: SpeedRule,
    segment: string,
    direction: string,
): Evaluation {
    const resolved = resolveSpeedLimit(
        limits,
        episode.at,
        segment,
        direction,
        rule.threshold_kph,
    );
    const trigger = resolved.limit + rule.tolerance_kph;
    const qualifies =
        episode.peak_kph !== null &&
        episode.peak_kph > trigger &&
        episode.seconds !== null &&
        episode.seconds >= rule.min_seconds;
    return { ...resolved, trigger, qualifies };
}

/** Road segments already recorded, for the evaluator's road choice. */
export function limitSegments(limits: SpeedLimit[]): string[] {
    const seen = new Map<string, string>();
    limits.forEach((limit) => {
        const key = normalise(limit.road_segment);
        if (!seen.has(key)) seen.set(key, limit.road_segment);
    });
    return [...seen.values()].sort((a, b) => a.localeCompare(b));
}

export function limitBadge(limit: SpeedLimit): {
    label: string;
    variant: StatusVariant;
} {
    if (limit.status === 'approved' && limit.expired)
        return { label: 'Expired', variant: 'neutral' };
    if (limit.status === 'approved')
        return { label: 'Approved', variant: 'success' };
    if (limit.status === 'pending')
        return { label: 'Pending review', variant: 'warning' };
    return { label: 'Retired', variant: 'neutral' };
}

export type PolicyForm = {
    braking: string;
    acceleration: string;
    overspeed: string;
    idle: string;
    coverage: string;
    trips: string;
    distance: string;
};

export function policyFormFrom(policy: DrivingPolicy): PolicyForm {
    return {
        braking: String(policy.weights.braking),
        acceleration: String(policy.weights.acceleration),
        overspeed: String(policy.weights.overspeed),
        idle: String(policy.weights.idle_per_minute),
        coverage: String(policy.min_score_coverage_pct),
        trips: policy.min_trips === null ? '' : String(policy.min_trips),
        distance:
            policy.min_distance_km === null
                ? ''
                : String(policy.min_distance_km),
    };
}

/** Mirrors the server rules: non-negative weights, coverage 50–100%, positive minimums. */
export function policyError(form: PolicyForm): string {
    const weights = [
        form.braking,
        form.acceleration,
        form.overspeed,
        form.idle,
    ];
    const bad =
        weights.some(
            (value) =>
                value.trim() === '' ||
                !Number.isFinite(Number(value)) ||
                Number(value) < 0 ||
                Number(value) > 100,
        ) ||
        !/^\d+$/.test(form.coverage.trim()) ||
        Number(form.coverage) < 50 ||
        Number(form.coverage) > 100 ||
        !/^\d+$/.test(form.trips.trim()) ||
        Number(form.trips) < 1 ||
        form.distance.trim() === '' ||
        !Number.isFinite(Number(form.distance)) ||
        Number(form.distance) < 1;
    return bad
        ? 'Use non-negative weights up to 100, coverage 50–100%, and positive sample minimums.'
        : '';
}

export type LimitForm = {
    road_segment: string;
    direction: string;
    limit_kph: string;
    effective_from_local: string;
    expires_at_local: string;
};

const LOCAL = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

export function limitError(form: LimitForm): string {
    const limit = Number(form.limit_kph);
    if (!form.road_segment.trim()) return 'Record the road or segment.';
    if (!form.direction) return 'Choose the direction.';
    if (!/^\d+$/.test(form.limit_kph) || limit < 5 || limit > 150)
        return 'Choose a limit between 5 and 150 km/h.';
    if (
        !LOCAL.test(form.effective_from_local) ||
        !LOCAL.test(form.expires_at_local)
    )
        return 'Choose when the limit starts and expires.';
    if (form.expires_at_local <= form.effective_from_local)
        return 'Choose an expiry after the start.';
    return '';
}
