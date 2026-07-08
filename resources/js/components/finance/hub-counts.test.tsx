import { describe, expect, it } from 'vitest';

import { tabCountBadge } from './finance-tabs';

/**
 * tabCountBadge formats a finance hub tab's row count for the strip badge.
 * A 0/absent count must yield no badge (an empty list reads clean — the page shows
 * its EmptyState instead), and large counts cap at 999+ so the chip stays tidy.
 */
describe('tabCountBadge', () => {
    it('omits the badge for zero or absent counts', () => {
        expect(tabCountBadge(0)).toBeUndefined();
        expect(tabCountBadge(undefined)).toBeUndefined();
    });

    it('renders a plain count up to 999', () => {
        expect(tabCountBadge(1)).toBe('1');
        expect(tabCountBadge(42)).toBe('42');
        expect(tabCountBadge(999)).toBe('999');
    });

    it('caps counts above 999 at 999+', () => {
        expect(tabCountBadge(1000)).toBe('999+');
        expect(tabCountBadge(12438)).toBe('999+');
    });
});
