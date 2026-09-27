import { describe, expect, it } from 'vitest';
import {
    attentionPriority,
    exportPath,
    inQueue,
    scheduleCounts,
    stageCounts,
    transportDay,
    transportPath,
} from './model';
import type { TransportRecord } from './types';

const row = (stage: string, extra: Partial<TransportRecord> = {}) =>
    ({
        stage,
        start: '2026-09-30T20:00:00Z',
        end: '2026-09-30T21:00:00Z',
        booking: null,
        return_stage: 'not_due',
        missing_items: [],
        ...extra,
    }) as TransportRecord;
describe('Transport work queues and graphs', () => {
    it('puts unresolved return work and journey completion before decisions and planning', () => {
        const priorities = [
            row('allocation'),
            row('decision'),
            row('returned'),
            row('completed', { return_stage: 'keys' }),
            row('travelling', { return_stage: 'overdue' }),
        ];
        expect(
            priorities
                .sort((a, b) => attentionPriority(a) - attentionPriority(b))
                .map((r) => r.stage),
        ).toEqual([
            'travelling',
            'completed',
            'returned',
            'decision',
            'allocation',
        ]);
    });
    it('counts each request once while keeping return work independent of passenger completion', () => {
        const rows = [
            'assessment',
            'information',
            'allocation',
            'decision',
            'ready',
            'depart',
            'travelling',
            'returned',
            'completed',
            'cancelled',
        ].map((stage) => row(stage));
        expect(stageCounts(rows).reduce((n, stage) => n + stage.value, 0)).toBe(
            rows.length,
        );
        const finished = row('completed', {
            return_stage: 'keys',
            missing_items: ['First aid kit'],
            booking: {
                status: 'returned',
                returned_at: '2026-09-30T21:00:00Z',
            } as TransportRecord['booking'],
        });
        expect(inQueue(finished, 'returns', 'all')).toBe(true);
        expect(inQueue(finished, 'returns', 'items')).toBe(true);
        expect(inQueue(finished, 'returns', 'keys')).toBe(true);
        expect(inQueue(finished, 'returns', 'complete')).toBe(false);
    });
    it('uses the allocated Fleet window, Auckland time and excludes cancellations', () => {
        const booked = row('ready', {
            booking: {
                start: '2026-09-30T23:00:00Z',
                end: '2026-10-01T00:00:00Z',
            } as TransportRecord['booking'],
        });
        const hours = scheduleCounts([
            booked,
            row('allocation'),
            row('cancelled'),
        ]);
        expect(hours.find((h) => h.hour === 9)?.departures).toBe(1);
        expect(hours.find((h) => h.hour === 12)?.departures).toBe(1);
        expect(hours.reduce((n, h) => n + h.returns, 0)).toBe(2);
    });
    it('preserves explicit scope and encodes search text in links and exports', () => {
        const filters = {
            from: '2026-10-01',
            to: '2026-10-02',
            site: '7',
            search: 'A&B + C',
        };
        const url = new URL(
            transportPath('requests', filters, { queue: 'decision' }),
            'https://example.test',
        );
        expect(url.searchParams.get('search')).toBe(filters.search);
        expect(url.searchParams.get('site')).toBe('7');
        expect(
            new URL(
                exportPath(filters, 42),
                'https://example.test',
            ).searchParams.get('request_id'),
        ).toBe('42');
        const selected = new URL(
            exportPath(filters, undefined, { view: 'returns', queue: 'keys' }),
            'https://example.test',
        );
        expect(selected.searchParams.get('queue')).toBe('keys');
        expect(transportDay('2026-09-30T20:00:00Z')).toBe('2026-10-01');
    });
});
