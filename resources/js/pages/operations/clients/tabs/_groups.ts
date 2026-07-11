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
            'privacy',
        ],
    },
];

const CLIENT_TAB_ALIASES: Record<string, string> = {
    support_plan: 'care_plans',
};

export function canonicalProfileTab(tabKey: string): string {
    return CLIENT_TAB_ALIASES[tabKey] ?? tabKey;
}

export function updateClientProfileQuery(
    values: Record<string, string | null>,
    mode: 'push' | 'replace' = 'push',
): void {
    if (typeof window === 'undefined') return;

    const url = new URL(window.location.href);
    Object.entries(values).forEach(([key, value]) => {
        if (value === null) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    });
    const method = mode === 'push' ? 'pushState' : 'replaceState';
    window.history[method]({}, '', url.toString());
}

export function resolveVisibleProfileTab(
    requestedTab: string,
    groups: Array<{ key: string; tabs: Array<{ key: string }> }>,
): string {
    const canonicalTab = canonicalProfileTab(requestedTab);
    if (
        groups.some((group) =>
            group.tabs.some((tab) => tab.key === canonicalTab),
        )
    ) {
        return canonicalTab;
    }

    const requestedGroup = groupForTab(canonicalTab);
    return (
        groups.find((group) => group.key === requestedGroup)?.tabs[0]?.key ??
        groups[0]?.tabs[0]?.key ??
        'profile'
    );
}

export function profileDialogFromSearch(search: string): {
    key: string;
    ctx?: Record<string, unknown>;
} | null {
    const params = new URLSearchParams(search);
    const key = params.get('dialog')?.trim();
    if (!key) return null;

    const record = params.get('record');
    if (!record) return { key };

    const recordId = Number(record);
    return Number.isSafeInteger(recordId) && recordId > 0
        ? { key, ctx: { recordId } }
        : { key };
}

type ProfileDialogRecord = Record<string, unknown> & {
    id: string | number;
};

export type ProfileDialogRecordSources = {
    carePlans?: ProfileDialogRecord[];
    goals?: ProfileDialogRecord[];
    risks?: ProfileDialogRecord[];
    carePlanContext?: Record<string, unknown>;
};

function recordWithId(
    records: ProfileDialogRecord[] | undefined,
    recordId: number,
): ProfileDialogRecord | undefined {
    return records?.find((record) => Number(record.id) === recordId);
}

/**
 * Turn a shareable `dialog` + `record` URL into the context expected by the
 * matching in-profile dialog. Goal and ABC dialogs fetch their full detail
 * from an id stub; collection-backed edit dialogs must resolve a record before
 * opening so a stale link can never fall through into create mode.
 */
export function profileDialogStateFromSearch(
    search: string,
    sources: ProfileDialogRecordSources = {},
): {
    key: string;
    ctx?: Record<string, unknown>;
} | null {
    const dialog = profileDialogFromSearch(search);
    const recordId = dialog?.ctx?.recordId;
    if (!dialog || typeof recordId !== 'number') return dialog;

    if (dialog.key === 'goal') {
        return {
            ...dialog,
            ctx: {
                ...dialog.ctx,
                goal: recordWithId(sources.goals, recordId) ?? {
                    id: recordId,
                },
            },
        };
    }

    if (dialog.key === 'abc') {
        return {
            ...dialog,
            ctx: { ...dialog.ctx, entry: { id: recordId } },
        };
    }

    if (dialog.key === 'emar') {
        return {
            ...dialog,
            ctx: { ...dialog.ctx, medicationId: recordId },
        };
    }

    if (dialog.key === 'care_plan') {
        const plan = recordWithId(sources.carePlans, recordId);
        if (!plan) return null;

        return {
            ...dialog,
            ctx: {
                ...dialog.ctx,
                plan,
                ...sources.carePlanContext,
            },
        };
    }

    if (dialog.key === 'edit_risk') {
        const risk = recordWithId(sources.risks, recordId);
        if (!risk) return null;

        return {
            ...dialog,
            ctx: { ...dialog.ctx, risk },
        };
    }

    return dialog;
}

export function profileDialogQuery(
    key: string,
    context?: Record<string, unknown>,
): Record<string, string | null> {
    const directId = context?.recordId ?? context?.id ?? context?.medicationId;
    const nestedId = [
        'record',
        'goal',
        'entry',
        'plan',
        'risk',
        'asset',
        'appointment',
        'document',
        'note',
        'item',
    ]
        .map((name) => context?.[name])
        .find(
            (value): value is { id: string | number } =>
                typeof value === 'object' &&
                value !== null &&
                'id' in value &&
                ['string', 'number'].includes(typeof value.id),
        )?.id;
    const recordId = directId ?? nestedId;

    return {
        dialog: key,
        record:
            typeof recordId === 'string' || typeof recordId === 'number'
                ? String(recordId)
                : null,
    };
}

export function groupForTab(tabKey: string): ClientTabGroupKey {
    const canonicalTabKey = canonicalProfileTab(tabKey);

    for (const group of CLIENT_TAB_GROUPS) {
        if (group.tabKeys.includes(canonicalTabKey)) {
            return group.key;
        }
    }
    return 'other';
}
