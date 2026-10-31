import type {
    SessionCommand,
    SessionSection,
} from '@/components/health-safety/lone-workers/types';
import { DRILL_REGISTER, drillReturn } from './drill-navigation';

export const LONE_WORKER_REGISTER = '/health-safety/lone-workers';
export const LONE_WORKER_SECTIONS = [
    'overview',
    'check-ins',
    'response',
    'location',
    'history',
    'connections',
] as const;
const ORIGIN = 'https://safety-session.invalid';
const byteLength = (value: string) => new TextEncoder().encode(value).length;
const ownRecord = (path: string) =>
    /^\/health-safety\/lone-workers\/sessions\/[1-9]\d*$/.test(path);
const containsPath = (value: string, path: string) => {
    const target = new URL(value, ORIGIN);
    const origin = target.searchParams.get('return_to');
    return (
        target.pathname === path ||
        (!!origin && new URL(origin, ORIGIN).pathname === path)
    );
};

function clean(
    value: string | null | undefined,
    nested: boolean,
): string | null {
    if (
        !value ||
        byteLength(value) > 1500 ||
        !value.startsWith('/') ||
        value.startsWith('//') ||
        /[\\#]/.test(value) ||
        [...value].some(
            (char) => char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127,
        )
    )
        return null;
    let target: URL;
    try {
        target = new URL(value, ORIGIN);
    } catch {
        return null;
    }
    const path = target.pathname;
    if (
        target.origin !== ORIGIN ||
        path !== value.split('?')[0] ||
        (!nested && target.searchParams.has('return_to'))
    )
        return null;
    const own = ownRecord(path);
    const shift = /^\/operations\/shifts\/[1-9]\d*$/.test(path);
    const record =
        own ||
        shift ||
        /^\/(?:incidents|hazards|control-room\/alerts|health-safety\/(?:events|corrective-actions|risk-assessments|procedures|drills))\/[1-9]\d*$/.test(
            path,
        );
    const people =
        /^\/operations\/people-locations(?:\/(?:map|people|analytics|alerts|history|settings))?$/.test(
            path,
        );
    const query = new URLSearchParams();
    let base: string;
    if (
        own ||
        path === LONE_WORKER_REGISTER ||
        path === '/security-devices/tracking' ||
        people
    ) {
        const keys = own
            ? ['section']
            : people
              ? [
                    'q',
                    'population',
                    'site',
                    'selected',
                    'source',
                    'date',
                    'cohort',
                    'sort',
                    'peopleView',
                    'chartView',
                    'alertStatus',
                ]
              : path === LONE_WORKER_REGISTER
                ? ['tab', 'q', 'site_id', 'user_id', 'status', 'period', 'page']
                : ['tab', 'attention'];
        for (const key of keys) {
            const item = target.searchParams.get(key);
            if (
                !item ||
                target.searchParams.getAll(key).length !== 1 ||
                byteLength(item) > (people ? 500 : 200) ||
                [...item].some(
                    (char) =>
                        char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127,
                )
            )
                continue;
            if (
                key === 'section' &&
                !LONE_WORKER_SECTIONS.includes(item as SessionSection)
            )
                continue;
            if (
                ['site_id', 'user_id', 'page'].includes(key) &&
                !/^[1-9]\d*$/.test(item)
            )
                continue;
            if (
                key === 'tab' &&
                !(
                    path === LONE_WORKER_REGISTER
                        ? ['sessions', 'alerts']
                        : [
                              'overview',
                              'personal-safety',
                              'fleet',
                              'assets',
                              'geofences',
                              'history',
                          ]
                ).includes(item)
            )
                continue;
            if (
                key === 'period' &&
                !['today', 'week', '30d', 'all'].includes(item)
            )
                continue;
            if (
                key === 'status' &&
                ![
                    'active',
                    'overdue',
                    'emergency',
                    'completed',
                    'open',
                    'ack',
                    'triaging',
                    'confirmed',
                    'resolved',
                    'closed',
                    'dismissed',
                ].includes(item)
            )
                continue;
            if (
                key === 'attention' &&
                ![
                    'offline',
                    'low-battery',
                    'consent-blocked',
                    'unassigned',
                    'stale',
                ].includes(item)
            )
                continue;
            query.set(key, item);
        }
        base = path + (query.size ? `?${query}` : '');
    } else if (shift) {
        base = path;
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = drillReturn(leaf.pathname + leaf.search);
        if (base === DRILL_REGISTER && path !== DRILL_REGISTER) return null;
    }
    const origin = target.searchParams.get('return_to');
    if (nested && record && origin) {
        const leaf = clean(origin, false);
        if (leaf && new URL(leaf, ORIGIN).pathname !== path)
            base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(leaf)}`;
    }
    return base;
}

export function loneWorkerReturn(value: string | null | undefined): string {
    return clean(value, true) ?? LONE_WORKER_REGISTER;
}

export function loneWorkerHref(
    id: number,
    options: {
        section?: SessionSection;
        command?: SessionCommand | null;
        returnTo?: string;
    } = {},
): string {
    if (!Number.isSafeInteger(id) || id < 1) return LONE_WORKER_REGISTER;
    const path = `${LONE_WORKER_REGISTER}/sessions/${id}`;
    const query = new URLSearchParams();
    if (options.section) query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    const back = clean(options.returnTo, true);
    if (back && !containsPath(back, path)) query.set('return_to', back);
    return path + (query.size ? `?${query}` : '');
}
