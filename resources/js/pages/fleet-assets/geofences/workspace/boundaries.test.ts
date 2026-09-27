import { describe, expect, it } from 'vitest';
import { mapBoundary, type BoundaryRecord } from './data';
import { decodeGeometry, encodeGeometry } from './geometry-tools';
import { mapResource } from './map-data';
import { clusterPoints, relation, resourceStatus } from './map-model';
import {
    normalisePolicy,
    overlappingWindows,
    policyError,
    sampleOutcome,
} from './rule-policy';
const area: BoundaryRecord = {
    id: 1,
    name: 'Area',
    site_id: 1,
    site: 'Site',
    address: 'Place',
    geometry: {
        type: 'circle',
        center: { lat: -41.29, lng: 174.77 },
        radius_m: 100,
    },
    geometry_version: 1,
    revision: 1,
    uses: ['Vehicles'],
    personal_eligible: false,
    retired_at: null,
    legacy_monitoring: false,
    copy_source: null,
    updated_at: null,
};
const boundary = mapBoundary(area)!;
const resource = mapResource({
    id: 1,
    name: 'Van',
    tag: null,
    kind: 'Vehicle',
    site: 'Site',
    position: { lat: -41.29, lng: 174.77 },
    observed_at: null,
    received_at: null,
    fresh: true,
    accuracy_m: 10,
    boundary_ids: [1],
    href: '/source',
});
describe('recorded geofence evidence', () => {
    it('does not turn absent accuracy or edge uncertainty into a crossing', () => {
        expect(relation(resource.position!, null, boundary.geometry)).toBe(
            'uncertain',
        );
        expect(relation(resource.position!, 101, boundary.geometry)).toBe(
            'uncertain',
        );
        expect(relation(resource.position!, 10, boundary.geometry)).toBe(
            'inside',
        );
    });
    it('keeps stale, missing and unavailable source states separate', () => {
        expect(
            resourceStatus({ ...resource, fresh: false }, [boundary]).key,
        ).toBe('stale');
        expect(
            resourceStatus({ ...resource, position: null }, [boundary]).key,
        ).toBe('missing');
        expect(resourceStatus(resource, []).key).toBe('unavailable');
        expect(
            resourceStatus(resource, [{ ...boundary, status: 'Retired' }]).key,
        ).toBe('unavailable');
        expect(
            resourceStatus({ ...resource, boundaries: [] }, [boundary]).key,
        ).toBe('unlinked');
    });
    it('clusters by position without hiding the identity of co-located records', () => {
        const rows = Array.from({ length: 120 }, (_, i) => ({
            ...resource,
            id: String(i),
        }));
        const groups = clusterPoints(rows, () => ({ x: 10, y: 10 }));
        expect(groups).toHaveLength(1);
        expect(new Set(groups[0].map((r) => r.id)).size).toBe(120);
    });
    it('round trips circle GeoJSON and rejects unsupported polygons with holes', () => {
        expect(decodeGeometry(encodeGeometry(boundary.geometry)).shape).toEqual(
            boundary.geometry,
        );
        expect(
            decodeGeometry(
                JSON.stringify({
                    type: 'Polygon',
                    coordinates: [
                        [
                            [0, 0],
                            [0, 2],
                            [2, 2],
                            [0, 0],
                        ],
                        [
                            [0.5, 0.5],
                            [0.5, 1],
                            [1, 1],
                            [0.5, 0.5],
                        ],
                    ],
                }),
            ).shape,
        ).toBeFalsy();
    });
});
describe('inactive timing and detection proposals', () => {
    it('normalises optional server nulls when reopening a rule', () => {
        const policy = normalisePolicy({
            accuracyM: null,
            bufferM: null,
        } as never);
        expect(policy.accuracyM).toBe('');
        expect(policyError(policy)).toBe('');
    });
    it('detects overlapping overnight windows across the week boundary', () => {
        const schedule = {
            timezone: 'Pacific/Auckland',
            weekdays: [7, 1],
            start: '22:00',
            end: '06:00',
            following_day: true,
            first_date: '2026-09-27',
            last_date: '2026-10-31',
            exception_dates: [],
        };
        expect(
            overlappingWindows(schedule, [
                { start: '22:00', end: '06:00', following_day: true },
                { start: '05:00', end: '07:00', following_day: false },
            ]),
        ).toBe(true);
        expect(
            overlappingWindows(schedule, [
                { start: '22:00', end: '06:00', following_day: true },
            ]),
        ).toBe(false);
    });
    it('keeps stale scenario evidence from claiming a confirmed crossing', () => {
        expect(
            sampleOutcome('exit', normalisePolicy(null), 'stale', 1000),
        ).toMatch(/stale|out of date|no|cannot/i);
    });
});
