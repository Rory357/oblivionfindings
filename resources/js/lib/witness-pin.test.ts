import { describe, expect, it } from 'vitest';

import {
    OWN_WITNESS_PIN_PROMPT,
    sanitiseWitnessPin,
    witnessIsSelectable,
    witnessOptionLabel,
} from './witness-pin';

describe('witness PIN helpers (PIN-1)', () => {
    it('only lets colleagues with a usable PIN be chosen', () => {
        expect(
            witnessIsSelectable({ id: 1, name: 'Aroha', witness_pin: 'set' }),
        ).toBe(true);
        for (const status of [
            'not_set',
            'locked',
            'reset',
            'expired',
        ] as const) {
            expect(
                witnessIsSelectable({
                    id: 1,
                    name: 'Aroha',
                    witness_pin: status,
                }),
            ).toBe(false);
        }
        // Older payloads without a status stay selectable; the server still
        // rejects a witness with no PIN.
        expect(witnessIsSelectable({ id: 1, name: 'Aroha' })).toBe(true);
    });

    it('explains why a colleague cannot be chosen', () => {
        expect(
            witnessOptionLabel({ id: 1, name: 'Mere', witness_pin: 'set' }),
        ).toBe('Mere');
        expect(
            witnessOptionLabel({ id: 1, name: 'Mere', witness_pin: 'not_set' }),
        ).toBe('Mere — no witness PIN set');
        expect(
            witnessOptionLabel({ id: 1, name: 'Mere', witness_pin: 'locked' }),
        ).toBe('Mere — witness PIN locked');
    });

    it('keeps only up to six digits', () => {
        expect(sanitiseWitnessPin('48 29-15')).toBe('482915');
        expect(sanitiseWitnessPin('12345678')).toBe('123456');
        expect(sanitiseWitnessPin('abc')).toBe('');
    });

    it('prompts the viewer for every unusable state', () => {
        expect(Object.keys(OWN_WITNESS_PIN_PROMPT).sort()).toEqual([
            'expired',
            'locked',
            'not_set',
            'reset',
        ]);
    });
});
