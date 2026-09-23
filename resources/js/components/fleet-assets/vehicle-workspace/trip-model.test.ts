import { describe, expect, it } from 'vitest';
import {
    dayOptions,
    DEFAULT_TRIP_FILTERS,
    dispositionFilename,
    driverCaption,
    driverName,
    endpointCaption,
    filterRange,
    playbackDelay,
    rangeInvalid,
    scoreCaption,
    scoreHeadline,
    speedChart,
    timelineMatches,
    tripBadge,
    tripQuery,
} from './trip-model';
import type {
    TripBehaviour,
    TripDriver,
    TripEvent,
    TripListItem,
    TripPoint,
    TripPolicy,
} from './trip-types';

const policy: TripPolicy = {
    speed_threshold_kph: 100,
    idle_speed_kph: 3,
    idle_after_minutes: 2,
    max_idle_increment_minutes: 15,
    coverage_gap_seconds: 120,
    min_score_coverage_pct: 90,
    weights: {
        braking: 5,
        acceleration: 3,
        other_harsh: 3,
        overspeed: 4,
        idle_per_minute: 0.5,
    },
};

const driver = (patch: Partial<TripDriver> = {}): TripDriver => ({
    state: 'none',
    source: null,
    id: null,
    name: null,
    attribution: null,
    booked_driver_id: null,
    booked_driver: null,
    booking_reference: null,
    ...patch,
});

const behaviour = (patch: Partial<TripBehaviour> = {}): TripBehaviour => ({
    samples: 20,
    coverage_pct: 96,
    partial: false,
    max_speed_kph: 58,
    overspeed_episodes: 0,
    overspeed_seconds: 0,
    harsh: {
        braking: 0,
        acceleration: 0,
        cornering: 0,
        unclassified: 0,
        total: 0,
    },
    faults: 0,
    driving_events: 0,
    idle_minutes: null,
    events_per_100km: 0,
    score: 100,
    score_state: 'scored',
    ...patch,
});

const point = (at: string, speed: number | null): TripPoint => ({
    at,
    lat: -41.28,
    lng: 174.77,
    speed_kph: speed,
    heading: null,
    ignition: null,
    address: null,
});

describe('trip filters', () => {
    it('turns the date choice into an inclusive local range', () => {
        expect(filterRange(DEFAULT_TRIP_FILTERS)).toEqual({
            from: null,
            to: null,
        });
        expect(
            filterRange({ ...DEFAULT_TRIP_FILTERS, day: '2026-09-21' }),
        ).toEqual({ from: '2026-09-21', to: '2026-09-21' });
        expect(
            filterRange({
                ...DEFAULT_TRIP_FILTERS,
                day: 'range',
                from: '2026-09-01',
                to: '',
            }),
        ).toEqual({ from: '2026-09-01', to: null });
    });

    it('flags a range that ends before it starts', () => {
        expect(
            rangeInvalid({
                ...DEFAULT_TRIP_FILTERS,
                day: 'range',
                from: '2026-09-10',
                to: '2026-09-01',
            }),
        ).toBe(true);
        expect(
            rangeInvalid({ ...DEFAULT_TRIP_FILTERS, day: '2026-09-10' }),
        ).toBe(false);
    });

    it('only sends the filters that narrow the list', () => {
        expect(tripQuery(DEFAULT_TRIP_FILTERS, { page: 2 }).toString()).toBe(
            'page=2',
        );
        expect(
            tripQuery({
                ...DEFAULT_TRIP_FILTERS,
                q: ' Kōwhai ',
                day: '2026-09-21',
                driver: '12',
                event: 'overspeed',
            }).toString(),
        ).toBe(
            'q=K%C5%8Dwhai&from=2026-09-21&to=2026-09-21&driver=12&event=overspeed',
        );
    });

    it('keeps a focused day that is not a recent trip day', () => {
        expect(dayOptions(['2026-09-21', '2026-09-20'], '2026-08-01')).toEqual([
            '2026-09-21',
            '2026-09-20',
            '2026-08-01',
        ]);
        expect(dayOptions(['2026-09-21'], 'range')).toEqual(['2026-09-21']);
    });
});

describe('trip cards and labels', () => {
    const item = (patch: Partial<TripListItem> = {}): TripListItem => ({
        id: 1,
        reference: 'Trip #1',
        started_at: '2026-09-20T20:00:00+00:00',
        ended_at: '2026-09-20T20:12:00+00:00',
        local_date: '2026-09-21',
        in_progress: false,
        distance_km: 2.4,
        duration_s: 720,
        from: null,
        to: null,
        is_personal: false,
        consent_blocked: false,
        coverage_pct: 98,
        partial: false,
        driving_events: 0,
        overspeed_episodes: 0,
        faults: 0,
        driver: driver(),
        ...patch,
    });

    it('puts privacy before trail and distance on the card badge', () => {
        expect(tripBadge(item())).toEqual({ label: '2.4 km', variant: 'info' });
        expect(tripBadge(item({ partial: true })).label).toBe('Partial trail');
        expect(
            tripBadge(item({ is_personal: true, partial: true })).label,
        ).toBe('Personal trip');
        expect(tripBadge(item({ consent_blocked: true })).label).toBe(
            'Location withheld',
        );
    });

    it('never presents a booking as the confirmed driver', () => {
        expect(driverName(driver())).toBe('Unassigned');
        expect(driverCaption(driver())).toBe('Driver confirmation needed');
        const booked = driver({
            state: 'recorded',
            source: 'booking',
            id: 4,
            name: 'Jamie Taylor',
        });
        expect(driverName(booked)).toBe('Jamie Taylor');
        expect(driverCaption(booked)).toContain('actual driver unconfirmed');
        expect(
            driverCaption(
                driver({ state: 'confirmed', attribution: 'handover' }),
            ),
        ).toBe('Confirmed driver · handover from the booking');
        expect(driverName(driver({ state: 'hidden' }))).toBe(
            'Driver not shown',
        );
    });

    it('explains a withheld score instead of showing a perfect one', () => {
        const withheld = behaviour({
            score: null,
            score_state: 'coverage',
            coverage_pct: 72,
        });
        expect(scoreHeadline(withheld)).toBe('Score withheld');
        expect(scoreCaption(withheld, policy)).toBe(
            '72% sample coverage · score withheld below 90%',
        );
        expect(
            scoreHeadline(behaviour({ score: null, score_state: 'personal' })),
        ).toBe('Not scored');
        expect(scoreHeadline(behaviour({ driving_events: 2, score: 84 }))).toBe(
            'Review driving events',
        );
    });

    it('describes how the trip started and ended', () => {
        expect(
            endpointCaption(
                { address: null, lat: 1, lng: 1, trigger: 'movement' },
                '8:00 am',
                5,
                'start',
            ),
        ).toBe('8:00 am · started when the vehicle moved');
        expect(
            endpointCaption(
                { address: null, lat: 1, lng: 1, trigger: 'stopped' },
                '8:12 am',
                5,
                'end',
            ),
        ).toBe('8:12 am · stopped for 5 min');
    });
});

describe('journey playback and timeline', () => {
    it('plays short trips at the approved pace and long trips in about 30 seconds', () => {
        expect(playbackDelay(5, 1)).toBe(1500);
        expect(playbackDelay(5, 2)).toBe(750);
        expect(playbackDelay(300, 1)).toBe(120);
    });

    it('filters journey events by kind', () => {
        const event = (kind: TripEvent['kind']): TripEvent => ({
            key: kind,
            type: kind,
            kind,
            title: kind,
            detail: '',
            at: '2026-09-20T20:00:00+00:00',
            point: 0,
            peak_kph: null,
            seconds: null,
            source: null,
        });
        expect(timelineMatches(event('speed'), 'driving')).toBe(true);
        expect(timelineMatches(event('journey'), 'driving')).toBe(false);
        expect(timelineMatches(event('power'), 'faults')).toBe(true);
        expect(timelineMatches(event('driving'), 'all')).toBe(true);
    });

    it('draws speed against time and breaks the line at missing reports', () => {
        const chart = speedChart(
            [
                point('2026-09-20T20:00:00Z', 0),
                point('2026-09-20T20:01:00Z', 40),
                point('2026-09-20T20:02:00Z', null),
                point('2026-09-20T20:10:00Z', 30),
                point('2026-09-20T20:11:00Z', 0),
            ],
            1,
            policy,
        );
        expect(chart.samples).toBe(4);
        expect(chart.maxSpeed).toBe(40);
        expect(chart.lines).toHaveLength(2);
        expect(chart.lines[0].split(' ')[0]).toBe('15,85');
        expect(chart.selected).not.toBeNull();
        expect(chart.threshold).toBeNull();
        expect(chart.dots).toHaveLength(4);
    });

    it('shows the fleet threshold when the trip approaches it', () => {
        const chart = speedChart(
            [
                point('2026-09-20T20:00:00Z', 60),
                point('2026-09-20T20:01:00Z', 104),
            ],
            0,
            policy,
        );
        expect(chart.threshold?.label).toBe('Fleet threshold 100 km/h');
        expect(chart.threshold?.y).toBeGreaterThan(5);
    });

    it('reports no chart when the tracker sent no speeds', () => {
        expect(
            speedChart([point('2026-09-20T20:00:00Z', null)], 0, policy)
                .samples,
        ).toBe(0);
    });
});

describe('export downloads', () => {
    it('reads the server filename from Content-Disposition', () => {
        expect(
            dispositionFilename(
                'attachment; filename=kwh014-trips-2026-09-01-to-2026-09-21.pdf',
                'fallback.pdf',
            ),
        ).toBe('kwh014-trips-2026-09-01-to-2026-09-21.pdf');
        expect(
            dispositionFilename(
                'attachment; filename="a.xls"; filename*=UTF-8\'\'k%C5%8Dwhai.xls',
                'x',
            ),
        ).toBe('kōwhai.xls');
        expect(dispositionFilename(null, 'fallback.pdf')).toBe('fallback.pdf');
    });
});
