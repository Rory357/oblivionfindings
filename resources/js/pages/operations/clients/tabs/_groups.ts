/**
 * Grouped two-tier navigation registry for the client profile redesign.
 *
 * Six groups × ~35 first-class tabs (previously 20 top-level + folded
 * sub-tabs). Keys match the `TabKey` union in `show.tsx`, so existing
 * `?tab=` deep links keep working. Labels/icons/counts/visibility come from
 * the `tabs` array in `show.tsx` — this file is the canonical key → group
 * mapping consumed by the hero group pills, tier-2 tabs and the search
 * palette.
 */
export type ClientTabGroupKey =
    | 'snapshot'
    | 'daily'
    | 'plans'
    | 'health'
    | 'operations'
    | 'governance'
    | 'other';

export type ClientTabGroup = {
    key: ClientTabGroupKey;
    label: string;
    tabKeys: string[];
};

export const CLIENT_TAB_GROUPS: ClientTabGroup[] = [
    {
        key: 'snapshot',
        label: 'Snapshot',
        tabKeys: [
            'profile',
            'personal_details',
            'onboarding',
            'location',
            'assignments',
        ],
    },
    {
        key: 'daily',
        label: 'Daily care',
        tabKeys: [
            'progress_notes',
            'timeline',
            'communication_notes',
            'family_notes',
            'rhythms_routines',
            'meal_prefs',
        ],
    },
    {
        key: 'plans',
        label: 'Plans & goals',
        tabKeys: ['care_plans', 'goals_path', 'observations', 'assessments'],
    },
    {
        key: 'health',
        label: 'Health & safety',
        tabKeys: [
            'health_monitoring',
            'medical',
            'mar',
            'incidents_accidents',
            'first_aid',
            'risk_management',
        ],
    },
    {
        key: 'operations',
        label: 'Day-to-day',
        tabKeys: [
            'calendar',
            'transport',
            'leave_excursions',
            'respite',
            'personal_assets',
            'finance',
            'service_agreements',
            'documents',
            'photos',
        ],
    },
    {
        key: 'governance',
        label: 'Relationships & governance',
        tabKeys: [
            'family_tree',
            'consents',
            'consent-requests',
            'portal',
            'actions_reviews',
            'audit_history',
        ],
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
