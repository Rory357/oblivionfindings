export const RISK_REGISTER = '/health-safety/risk-assessments';
export const RISK_SECTIONS = [
    'overview',
    'risk',
    'evidence',
    'history',
    'connections',
] as const;
export type RiskSection = (typeof RISK_SECTIONS)[number];
export const RISK_COMMANDS = [
    'approve',
    'review',
    'residual',
    'complete-review',
    'archive',
] as const;
export type RiskCommand = (typeof RISK_COMMANDS)[number];

/** Matches the backend's leaf-only return contract; never authorizes a destination. */
export function riskReturn(value: string | null | undefined): string {
    if (
        !value ||
        value.length > 2000 ||
        /[\\#]/.test(value) ||
        [...value].some(
            (char) => char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127,
        ) ||
        !value.startsWith('/') ||
        value.startsWith('//')
    )
        return RISK_REGISTER;
    let url: URL;
    try {
        url = new URL(value, 'https://risk-return.invalid');
    } catch {
        return RISK_REGISTER;
    }
    if (url.origin !== 'https://risk-return.invalid') return RISK_REGISTER;
    const path = url.pathname;
    const keys =
        path === RISK_REGISTER
            ? [
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
              ]
            : path === '/tasks'
              ? ['sources', 'status', 'done', 'site_id', 'search', 'q', 'page']
              : path === '/my-day'
                ? []
                : /^\/(?:sites\/[1-9]\d*|operations\/clients\/[1-9]\d*|health-safety\/events\/[1-9]\d*)$/.test(
                        path,
                    )
                  ? ['section', 'tab']
                  : null;
    if (!keys) return RISK_REGISTER;
    const query = new URLSearchParams();
    for (const key of keys) {
        const item = url.searchParams.get(key);
        if (
            !item ||
            item.length > 200 ||
            [...item].some(
                (char) => char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127,
            )
        )
            continue;
        if (
            ['site_id', 'client_id', 'hs_event_id', 'page'].includes(key) &&
            !/^[1-9]\d*$/.test(item)
        )
            continue;
        if (
            key === 'tab' &&
            path === RISK_REGISTER &&
            !['all', 'active', 'drafts', 'due', 'high', 'closed'].includes(item)
        )
            continue;
        if (
            key === 'status' &&
            path === RISK_REGISTER &&
            ![
                'draft',
                'active',
                'under_review',
                'superseded',
                'archived',
            ].includes(item)
        )
            continue;
        if (
            key === 'risk_level' &&
            !['low', 'medium', 'high', 'extreme'].includes(item)
        )
            continue;
        if (key === 'risk_acceptable' && !['0', '1'].includes(item)) continue;
        if (
            ['due_for_review', 'done'].includes(key) &&
            !['true', 'false'].includes(item)
        )
            continue;
        if (
            ['section', 'tab'].includes(key) &&
            !/^[a-z][a-z0-9_-]{0,49}$/.test(item)
        )
            continue;
        query.set(key, item);
    }
    return path + (query.size ? `?${query}` : '');
}

export function riskLocation(value: string) {
    const query = new URLSearchParams(value.split('?')[1]?.split('#')[0] ?? '');
    return {
        section:
            RISK_SECTIONS.find((s) => s === query.get('section')) ?? 'overview',
        command: RISK_COMMANDS.find((s) => s === query.get('action')) ?? null,
        returnTo: riskReturn(query.get('return_to')),
    };
}

export function riskHref(
    id: number,
    options: {
        section?: RiskSection;
        command?: RiskCommand | null;
        returnTo?: string;
    } = {},
) {
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview')
        query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    if (options.returnTo) query.set('return_to', riskReturn(options.returnTo));
    return `${RISK_REGISTER}/${id}${query.size ? `?${query}` : ''}`;
}
