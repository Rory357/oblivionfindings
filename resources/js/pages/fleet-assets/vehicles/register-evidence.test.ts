import { describe, expect, it } from 'vitest';
import type { FleetEvent } from './fleet-calendar';
import { registerEvidence } from './register-evidence';

const instant = Date.parse('2026-09-28T01:00:00Z');
const entry = (values: Partial<FleetEvent>) =>
    ({
        id: 'one',
        vehicleId: 1,
        kind: 'busy',
        start: '2026-09-28T00:00:00Z',
        end: '2026-09-28T02:00:00Z',
        title: 'Busy',
        statusLabel: 'Busy only',
        ...values,
    }) as FleetEvent;
describe('Register source evidence', () => {
    it('keeps busy-only evidence and vehicle scope intact', () => {
        const busy = entry({});
        const result = registerEvidence(
            [busy, entry({ vehicleId: 2, title: 'Other site private trip' })],
            1,
            instant,
        );
        expect(result.current).toBe(busy);
        expect(result.next).toBeUndefined();
    });
    it('prioritises an active restriction and uses exclusive end boundaries', () => {
        const restriction = entry({
            id: 'restriction',
            kind: 'restriction',
            end: null,
        });
        const next = entry({
            id: 'next',
            start: '2026-09-28T03:00:00Z',
            end: '2026-09-28T04:00:00Z',
        });
        expect(
            registerEvidence(
                [entry({ end: '2026-09-28T01:00:00Z' }), next, restriction],
                1,
                instant,
            ),
        ).toEqual({ current: restriction, next });
        expect(registerEvidence([], 1, instant).current).toBeUndefined();
    });
});
