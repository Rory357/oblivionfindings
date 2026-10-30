import { describe, expect, it } from 'vitest';

import { normalizeMedicationStockQuantityInput } from './medication-stock-governance';

// Moved from the retired ScheduledStockCounts contract test (EA-204, Lane C10).
describe('medication stock quantity input', () => {
    it('normalizes exact hundredths without truncating fractional stock', () => {
        expect(normalizeMedicationStockQuantityInput('9.5')).toBe('9.50');
        expect(normalizeMedicationStockQuantityInput('9.99')).toBe('9.99');
        expect(normalizeMedicationStockQuantityInput('9.999')).toBeNull();
        expect(normalizeMedicationStockQuantityInput('1e2')).toBeNull();
    });
});
