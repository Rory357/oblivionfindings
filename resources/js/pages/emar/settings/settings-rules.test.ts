import { describe, expect, it } from 'vitest';
import { ruleNeeds, ruleSentence, ruleWhat } from './_rules';

/** The builder's live sentence must read exactly like the server's
 * MedicineRuleWording, which the change history and the list use. */
describe('medicine rule wording — the same words as the server', () => {
    it('names what the rule applies to', () => {
        expect(
            ruleWhat({
                match_type: 'medicine_name',
                match_value: 'Insulin glargine',
            }),
        ).toBe('Insulin glargine');
        expect(
            ruleWhat({
                match_type: 'route',
                match_value: 'Subcutaneous injection',
            }),
        ).toBe('any medicine given by subcutaneous injection');
        expect(
            ruleWhat({ match_type: 'nzulm_code', match_value: '9000052' }),
        ).toBe('the product with NZULM code 9000052');
        expect(ruleWhat({ match_type: 'controlled', match_value: '' })).toBe(
            'any controlled medicine',
        );
    });

    it('says what a dose needs, and asks for something when nothing is on', () => {
        expect(
            ruleNeeds({
                requires_countersign: true,
                required_observations: ['blood_glucose'],
            }),
        ).toBe(
            'a second person confirms with their witness PIN and record blood sugar (BSL)',
        );
        expect(
            ruleNeeds({
                requires_countersign: false,
                required_observations: [],
            }),
        ).toBe('choose what it requires');
    });

    it('builds the sentences the server records', () => {
        expect(
            ruleSentence(
                {
                    match_type: 'medicine_name',
                    match_value: 'Insulin glargine',
                    site_id: null,
                    requires_countersign: false,
                    required_observations: ['blood_glucose'],
                },
                'All houses',
            ),
        ).toBe(
            'Before saving a dose of Insulin glargine at All houses: record blood sugar (BSL).',
        );
        expect(
            ruleSentence(
                {
                    match_type: 'controlled',
                    match_value: '',
                    site_id: null,
                    requires_countersign: true,
                    required_observations: [],
                },
                'All houses',
            ),
        ).toBe(
            'Before saving a dose of any controlled medicine at All houses: a second person confirms with their witness PIN.',
        );
    });
});
