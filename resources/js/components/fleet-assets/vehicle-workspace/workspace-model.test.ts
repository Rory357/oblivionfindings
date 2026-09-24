import { describe, expect, it } from 'vitest';
import { addMonthsNoOverflow } from './service-schedules';
import type { ReadinessReason, VehicleWorkspace } from './types';
import {
    formatKm,
    headerStatus,
    locationUrl,
    readLocation,
    reasonDestination,
    todayInAuckland,
} from './workspace-model';

const reason = (code: string): ReadinessReason => ({
    code,
    message: code,
    scope: 'vehicle',
    kind: null,
    source_id: null,
    version_id: null,
    blocks_decision: true,
});

describe('vehicle workspace location', () => {
    it('defaults each tab to its first view and ignores unknown values', () => {
        expect(readLocation('')).toEqual({
            tab: 'overview',
            view: 'readiness',
        });
        expect(readLocation('?tab=service')).toEqual({
            tab: 'service',
            view: 'evidence',
        });
        expect(readLocation('?tab=service&view=mileage')).toEqual({
            tab: 'service',
            view: 'mileage',
        });
        expect(readLocation('?tab=service&view=finance')).toEqual({
            tab: 'service',
            view: 'evidence',
        });
        expect(readLocation('?tab=nope')).toEqual({
            tab: 'overview',
            view: 'readiness',
        });
        // Old Technology links open the telemetry view under Map.
        expect(readLocation('?tab=technology')).toEqual({
            tab: 'map',
            view: 'telemetry',
        });
    });

    it('opens the calendar and trip history on a valid day only', () => {
        expect(readLocation('?tab=calendar&date=2026-09-24')).toEqual({
            tab: 'calendar',
            date: '2026-09-24',
        });
        expect(readLocation('?tab=trips&date=24/09/2026')).toEqual({
            tab: 'trips',
        });
        expect(readLocation('?tab=calendar&view=month')).toEqual({
            tab: 'calendar',
        });
    });

    it('builds shareable URLs that read back to the same place', () => {
        expect(locationUrl(7, { tab: 'overview' })).toBe(
            '/fleet-assets/vehicles/7',
        );
        const url = locationUrl(7, { tab: 'service', view: 'reminders' });
        expect(url).toBe('/fleet-assets/vehicles/7?tab=service&view=reminders');
        expect(readLocation(url.split('?')[1])).toEqual({
            tab: 'service',
            view: 'reminders',
        });
        const day = locationUrl(7, { tab: 'calendar', date: '2026-10-28' });
        expect(day).toBe(
            '/fleet-assets/vehicles/7?tab=calendar&date=2026-10-28',
        );
        expect(readLocation(day.split('?')[1])).toEqual({
            tab: 'calendar',
            date: '2026-10-28',
        });
    });

    it('deep links to one compliance requirement on Evidence & due dates only', () => {
        const url = locationUrl(7, {
            tab: 'service',
            view: 'evidence',
            focus: 'wof',
        });
        expect(url).toBe(
            '/fleet-assets/vehicles/7?tab=service&view=evidence&focus=wof',
        );
        expect(readLocation(url.split('?')[1])).toEqual({
            tab: 'service',
            view: 'evidence',
            focus: 'wof',
        });
        expect(
            readLocation('?tab=service&view=evidence&focus=insurance'),
        ).toEqual({ tab: 'service', view: 'evidence' });
        expect(readLocation('?tab=service&view=mileage&focus=ruc')).toEqual({
            tab: 'service',
            view: 'mileage',
        });
    });

    it('sends each readiness reason to where it is resolved', () => {
        expect(
            reasonDestination(reason('compliance.ruc.coverage_exceeded')),
        ).toEqual({ tab: 'service', view: 'mileage' });
        expect(reasonDestination(reason('compliance.wof.unresolved'))).toEqual({
            tab: 'service',
            view: 'evidence',
        });
        // A reason about one requirement opens its row (where "Not required" lives).
        expect(
            reasonDestination({ code: 'compliance.ruc.missing', kind: 'ruc' }),
        ).toEqual({ tab: 'service', view: 'evidence', focus: 'ruc' });
        expect(
            reasonDestination(reason('maintenance.unresolved_check')),
        ).toEqual({ tab: 'checks', view: 'recent' });
        expect(
            reasonDestination(reason('maintenance.restriction_active')),
        ).toEqual({ tab: 'maintenance', view: 'open' });
        expect(reasonDestination(reason('odometer.missing'))).toEqual({
            tab: 'service',
            view: 'mileage',
        });
    });

    it('opens the check that holds the vehicle, and only on Recent checks', () => {
        expect(
            reasonDestination({
                ...reason('maintenance.unresolved_check'),
                source_id: 190,
            }),
        ).toEqual({ tab: 'checks', view: 'recent', run: 190 });
        const url = locationUrl(7, { tab: 'checks', view: 'recent', run: 190 });
        expect(url).toBe(
            '/fleet-assets/vehicles/7?tab=checks&view=recent&run=190',
        );
        expect(readLocation(url.split('?')[1])).toEqual({
            tab: 'checks',
            view: 'recent',
            run: 190,
        });
        expect(readLocation('?tab=checks&view=recent&run=abc')).toEqual({
            tab: 'checks',
            view: 'recent',
        });
        expect(readLocation('?tab=checks&view=recent&run=0')).toEqual({
            tab: 'checks',
            view: 'recent',
        });
        expect(readLocation('?tab=checks&view=templates&run=190')).toEqual({
            tab: 'checks',
            view: 'templates',
        });
        expect(readLocation('?tab=service&view=evidence&run=190')).toEqual({
            tab: 'service',
            view: 'evidence',
        });
    });
});

describe('vehicle header status', () => {
    const workspace = (latest: {
        outcome: string;
        assessed: boolean;
    }): VehicleWorkspace =>
        ({
            vehicle: { status: 'active' },
            readiness: { restriction_ids: [], can_proceed: true },
            schedules: [],
            checks: {
                latest: {
                    id: 190,
                    template: 'Vehicle condition record',
                    submitted_at: '2026-09-22T01:00:00+00:00',
                    ...latest,
                },
                next_due_at: null,
            },
        }) as unknown as VehicleWorkspace;

    it('no longer asks for review once Maintenance released the latest check', () => {
        expect(
            headerStatus(
                workspace({ outcome: 'needs_assessment', assessed: false }),
                '2026-09-22',
            ).label,
        ).toBe('Needs assessment');
        expect(
            headerStatus(
                workspace({ outcome: 'needs_assessment', assessed: true }),
                '2026-09-22',
            ),
        ).toEqual({ label: 'Ready', variant: 'success', state: 'ready' });
    });
});

describe('vehicle workspace formatting', () => {
    it('formats kilometres in NZ English and says when nothing is recorded', () => {
        expect(formatKm(50001)).toBe('50,001 km');
        expect(formatKm(null)).toBe('Not recorded');
    });

    it('uses the Auckland calendar date', () => {
        // 12:30 UTC on 30 June is already 1 July in Auckland (NZST, +12).
        expect(todayInAuckland(new Date('2026-06-30T12:30:00Z'))).toBe(
            '2026-07-01',
        );
    });

    it('adds calendar months without overflowing short months', () => {
        expect(addMonthsNoOverflow('2026-01-31', 1)).toBe('2026-02-28');
        expect(addMonthsNoOverflow('2028-01-31', 1)).toBe('2028-02-29');
        expect(addMonthsNoOverflow('2026-08-31', 6)).toBe('2027-02-28');
        expect(addMonthsNoOverflow('2026-03-15', 12)).toBe('2027-03-15');
    });
});
