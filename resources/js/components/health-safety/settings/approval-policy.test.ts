import { describe, expect, it } from 'vitest';
import {
    confirmsApprovalPolicyReceipt,
    historicalPolicyLabel,
} from './approval-policy';

const input = {
    mode: 'authorised_reviewer' as const,
    expected_policy_revision: 3,
    idempotency_key: '799b6530-9779-42c0-9f8e-7d0b1c777916',
};
const key = 'healthSafety.restraints.plan_approval_policy';
const receipt = {
    accepted: true,
    ...input,
    setting_key: key,
    setting_change_id: 12,
    policy_revision: 4,
    schema_version: 1,
    policy_snapshot: { schema_version: 1, mode: input.mode },
    policy_digest: 'a'.repeat(64),
    action: 'changed',
    origin: 'organisation_setting',
    recorded_at: '2026-10-11T08:00:00Z',
    replayed: false,
};

describe('Approval policy acceptance', () => {
    it('accepts the exact saved command and its exact retained replay', () => {
        expect(confirmsApprovalPolicyReceipt(receipt, input, key)).toBe(true);
        expect(
            confirmsApprovalPolicyReceipt(
                { ...receipt, replayed: true },
                input,
                key,
            ),
        ).toBe(true);
    });
    it.each([
        { accepted: false },
        { idempotency_key: 'another-command' },
        { setting_key: 'another-setting' },
        { mode: 'separate_specialist' },
        { policy_revision: 3 },
        { setting_change_id: null },
        { policy_snapshot: { schema_version: 1, mode: 'separate_specialist' } },
        {
            policy_snapshot: {
                schema_version: 1,
                mode: input.mode,
                legacy: true,
            },
        },
        { policy_digest: 'unknown' },
        { origin: 'application_default' },
        { recorded_at: '2026-10-11T08:00:00' },
        { recorded_at: 'unknown' },
        { replayed: undefined },
    ])(
        'does not show success for mismatched or incomplete retained proof %j',
        (override) => {
            expect(
                confirmsApprovalPolicyReceipt(
                    { ...receipt, ...override },
                    input,
                    key,
                ),
            ).toBe(false);
        },
    );
    it('does not infer an approval from an empty workflow or unsafe reviewed revision', () => {
        expect(
            confirmsApprovalPolicyReceipt(
                { ...receipt, mode: '' },
                { ...input, mode: '' },
                key,
            ),
        ).toBe(false);
        expect(
            confirmsApprovalPolicyReceipt(
                receipt,
                {
                    ...input,
                    expected_policy_revision: Number.MAX_SAFE_INTEGER + 1,
                },
                key,
            ),
        ).toBe(false);
    });
});

it('retains unknown historical policy as unknown without exposing arbitrary stored data', () => {
    expect(historicalPolicyLabel(null)).toBe('No policy value recorded');
    expect(
        historicalPolicyLabel({
            schema_version: 1,
            mode: 'separate_specialist',
        }),
    ).toBe('Separate specialist decision');
    expect(
        historicalPolicyLabel({
            schema_version: 1,
            mode: 'authorised_reviewer',
        }),
    ).toBe('Authorised reviewer decision');
    for (const value of [
        'private malformed content',
        ['private'],
        { mode: 'unknown' },
        {
            schema_version: 1,
            mode: 'separate_specialist',
            private: 'private malformed content',
        },
    ]) {
        expect(historicalPolicyLabel(value)).toBe('Invalid configuration');
    }
});
