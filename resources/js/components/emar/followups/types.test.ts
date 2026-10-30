import { describe, expect, it } from 'vitest';
import { followupSourceUrl, type MedicationFollowup } from './types';

describe('source follow-up navigation', () => {
    it('opens only canonical local paths', () => {
        const row = (source_url: string) => ({ source_url } as MedicationFollowup);
        expect(followupSourceUrl(row('/emar/orders/12'))).toBe('/emar/orders/12');
        for (const url of ['https://other.test', 'javascript:alert(1)', '//other.test', '/\\other.test', '/\t/other.test']) {
            expect(followupSourceUrl(row(url))).toBeNull();
        }
    });
});
