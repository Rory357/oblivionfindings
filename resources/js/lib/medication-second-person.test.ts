import { forgottenPinAvailable } from '@/components/emar/record-dose/fields';
import type { DoseRequirements } from '@/components/emar/record-dose/types';
import { describe, expect, it } from 'vitest';
import { secondPersonDisplay } from './medication-second-person';
describe('second-person evidence', () => {
    it.each(['pending', 'disputed', 'expired'] as const)(
        'never describes %s as PIN verified',
        (status) => {
            const result = secondPersonDisplay({
                second_person_status: 'not_verified',
                witness_method: 'pin_forgotten',
                second_person_confirmation: {
                    id: 3,
                    status,
                    nominated_name: 'Mere',
                    due_at: '2026-10-06T02:00:00Z',
                },
            });
            expect(result?.tone).not.toBe('success');
            expect(result?.label).not.toContain('PIN');
        },
    );
    it('uses own-account confirmation instead of inventing a PIN check', () => {
        expect(
            secondPersonDisplay({
                second_person_status: 'verified',
                witness_method: 'own_session_confirmation',
                witness: 'Mere',
            })?.label,
        ).toBe('Confirmed in their own account');
    });
    it('does not turn a nominated person into a witness', () => {
        expect(
            secondPersonDisplay({
                second_person_status: 'not_verified',
                witness_method: 'pin_forgotten',
                witness: 'Mere',
            })?.label,
        ).toBe('Second-person confirmation pending');
    });
    it('keeps controlled and explicit witnessing outside the fallback even if a stale flag says allowed', () => {
        const req = {
            second_person: {
                forgotten_pin_allowed: true,
                forgotten_pin_amount_allowed: true,
                candidates: [{ id: 2, can_confirm: true }],
            },
            order: { controlled: true, witness_required: false },
        } as DoseRequirements;
        expect(forgottenPinAvailable(req, 'rule', 2)).toBe(false);
        req.order.controlled = false;
        req.order.witness_required = true;
        expect(forgottenPinAvailable(req, 'amount', 2)).toBe(false);
        req.order.witness_required = false;
        expect(forgottenPinAvailable(req, 'witness', 2)).toBe(false);
        expect(forgottenPinAvailable(req, 'rule', 9)).toBe(false);
        expect(forgottenPinAvailable(req, 'amount', 2)).toBe(true);
        req.second_person.forgotten_pin_amount_allowed = false;
        expect(forgottenPinAvailable(req, 'amount', 2)).toBe(false);
    });
});
