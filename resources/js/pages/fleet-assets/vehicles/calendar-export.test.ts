import { describe, expect, it } from 'vitest';
import { calendarCsv } from './calendar-export';
import type { FleetEvent } from './fleet-calendar';

describe('Fleet visible calendar export', () => {
    it('exports busy-only time without record identity or private details', () => {
        const csv = calendarCsv(
            [
                {
                    kind: 'busy',
                    vehicleId: 1,
                    title: 'Private title',
                    start: '2026-09-28T01:00:00Z',
                    end: '2026-09-28T02:00:00Z',
                    statusLabel: 'Busy',
                    link: '/private',
                    ref: 'PRIVATE-1',
                } as FleetEvent,
            ],
            () => 'Fleet van',
        );
        expect(csv).toContain('"Fleet van","Busy"');
        expect(csv).not.toMatch(/Private|PRIVATE|\/private/);
    });
    it('quotes cells and protects against spreadsheet formulas', () => {
        const csv = calendarCsv(
            [
                {
                    kind: 'booking',
                    vehicleId: 1,
                    title: '="a",b',
                    start: null,
                    end: null,
                    statusLabel: 'Pending',
                } as FleetEvent,
            ],
            () => '=NAME',
        );
        expect(csv).toContain('"\'=NAME"');
        expect(csv).toContain('"\'=""a"",b"');
    });
});
