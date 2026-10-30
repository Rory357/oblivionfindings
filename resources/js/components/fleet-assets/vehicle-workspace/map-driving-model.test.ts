import { describe, expect, it } from 'vitest';
import {
    coachingRecords,
    evaluateEpisode,
    eventMatches,
    km,
    kpiTiles,
    limitBadge,
    limitError,
    limitSegments,
    overspeedExcess,
    plural,
    policyBadge,
    policyError,
    policyFormFrom,
    policyFormula,
    resolveSpeedLimit,
    reviewBadge,
    routeSummary,
    scoreCaption,
    scoreHeadline,
    scoreRingDegrees,
    scoreStateLabel,
    tripFlags,
    type LimitForm,
    type PolicyForm,
} from './map-driving-model';
import type {
    DrivingInsights,
    DrivingPolicy,
    EventReview,
    SpeedEpisode,
    SpeedLimit,
    SpeedRule,
} from './map-driving-types';
import type { VehicleReminder } from './types';

const policy = (patch: Partial<DrivingPolicy> = {}): DrivingPolicy => ({
    version: 1,
    source: 'fleet_settings',
    weights: {
        braking: 5,
        acceleration: 3,
        other_harsh: 3,
        overspeed: 4,
        idle_per_minute: 0.5,
    },
    min_score_coverage_pct: 90,
    min_trips: null,
    min_distance_km: null,
    speed_threshold_kph: 100,
    coverage_gap_seconds: 120,
    published_at: null,
    published_by: null,
    reason: null,
    history: [],
    ...patch,
});

const insights = (
    patch: {
        score?: Partial<DrivingInsights['score']>;
        summary?: Partial<DrivingInsights['summary']>;
        policy?: Partial<DrivingPolicy>;
    } = {},
): DrivingInsights => ({
    as_of: '2026-09-21T20:30:00Z',
    timezone: 'Pacific/Auckland',
    vehicle: { id: 7, name: 'Hiace', registration_number: 'ABC123' },
    period: { key: 'week', from: '2026-09-16', to: '2026-09-22' },
    driver: 'all',
    drivers: [],
    unassigned_trips: 0,
    has_data: true,
    policy: policy(patch.policy),
    score: {
        kind: 'vehicle',
        value: 88,
        eligible_trips: 3,
        eligible_km: 42.5,
        personal_trips: 0,
        enough: true,
        ...patch.score,
    },
    summary: {
        trips: 4,
        scored_trips: 3,
        partial_trips: 1,
        distance_km: 51.2,
        overspeed_episodes: 2,
        overspeed_seconds: 34,
        idle_minutes: 12.5,
        idle_pct: 8,
        events_per_100km: 3.92,
        confirmed_trips: 1,
        cornering_events: 0,
        withheld: { personal: 0, consent: 0 },
        ...patch.summary,
    },
    days: [],
    trips: [],
    events: [],
    overspeed: [],
    manual_limits: 0,
    can: {
        review: true,
        manage_policy: true,
        route: true,
        coach: true,
        confirm_driver: true,
        view_alerts: true,
        manage_limits: true,
        upload_evidence: true,
    },
});

const review = (outcome: EventReview['outcome']): EventReview => ({
    id: 1,
    outcome,
    reason: 'Checked against the trail',
    review_owner: 'Aroha Smith',
    recorded_by: 'Ben Jones',
    at: '2026-09-21T20:00:00Z',
    policy_version: 1,
    sequence: 1,
});

const limit = (patch: Partial<SpeedLimit> = {}): SpeedLimit => ({
    id: 3,
    reference: 'SL-000003',
    road_segment: 'Great South Road',
    direction: 'Northbound',
    limit_kph: 50,
    effective_from: '2026-09-01T00:00:00Z',
    expires_at: '2026-10-01T00:00:00Z',
    expired: false,
    reason: 'Council notice',
    status: 'approved',
    lock_version: 1,
    proposed_by: 'Aroha Smith',
    proposed_by_me: false,
    reviewed_by: 'Ben Jones',
    files: [],
    history: [],
    ...patch,
});

const episode = (patch: Partial<SpeedEpisode> = {}): SpeedEpisode => ({
    source_key: 'overspeed:42:overspeed-1',
    trip_id: 42,
    trip_reference: 'Trip #42',
    local_date: '2026-09-21',
    event_key: 'overspeed-1',
    at: '2026-09-20T21:15:00Z',
    peak_kph: 62,
    seconds: 20,
    source: 'fleet_threshold',
    ...patch,
});

const rule: SpeedRule = {
    threshold_kph: 100,
    tolerance_kph: 5,
    min_seconds: 10,
    source: 'plan',
    plan_version: 2,
};

let reminderId = 0;
const reminder = (
    title: string,
    state: VehicleReminder['state'],
): VehicleReminder => ({
    id: ++reminderId,
    title,
    action_text: null,
    source: { type: null, id: null, label: 'Vehicle' },
    due_at: '2026-09-25T00:00:00Z',
    repeat_months: 0,
    owner: null,
    backup: null,
    state,
    lock_version: 1,
    events: [],
});

describe('number helpers', () => {
    it('formats kilometres and counts in NZ English', () => {
        expect(km(1234.56)).toBe('1,234.6 km');
        expect(plural(1, 'trip')).toBe('1 trip');
        expect(plural(2, 'trip')).toBe('2 trips');
        expect(plural(3, 'box', 'boxes')).toBe('3 boxes');
    });

    it('filters events by type', () => {
        expect(eventMatches({ type: 'harsh-braking' }, 'all')).toBe(true);
        expect(eventMatches({ type: 'harsh-braking' }, 'harsh-braking')).toBe(
            true,
        );
        expect(eventMatches({ type: 'harsh-braking' }, 'overspeed')).toBe(
            false,
        );
    });
});

describe('score ring and headline', () => {
    it('sweeps 0–100 points around the ring and clamps', () => {
        expect(scoreRingDegrees(null)).toBe('0deg');
        expect(scoreRingDegrees(50)).toBe('180deg');
        expect(scoreRingDegrees(120)).toBe('360deg');
        expect(scoreRingDegrees(-4)).toBe('0deg');
    });

    it('explains why a score is missing', () => {
        expect(scoreHeadline(insights())).toBe('Know what shaped the score');
        expect(scoreHeadline(insights({ score: { value: null } }))).toBe(
            'More coverage needed',
        );
        expect(
            scoreHeadline(insights({ score: { kind: 'driver', value: null } })),
        ).toBe('Identity & sample needed');
    });

    it('captions a vehicle score with eligible trips and distance', () => {
        expect(scoreCaption(insights())).toBe(
            '3 of 4 trips scored · 42.5 km eligible. Partial trips excluded.',
        );
    });

    it('needs a published policy before scoring a person', () => {
        expect(
            scoreCaption(
                insights({ score: { kind: 'driver', personal_trips: 1 } }),
            ),
        ).toBe(
            "1 reviewed, confirmed trip. A person's score needs a published scoring policy with minimum trips and distance; partial or unconfirmed trips are excluded.",
        );
        expect(
            scoreCaption(
                insights({
                    score: { kind: 'driver', personal_trips: 2 },
                    policy: {
                        source: 'published',
                        version: 2,
                        min_trips: 5,
                        min_distance_km: 50,
                    },
                }),
            ),
        ).toBe(
            '2 reviewed, confirmed trips. Minimum 5 trips / 50 km; partial or unconfirmed trips excluded.',
        );
    });
});

describe('scoring policy', () => {
    it('labels where the policy came from', () => {
        expect(policyBadge(policy())).toBe('Score policy v1 · fleet settings');
        expect(policyBadge(policy({ source: 'published', version: 3 }))).toBe(
            'Score policy v3',
        );
    });

    it('writes the deduction formula from the weights', () => {
        expect(policyFormula(policy())).toBe(
            '100 − braking×5 − acceleration×3 − overspeed episodes×4 − round(idle minutes×0.5)',
        );
        expect(
            policyFormula(
                policy({
                    weights: {
                        braking: 2.25,
                        acceleration: 1,
                        other_harsh: 1,
                        overspeed: 6,
                        idle_per_minute: 0.25,
                    },
                }),
            ),
        ).toBe(
            '100 − braking×2.25 − acceleration×1 − overspeed episodes×6 − round(idle minutes×0.25)',
        );
    });

    it('prefills the form and leaves unpublished minimums blank', () => {
        expect(policyFormFrom(policy())).toEqual({
            braking: '5',
            acceleration: '3',
            overspeed: '4',
            idle: '0.5',
            coverage: '90',
            trips: '',
            distance: '',
        });
        expect(
            policyFormFrom(policy({ min_trips: 5, min_distance_km: 50 })),
        ).toMatchObject({ trips: '5', distance: '50' });
    });

    it('mirrors the server validation', () => {
        const valid: PolicyForm = {
            braking: '5',
            acceleration: '3',
            overspeed: '4',
            idle: '0.5',
            coverage: '90',
            trips: '5',
            distance: '50',
        };
        expect(policyError(valid)).toBe('');
        expect(policyError({ ...valid, braking: '-1' })).not.toBe('');
        expect(policyError({ ...valid, overspeed: '101' })).not.toBe('');
        expect(policyError({ ...valid, idle: ' ' })).not.toBe('');
        expect(policyError({ ...valid, coverage: '49' })).not.toBe('');
        expect(policyError({ ...valid, coverage: '90.5' })).not.toBe('');
        expect(policyError({ ...valid, trips: '' })).not.toBe('');
        expect(policyError({ ...valid, trips: '0' })).not.toBe('');
        expect(policyError({ ...valid, distance: '0.5' })).not.toBe('');
    });
});

describe('KPI tiles', () => {
    it('summarises distance, overspeed, idle and event rate', () => {
        const tiles = kpiTiles(insights());
        expect(tiles.map((tile) => tile.key)).toEqual([
            'distance',
            'overspeed',
            'idle',
            'rate',
        ]);
        expect(tiles[0]).toMatchObject({
            value: '51.2 km',
            hint: '4 trips · estimated',
        });
        expect(tiles[1]).toMatchObject({
            value: '2',
            hint: '34s over threshold',
        });
        expect(tiles[2]).toMatchObject({
            value: '12.5 min',
            hint: '8% of recorded time',
        });
        expect(tiles[3]).toMatchObject({
            value: '3.9',
            hint: '42.5 km eligible',
        });
    });

    it('is honest about missing measurements and small samples', () => {
        const tiles = kpiTiles(
            insights({
                summary: {
                    overspeed_seconds: null,
                    idle_minutes: null,
                    idle_pct: null,
                    events_per_100km: null,
                },
                policy: {
                    source: 'published',
                    min_trips: 5,
                    min_distance_km: 50,
                },
            }),
        );
        expect(tiles[1].hint).toBe('Duration not fully recorded');
        expect(tiles[2]).toMatchObject({
            value: 'Not reported',
            hint: 'Ignition not reported',
        });
        expect(tiles[3]).toMatchObject({
            value: '—',
            hint: '42.5 km eligible · small sample',
        });
    });
});

describe('reviews and trip rows', () => {
    it('badges each review outcome', () => {
        expect(reviewBadge(null)).toEqual({
            label: 'Unreviewed',
            variant: 'warning',
        });
        expect(reviewBadge(review('confirmed')).variant).toBe('success');
        expect(reviewBadge(review('dismissed')).label).toBe('Dismissed');
        expect(reviewBadge(review('disputed'))).toEqual({
            label: 'Disputed',
            variant: 'warning',
        });
    });

    it('explains withheld scores', () => {
        expect(scoreStateLabel('scored')).toBe('Scored');
        expect(scoreStateLabel('disputed')).toBe(
            'Withheld while an event is disputed',
        );
        expect(scoreStateLabel('coverage')).toBe(
            'Partial trail · below minimum coverage',
        );
        expect(scoreStateLabel('personal')).toBe('Not scored');
    });

    it('flags driving events, then overspeed or faults', () => {
        expect(
            tripFlags({ driving_events: 1, overspeed_episodes: 2, faults: 3 }),
        ).toEqual(['1 driving event', '2 overspeed episodes']);
        expect(
            tripFlags({ driving_events: 0, overspeed_episodes: 0, faults: 1 }),
        ).toEqual(['0 driving events', '1 vehicle fault']);
    });

    it('rounds the excess over the threshold', () => {
        expect(overspeedExcess(112.4, 100)).toBe(12);
        expect(overspeedExcess(95, 100)).toBe(0);
        expect(overspeedExcess(null, 100)).toBeNull();
    });

    it('summarises where a routed event got to', () => {
        expect(routeSummary(null)).toBeNull();
        expect(
            routeSummary({
                signal_id: 1,
                delivery: 'unroutable',
                response: null,
            }),
        ).toBe('Sent · Control Room delivery failed');
        expect(
            routeSummary({ signal_id: 1, delivery: 'pending', response: null }),
        ).toBe('Sent to Control Room · awaiting receipt');
        expect(
            routeSummary({
                signal_id: 1,
                delivery: 'sent',
                response: { id: 9, reference: 'CRA-000009', status: 'open' },
            }),
        ).toBe('Sent to Control Room · CRA-000009');
    });
});

describe('coaching records', () => {
    it('reads trip coaching follow-ups from vehicle reminders', () => {
        const records = coachingRecords([
            reminder('Coaching review · Trip #42 · harsh braking', 'scheduled'),
            reminder('Coaching review · Trip #421', 'acknowledged'),
            reminder('Coaching review · Trip #7', 'completed'),
            reminder('WOF due', 'scheduled'),
        ]);
        expect(records.map((record) => record.tripId)).toEqual([42, 421, 7]);
        expect(records[0]).toMatchObject({
            tripReference: 'Trip #42',
            status: { label: 'Assigned', variant: 'info' },
            next: { action: 'acknowledge', label: 'Acknowledge' },
        });
        expect(records[1].next).toEqual({
            action: 'complete',
            label: 'Record outcome',
        });
        expect(records[2]).toMatchObject({
            status: { label: 'Completed', variant: 'success' },
            next: null,
        });
    });
});

describe('speed limits', () => {
    it('applies one approved, current limit for the road and direction', () => {
        const resolved = resolveSpeedLimit(
            [limit()],
            '2026-09-20T21:15:00Z',
            '  great   south road ',
            'northbound',
            100,
        );
        expect(resolved).toMatchObject({
            road: 50,
            limit: 50,
            source: 'Approved manual limit',
            record: 'SL-000003',
            limitId: 3,
        });
    });

    it('never raises the fleet threshold', () => {
        expect(
            resolveSpeedLimit(
                [limit({ limit_kph: 110 })],
                '2026-09-20T21:15:00Z',
                'Great South Road',
                'Northbound',
                100,
            ),
        ).toMatchObject({ road: 110, limit: 100 });
    });

    it('ignores pending, other-direction and out-of-window limits', () => {
        const unknown = (limits: SpeedLimit[], at = '2026-09-20T21:15:00Z') =>
            resolveSpeedLimit(
                limits,
                at,
                'Great South Road',
                'Northbound',
                100,
            );
        expect(unknown([limit({ status: 'pending' })])).toMatchObject({
            road: null,
            limit: 100,
            confidence: 'Road limit unknown',
        });
        expect(unknown([limit({ direction: 'Southbound' })]).road).toBeNull();
        expect(unknown([limit()], '2026-10-01T00:00:00Z').road).toBeNull();
        expect(unknown([limit()], '2026-08-31T23:59:59Z').road).toBeNull();
    });

    it('refuses to choose between conflicting approved limits', () => {
        const resolved = resolveSpeedLimit(
            [limit(), limit({ id: 4, reference: 'SL-000004', limit_kph: 60 })],
            '2026-09-20T21:15:00Z',
            'Great South Road',
            'Northbound',
            100,
        );
        expect(resolved).toMatchObject({
            road: null,
            limit: 100,
            record: 'Conflict',
            limitId: null,
        });
    });

    it('qualifies an episode above limit plus tolerance for long enough', () => {
        const at = (patch: Partial<SpeedEpisode>) =>
            evaluateEpisode(
                episode(patch),
                [limit()],
                rule,
                'Great South Road',
                'Northbound',
            );
        expect(at({})).toMatchObject({ trigger: 55, qualifies: true });
        expect(at({ peak_kph: 55 }).qualifies).toBe(false);
        expect(at({ seconds: 9 }).qualifies).toBe(false);
        expect(at({ seconds: null }).qualifies).toBe(false);
        expect(at({ peak_kph: null }).qualifies).toBe(false);
        expect(
            evaluateEpisode(episode(), [], rule, 'Unmapped road', 'Northbound'),
        ).toMatchObject({ trigger: 105, qualifies: false });
    });

    it('lists recorded road segments once, alphabetically', () => {
        expect(
            limitSegments([
                limit({ road_segment: 'Great South Road' }),
                limit({ road_segment: 'great south road' }),
                limit({ road_segment: 'Dominion Road' }),
            ]),
        ).toEqual(['Dominion Road', 'Great South Road']);
    });

    it('badges limit status', () => {
        expect(limitBadge(limit()).label).toBe('Approved');
        expect(limitBadge(limit({ expired: true })).label).toBe('Expired');
        expect(limitBadge(limit({ status: 'pending' })).label).toBe(
            'Pending review',
        );
        expect(limitBadge(limit({ status: 'retired' })).label).toBe('Retired');
    });

    it('validates a manual limit before it is proposed', () => {
        const valid: LimitForm = {
            road_segment: 'Great South Road',
            direction: 'Northbound',
            limit_kph: '50',
            effective_from_local: '2026-09-22T09:00',
            expires_at_local: '2026-12-22T09:00',
        };
        expect(limitError(valid)).toBe('');
        expect(limitError({ ...valid, road_segment: ' ' })).toBe(
            'Record the road or segment.',
        );
        expect(limitError({ ...valid, direction: '' })).toBe(
            'Choose the direction.',
        );
        expect(limitError({ ...valid, limit_kph: '4' })).toBe(
            'Choose a limit between 5 and 150 km/h.',
        );
        expect(limitError({ ...valid, limit_kph: '52.5' })).toBe(
            'Choose a limit between 5 and 150 km/h.',
        );
        expect(limitError({ ...valid, expires_at_local: '' })).toBe(
            'Choose when the limit starts and expires.',
        );
        expect(
            limitError({ ...valid, expires_at_local: '2026-09-22T09:00' }),
        ).toBe('Choose an expiry after the start.');
    });
});
