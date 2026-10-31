import { RESTRAINT_REGISTER, restraintReturn } from './restraint-navigation';
import { INJURY_READ_SECTIONS } from './injury-read-leaf';

export const INJURY_REGISTER = '/health-safety/injuries';
export const INJURY_SECTIONS = INJURY_READ_SECTIONS;
export type InjuryWorkspaceSection = (typeof INJURY_SECTIONS)[number];

// Existing command bookmarks remain navigation hints, never permission grants.
export const INJURY_COMMANDS = [
    'start_treatment',
    'begin_rtw',
    'mark_recovered',
    'close',
    'acc',
    'add_rtw',
    'add_capacity',
    'edit',
    'assign',
    'review',
    'edit_rtw',
    'add_duty',
    'upload_evidence',
    'remove_evidence',
] as const;
export type InjuryWorkspaceCommand = (typeof INJURY_COMMANDS)[number];
const ORIGIN = 'https://injury-return.invalid';
const bytes = (value: string) => new TextEncoder().encode(value).length;
const controls = (value: string, spaces = false) =>
    [...value].some(
        (char) =>
            char.charCodeAt(0) < (spaces ? 33 : 32) ||
            char.charCodeAt(0) === 127,
    );
const positive = (value: string | null) =>
    value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : null;
const single = (query: URLSearchParams, key: string) =>
    query.getAll(key).length === 1 ? query.get(key) : null;
const section = (value: string | null): InjuryWorkspaceSection =>
    value === 'rtw'
        ? 'recovery'
        : (INJURY_SECTIONS.find((item) => item === value) ?? 'overview');
const validDate = (value: string) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const date = new Date(value + 'T00:00:00Z');
    return (
        !Number.isNaN(date.getTime()) &&
        date.toISOString().slice(0, 10) === value
    );
};
function parsed(value: string | null | undefined): URL | null {
    if (
        !value ||
        bytes(value) > 1500 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        /[\\#]/.test(value) ||
        controls(value, true)
    )
        return null;
    try {
        const target = new URL(value, ORIGIN);
        return target.origin === ORIGIN &&
            target.pathname === value.split('?')[0]
            ? target
            : null;
    } catch {
        return null;
    }
}
function containsPath(value: string, path: string): boolean {
    const target = new URL(value, ORIGIN);
    const back = target.searchParams.get('return_to');
    return (
        target.pathname === path ||
        (!!back && new URL(back, ORIGIN).pathname === path)
    );
}
const registerEnums: Record<string, readonly string[]> = {
    tab: [
        'all',
        'reported',
        'under_treatment',
        'return_to_work',
        'recovered',
        'closed',
        'worksafe',
        'acc',
    ],
    status: ['reported', 'under_treatment', 'return_to_work', 'recovered', 'closed'],
    severity: ['minor', 'moderate', 'serious', 'critical'],
    treatment: [
        'none',
        'first_aid',
        'gp_visit',
        'hospital',
        'emergency_department',
        'hospitalisation',
        'specialist',
        'ongoing',
    ],
    period: ['all', 'week', '30d', 'quarter', 'year'],
    acc_open: ['0', '1', 'false', 'true'],
    worksafe: ['0', '1', 'false', 'true'],
};
function registerQuery(target: URL): URLSearchParams {
    const query = new URLSearchParams();
    for (const key of [
        'tab',
        'q',
        'site_id',
        'severity',
        'status',
        'treatment',
        'acc_open',
        'worksafe',
        'period',
        'from',
        'to',
        'type',
        'body_part',
        'page',
    ]) {
        const item = single(target.searchParams, key);
        if (!item || bytes(item) > 200 || controls(item)) continue;
        if (['site_id', 'page'].includes(key) && !positive(item)) continue;
        if (['from', 'to'].includes(key) && !validDate(item)) continue;
        if (registerEnums[key] && !registerEnums[key].includes(item)) continue;
        query.set(key, item);
    }
    return query;
}
function clean(value: string | null | undefined, nested: boolean): string | null {
    const target = parsed(value);
    if (!target || (!nested && target.searchParams.has('return_to'))) return null;
    const path = target.pathname;
    const own = path.match(/^\/health-safety\/injuries\/([1-9]\d*)$/);
    if (own && !positive(own[1])) return null;
    const staffProfile = path.match(/^\/hr\/people\/([1-9]\d*)$/);
    if (staffProfile && !positive(staffProfile[1])) return null;
    let base: string;
    if (path === INJURY_REGISTER) {
        const query = registerQuery(target);
        base = path + (query.size ? `?${query}` : '');
    } else if (own) {
        const query = new URLSearchParams();
        const selected = single(target.searchParams, 'section');
        if (selected && (selected === 'rtw' || INJURY_SECTIONS.some((item) => item === selected)))
            query.set('section', section(selected));
        for (const key of ['plan', 'assessment', 'duty']) {
            const id = positive(single(target.searchParams, key));
            if (id) query.set(key, String(id));
        }
        base = path + (query.size ? `?${query}` : '');
    } else if (path === '/hr/wellbeing') {
        base = path;
    } else if (staffProfile) {
        // The HR profile uses this actual tab for injury and recovery records.
        // A return hint never carries an injury command or private selection.
        const tab = single(target.searchParams, 'tab');
        base = path + (tab === 'injuries' ? '?tab=injuries' : '');
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = restraintReturn(leaf.pathname + leaf.search);
        if (base === RESTRAINT_REGISTER && path !== RESTRAINT_REGISTER)
            return null;
    }
    const record = own || staffProfile || /^\/(?:incidents|control-room\/alerts|operations\/shifts|health-safety\/(?:events|first-aid|corrective-actions))\/[1-9]\d*$/.test(path);
    if (nested && record) {
        const back = clean(single(target.searchParams, 'return_to'), false);
        if (back && !containsPath(back, path))
            base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(back)}`;
    }
    return base;
}
/** A bounded return hint; every destination independently enforces current access. */
export function injuryReturn(value: string | null | undefined): string {
    return clean(value, true) ?? INJURY_REGISTER;
}
export function injuryLocation(value: string) {
    const query = parsed(value)?.searchParams ?? new URLSearchParams();
    return {
        section: section(single(query, 'section')),
        command: INJURY_COMMANDS.find((item) => item === single(query, 'action')) ?? null,
        plan: positive(single(query, 'plan')),
        assessment: positive(single(query, 'assessment')),
        duty: positive(single(query, 'duty')),
        attachment: positive(single(query, 'attachment')),
        returnTo: injuryReturn(single(query, 'return_to')),
    };
}
export function injuryHref(
    id: number,
    options: {
        section?: InjuryWorkspaceSection;
        command?: InjuryWorkspaceCommand | null;
        plan?: number | null;
        assessment?: number | null;
        duty?: number | null;
        attachment?: number | null;
        returnTo?: string;
    } = {},
): string {
    if (!Number.isSafeInteger(id) || id < 1) return INJURY_REGISTER;
    const path = `${INJURY_REGISTER}/${id}`;
    const query = new URLSearchParams();
    if (options.section) query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    for (const key of ['plan', 'assessment', 'duty', 'attachment'] as const) {
        const selected = options[key];
        if (selected && Number.isSafeInteger(selected) && selected > 0)
            query.set(key, String(selected));
    }
    const back = clean(options.returnTo, true);
    if (back && !containsPath(back, path)) query.set('return_to', back);
    return path + (query.size ? `?${query}` : '');
}
/** Converge the old detail-over-register URL without carrying its commands back. */
export function injuryLegacyHref(value: string): string | null {
    const target = parsed(value);
    if (target?.pathname !== INJURY_REGISTER) return null;
    const id = positive(single(target.searchParams, 'injury'));
    if (!id) return null;
    const location = injuryLocation(value);
    return injuryHref(id, {
        ...location,
        returnTo: injuryReturn(value),
    });
}
