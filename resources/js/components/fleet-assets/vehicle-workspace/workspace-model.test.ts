import { describe, expect, it } from 'vitest';
import { addMonthsNoOverflow } from './service-schedules';
import type { CheckSummary, ReadinessReason, VehicleWorkspace } from './types';
import {
    dailyIssue,
    formatKm,
    headerStatus,
    lastCheck,
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

    it('sends each readiness reason to where it is resolved', () => {
        expect(
            reasonDestination(reason('compliance.ruc.coverage_exceeded')),
        ).toEqual({ tab: 'service', view: 'mileage' });
        expect(reasonDestination(reason('compliance.wof.unresolved'))).toEqual({
            tab: 'service',
            view: 'evidence',
        });
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
});

const check = (
    id: number,
    outcome: string | null,
    submitted_at: string,
): CheckSummary => ({ id, outcome, template: 'Checklist', submitted_at });

/** Only what the readiness picture reads; everything else is irrelevant here. */
const workspaceWith = (
    checks: Partial<VehicleWorkspace['checks']>,
): VehicleWorkspace =>
    ({
        vehicle: { status: 'active' },
        readiness: { restriction_ids: [], can_proceed: true, reasons: [] },
        schedules: [],
        checks: {
            latest: null,
            latest_daily: null,
            next_due_at: null,
            ...checks,
        },
    }) as unknown as VehicleWorkspace;

describe('vehicle checks in the header', () => {
    const passed = check(4, 'passed', '2026-09-23T21:00:00+00:00');

    it('shows the most recent check, daily checks included', () => {
        const daily = check(
            9,
            'no_issue_recorded',
            '2026-09-24T20:30:00+00:00',
        );
        expect(
            lastCheck({
                latest: passed,
                latest_daily: daily,
                next_due_at: null,
            }),
        ).toBe(daily);
        expect(
            lastCheck({
                latest: passed,
                latest_daily: null,
                next_due_at: null,
            }),
        ).toBe(passed);
        expect(
            lastCheck({ latest: null, latest_daily: daily, next_due_at: null }),
        ).toBe(daily);
        expect(
            lastCheck({ latest: null, latest_daily: null, next_due_at: null }),
        ).toBeNull();
        // A later vehicle check is still the last check.
        const older = check(3, 'issue_recorded', '2026-09-22T20:30:00+00:00');
        expect(
            lastCheck({
                latest: passed,
                latest_daily: older,
                next_due_at: null,
            }),
        ).toBe(passed);
    });

    it('keeps a vehicle ready after a daily check with no issue', () => {
        const daily = check(
            9,
            'no_issue_recorded',
            '2026-09-24T20:30:00+00:00',
        );
        const workspace = workspaceWith({
            latest: passed,
            latest_daily: daily,
        });
        expect(dailyIssue(workspace)).toBe(false);
        expect(headerStatus(workspace, '2026-09-25')).toEqual({
            label: 'Ready',
            variant: 'success',
            state: 'ready',
        });
    });

    it('asks for review, without blocking, when the latest daily check recorded an issue', () => {
        const issue = check(9, 'issue_recorded', '2026-09-24T20:30:00+00:00');
        const workspace = workspaceWith({
            latest: passed,
            latest_daily: issue,
        });
        expect(dailyIssue(workspace)).toBe(true);
        expect(headerStatus(workspace, '2026-09-25')).toEqual({
            label: 'Needs assessment',
            variant: 'warning',
            state: 'review',
        });
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
