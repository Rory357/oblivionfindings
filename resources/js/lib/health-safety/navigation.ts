import type {
    EventActionKey,
    EventSectionKey,
    SafetyReviewPane,
} from '@/components/health-safety/event-detail-dialog';
import { drillReturn } from './drill-navigation';
import { exposureHref, exposureReturn, type ExposureSection } from './chemical-exposure-navigation';
import { exposureLeaf, parseExposureNavigation, positiveExposureIdentity } from './chemical-exposure-leaf';
import { firstAidReturn } from './first-aid-navigation';
import { hazardReturn } from './hazard-navigation';
import { loneWorkerReturn } from './lone-worker-navigation';
import { procedureReturn } from './procedure-navigation';
import { riskReturn } from './risk-navigation';

/** Keep supported return context without accepting external or nested redirects. */
export function safetyReviewReturn(value: string | null | undefined): string {
    const fallback = '/health-safety/events';
    if (/^\/health-safety\/injuries(?:\/[1-9]\d*)?(?:\?|$)/.test(value ?? ''))
        return firstAidReturn(value);
    if (/^\/health-safety\/first-aid\/[1-9]\d*(?:\?|$)/.test(value ?? ''))
        return firstAidReturn(value);
    if (
        /^\/health-safety\/lone-workers\/sessions\/[1-9]\d*(?:\?|$)/.test(
            value ?? '',
        )
    )
        return loneWorkerReturn(value);
    if (!value?.startsWith('/') || value.startsWith('//')) return fallback;
    let target: URL;
    try {
        target = new URL(value, 'https://safety-return.invalid');
    } catch {
        return fallback;
    }
    if (
        target.origin !== 'https://safety-return.invalid' ||
        !(
            [
                '/health-safety/events',
                '/health-safety/corrective-actions',
                '/my-day',
                '/tasks',
            ].includes(target.pathname) ||
            /^\/(?:hazards|incidents|control-room\/alerts|health-safety\/(?:events|corrective-actions|risk-assessments|procedures|drills))\/[1-9]\d*$/.test(
                target.pathname,
            )
        )
    )
        return fallback;
    if (/^\/health-safety\/procedures\/[1-9]\d*$/.test(target.pathname))
        return procedureReturn(value);
    if (/^\/health-safety\/drills\/[1-9]\d*$/.test(target.pathname))
        return drillReturn(value);
    const allowed = new Set([
        'q',
        'tab',
        'severity',
        'category',
        'source',
        'site_id',
        'client_id',
        'status',
        'priority',
        'assigned_to',
        'from',
        'to',
        'range',
        'page',
        'section',
        'unassigned',
        'worksafe',
    ]);
    if (target.pathname === '/tasks') {
        [
            'sources',
            'bucket',
            'assigned',
            'overdue',
            'due',
            'following',
            'done',
        ].forEach((key) => allowed.add(key));
    }
    if (target.pathname === '/health-safety/events') {
        ['q', 'priority', 'site_id', 'from', 'to', 'unassigned', 'tab'].forEach(
            (key) => allowed.add(`action_context[${key}]`),
        );
    }
    const query = new URLSearchParams();
    target.searchParams.forEach((value, key) => {
        if (allowed.has(key)) query.set(key, value);
    });
    // One bounded level: a hazard may itself return to its filtered worklist.
    // hazardReturn never accepts another hazard or nested redirect.
    if (
        /^\/hazards\/[1-9]\d*$/.test(target.pathname) &&
        target.searchParams.has('return_to')
    ) {
        query.set(
            'return_to',
            hazardReturn(target.searchParams.get('return_to')),
        );
    }
    if (
        /^\/health-safety\/risk-assessments\/[1-9]\d*$/.test(target.pathname) &&
        target.searchParams.has('return_to')
    ) {
        query.set(
            'return_to',
            riskReturn(target.searchParams.get('return_to')),
        );
    }
    return target.pathname + (query.size ? `?${query}` : '');
}

/** Safety record pages retain the queue context; other task links stay source-owned. */
export function safetyWorkspaceTaskHref(
    href: string,
    returnTo: string,
): string {
    if (/^\/health-safety\/chemical-exposures\//.test(href)) {
        const leaf = exposureLeaf(href); const original = parseExposureNavigation(href);
        if (!leaf || !original) return '/health-safety/chemical-exposures';
        const target = new URL(leaf, 'https://safety-return.invalid');
        const id = positiveExposureIdentity(target.pathname.split('/').pop() ?? null);
        if (id === null) return '/health-safety/chemical-exposures';
        const section = target.searchParams.get('section') as ExposureSection | null;
        const followup = original.searchParams.getAll('followup').length === 1
            ? positiveExposureIdentity(original.searchParams.get('followup')) : null;
        return exposureHref(id, { section: section ?? undefined, followup: section === 'followups' ? followup ?? undefined : undefined,
            pages: Object.fromEntries([...target.searchParams].filter(([key]) => key.endsWith('_page')).map(([key, value]) => [key, Number(value)])),
            returnTo: exposureReturn(returnTo) });
    }
    if (/^\/health-safety\/first-aid\/[1-9]\d*(?:\?|$)/.test(href)) {
        const target = new URL(
            firstAidReturn(href),
            'https://safety-return.invalid',
        );
        target.searchParams.set('return_to', firstAidReturn(returnTo));
        return target.pathname + target.search;
    }
    if (/^\/health-safety\/drills\/[1-9]\d*(?:\?|$)/.test(href)) {
        const target = new URL(
            drillReturn(href),
            'https://safety-return.invalid',
        );
        target.searchParams.set('return_to', drillReturn(returnTo));
        return target.pathname + target.search;
    }
    if (/^\/health-safety\/procedures\/[1-9]\d*(?:\?|$)/.test(href)) {
        const target = new URL(
            procedureReturn(href),
            'https://safety-return.invalid',
        );
        target.searchParams.set('return_to', procedureReturn(returnTo));
        return target.pathname + target.search;
    }
    if (/^\/hazards\/[1-9]\d*(?:[?#]|$)/.test(href)) {
        const target = new URL(href, 'https://safety-return.invalid');
        target.searchParams.set('return_to', hazardReturn(returnTo));
        return target.pathname + target.search + target.hash;
    }
    if (
        !/^\/health-safety\/(?:corrective-actions|events)\/[1-9]\d*(?:\?|$)/.test(
            href,
        )
    )
        return href;
    const target = new URL(href, 'https://safety-return.invalid');
    target.searchParams.set('return_to', safetyReviewReturn(returnTo));
    return target.pathname + target.search;
}

export type CorrectiveActionPane = 'complete' | 'verify' | 'return' | 'owner';
export type CorrectiveActionSection = 'overview' | 'evidence' | 'history';

export function correctiveActionHref(
    id: number,
    options: {
        section?: CorrectiveActionSection;
        action?: CorrectiveActionPane | null;
        returnTo?: string;
    } = {},
): string {
    const query = new URLSearchParams();
    if (options.section && options.section !== 'overview')
        query.set('section', options.section);
    if (options.action) query.set('action', options.action);
    if (options.returnTo)
        query.set('return_to', safetyReviewReturn(options.returnTo));
    return `/health-safety/corrective-actions/${id}${query.size ? `?${query}` : ''}`;
}

export function safetyReviewHref(
    id: number,
    options: {
        section?: EventSectionKey;
        action?: EventActionKey;
        returnTo?: string;
        pane?: SafetyReviewPane | null;
    } = {},
): string {
    const params = new URLSearchParams();
    if (options.section) params.set('section', options.section);
    if (options.action)
        params.set('action', options.action.replaceAll('_', '-'));
    if (options.pane) {
        const pane = options.pane;
        params.set('action', pane.kind.replaceAll('_', '-'));
        if ('investigationId' in pane)
            params.set('investigation', String(pane.investigationId));
        if ('actionId' in pane)
            params.set('corrective_action', String(pane.actionId));
        if ('recommendationIndex' in pane)
            params.set('recommendation', String(pane.recommendationIndex));
    }
    const returnTo = safetyReviewReturn(options.returnTo);
    if (returnTo !== '/health-safety/events') params.set('return_to', returnTo);
    return `/health-safety/events/${id}${params.size ? `?${params}` : ''}`;
}

/** Targets stay record IDs only; the loaded review supplies parent membership and authority. */
export function safetyReviewPaneFromUrl(url: string): SafetyReviewPane | null {
    const query = new URLSearchParams(url.split('?')[1]?.split('#')[0] ?? '');
    const action = query.get('action')?.replaceAll('_', '-');
    const positiveId = (key: string) => {
        const value = query.get(key) ?? '';
        return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))
            ? Number(value)
            : null;
    };
    const investigationId = positiveId('investigation');
    const actionId = positiveId('corrective_action');
    if (action === 'inv-start' || action === 'investigation')
        return { kind: 'inv_start' };
    if (action === 'ca-add' || action === 'add-action')
        return { kind: 'ca_add' };
    if (
        investigationId &&
        ['inv-findings', 'inv-complete', 'inv-return'].includes(action ?? '')
    ) {
        return {
            kind: action!.replaceAll('-', '_') as
                | 'inv_findings'
                | 'inv_complete'
                | 'inv_return',
            investigationId,
        };
    }
    if (investigationId && action === 'inv-disposition') {
        const raw = query.get('recommendation') ?? '';
        if (/^(0|[1-9]\d*)$/.test(raw) && Number.isSafeInteger(Number(raw)))
            return {
                kind: 'inv_disposition',
                investigationId,
                recommendationIndex: Number(raw),
            };
    }
    if (
        actionId &&
        ['ca-complete', 'ca-verify', 'ca-return'].includes(action ?? '')
    ) {
        return {
            kind: action!.replaceAll('-', '_') as
                | 'ca_complete'
                | 'ca_verify'
                | 'ca_return',
            actionId,
        };
    }
    return null;
}
