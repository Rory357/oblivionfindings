import { describe, expect, it } from 'vitest';

import {
    formatReadableOperationalState,
    formatReadableOperationalValue,
} from './readable-operational-state';

describe('readable operational state', () => {
    it('turns nested safe state into concise plain language without raw JSON', () => {
        const value = formatReadableOperationalState({
            expected_state: {
                connectivity: 'provider_confirmed',
                checks: [{ status: 'ready', retry_count: 2 }],
            },
            enabled: true,
        });

        expect(value).toBe(
            'Expected state: Connectivity: provider confirmed; Checks: 2 recorded fields · Enabled: Yes',
        );
        expect(value).not.toContain('{');
        expect(value).not.toContain('[');
        expect(value).not.toContain('"');
    });

    it('uses clear empty and boolean labels', () => {
        expect(formatReadableOperationalValue([])).toBe('None recorded');
        expect(formatReadableOperationalValue({})).toBe('None recorded');
        expect(formatReadableOperationalValue(false)).toBe('No');
        expect(formatReadableOperationalState({})).toBe('Not recorded');
    });
});
