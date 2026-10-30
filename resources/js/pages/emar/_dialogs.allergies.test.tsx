import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import {
    loadOrderAllergies,
    OrderAllergyNotice,
    parseOrderAllergies,
} from './_dialogs';

afterEach(cleanup);

describe('new-order allergy check', () => {
    it('reads the combined register + health-profile list the endpoint returns', () => {
        expect(
            parseOrderAllergies({
                allergies: [
                    { id: 1, allergen: 'Penicillin', severity: 'severe' },
                ],
                recorded_allergies: [
                    {
                        allergen: 'Penicillin',
                        severity: 'severe',
                        source: 'medication_register',
                    },
                    {
                        allergen: 'Codeine / Opioids',
                        severity: null,
                        source: 'health_profile',
                    },
                    { allergen: '   ' },
                ],
            }),
        ).toEqual([
            {
                allergen: 'Penicillin',
                severity: 'severe',
                source: 'medication_register',
            },
            {
                allergen: 'Codeine / Opioids',
                severity: null,
                source: 'health_profile',
            },
        ]);
    });

    it('falls back to the register list, and never treats a missing list as none recorded', () => {
        expect(
            parseOrderAllergies({ allergies: [{ allergen: 'Latex' }] }),
        ).toEqual([{ allergen: 'Latex', severity: null, source: null }]);
        expect(parseOrderAllergies({ recorded_allergies: [] })).toEqual([]);
        expect(parseOrderAllergies({ data: [] })).toBe('unavailable');
        expect(parseOrderAllergies(null)).toBe('unavailable');
    });

    it('reports a failed request as unavailable, not as an empty record', async () => {
        await expect(
            loadOrderAllergies(7, () => Promise.reject(new Error('offline'))),
        ).resolves.toBe('unavailable');
        await expect(
            loadOrderAllergies(7, (url) => {
                expect(url).toBe('/api/medications/clients/7/allergies');
                return Promise.resolve({
                    data: { recorded_allergies: [{ allergen: 'Penicillin' }] },
                });
            }),
        ).resolves.toEqual([
            { allergen: 'Penicillin', severity: null, source: null },
        ]);
    });

    it('says the record could not be loaded on error, and never "none recorded"', () => {
        render(<OrderAllergyNotice allergies="unavailable" />);

        expect(
            screen.getByText(/Allergy record couldn.t be loaded/),
        ).toBeTruthy();
        expect(screen.queryByText(/No allergies recorded/)).toBeNull();
    });

    it('states an empty record plainly rather than as a safety reassurance', () => {
        render(<OrderAllergyNotice allergies={[]} />);

        expect(screen.getByText('No allergies recorded.')).toBeTruthy();
        expect(
            screen.queryByText(/No recorded allergies for this client/),
        ).toBeNull();
    });

    it('lists recorded allergies, and flags a name match', () => {
        const penicillin = {
            allergen: 'Penicillin',
            severity: 'severe',
            source: 'medication_register',
        };
        const { rerender } = render(
            <OrderAllergyNotice allergies={[penicillin]} />,
        );
        expect(screen.getByText(/Recorded allergies: Penicillin/)).toBeTruthy();

        rerender(
            <OrderAllergyNotice allergies={[penicillin]} clash={penicillin} />,
        );
        expect(screen.getByText(/Allergy alert:/)).toBeTruthy();
    });
});
