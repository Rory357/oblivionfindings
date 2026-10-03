import { describe, expect, it } from 'vitest';
import { emergencyDurationOptions, emergencyTimeLeft } from './emergency-access';
import type { EmergencyPolicy } from '@/pages/emergency/_types';

const policy: EmergencyPolicy = { default_minutes: 45, max_minutes: 90, extend_minutes: 15, second_person: 'optional', review_days: 2, reason_required: true, repeat_threshold_count: 4, repeat_window_days: 7 };
describe('Emergency access policy and countdown', () => {
    it('offers the current default and cap without offering a longer grant', () => {
        expect(emergencyDurationOptions(policy)).toEqual([30, 45, 60, 90]);
        expect(emergencyDurationOptions({ ...policy, default_minutes: 5, max_minutes: 5 })).toEqual([5]);
    });
    it('warns at ten minutes and handles expiry without negative time', () => {
        const end = '2026-10-03T09:00:00Z';
        expect(emergencyTimeLeft(end, Date.parse('2026-10-03T08:50:00Z'))).toEqual({ ended: false, warning: true, label: '10:00' });
        expect(emergencyTimeLeft(end, Date.parse(end))).toEqual({ ended: true, warning: false, label: 'Ended' });
        expect(emergencyTimeLeft('invalid', 1).ended).toBe(true);
    });
});
