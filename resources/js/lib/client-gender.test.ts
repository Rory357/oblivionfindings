import { describe, expect, it } from 'vitest';

import {
    GENDER_OPTIONS,
    genderLabel,
    genderRecordedAsNotice,
    isGenderValue,
} from './client-gender';

describe('client gender options', () => {
    it('offers the Stats NZ options plus a decline option, in order', () => {
        expect(GENDER_OPTIONS.map((o) => o.label)).toEqual([
            'Female',
            'Male',
            'Another gender',
            'Prefer not to say',
        ]);
        // An empty value would crash the Radix Select.
        expect(
            GENDER_OPTIONS.every((o) => (o.value as string).trim() !== ''),
        ).toBe(true);
    });

    it('labels stored values, with the person’s words for Another gender', () => {
        expect(genderLabel('female')).toBe('Female');
        expect(genderLabel('prefer_not_to_say')).toBe('Prefer not to say');
        expect(genderLabel('another_gender', ' Takatāpui ')).toBe(
            'Another gender: Takatāpui',
        );
        expect(genderLabel('another_gender', '')).toBe('Another gender');
        expect(genderLabel('male', 'ignored')).toBe('Male');
        expect(genderLabel(null)).toBeNull();
        expect(genderLabel('Woman')).toBeNull();
    });

    it('knows which values are on the list', () => {
        expect(isGenderValue('another_gender')).toBe(true);
        expect(isGenderValue('non_binary')).toBe(false);
        expect(isGenderValue('')).toBe(false);
    });

    it('asks for a choice when an old typed answer is waiting', () => {
        expect(genderRecordedAsNotice('see notes')).toBe(
            'Gender was recorded as ‘see notes’. Please choose an option.',
        );
    });
});
