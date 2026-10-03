import { describe, expect, it } from 'vitest';
import { supportTimeCandidates } from './time';
import { allowedModes, assessmentCap } from './types';

describe('approved medication support rules', () => {
    it('preserves the existing five-score boundaries and consent-first outcome', () => {
        expect(
            [10, 11, 15, 16, 20, 21, 25].map((n) =>
                assessmentCap(true, true, n),
            ),
        ).toEqual([
            'staff_given',
            'assisted',
            'assisted',
            'prompted',
            'prompted',
            'self_managed',
            'self_managed',
        ]);
        expect(assessmentCap(false, true, 25)).toBe('staff_given');
        expect(assessmentCap(true, false, 25)).toBe('staff_given');
    });
    it('limits choices by the assessment and controlled medicine ceiling', () => {
        expect(allowedModes('self_managed', true)).toEqual([
            'assisted',
            'staff_given',
        ]);
        expect(allowedModes('prompted', false)).toEqual([
            'prompted',
            'assisted',
            'staff_given',
        ]);
        expect(allowedModes('staff_given', false)).toEqual(['staff_given']);
    });
    it('preserves exact NZ minutes and requires a choice in the repeated hour', () => {
        expect(supportTimeCandidates('2026-09-27T02:30')).toEqual([]);
        expect(supportTimeCandidates('2026-04-05T02:37')).toEqual([
            '2026-04-05T02:37+13:00',
            '2026-04-05T02:37+12:00',
        ]);
        expect(supportTimeCandidates('2026-10-03T07:19')).toEqual([
            '2026-10-03T07:19+13:00',
        ]);
    });
});
