import { describe, expect, it } from 'vitest';
import {
    medicationReturnTo,
    withMedicationReturn,
} from './medication-navigation';

describe('medication journey return context', () => {
    it('retains the originating workboard filters and fragment', () => {
        const source =
            '/meds/today?client_id=16&site_id=5&date=2026-10-07&view=activity#dose';
        const href = withMedicationReturn('/emar/mar?client_id=16', source);
        expect(
            medicationReturnTo(
                new URL(href, 'https://app.invalid').searchParams.get(
                    'return_to',
                ),
            ),
        ).toBe(source);
    });
    it.each([
        'https://evil.invalid',
        '//evil.invalid',
        '/\\evil.invalid',
        '/%2f%2fevil.invalid',
        '/emar/../logout',
        '/emar/%2e%2e/logout',
        '/logout',
        '/emar\n/orders',
    ])('rejects unsafe or unrelated return destinations: %s', (value) => {
        expect(medicationReturnTo(value)).toBeNull();
    });
    it('removes nested return destinations while retaining the actual source view', () => {
        expect(
            medicationReturnTo(
                '/emar/prn?date=2026-10-07&return_to=%2Fmeds%2Ftoday',
            ),
        ).toBe('/emar/prn?date=2026-10-07');
    });
});
