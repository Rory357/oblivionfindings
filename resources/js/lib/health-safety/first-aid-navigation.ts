import type {
    FirstAidCommand,
    FirstAidSection,
} from '@/components/health-safety/first-aid/types';
import {
    LONE_WORKER_REGISTER,
    loneWorkerReturn,
} from './lone-worker-navigation';

import { injuryReadLeaf } from './injury-read-leaf';

export const FIRST_AID_REGISTER = '/health-safety/first-aid';
export const FIRST_AID_SECTIONS = [
    'overview',
    'injury',
    'incident',
    'followups',
    'evidence',
    'history',
    'connections',
] as const;
export const FIRST_AID_COMMANDS = [
    'edit',
    'archive',
    'link-incident',
    'add-followup',
    'edit-followup',
    'complete-followup',
    'add-evidence',
    'remove-evidence',
] as const;
const ORIGIN = 'https://first-aid.invalid';
const byteLength = (value: string) => new TextEncoder().encode(value).length;
const positive = (value: string | null) =>
    value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : null;
const ownRecord = (path: string) =>
    /^\/health-safety\/first-aid\/[1-9]\d*$/.test(path);
function containsPath(value: string, path: string) {
    const target = new URL(value, ORIGIN);
    const back = target.searchParams.get('return_to');
    return (
        target.pathname === path ||
        (!!back && new URL(back, ORIGIN).pathname === path)
    );
}
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
    const injury = /^\/health-safety\/injuries\/[1-9]\d*$/.test(path);
    if (own && !positive(path.split('/').at(-1) ?? null)) return null;
    const record =
        own || injury ||
        /^\/(?:incidents|hazards|control-room\/alerts|health-safety\/(?:events|corrective-actions|risk-assessments|procedures|drills))\/[1-9]\d*$/.test(
            path,
        ) ||
        /^\/health-safety\/lone-workers\/sessions\/[1-9]\d*$/.test(path);
    let base: string;
    if (path === '/health-safety' || path === '/dashboard') {
        base = path;
    } else if (injury || path === '/health-safety/injuries') {
        const leaf = new URL(target); leaf.searchParams.delete('return_to');
        const destination = injuryReadLeaf(leaf.pathname + leaf.search);
        if (!destination) return null;
        base = destination;
    } else if (own || path === FIRST_AID_REGISTER) {
        const query = new URLSearchParams();
        for (const key of own
            ? ['section', 'followup']
            : [
                  'tab',
                  'site_id',
                  'treated_person_type',
                  'treatment_outcome',
                  'first_aider_id',
                  'period',
                  'q',
                  'page',
              ]) {
            const item = target.searchParams.get(key);
            if (
                !item ||
                target.searchParams.getAll(key).length !== 1 ||
                byteLength(item) > 200 ||
                [...item].some(
                    (char) =>
                        char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127,
                )
            )
                continue;
            if (
                ['followup', 'site_id', 'first_aider_id', 'page'].includes(
                    key,
                ) &&
                !positive(item)
            )
                continue;
            if (
                key === 'section' &&
                !FIRST_AID_SECTIONS.some((value) => value === item)
            )
                continue;
            if (
                key === 'tab' &&
                ![
                    'all',
                    'ambulance',
                    'linked',
                    'reportable',
                    'followup',
                ].includes(item)
            )
                continue;
            if (
                key === 'period' &&
                !['week', '30d', 'quarter', 'all'].includes(item)
            )
                continue;
            if (
                key === 'treated_person_type' &&
                !['staff', 'client', 'visitor', 'contractor'].includes(item)
            )
                continue;
            if (
                key === 'treatment_outcome' &&
                ![
                    'returned_to_activity',
                    'sent_home',
                    'medical_centre',
                    'sent_to_hospital',
                    'ongoing_monitoring',
                    'refused_treatment',
                    'other',
                ].includes(item)
            )
                continue;
            query.set(key, item);
        }
        base = path + (query.size ? `?${query}` : '');
    } else if (
        /^\/(?:sites|operations\/clients)\/[1-9]\d*$/.test(path) &&
        target.searchParams.get('tab') === 'first_aid'
    ) {
        base = path + '?tab=first_aid';
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = loneWorkerReturn(leaf.pathname + leaf.search);
        if (base === LONE_WORKER_REGISTER && path !== LONE_WORKER_REGISTER)
            return null;
    }
    if (nested && record) {
        const origin = clean(target.searchParams.get('return_to'), false);
        if (origin && !containsPath(origin, path))
            base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(origin)}`;
    }
    return base;
}
/** A bounded navigation hint; each destination retains independent authorization. */
export function firstAidReturn(value: string | null | undefined) {
    return clean(value, true) ?? FIRST_AID_REGISTER;
}
export function firstAidLocation(value: string) {
    const query = new URLSearchParams(value.split('?')[1]?.split('#')[0] ?? '');
    return {
        section:
            FIRST_AID_SECTIONS.find((item) => item === query.get('section')) ??
            'overview',
        command:
            FIRST_AID_COMMANDS.find((item) => item === query.get('action')) ??
            null,
        followup: positive(query.get('followup')),
        attachment: positive(query.get('attachment')),
        returnTo: firstAidReturn(query.get('return_to')),
    };
}
export function firstAidHref(
    id: number,
    options: {
        section?: FirstAidSection;
        command?: FirstAidCommand | null;
        followup?: number | null;
        attachment?: number | null;
        returnTo?: string;
    } = {},
) {
    if (!Number.isSafeInteger(id) || id < 1) return FIRST_AID_REGISTER;
    const path = `${FIRST_AID_REGISTER}/${id}`,
        query = new URLSearchParams();
    if (options.section) query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    for (const key of ['followup', 'attachment'] as const) {
        const value = options[key];
        if (value && Number.isSafeInteger(value) && value > 0)
            query.set(key, String(value));
    }
    const back = clean(options.returnTo, true);
    if (back && !containsPath(back, path)) query.set('return_to', back);
    return path + (query.size ? `?${query}` : '');
}
