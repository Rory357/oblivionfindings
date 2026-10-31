import { calendarReturn } from '@/lib/calendar/navigation';
import { PROCEDURE_REGISTER, procedureReturn } from './procedure-navigation';

export const DRILL_REGISTER = '/health-safety/drills';
export const DRILL_SECTIONS = [
    'overview',
    'run',
    'participants',
    'findings',
    'evidence',
    'history',
    'connections',
] as const;
export type DrillSection = (typeof DRILL_SECTIONS)[number];
export const DRILL_COMMANDS = [
    'edit',
    'start',
    'complete',
    'cancel',
    'add-participant',
    'edit-participant',
    'add-finding',
    'edit-finding',
    'resolve-finding',
    'corrective-action',
    'schedule-next',
] as const;
export type DrillCommand = (typeof DRILL_COMMANDS)[number];
const ORIGIN = 'https://drill-return.invalid';
const positiveId = (value: string | null) =>
    value && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
        ? Number(value)
        : null;

function containsPath(value: string, path: string): boolean {
    const url = new URL(value, ORIGIN);
    const nested = url.searchParams.get('return_to');
    return (
        url.pathname === path ||
        (!!nested && new URL(nested, ORIGIN).pathname === path)
    );
}

function boundedReturn(
    value: string | null | undefined,
    nested: boolean,
): string | null {
    if (
        !value ||
        value.length > 1500 ||
        /[\\#]/.test(value) ||
        [...value].some(
            (char) => char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127,
        ) ||
        !value.startsWith('/') ||
        value.startsWith('//')
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
    const own = /^\/health-safety\/drills\/[1-9]\d*$/.test(target.pathname);
    const alert = /^\/control-room\/alerts\/[1-9]\d*$/.test(target.pathname);
    const alertList = target.pathname === '/control-room/alerts';
    const site =
        /^\/sites\/[1-9]\d*$/.test(target.pathname) &&
        target.searchParams.get('tab') === 'drills';
    const otherRecord =
        alert ||
        /^\/(?:incidents|hazards|health-safety\/(?:events|corrective-actions|risk-assessments|procedures))\/[1-9]\d*$/.test(
            target.pathname,
        );
    const query = new URLSearchParams();
    let base: string;
    const calendar = calendarReturn(value);
    if (calendar) return calendar;
    if (
        target.pathname === '/calendar' ||
        /^\/sites\/[1-9]\d*\/calendar$/.test(target.pathname)
    ) {
        // Calendar date/filter URL restoration is a separate integration contract.
        base = target.pathname;
    } else if (
        own ||
        site ||
        alert ||
        alertList ||
        target.pathname === DRILL_REGISTER
    ) {
        const keys = alert
            ? ['section']
            : alertList
              ? [
                    'lens',
                    'search',
                    'severity',
                    'source',
                    'queue_id',
                    'assigned_to',
                    'status',
                    'date_from',
                    'date_to',
                    'sort',
                    'dir',
                    'snoozed',
                    'page',
                ]
              : own
                ? ['section', 'finding']
                : site
                  ? ['tab']
                  : [
                        'tab',
                        'q',
                        'period',
                        'drill_type',
                        'outcome',
                        'site_id',
                        'page',
                    ];
        const choices: Record<string, readonly string[]> = {
            section: alert ? ['overview', 'tasks'] : DRILL_SECTIONS,
            lens: [
                'active',
                'history',
                'snoozed',
                'my_queue',
                'safety_handover',
            ],
            status: [
                'all',
                'open',
                'ack',
                'triaging',
                'confirmed',
                'dismissed',
                'resolved',
                'closed',
            ],
            severity: ['all', 'critical', 'high', 'medium', 'low', 'info'],
            sort: [
                'priority',
                'severity',
                'triggered_at',
                'status',
                'alert_type',
            ],
            dir: ['asc', 'desc'],
            snoozed: ['1'],
            tab: site
                ? ['drills']
                : [
                      'all',
                      'scheduled',
                      'overdue',
                      'in_progress',
                      'completed',
                      'findings',
                  ],
            period: ['30d', 'quarter', '6mo', 'all'],
            drill_type: [
                'fire',
                'fire_evacuation',
                'earthquake',
                'lockdown',
                'tsunami',
                'chemical_spill',
                'medical_emergency',
                'other',
            ],
            outcome: ['passed', 'passed_actions', 'failed'],
        };
        for (const key of keys) {
            const item = target.searchParams.get(key);
            if (
                !item ||
                item.length > 200 ||
                [...item].some(
                    (char) =>
                        char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127,
                )
            )
                continue;
            if (
                ['finding', 'site_id', 'page', 'queue_id'].includes(key) &&
                !positiveId(item)
            )
                continue;
            if (
                key === 'assigned_to' &&
                !['me', 'unassigned'].includes(item) &&
                !positiveId(item)
            )
                continue;
            if (
                ['date_from', 'date_to'].includes(key) &&
                (!/^\d{4}-\d{2}-\d{2}$/.test(item) ||
                    Number.isNaN(Date.parse(item)) ||
                    new Date(item).toISOString().slice(0, 10) !== item)
            )
                continue;
            if (choices[key] && !choices[key].includes(item)) continue;
            query.set(key, item);
        }
        base = target.pathname + (query.size ? `?${query}` : '');
    } else {
        const leaf = new URL(target);
        leaf.searchParams.delete('return_to');
        base = procedureReturn(leaf.pathname + leaf.search);
        if (base === PROCEDURE_REGISTER && leaf.pathname !== PROCEDURE_REGISTER)
            return null;
    }
    if (nested && (own || otherRecord)) {
        const origin = boundedReturn(
            target.searchParams.get('return_to'),
            false,
        );
        if (origin && !containsPath(origin, target.pathname))
            base += `${base.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(origin)}`;
    }
    return base;
}

/** Navigation context only; every destination must recheck source and Site access. */
export function drillReturn(value: string | null | undefined): string {
    return boundedReturn(value, true) ?? DRILL_REGISTER;
}

export function drillLocation(value: string) {
    const query = new URLSearchParams(value.split('?')[1]?.split('#')[0] ?? '');
    const action = query.get('action')?.replaceAll('_', '-');
    return {
        section:
            DRILL_SECTIONS.find(
                (section) => section === query.get('section'),
            ) ?? 'overview',
        command: DRILL_COMMANDS.find((command) => command === action) ?? null,
        finding: positiveId(query.get('finding')),
        participant: positiveId(query.get('participant')),
        returnTo: drillReturn(query.get('return_to')),
    };
}

export function drillHref(
    id: number,
    options: {
        section?: DrillSection;
        command?: DrillCommand | null;
        finding?: number | null;
        participant?: number | null;
        returnTo?: string;
    } = {},
): string {
    const path = `${DRILL_REGISTER}/${id}`;
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview')
        query.set('section', options.section);
    if (options.command) query.set('action', options.command);
    if (
        options.finding &&
        Number.isSafeInteger(options.finding) &&
        options.finding > 0
    )
        query.set('finding', String(options.finding));
    if (
        options.participant &&
        Number.isSafeInteger(options.participant) &&
        options.participant > 0
    )
        query.set('participant', String(options.participant));
    const origin = options.returnTo
        ? boundedReturn(options.returnTo, true)
        : null;
    if (origin && !containsPath(origin, path)) query.set('return_to', origin);
    return path + (query.size ? `?${query}` : '');
}
