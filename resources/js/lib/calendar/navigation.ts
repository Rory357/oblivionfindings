export const CALENDAR_VIEWS = [
    'day',
    'week',
    'month',
    'agenda',
    'timeline',
] as const;
export type CalendarView = (typeof CALENDAR_VIEWS)[number];
const ORIGIN = 'https://calendar-state.invalid';
const validDay = (value: string | null): value is string =>
    !!value &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    !Number.isNaN(Date.parse(value)) &&
    new Date(value).toISOString().slice(0, 10) === value;
const positiveId = (value: string | null) =>
    !!value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value));
export function calendarReturn(value: string): string | null {
    if (
        !value.startsWith('/') ||
        value.startsWith('//') ||
        value.length > 1500 ||
        /[\\#]/.test(value) ||
        [...value].some(
            (char) => char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127,
        )
    )
        return null;
    const target = new URL(value, ORIGIN);
    if (target.origin !== ORIGIN || target.pathname !== value.split('?')[0])
        return null;
    const profile =
        /^\/sites\/[1-9]\d*$/.test(target.pathname) &&
        target.searchParams.get('tab') === 'calendar';
    if (
        target.pathname !== '/calendar' &&
        !/^\/sites\/[1-9]\d*\/calendar$/.test(target.pathname) &&
        !profile
    )
        return null;
    const query = new URLSearchParams();
    if (profile) query.set('tab', 'calendar');
    const date = target.searchParams.get('date');
    if (validDay(date)) query.set('date', date);
    const view = target.searchParams.get('view');
    if (CALENDAR_VIEWS.some((item) => item === view)) query.set('view', view!);
    const sources = target.searchParams.get('sources');
    if (sources === 'none') query.set('sources', sources);
    else if (sources) {
        const keys = [...new Set(sources.split(','))];
        if (
            keys.length <= 20 &&
            keys.every((key) => /^[a-z][a-z0-9_]{0,63}$/.test(key))
        )
            query.set('sources', keys.join(','));
    }
    const site = target.searchParams.get('site_id');
    if (positiveId(site)) query.set('site_id', site!);
    const search = target.searchParams.get('q');
    if (
        search &&
        new TextEncoder().encode(search).length <= 200 &&
        ![...search].some(
            (char) => char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127,
        )
    )
        query.set('q', search);
    for (const [key, values] of [
        ['color', ['source', 'status', 'owner']],
        ['density', ['comfortable', 'compact']],
    ] as const) {
        const current = target.searchParams.get(key);
        if (values.some((item) => item === current)) query.set(key, current!);
    }
    return target.pathname + (query.size ? `?${query}` : '');
}
export function calendarLocation(value: string) {
    const url = new URL(calendarReturn(value) ?? '/calendar', ORIGIN);
    const query = url.searchParams;
    const rawSources = query.get('sources');
    return {
        date: query.get('date'),
        view: query.get('view') as CalendarView | null,
        sources:
            rawSources === null
                ? null
                : rawSources === 'none'
                  ? []
                  : rawSources.split(','),
        siteId: query.has('site_id') ? Number(query.get('site_id')) : null,
        q: query.get('q') ?? '',
        color: query.get('color') as 'source' | 'status' | 'owner' | null,
        density: query.get('density') as 'comfortable' | 'compact' | null,
    };
}
export function calendarHref(
    path: string,
    state: {
        date: Date;
        view: CalendarView;
        sources: Iterable<string>;
        siteId: number | 'all';
        q: string;
        color: string;
        density: string;
    },
) {
    const query = new URLSearchParams(path.split('?')[1]);
    query.set(
        'date',
        `${state.date.getFullYear()}-${String(state.date.getMonth() + 1).padStart(2, '0')}-${String(state.date.getDate()).padStart(2, '0')}`,
    );
    query.set('view', state.view);
    query.set('sources', [...state.sources].join(',') || 'none');
    if (state.siteId !== 'all') query.set('site_id', String(state.siteId));
    if (state.q) query.set('q', state.q);
    query.set('color', state.color);
    query.set('density', state.density);
    return (
        calendarReturn(path.split('?')[0] + '?' + query) ?? path.split('?')[0]
    );
}
/** Only canonical drill links receive a Calendar origin. No command is restored. */
export function drillCalendarHref(
    href: string | null | undefined,
    origin: string,
): string | null | undefined {
    if (
        !href ||
        !href.startsWith('/') ||
        href.startsWith('//') ||
        /[\\#]/.test(href)
    )
        return href;
    const target = new URL(href, ORIGIN);
    if (
        target.origin !== ORIGIN ||
        !/^\/health-safety\/drills\/[1-9]\d*$/.test(target.pathname)
    )
        return href;
    const query = new URLSearchParams();
    const section = target.searchParams.get('section');
    if (
        section &&
        [
            'overview',
            'run',
            'participants',
            'findings',
            'evidence',
            'history',
            'connections',
        ].includes(section)
    )
        query.set('section', section);
    const finding = target.searchParams.get('finding');
    if (positiveId(finding)) query.set('finding', finding!);
    const back = calendarReturn(origin);
    if (back) query.set('return_to', back);
    return target.pathname + (query.size ? `?${query}` : '');
}
