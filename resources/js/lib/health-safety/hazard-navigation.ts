import type {
    HazardDetail,
    HazardSectionKey,
} from '@/components/health-safety/hazard-kit';

export type HazardPane =
    | {
          kind:
              | 'assign'
              | 'start'
              | 'mitigate'
              | 'add_action'
              | 'review'
              | 'close';
      }
    | { kind: 'complete_action'; actionId: number };

/** Keep the originating worklist, but never an external or recursive redirect. */
export function hazardReturn(value?: string | null): string {
    const fallback = '/compliance/hazards';
    if (!value?.startsWith('/') || value.startsWith('//')) return fallback;
    let target: URL;
    try {
        target = new URL(value, 'https://hazard-return.invalid');
    } catch {
        return fallback;
    }
    if (
        target.origin !== 'https://hazard-return.invalid' ||
        !(
            ['/compliance/hazards', '/my-day', '/tasks'].includes(
                target.pathname,
            ) ||
            /^\/sites\/[1-9]\d*(?:\/hazards)?$/.test(target.pathname) ||
            /^\/operations\/clients\/[1-9]\d*$/.test(target.pathname)
        )
    )
        return fallback;
    const allowed = new Set([
        'q',
        'tab',
        'site_id',
        'site_type',
        'severity',
        'risk_rating',
        'assignee_id',
        'due_state',
        'from',
        'to',
        'page',
        'section',
    ]);
    if (target.pathname === '/tasks')
        [
            'sources',
            'bucket',
            'assigned',
            'overdue',
            'due',
            'following',
            'done',
        ].forEach((key) => allowed.add(key));
    const query = new URLSearchParams();
    target.searchParams.forEach((value, key) => {
        if (allowed.has(key)) query.set(key, value);
    });
    return target.pathname + (query.size ? `?${query}` : '');
}

export function hazardHref(
    id: number,
    options: {
        section?: HazardSectionKey;
        pane?: HazardPane | null;
        returnTo?: string;
    } = {},
): string {
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview')
        query.set('section', options.section);
    if (options.pane) {
        query.set('action', options.pane.kind.replaceAll('_', '-'));
        if ('actionId' in options.pane)
            query.set('corrective_action', String(options.pane.actionId));
    }
    const returnTo = hazardReturn(options.returnTo);
    if (returnTo !== '/compliance/hazards') query.set('return_to', returnTo);
    return `/hazards/${id}${query.size ? `?${query}` : ''}`;
}

export function hazardLocation(url: string): {
    section: HazardSectionKey;
    pane: HazardPane | null;
    returnTo: string;
} {
    const query = new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '');
    const requested = query.get('section') as HazardSectionKey;
    const section = [
        'overview',
        'risk',
        'actions',
        'evidence',
        'history',
        'connections',
    ].includes(requested)
        ? requested
        : 'overview';
    const kind = query.get('action')?.replaceAll('-', '_');
    let pane: HazardPane | null = null;
    if (
        [
            'assign',
            'start',
            'mitigate',
            'add_action',
            'review',
            'close',
        ].includes(kind ?? '')
    )
        pane = { kind } as HazardPane;
    const id = query.get('corrective_action') ?? '';
    if (
        kind === 'complete_action' &&
        /^[1-9]\d*$/.test(id) &&
        Number.isSafeInteger(Number(id))
    )
        pane = { kind, actionId: Number(id) };
    return { section, pane, returnTo: hazardReturn(query.get('return_to')) };
}

/** The URL is intent only; the current record and permissions decide availability. */
export function hazardPaneAllowed(pane: HazardPane, d: HazardDetail): boolean {
    if (d.status === 'closed') return false;
    if (pane.kind === 'assign') return d.can.assign;
    if (pane.kind === 'close') return d.can.close;
    if (!d.can.manage) return false;
    if (pane.kind === 'start') return d.status === 'open';
    if (pane.kind === 'mitigate') return d.status === 'in_progress';
    if (pane.kind === 'complete_action')
        return d.actions.some(
            (a) => a.id === pane.actionId && a.status !== 'completed',
        );
    return true;
}

/** Add context only to destinations that implement the hazard return contract. */
export function hazardConnectionHref(href: string, returnTo?: string): string {
    if (
        !returnTo?.startsWith('/') ||
        returnTo.startsWith('//') ||
        !/^\/(?:hazards|health-safety\/(?:events|corrective-actions)|control-room\/alerts)\/[1-9]\d*(?:[?#]|$)/.test(
            href,
        )
    )
        return href;
    const record = new URL(returnTo, 'https://hazard-return.invalid');
    const id = /^\/hazards\/([1-9]\d*)$/.exec(record.pathname)?.[1];
    if (
        !id ||
        !Number.isSafeInteger(Number(id)) ||
        record.origin !== 'https://hazard-return.invalid'
    )
        return href;
    const context = hazardLocation(returnTo);
    const target = new URL(href, 'https://hazard-return.invalid');
    if (target.pathname.startsWith('/hazards/')) {
        if (target.pathname !== record.pathname) return href;
        target.searchParams.set('return_to', context.returnTo);
        return target.pathname + target.search + target.hash;
    }
    target.searchParams.set(
        'return_to',
        hazardHref(Number(id), {
            section: 'connections',
            returnTo: context.returnTo,
        }),
    );
    return target.pathname + target.search + target.hash;
}
