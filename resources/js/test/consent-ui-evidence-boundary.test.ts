import {
    PROFILE_FLOWS,
    type ProfileFlowContext,
} from '@/components/clients/profile/flows';
import { buildDirectConsentPayload } from '@/pages/operations/clients/consents/Index';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
        put: vi.fn(),
        reload: vi.fn(),
    },
    Head: () => null,
    usePage: () => ({ props: { labels: {} } }),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const context: ProfileFlowContext = {
    clientId: 41,
    clientLabel: 'Client',
    preferredName: 'Aroha',
    staffOptions: [],
    goalOptions: [],
    consentTypeOptions: [{ value: '7', label: 'Care information' }],
    fundOptions: [],
    carePlanId: null,
    carePlanTitle: null,
    onboardingWorkflowId: null,
    canSendFamilyChat: false,
};

describe('direct-consent evidence boundary', () => {
    beforeEach(() => vi.clearAllMocks());

    it('does not offer or submit capacity assertions from the profile wizard', () => {
        const flow = PROFILE_FLOWS.consent_record(context);
        const fieldKeys = flow.steps.flatMap((step) =>
            (step.fields ?? []).map((field) => field.key),
        );

        expect(fieldKeys).not.toContain('capacity_assessed');
        expect(fieldKeys).not.toContain('capacity_outcome');
        expect(fieldKeys).not.toContain('best_interests_decision');

        flow.submit(
            {
                status: 'given',
                consent_type_id: '7',
                given_method: 'written',
                given_at: '2026-08-21',
                given_by_relationship: 'self',
                capacity_assessed: true,
                capacity_outcome: 'lacks_capacity',
            },
            { onDone: vi.fn(), onError: vi.fn() },
        );

        const payload = vi.mocked(router.post).mock.calls[0][1];
        expect(payload).not.toHaveProperty('capacity_assessed');
        expect(payload).not.toHaveProperty('capacity_outcome');
        expect(payload).not.toHaveProperty('best_interests_decision');
    });

    it('allowlists the retained Record Consent dialog payload', () => {
        const forgedForm = {
            consent_type_id: '7',
            status: 'given',
            given_method: 'written',
            given_at: '2026-08-21',
            given_by_relationship: 'self',
            given_notes: 'Direct consent recorded.',
            expires_at: '',
            evidence_type: 'signed_form',
            refusal_reason: '',
            capacity_assessed: true,
            capacity_outcome: 'lacks_capacity',
            best_interests_decision: true,
        };
        const payload = buildDirectConsentPayload(forgedForm, null);

        expect(payload).not.toHaveProperty('capacity_assessed');
        expect(payload).not.toHaveProperty('capacity_outcome');
        expect(payload).not.toHaveProperty('best_interests_decision');
        expect(payload).toMatchObject({
            consent_type_id: '7',
            given_method: 'written',
            evidence_type: 'signed_form',
        });
    });
});
