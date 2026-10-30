import { expect, it } from 'vitest';
import {
    matchesHouseRequirementResult,
    type HouseRequirementIntent,
} from './house-requirement-outcome';

const intent: HouseRequirementIntent = {
    actor_id: 4,
    site_id: 5,
    request_id: '6aaf2f78-3ec8-4bb3-aadc-cd20f7e42be7',
    action: 'created',
    requirement_id: null,
    values: {
        site_id: 5,
        requirement_name: 'First aid',
        category: 'mandatory',
        description: null,
        certification_required: true,
        expiry_period_months: 24,
        hr_compliance_requirement_id: 12,
        applicability_mode: 'minimum_staff',
        minimum_qualified_staff: 2,
        is_active: true,
    },
};
const receipt = () => ({
    version: 1,
    scope: 'house_requirement',
    ...intent,
    requirement_id: 7,
    changed: true,
    outcome: 'saved',
    committed_at: '2026-10-08T00:00:00.000Z',
    refresh: {
        source_type: 'site_staff_requirements',
        source_id: 7,
        source_fingerprint: 'a'.repeat(64),
        source_version: 3,
        site_id: 5,
    },
});
const page = (result: unknown) => ({
    props: {
        auth: { user: { id: 4 } },
        flash: { house_qualification_result: result },
        errors: {},
    },
});

it('accepts only the complete saved House rule for the current actor and attempt', () => {
    expect(matchesHouseRequirementResult(page(receipt()), intent)).toBe(true);
});
it.each([
    ['another attempt', { request_id: 'another' }],
    ['another actor', { actor_id: 8 }],
    ['another Site', { site_id: 6 }],
    ['a legacy result', { version: 0 }],
    ['a missing refresh', { refresh: null }],
    ['a false no-op create', { changed: false, outcome: 'unchanged' }],
    ['an uncommitted result', { committed_at: null }],
    [
        'an altered count',
        { values: { ...intent.values, minimum_qualified_staff: 1 } },
    ],
    ['missing recorded values', { values: null }],
    [
        'a recheck for another Site',
        { refresh: { ...receipt().refresh, site_id: 6 } },
    ],
])('keeps the draft for %s', (_label, patch) => {
    expect(
        matchesHouseRequirementResult(page({ ...receipt(), ...patch }), intent),
    ).toBe(false);
});
it('rejects a response from a changed session even when its result matches', () => {
    const response = page(receipt());
    response.props.auth.user.id = 8;
    expect(matchesHouseRequirementResult(response, intent)).toBe(false);
});
it('confirms an unchanged update without claiming a new eligibility refresh', () => {
    const update = { ...intent, action: 'updated' as const, requirement_id: 7 };
    expect(
        matchesHouseRequirementResult(
            page({
                ...receipt(),
                ...update,
                outcome: 'unchanged',
                changed: false,
                refresh: null,
            }),
            update,
        ),
    ).toBe(true);
    expect(
        matchesHouseRequirementResult(
            page({ ...receipt(), ...update, requirement_id: 8 }),
            update,
        ),
    ).toBe(false);
});
it('requires a confirmed removal and the removed rule’s matching recheck', () => {
    const remove = {
        ...intent,
        action: 'deleted' as const,
        requirement_id: 7,
        values: null,
    };
    const result = { ...receipt(), ...remove, outcome: 'deleted' };
    expect(matchesHouseRequirementResult(page(result), remove)).toBe(true);
    expect(
        matchesHouseRequirementResult(
            page({ ...result, refresh: null }),
            remove,
        ),
    ).toBe(false);
    expect(
        matchesHouseRequirementResult(
            page({ ...result, values: intent.values }),
            remove,
        ),
    ).toBe(false);
});
