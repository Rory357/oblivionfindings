import { describe, expect, it } from 'vitest';
import { handoverCommandWeek } from './handover-command-week';

describe('handover return week', () => {
    it('keeps a Monday Auckland shift in its own week while the UTC date is still Sunday', () => {
        expect(handoverCommandWeek('2026-10-11T13:30:00Z', 'Pacific/Auckland')).toBe('2026-10-12');
        expect(handoverCommandWeek('2026-10-11T13:30:00Z', 'UTC')).toBe('2026-10-05');
    });
    it('uses the configured zone at year and daylight-saving boundaries', () => {
        expect(handoverCommandWeek('2025-12-28T12:30:00Z', 'Pacific/Auckland')).toBe('2025-12-29');
        expect(handoverCommandWeek('2026-09-27T11:30:00Z', 'Pacific/Auckland')).toBe('2026-09-28');
    });
    it('does not fabricate a current week for a missing date or invalid zone', () => {
        expect(handoverCommandWeek(null, 'Pacific/Auckland')).toBeNull();
        expect(handoverCommandWeek('not-a-date', 'Pacific/Auckland')).toBeNull();
        expect(handoverCommandWeek('2026-10-11T13:30:00Z', 'invalid-zone')).toBeNull();
    });
});
