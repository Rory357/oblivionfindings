/**
 * Maps a workflow action's (area, status) to a board-friendly verb.
 *
 * Cards never show generic "Open" labels — every priority gets a verb that
 * tells a non-technical board member exactly what they will do next.
 *
 * The backend may override the verb by setting `action_label` on the
 * workflow action; if absent we fall back to this map; if neither matches
 * we use the area-default below.
 */

export type WorkflowArea =
    | 'meeting'
    | 'resolution'
    | 'risk'
    | 'compliance'
    | 'budget'
    | 'spend'
    | 'action'
    | 'policy'
    | 'conflict'
    | 'ceo_report'
    | 'pack'
    | string;

export type WorkflowStatus = 'overdue' | 'due_soon' | 'pending' | string;

const VERBS: Record<string, Record<string, string>> = {
    meeting: {
        overdue: 'Approve minutes',
        due_soon: 'Review agenda',
        pending: 'Open meeting',
    },
    resolution: {
        overdue: 'Cast vote',
        due_soon: 'Cast vote',
        pending: 'Review resolution',
    },
    risk: {
        overdue: 'Acknowledge risk',
        due_soon: 'Review mitigation',
        pending: 'Open risk',
    },
    compliance: {
        overdue: 'Upload evidence',
        due_soon: 'Upload evidence',
        pending: 'Open obligation',
    },
    budget: {
        overdue: 'Approve budget',
        due_soon: 'Review budget',
        pending: 'Open budget',
    },
    spend: {
        overdue: 'Approve spend',
        due_soon: 'Approve spend',
        pending: 'Review request',
    },
    action: {
        overdue: 'Mark complete',
        due_soon: 'Update progress',
        pending: 'Assign owner',
    },
    policy: {
        overdue: 'Sign policy',
        due_soon: 'Sign policy',
        pending: 'Read policy',
    },
    conflict: {
        overdue: 'Declare conflict',
        due_soon: 'Declare conflict',
        pending: 'Open register',
    },
    ceo_report: {
        overdue: 'Submit report',
        due_soon: 'Finalise report',
        pending: 'Open report',
    },
    pack: {
        overdue: 'Upload pack',
        due_soon: 'Read board pack',
        pending: 'Read board pack',
    },
};

const AREA_DEFAULTS: Record<string, string> = {
    meeting: 'Open meeting',
    resolution: 'Review resolution',
    risk: 'Open risk',
    compliance: 'Open obligation',
    budget: 'Open budget',
    spend: 'Review request',
    action: 'Open action',
    policy: 'Read policy',
    conflict: 'Open register',
    ceo_report: 'Open report',
    pack: 'Read board pack',
};

/**
 * Resolve the verb for a given area + status, with an optional override.
 * Override wins (backend can ship its own copy via `action_label`).
 */
export function resolveActionVerb(
    area: WorkflowArea,
    status: WorkflowStatus,
    override?: string | null,
): string {
    if (
        override &&
        override.trim().length > 0 &&
        !/^open$/i.test(override.trim())
    ) {
        return override;
    }
    const areaKey = normaliseArea(area);
    const statusKey = normaliseStatus(status);
    const verb = VERBS[areaKey]?.[statusKey] ?? AREA_DEFAULTS[areaKey];
    if (verb) return verb;
    return override ?? 'Review';
}

function normaliseArea(area: WorkflowArea): string {
    const lower = String(area ?? '')
        .toLowerCase()
        .trim();
    if (lower.startsWith('meeting')) return 'meeting';
    if (lower.startsWith('resolution') || lower.startsWith('vote'))
        return 'resolution';
    if (lower.startsWith('risk')) return 'risk';
    if (lower.startsWith('compliance') || lower.startsWith('obligation'))
        return 'compliance';
    if (lower.startsWith('budget')) return 'budget';
    if (lower.startsWith('spend')) return 'spend';
    if (lower.startsWith('action')) return 'action';
    if (lower.startsWith('policy') || lower.startsWith('policies'))
        return 'policy';
    if (lower.startsWith('conflict') || lower.startsWith('interest'))
        return 'conflict';
    if (lower.startsWith('ceo')) return 'ceo_report';
    if (lower.startsWith('pack')) return 'pack';
    return lower;
}

function normaliseStatus(status: WorkflowStatus): string {
    const lower = String(status ?? '')
        .toLowerCase()
        .trim();
    if (lower === 'overdue' || lower === 'late') return 'overdue';
    if (lower === 'due_soon' || lower === 'due-soon' || lower === 'soon')
        return 'due_soon';
    return 'pending';
}
