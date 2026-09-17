import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
}));

import {
    comparisonText,
    indicatorChip,
    indicatorStatusKey,
    targetLabel,
    unitLabel,
    type SnapshotValue,
} from './_shared';

const value = (overrides: Partial<SnapshotValue> = {}): SnapshotValue => ({
    indicator_id: 1,
    indicator_code: 'HCG-002',
    value: 2,
    status: 'warning',
    trend: 'up',
    previous_value: 1,
    recorded: true,
    source_href: null,
    source_label: null,
    ...overrides,
});

describe('Care quality wording', () => {
    it('says "Target: none" for a zero target and never shows the "count" unit', () => {
        expect(targetLabel('below', 0)).toBe('Target: none');
        expect(targetLabel('below', 2)).toBe('Target: 2 or fewer');
        expect(targetLabel('above', 5)).toBe('Target: 5 or more');
        expect(targetLabel('below', null)).toBe('No target set');
        expect(unitLabel('count')).toBeNull();
        expect(unitLabel('Count')).toBeNull();
        expect(unitLabel('%')).toBe('%');
    });

    it('compares with the same days last month in words', () => {
        expect(comparisonText(value(), '1–14 Aug')).toBe('Up from 1 in 1–14 Aug');
        expect(comparisonText(value({ value: 0, previous_value: 3 }), 'August 2026')).toBe(
            'Down from 3 in August 2026',
        );
        expect(comparisonText(value({ value: 1, previous_value: 1 }), '1–14 Aug')).toBe(
            'Same as 1–14 Aug',
        );
        expect(comparisonText(value({ previous_value: null }), '1–14 Aug')).toBeNull();
    });

    it('says "No data yet" instead of "On target" when nothing has been recorded', () => {
        expect(indicatorChip(value({ status: 'normal', recorded: false }))).toEqual({
            label: 'No data yet',
            variant: 'neutral',
        });
        expect(indicatorChip(null).label).toBe('No data yet');
        expect(indicatorStatusKey(value({ status: 'normal', recorded: false }))).toBe('no_data');
        expect(indicatorChip(value({ status: 'normal' }))).toEqual({
            label: 'On target',
            variant: 'success',
        });
    });
});
