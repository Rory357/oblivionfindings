/**
 * Semantic grouping for the 22 client profile tabs.
 *
 * Keys here MUST match the `key` field on the ClientTab objects defined in
 * `show.tsx`. Tabs that aren't listed here fall into the "other" group at
 * the end (currently empty, but it's a guardrail for future additions).
 */
export type ClientTabGroupKey =
    | 'live'
    | 'care'
    | 'records'
    | 'logistics'
    | 'compliance'
    | 'other';

export type ClientTabGroup = {
    key: ClientTabGroupKey;
    label: string;
    tabKeys: string[];
};

export const CLIENT_TAB_GROUPS: ClientTabGroup[] = [
    {
        key: 'live',
        label: 'Live status',
        tabKeys: ['profile', 'onboarding', 'location'],
    },
    {
        key: 'care',
        label: 'Care delivery',
        tabKeys: ['medical', 'mar', 'meal_prefs', 'observations', 'care_plans', 'progress_notes'],
    },
    {
        key: 'records',
        label: 'Records',
        tabKeys: ['assessments', 'timeline', 'documents', 'photos'],
    },
    {
        key: 'logistics',
        label: 'Logistics',
        tabKeys: ['calendar', 'transport', 'personal_assets', 'respite'],
    },
    {
        key: 'compliance',
        label: 'Compliance & relationships',
        tabKeys: ['consents', 'consent-requests', 'service_agreements', 'portal', 'family_notes', 'assignments'],
    },
];

export function groupForTab(tabKey: string): ClientTabGroupKey {
    for (const group of CLIENT_TAB_GROUPS) {
        if (group.tabKeys.includes(tabKey)) {
            return group.key;
        }
    }
    return 'other';
}
