import { describe, expect, it } from 'vitest';
import { hasPosition, positionState, type FleetMapVehicle } from './fleet-map';

const vehicle = (patch: Partial<FleetMapVehicle> = {}): FleetMapVehicle => ({
    id: 1,
    name: 'Van',
    asset_tag: 'V-1',
    registration_number: 'ABC123',
    status: 'active',
    home_site: null,
    tracker_linked: true,
    position: {
        lat: 0,
        lng: 0,
        observed_at: '2026-09-27T09:00:00+13:00',
        received_at: null,
        fresh: true,
        withheld: null,
        speed_kph: null,
        battery_pct: null,
        status: 'online',
    },
    ...patch,
});

describe('fleet position projection', () => {
    it('accepts zero coordinates and keeps unknown metrics unknown', () => {
        const row = vehicle();
        expect(hasPosition(row)).toBe(true);
        expect(positionState(row)).toBe('reported');
        expect(row.position?.speed_kph).toBeNull();
        expect(row.position?.battery_pct).toBeNull();
    });
    it('distinguishes stale, missing, trackerless and withheld positions', () => {
        expect(
            positionState(
                vehicle({ position: { ...vehicle().position!, fresh: false } }),
            ),
        ).toBe('stale');
        expect(
            positionState(
                vehicle({ position: { ...vehicle().position!, lat: null } }),
            ),
        ).toBe('no-fix');
        expect(
            positionState(vehicle({ tracker_linked: false, position: null })),
        ).toBe('no-tracker');
        const withheld = vehicle({
            position: {
                ...vehicle().position!,
                withheld: 'personal',
                lat: null,
                lng: null,
            },
        });
        expect(positionState(withheld)).toBe('withheld');
        expect(hasPosition(withheld)).toBe(false);
    });
});
