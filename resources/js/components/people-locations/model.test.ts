import { describe, expect, it } from 'vitest';
import {
    batterySeries,
    distribution,
    filterPeople,
    hourlySamples,
    observationGaps,
    type Person,
    type Sample,
} from './model';
const person = (changes: Partial<Person>): Person => ({
    id: 'c1',
    recordId: 1,
    kind: 'client',
    name: 'Māia Thompson',
    reference: 'Client 1',
    site: { id: 1, name: 'Kōwhai House' },
    sources: [
        {
            id: '1',
            label: 'Tracker',
            reference: 'Device 8',
            retentionDays: 30,
            purpose: 'Safety',
        },
    ],
    authority: 'Current',
    position: null,
    positionState: 'unknown',
    battery: null,
    batteryAt: null,
    power: 'unknown',
    powerAt: null,
    motion: 'unknown',
    motionAt: null,
    contactAt: null,
    profileUrl: '',
    transportUrl: null,
    ...changes,
});
describe('People Locations evidence', () => {
    it('searches accents, sources and Sites without introducing other people', () => {
        expect(
            filterPeople([person({})], 'kowhai', 'all', 'name'),
        ).toHaveLength(1);
        expect(
            filterPeople([person({})], 'device 8', 'all', 'name'),
        ).toHaveLength(1);
        expect(
            filterPeople([person({})], 'maia', 'position:recent', 'name'),
        ).toHaveLength(0);
    });
    it('sorts unknown batteries after zero and preserves unknown in totals', () => {
        const people = [
            person({ id: 'unknown' }),
            person({ id: 'zero', battery: 0 }),
            person({ id: 'high', battery: 70 }),
        ];
        expect(
            filterPeople(people, '', 'all', 'battery').map((p) => p.id),
        ).toEqual(['zero', 'high', 'unknown']);
        expect(
            distribution(
                people,
                ['recent', 'stale', 'unknown'],
                (p) => p.positionState,
            ).reduce((n, r) => n + r.value, 0),
        ).toBe(3);
    });
    it('keeps exact chart drill-down membership for missing and low readings', () => {
        const people = [
            person({ id: 'a', battery: 20 }),
            person({ id: 'b', battery: 21 }),
            person({ id: 'c' }),
        ];
        expect(
            filterPeople(people, '', 'battery:low', 'name').map((p) => p.id),
        ).toEqual(['a']);
        expect(
            filterPeople(people, '', 'battery:low|site:1', 'name').map(
                (p) => p.id,
            ),
        ).toEqual(['a']);
        expect(
            filterPeople(people, '', 'battery:low|site:2', 'name'),
        ).toHaveLength(0);
        expect(
            filterPeople(people, '', 'battery:unknown', 'name').map(
                (p) => p.id,
            ),
        ).toEqual(['c']);
    });
    it('leaves a gap instead of drawing continuous battery coverage', () => {
        const samples = [
            { at: '2026-09-26T20:00:00Z', battery: 50 },
            { at: '2026-09-26T22:00:00Z', battery: 80 },
        ] as Sample[];
        expect(batterySeries(samples).map((p) => p.battery)).toEqual([
            50,
            null,
            80,
        ]);
    });
    it('uses authorised buckets and counts boundary samples once', () => {
        const samples = ['20:00', '21:00', '21:30'].map(
            (t) => ({ at: `2026-09-26T${t}:00Z` }) as Sample,
        );
        expect(
            hourlySamples(samples, {
                from: '2026-09-26T20:00:00Z',
                to: '2026-09-26T21:30:00Z',
                timezone: 'Pacific/Auckland',
            }).map((b) => [b.count, b.partial]),
        ).toEqual([
            [1, false],
            [2, true],
        ]);
    });
    it('keeps priority and missing-source drill-downs in their exact permitted cohorts', () => {
        const base = person({});
        const people = [
            person({ id: 'recent', positionState: 'recent' }),
            person({ id: 'stale', positionState: 'stale' }),
            person({
                id: 'choice',
                sources: [base.sources[0], { ...base.sources[0], id: '2' }],
            }),
            person({ id: 'none', sources: [] }),
            person({ id: 'missing' }),
        ];
        expect(
            filterPeople(people, '', 'position:not-recent', 'name').map(
                (p) => p.id,
            ),
        ).toEqual(['stale', 'choice', 'none', 'missing']);
        for (const [cohort, id] of [
            ['source-choice', 'choice'],
            ['no-source', 'none'],
            ['no-position', 'missing'],
        ]) {
            expect(
                filterPeople(people, '', `missing:${cohort}`, 'name').map(
                    (p) => p.id,
                ),
            ).toEqual([id]);
        }
        expect(
            filterPeople(people, '', 'position:not-recent|site:2', 'name'),
        ).toHaveLength(0);
        expect(
            filterPeople(people, '', 'position:stale|site:1', 'name').map(
                (p) => p.id,
            ),
        ).toEqual(['stale']);
    });
    it('shades only reporting gaps over thirty minutes, in recorded order', () => {
        const samples = ['22:00', '20:00', '20:30'].map(
            (t) => ({ at: `2026-09-26T${t}:00Z`, battery: 50 }) as Sample,
        );
        expect(observationGaps(samples)).toEqual([
            {
                from: Date.parse('2026-09-26T20:30:00Z'),
                to: Date.parse('2026-09-26T22:00:00Z'),
            },
        ]);
        expect(observationGaps([])).toEqual([]);
    });
});

it('uses independent observation-age cohorts at the checked snapshot time', () => {
    const rows = [
        person({
            id: 'older-battery',
            position: { lat: 1, lng: 1, timestamp: '2026-09-27T00:00:00Z' },
            battery: 10,
            batteryAt: '2026-09-25T00:00:00Z',
        }),
        person({ id: 'unknown' }),
    ];
    expect(
        filterPeople(
            rows,
            '',
            'positionAge:recent',
            'name',
            '2026-09-27T00:10:00Z',
        ).map((p) => p.id),
    ).toEqual(['older-battery']);
    expect(
        filterPeople(
            rows,
            '',
            'batteryAge:older',
            'name',
            '2026-09-27T00:10:00Z',
        ).map((p) => p.id),
    ).toEqual(['older-battery']);
    expect(
        filterPeople(
            rows,
            '',
            'batteryAge:unknown',
            'name',
            '2026-09-27T00:10:00Z',
        ).map((p) => p.id),
    ).toEqual(['unknown']);
});
