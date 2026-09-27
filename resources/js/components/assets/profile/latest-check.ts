import type { ProfileWorkspace } from './types';

export function latestAssetCheck(data: ProfileWorkspace) {
    const observation = data.checks[0];
    const original = data.sources?.original_checks[0];
    if (
        original?.at &&
        (!observation || Date.parse(original.at) > Date.parse(observation.at))
    ) {
        return {
            id: original.id,
            at: original.at,
            by: original.by,
            result: original.outcome,
            notes: original.name,
            due: null,
        };
    }
    return observation;
}

export const checkNeedsAttention = (result: string) =>
    !['pass', 'passed', 'no_issue_recorded'].includes(result);
