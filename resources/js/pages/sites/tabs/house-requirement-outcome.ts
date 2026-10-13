import { object } from '@/components/rostering/workforce-settings-outcome';

export type HouseRequirementValues = {
    site_id: number;
    requirement_name: string;
    category: string;
    description: string | null;
    certification_required: boolean;
    expiry_period_months: number | null;
    hr_compliance_requirement_id: number | null;
    applicability_mode: 'all_workers' | 'minimum_staff' | null;
    minimum_qualified_staff: number | null;
    is_active: boolean;
};
export type HouseRequirementIntent = {
    actor_id: number;
    site_id: number;
    request_id: string;
    action: 'created' | 'updated' | 'deleted';
    requirement_id: number | null;
    values: HouseRequirementValues | null;
};
const positiveId = (value: unknown) =>
    Number.isSafeInteger(value) && Number(value) > 0;

/** A redirect alone cannot confirm that the requested House rule was stored. */
export function matchesHouseRequirementResult(
    page: unknown,
    expected: HouseRequirementIntent,
): boolean {
    const props = object(object(page)?.props);
    const flash = object(props?.flash);
    const result = object(flash?.house_qualification_result);
    if (
        !positiveId(expected.actor_id) ||
        !positiveId(expected.site_id) ||
        !result ||
        object(object(props?.auth)?.user)?.id !== expected.actor_id ||
        flash?.error ||
        Object.keys(object(props?.errors) ?? {}).length ||
        result.version !== 1 ||
        result.scope !== 'house_requirement' ||
        result.actor_id !== expected.actor_id ||
        result.site_id !== expected.site_id ||
        result.request_id !== expected.request_id ||
        result.action !== expected.action ||
        !positiveId(result.requirement_id) ||
        (expected.requirement_id !== null &&
            result.requirement_id !== expected.requirement_id) ||
        typeof result.committed_at !== 'string' ||
        !/Z$/.test(result.committed_at) ||
        !Number.isFinite(Date.parse(result.committed_at))
    )
        return false;

    if (expected.action === 'deleted') {
        if (
            result.values !== null ||
            result.outcome !== 'deleted' ||
            result.changed !== true
        )
            return false;
    } else {
        const values = object(result.values);
        if (
            !values ||
            !expected.values ||
            Object.keys(values).length !==
                Object.keys(expected.values).length ||
            Object.entries(expected.values).some(
                ([key, value]) => values[key] !== value,
            ) ||
            !(
                (result.outcome === 'saved' && result.changed === true) ||
                (expected.action === 'updated' &&
                    result.outcome === 'unchanged' &&
                    result.changed === false)
            )
        )
            return false;
    }
    const refresh = object(result.refresh);
    if (refresh) {
        return (
            refresh.source_type === 'site_staff_requirements' &&
            refresh.source_id === result.requirement_id &&
            refresh.site_id === expected.site_id &&
            positiveId(refresh.source_version) &&
            typeof refresh.source_fingerprint === 'string' &&
            /^[a-f0-9]{64}$/.test(refresh.source_fingerprint)
        );
    }
    // Description-only and unchanged updates may leave eligibility unchanged.
    return expected.action === 'updated' && result.refresh === null;
}
