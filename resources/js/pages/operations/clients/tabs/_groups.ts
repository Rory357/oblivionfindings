/**
 * Semantic grouping for the phase-one 20-tab client profile rail.
 *
 * Keys here match the visible top-level ClientTab keys in `show.tsx`. Legacy
 * tabs remain reachable as folded related sections, but do not appear in the
 * primary web navigation rail.
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
        tabKeys: ['profile', 'personal_details'],
    },
    {
        key: 'daily',
        label: 'Daily care',
        tabKeys: [
            'progress_notes',
            'timeline',
            'communication_notes',
            'rhythms_routines',
        ],
    },
    {
        key: 'plans',
        label: 'Plans & goals',
        tabKeys: ['care_plans', 'goals_path', 'observations'],
    },
    {
        key: 'health',
        label: 'Health & safety',
        tabKeys: [
            'health_monitoring',
            'incidents_accidents',
            'risk_management',
        ],
    },
    {
        key: 'operations',
        label: 'Day-to-day operations',
        tabKeys: [
            'calendar',
            'leave_excursions',
            'personal_assets',
            'finance',
            'documents',
        ],
    },
    {
        key: 'governance',
        label: 'Relationships & governance',
        tabKeys: ['family_tree', 'actions_reviews', 'audit_history'],
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
