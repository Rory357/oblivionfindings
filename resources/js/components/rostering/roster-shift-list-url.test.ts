import { describe, expect, it } from 'vitest';
import { rosterShiftListUrl } from './roster-shift-list-url';

describe('roster to full shift list context', () => {
    it('retains multiple selected sites, worker and person using the actual list query vocabulary', () => {
        const link = new URL(rosterShiftListUrl({
            from: '2026-10-05', to: '2026-10-11', siteIds: [12, 18],
            staffId: 42, clientId: 9,
        }), 'http://localhost');
        expect(link.pathname).toBe('/operations/shifts');
        expect(link.searchParams.getAll('site_ids[]')).toEqual(['12', '18']);
        expect(link.searchParams.get('user_id')).toBe('42');
        expect(link.searchParams.has('staff_id')).toBe(false);
        expect(link.searchParams.get('client_id')).toBe('9');
        expect(link.searchParams.has('q')).toBe(false);
        expect(link.searchParams.get('from')).toBe('2026-10-05');
        expect(link.searchParams.get('to')).toBe('2026-10-11');
    });

    it('keeps a year-crossing period and omits absent selections instead of inventing a record ID', () => {
        const link = new URL(rosterShiftListUrl({
            from: '2026-12-28', to: '2027-01-03', siteIds: [],
            staffId: null, clientId: null,
        }), 'http://localhost');
        expect([...link.searchParams]).toEqual([
            ['from', '2026-12-28'], ['to', '2027-01-03'],
        ]);
    });
});
