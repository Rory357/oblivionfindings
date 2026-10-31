import { formatDateOnly } from '@/lib/datetime';
export const PROCEDURE_REGISTER = '/health-safety/procedures';
export const PROCEDURE_SECTIONS = [
    'overview',
    'steps',
    'ppe',
    'applies',
    'documents',
    'history',
    'connections',
] as const;
export type ProcedureSection = (typeof PROCEDURE_SECTIONS)[number];
export const PROCEDURE_COMMANDS = [
    'submit-review',
    'approve',
    'request-changes',
    'record-review',
    'archive',
    'restore',
    'acknowledge',
] as const;
export type ProcedureCommand = (typeof PROCEDURE_COMMANDS)[number];

const LIST_KEYS: Record<string, string[]> = {
    '/incidents': [
        'tab',
        'q',
        'site_id',
        'client_id',
        'severity',
        'source',
        'kind',
        'from',
        'to',
        'page',
    ],
    '/health-safety/events': [
        'tab',
        'q',
        'site_id',
        'severity',
        'category',
        'source',
        'from',
        'to',
        'priority',
        'unassigned',
        'page',
    ],
    '/health-safety/corrective-actions': [
        'tab',
        'q',
        'site_id',
        'status',
        'priority',
        'assigned_to',
        'overdue',
        'awaiting_verification',
        'from',
        'to',
        'page',
    ],
    '/compliance/hazards': [
        'tab',
        'q',
        'site_id',
        'site_type',
        'severity',
        'risk_rating',
        'assignee_id',
        'due_state',
        'from',
        'to',
        'page',
    ],
    '/health-safety/risk-assessments': [
        'tab',
        'status',
        'risk_level',
        'due_for_review',
        'risk_acceptable',
        'site_id',
        'client_id',
        'hs_event_id',
        'search',
        'page',
    ],
    [PROCEDURE_REGISTER]: [
        'tab',
        'q',
        'category',
        'status',
        'site_id',
        'review_state',
        'page',
    ],
    '/tasks': [
        'q',
        'sources',
        'severity',
        'bucket',
        'assigned',
        'overdue',
        'due',
        'following',
        'done',
        'site_id',
        'page',
    ],
    '/my-day': [],
    '/hr/my': [],
};
function boundedReturn(
    value: string | null | undefined,
    allowRecord: boolean,
): string | null {
    if (
        !value ||
        value.length > 1500 ||
        /[\\#]/.test(value) ||
        [...value].some(
            (c) => c.charCodeAt(0) <= 32 || c.charCodeAt(0) === 127,
        ) ||
        !value.startsWith('/') ||
        value.startsWith('//')
    )
        return null;
    let url: URL;
    try {
        url = new URL(value, 'https://procedure-return.invalid');
    } catch {
        return null;
    }
    if (
        url.origin !== 'https://procedure-return.invalid' ||
        url.pathname !== value.split('?')[0]
    )
        return null;
    const record =
        /^\/(?:incidents|hazards|health-safety\/(?:events|corrective-actions|risk-assessments|procedures))\/[1-9]\d*$/.test(
            url.pathname,
        );
    const profile =
        /^\/(?:sites|operations\/clients|hr\/people)\/[1-9]\d*$/.test(
            url.pathname,
        );
    const isProcedure = url.pathname.startsWith(PROCEDURE_REGISTER + '/');
    if (!allowRecord && url.searchParams.has('return_to')) return null;
    const keys =
        LIST_KEYS[url.pathname] ??
        (isProcedure
            ? ['section', 'version', 'revision', 'request']
            : record
              ? ['section']
              : profile
                ? ['tab']
                : null);
    if (!keys) return null;
    const query = new URLSearchParams();
    for (const key of keys) {
        const item = url.searchParams.get(key);
        if (
            !item ||
            item.length > 200 ||
            [...item].some(
                (c) => c.charCodeAt(0) < 32 || c.charCodeAt(0) === 127,
            )
        )
            continue;
        if (
            [
                'site_id',
                'client_id',
                'hs_event_id',
                'assignee_id',
                'page',
                'version',
                'request',
            ].includes(key) &&
            !/^[1-9]\d*$/.test(item)
        )
            continue;
        if (['from', 'to'].includes(key) && !formatDateOnly(item, '')) continue;
        if (
            key === 'revision' &&
            (item !== 'working' || url.searchParams.has('version'))
        )
            continue;
        if (key === 'section' && !recordSections(url.pathname).includes(item))
            continue;
        if (
            key === 'tab' &&
            ((url.pathname.startsWith('/sites/') &&
                !['hazards', 'risk_assessments'].includes(item)) ||
                (url.pathname.startsWith('/operations/clients/') &&
                    item !== 'risk_management') ||
                (url.pathname.startsWith('/hr/people/') && item !== 'training'))
        )
            continue;
        if (
            key === 'tab' &&
            url.pathname === PROCEDURE_REGISTER &&
            ![
                'all',
                'draft',
                'under_review',
                'approved',
                'review_due',
                'archived',
            ].includes(item)
        )
            continue;
        if (
            key === 'status' &&
            url.pathname === PROCEDURE_REGISTER &&
            !['draft', 'under_review', 'approved', 'archived'].includes(item)
        )
            continue;
        if (
            key === 'review_state' &&
            !['overdue', 'today', 'due_soon', 'current', 'none'].includes(item)
        )
            continue;
        if (key === 'kind' && !['all', 'incidents', 'near_misses'].includes(item))
            continue;
        if (!['q', 'search'].includes(key) && !/^[a-zA-Z0-9_,.-]+$/.test(item))
            continue;
        query.set(key, item);
    }
    if (record && allowRecord) {
        const nested = boundedReturn(url.searchParams.get('return_to'), false);
        if (nested && !containsPath(nested, url.pathname))
            query.set('return_to', nested);
    }
    return url.pathname + (query.size ? `?${query}` : '');
}
function recordSections(path: string): readonly string[] {
    if (path.startsWith(PROCEDURE_REGISTER + '/')) return PROCEDURE_SECTIONS;
    if (path.startsWith('/hazards/'))
        return [
            'overview',
            'risk',
            'actions',
            'connections',
            'evidence',
            'history',
        ];
    if (path.startsWith('/health-safety/risk-assessments/'))
        return ['overview', 'risk', 'connections', 'evidence', 'history'];
    if (path.startsWith('/health-safety/corrective-actions/'))
        return ['overview', 'evidence', 'history'];
    if (path.startsWith('/incidents/'))
        return [
            'overview',
            'followups',
            'evidence',
            'investigation',
            'linked',
            'history',
        ];
    return [
        'overview',
        'handover',
        'investigation',
        'actions',
        'risk',
        'timeline',
        'evidence',
    ];
}
function containsPath(value: string, path: string) {
    const url = new URL(value, 'https://procedure-return.invalid');
    const nested = url.searchParams.get('return_to');
    return (
        url.pathname === path ||
        (!!nested &&
            new URL(nested, 'https://procedure-return.invalid').pathname ===
                path)
    );
}
/** Navigation context only. The destination always rechecks record permissions. */
export function procedureReturn(value: string | null | undefined): string {
    return boundedReturn(value, true) ?? PROCEDURE_REGISTER;
}
export function procedureLocation(value: string) {
    const query = new URLSearchParams(value.split('?')[1]?.split('#')[0] ?? '');
    const version = query.get('version');
    const request = query.get('request');
    return {
        section:
            PROCEDURE_SECTIONS.find((s) => s === query.get('section')) ??
            'overview',
        command:
            PROCEDURE_COMMANDS.find((s) => s === query.get('action')) ?? null,
        revision:
            query.get('revision') === 'working' ? ('working' as const) : null,
        version:
            version &&
            /^[1-9]\d*$/.test(version) &&
            Number.isSafeInteger(Number(version))
                ? Number(version)
                : null,
        returnTo: procedureReturn(query.get('return_to')),
        request:
            request &&
            /^[1-9]\d*$/.test(request) &&
            Number.isSafeInteger(Number(request))
                ? Number(request)
                : null,
    };
}
export function procedureHref(
    id: number,
    options: {
        section?: ProcedureSection;
        command?: ProcedureCommand | null;
        revision?: 'working' | null;
        version?: number | null;
        returnTo?: string;
        request?: number | null;
    } = {},
) {
    const query = new URLSearchParams();
    if (options.request) query.set('request', String(options.request));
    if (options.section && options.section !== 'overview')
        query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    if (options.version) query.set('version', String(options.version));
    else if (options.revision) query.set('revision', options.revision);
    const origin = options.returnTo ? procedureReturn(options.returnTo) : null;
    if (origin && !containsPath(origin, `${PROCEDURE_REGISTER}/${id}`))
        query.set('return_to', origin);
    return `${PROCEDURE_REGISTER}/${id}${query.size ? `?${query}` : ''}`;
}

/** Keep the server-selected readable revision when linking from connected work. */
export function withProcedureRequest(
    href: string,
    request: number | null,
): string {
    if (!request || !Number.isSafeInteger(request) || request < 1) return href;
    const safe = procedureReturn(href);
    const target = new URL(safe, 'https://procedure-return.invalid');
    if (!/^\/health-safety\/procedures\/[1-9]\d*$/.test(target.pathname))
        return PROCEDURE_REGISTER;
    target.searchParams.set('request', String(request));
    return target.pathname + target.search;
}

export function procedureConnectionHref(
    id: number,
    selectedHref: string,
    returnTo: string,
) {
    const safe = procedureReturn(selectedHref);
    const selected = procedureLocation(safe);
    const matches = safe.split('?')[0] === `${PROCEDURE_REGISTER}/${id}`;
    return procedureHref(id, {
        section: 'connections',
        version: matches ? selected.version : null,
        revision: matches ? selected.revision : null,
        request: matches ? selected.request : null,
        returnTo,
    });
}
