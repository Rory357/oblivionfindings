import { FIRST_AID_REGISTER, firstAidReturn } from './first-aid-navigation';

import { injuryReadLeaf } from './injury-read-leaf';

export const RESTRAINT_REGISTER = '/health-safety/restraints';
export const RESTRAINT_EVENT_SECTIONS = [
    'overview',
    'review',
    'monitoring',
    'debrief',
    'followups',
    'evidence',
    'history',
    'connections',
] as const;
export const RESTRAINT_PLAN_SECTIONS = [
    'overview',
    'content',
    'review',
    'approval',
    'versions',
    'history',
    'connections',
] as const;
export const RESTRAINT_EVENT_COMMANDS = [
    'correct',
    'review',
    'assign-review',
    'link-incident',
    'unlink-incident',
    'add-observation',
    'correct-observation',
    'record-debrief',
    'correct-debrief',
    'add-followup',
    'reassign-followup',
    'complete-followup',
    'correct-followup',
    'cancel-followup',
    'add-evidence',
    'remove-evidence',
] as const;
export const RESTRAINT_PLAN_COMMANDS = [
    'edit',
    'submit-review',
    'activate',
    'archive',
    'review',
    'decision',
] as const;
export type RestraintEventSection = (typeof RESTRAINT_EVENT_SECTIONS)[number];
export type RestraintPlanSection = (typeof RESTRAINT_PLAN_SECTIONS)[number];
export type RestraintEventCommand = (typeof RESTRAINT_EVENT_COMMANDS)[number];
export type RestraintPlanCommand = (typeof RESTRAINT_PLAN_COMMANDS)[number];
export type RestraintWorkspace = 'view' | 'review' | 'approval';
const ORIGIN = 'https://restraint.invalid';
const bytes = (value: string) => new TextEncoder().encode(value).length;
const control = (value: string, spaces = false) =>
    [...value].some(
        (char) =>
            char.charCodeAt(0) < (spaces ? 33 : 32) ||
            char.charCodeAt(0) === 127,
    );
export function restraintPositive(value: string | null): number | null {
    return value &&
        /^[1-9]\d*$/.test(value) &&
        Number.isSafeInteger(Number(value))
        ? Number(value)
        : null;
}
function containsPath(value: string, path: string) {
    const target = new URL(value, ORIGIN);
    const leaf = target.searchParams.get('return_to');
    return (
        target.pathname === path ||
        (!!leaf && new URL(leaf, ORIGIN).pathname === path)
    );
}
function validDate(value: string) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const date = new Date(value + 'T00:00:00Z');
    return (
        !Number.isNaN(date.getTime()) &&
        date.toISOString().slice(0, 10) === value
    );
}
function clean(
    value: string | null | undefined,
    nested: boolean,
): string | null {
    if (
        !value ||
        bytes(value) > 1500 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        /[\\#]/.test(value) ||
        control(value, true)
    )
        return null;
    let target: URL;
    try {
        target = new URL(value, ORIGIN);
    } catch {
        return null;
    }
    if (
        target.origin !== ORIGIN ||
        target.pathname !== value.split('?')[0] ||
        (!nested && target.searchParams.has('return_to'))
    )
        return null;
    const path = target.pathname;
    const injury = /^\/health-safety\/injuries\/[1-9]\d*$/.test(path);
    const record = path.match(
        /^\/health-safety\/restraints\/(events|plans)\/([1-9]\d*)(?:\/(review|approval))?$/,
    );
    if (
        record &&
        (!restraintPositive(record[2]) ||
            (record[1] === 'events' && record[3] === 'approval'))
    )
        return null;
    let base: string;
    const stay = path.match(/^\/respite\/stays\/([1-9]\d*)$/);
    const client = path.match(/^\/operations\/clients\/([1-9]\d*)$/);
    if (
        (stay && !restraintPositive(stay[1])) ||
        (client && !restraintPositive(client[1]))
    )
        return null;
    if (injury || path === '/health-safety/injuries') {
        const leaf = new URL(target); leaf.searchParams.delete('return_to');
        const destination = injuryReadLeaf(leaf.pathname + leaf.search);
        if (!destination) return null;
        base = destination;
    } else if (stay && restraintPositive(stay[1])) {
        base = path;
    } else if (
        client &&
        restraintPositive(client[1]) &&
        target.searchParams.get('tab') === 'observations'
    ) {
        base = path + '?tab=observations';
    } else if (
        path === '/respite' &&
        target.searchParams.get('tab') === 'stays'
    ) {
        base = '/respite?tab=stays';
    } else if (path === '/health-clinical/behaviour') {
        const query = new URLSearchParams();
        for (const key of [
            'client_id',
            'site_id',
            'date_from',
            'date_to',
            'view',
        ]) {
            const item = target.searchParams.get(key);
            if (
                item &&
                (['client_id', 'site_id'].includes(key)
                    ? !!restraintPositive(item)
                    : ['date_from', 'date_to'].includes(key)
                      ? validDate(item)
                      : item === 'restraint')
            )
                query.set(key, item);
        }
        base = path + (query.size ? `?${query}` : '');
    } else if (record || path === RESTRAINT_REGISTER) {
        const query = new URLSearchParams();
        const keys = record
            ? record[1] === 'events'
                ? ['section', 'followup']
                : ['section', 'version', 'approval']
            : [
                  'lens',
                  'tab',
                  'client_id',
                  'site_id',
                  'restraint_type',
                  'severity',
                  'within_plan',
                  'review_state',
                  'period',
                  'from',
                  'to',
                  'q',
                  'events_page',
                  'plans_page',
              ];
        for (const key of keys) {
            const item = target.searchParams.get(key);
            if (
                !item ||
                target.searchParams.getAll(key).length !== 1 ||
                bytes(item) > 200 ||
                control(item)
            )
                continue;
            if (
                [
                    'followup',
                    'version',
                    'approval',
                    'client_id',
                    'site_id',
                    'events_page',
                    'plans_page',
                ].includes(key) &&
                !restraintPositive(item)
            )
                continue;
            if (
                key === 'section' &&
                !(
                    record?.[1] === 'events'
                        ? RESTRAINT_EVENT_SECTIONS
                        : RESTRAINT_PLAN_SECTIONS
                ).some((value) => value === item)
            )
                continue;
            const enums: Record<string, string[]> = {
                lens: ['events', 'plans'],
                tab: [
                    'all',
                    'unreviewed',
                    'out_of_plan',
                    'injury',
                    'critical',
                    '30d',
                    'active',
                    'draft',
                    'under_review',
                    'archived',
                    'review_due',
                ],
                restraint_type: [
                    'physical',
                    'chemical',
                    'mechanical',
                    'seclusion',
                    'environmental',
                ],
                severity: ['low', 'medium', 'high', 'critical'],
                within_plan: ['yes', 'no'],
                review_state: ['reviewed', 'unreviewed', 'due', 'overdue'],
                period: ['week', '30d', 'quarter', 'all'],
            };
            if (enums[key] && !enums[key].includes(item)) continue;
            if (['from', 'to'].includes(key) && !validDate(item)) continue;
            query.set(key, item);
        }
        base = path + (query.size ? `?${query}` : '');
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = firstAidReturn(leaf.pathname + leaf.search);
        if (base === FIRST_AID_REGISTER && path !== FIRST_AID_REGISTER)
            return null;
    }
    if (nested && (record || injury)) {
        const back = clean(target.searchParams.get('return_to'), false);
        if (back && !containsPath(back, path))
            base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(back)}`;
    }
    return base;
}
export function restraintReturn(value: string | null | undefined) {
    return clean(value, true) ?? RESTRAINT_REGISTER;
}
export function restraintLocation(value: string, kind: 'events' | 'plans') {
    const query = new URLSearchParams(value.split('?')[1]?.split('#')[0] ?? '');
    return {
        section:
            (kind === 'events'
                ? RESTRAINT_EVENT_SECTIONS
                : RESTRAINT_PLAN_SECTIONS
            ).find((item) => item === query.get('section')) ?? 'overview',
        command:
            (kind === 'events'
                ? RESTRAINT_EVENT_COMMANDS
                : RESTRAINT_PLAN_COMMANDS
            ).find((item) => item === query.get('action')) ?? null,
        followup: restraintPositive(query.get('followup')),
        observation: restraintPositive(query.get('observation')),
        debrief: restraintPositive(query.get('debrief')),
        attachment: restraintPositive(query.get('attachment')),
        version: restraintPositive(query.get('version')),
        approval: restraintPositive(query.get('approval')),
        returnTo: restraintReturn(query.get('return_to')),
    };
}
export function restraintHref(
    kind: 'events' | 'plans',
    id: number,
    options: {
        workspace?: RestraintWorkspace;
        section?: RestraintEventSection | RestraintPlanSection;
        command?: RestraintEventCommand | RestraintPlanCommand | null;
        child?: number | null;
        childKey?:
            | 'followup'
            | 'observation'
            | 'debrief'
            | 'attachment'
            | 'version'
            | 'approval';
        returnTo?: string;
    } = {},
) {
    if (!Number.isSafeInteger(id) || id < 1) return RESTRAINT_REGISTER;
    const path = `${RESTRAINT_REGISTER}/${kind}/${id}${options.workspace && options.workspace !== 'view' ? '/' + options.workspace : ''}`;
    const query = new URLSearchParams();
    if (options.section) query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    if (
        options.child &&
        options.childKey &&
        Number.isSafeInteger(options.child) &&
        options.child > 0
    )
        query.set(options.childKey, String(options.child));
    const back = clean(options.returnTo, true);
    if (back && !containsPath(back, path)) query.set('return_to', back);
    return path + (query.size ? `?${query}` : '');
}
