import { describe, expect, it } from 'vitest';
import { safeFleetReturn } from './fleet-return';

describe('Fleet source return boundary', () => {
    it('retains the exact Fleet view and filters', () => {
        const path =
            '/fleet-assets/vehicles?view=week&date=2026-09-28&vehicle=9&search=van';
        expect(safeFleetReturn(path)).toBe(path);
    });
    it('rejects external, other-record and malformed destinations', () => {
        for (const path of [
            '//evil.test',
            'https://evil.test',
            '/fleet-assets/vehicles/8',
            '/fleet-assets/vehicles\\evil',
            '/fleet-assets/vehicles\n',
            '/fleet-assets/vehicles-extra',
        ])
            expect(safeFleetReturn(path)).toBeNull();
    });
});
