import type { ResponsePolicy } from './chat-response-settings';

/** Keep only this editor's actual field changes; the administrator reviews them against latest before saving. */
export function reviewLatestChatSettings<
    T extends ResponsePolicy & { version: number },
>(before: T, draft: T, latest: T): T {
    const result = structuredClone(latest);
    for (const field of Object.keys(draft) as (keyof T)[]) {
        if (field === 'version' || field === 'site_overrides') continue;
        if (JSON.stringify(before[field]) !== JSON.stringify(draft[field]))
            result[field] = structuredClone(draft[field]);
    }
    for (const site of new Set([
        ...Object.keys(before.site_overrides),
        ...Object.keys(draft.site_overrides),
    ])) {
        const old = before.site_overrides[site] ?? {};
        const proposed = draft.site_overrides[site] ?? {};
        const effective = { ...result.site_overrides[site] };
        for (const field of new Set([
            ...Object.keys(old),
            ...Object.keys(proposed),
        ])) {
            if (JSON.stringify(old[field]) === JSON.stringify(proposed[field]))
                continue;
            if (!Object.hasOwn(proposed, field)) delete effective[field];
            else
                Object.assign(effective, {
                    [field]: structuredClone(proposed[field]),
                });
        }
        if (Object.keys(effective).length)
            result.site_overrides[site] = effective;
        else delete result.site_overrides[site];
    }
    return result;
}
