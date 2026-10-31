import { describe, expect, it } from 'vitest';
import {
    correctiveActionHref,
    safetyReviewHref,
    safetyReviewPaneFromUrl,
    safetyReviewReturn,
    safetyWorkspaceTaskHref,
} from './navigation';

describe('Safety review navigation', () => {
    it('retains an exact private follow-up read hint and filtered task origin without carrying commands or receipt keys', () => {
        const href = safetyWorkspaceTaskHref('/health-safety/chemical-exposures/22?section=followups&followup=31&followups_page=3&action=complete_followup&receipt_key=private&return_to=%2Fsettings',
            '/tasks?assigned=me&sources=chemical_exposure_followup&page=2&q=follow-up');
        const target = new URL(href, 'https://example.test');
        expect(target.pathname).toBe('/health-safety/chemical-exposures/22');
        expect(target.searchParams.get('section')).toBe('followups'); expect(target.searchParams.get('followup')).toBe('31');
        expect(target.searchParams.get('followups_page')).toBe('3');
        expect(target.searchParams.has('action')).toBe(false); expect(target.searchParams.has('receipt_key')).toBe(false);
        const back = new URL(target.searchParams.get('return_to')!, 'https://example.test');
        expect(back.pathname).toBe('/tasks'); expect(back.searchParams.get('sources')).toBe('chemical_exposure_followup');
        expect(back.searchParams.get('page')).toBe('2'); expect(back.searchParams.get('q')).toBe('follow-up');
    });
    it('rejects ambiguous or invalid private follow-up hints while retaining unrelated source-owned links', () => {
        for (const suffix of ['followup=31&followup=32', 'followup=0', 'followup=2147483648']) {
            const target = new URL(safetyWorkspaceTaskHref('/health-safety/chemical-exposures/22?section=followups&' + suffix, '/my-day'), 'https://example.test');
            expect(target.searchParams.has('followup')).toBe(false); expect(target.searchParams.get('return_to')).toBe('/my-day');
        }
        expect(safetyWorkspaceTaskHref('/health-safety/chemical-exposures/22?followup[]=31', '/my-day')).toBe('/health-safety/chemical-exposures');
        expect(safetyWorkspaceTaskHref('/it/tickets/8?tab=activity', '/my-day')).toBe('/it/tickets/8?tab=activity');
    });
    it('restores the selected treatment follow-up and a bounded filtered task queue', () => {
        const origin =
            '/tasks?sources=first_aid_followup&assigned=me&q=call&page=2';
        const source =
            '/health-safety/first-aid/9?section=followups&followup=3&action=complete-followup&attachment=7';
        const target = new URL(
            safetyWorkspaceTaskHref(source, origin),
            'https://app.example',
        );
        expect(target.searchParams.get('followup')).toBe('3');
        const returned = new URL(
            target.searchParams.get('return_to')!,
            'https://app.example',
        );
        expect(returned.pathname).toBe('/tasks');
        expect(Object.fromEntries(returned.searchParams)).toEqual(
            Object.fromEntries(
                new URL(origin, 'https://app.example').searchParams,
            ),
        );
        expect(target.searchParams.has('action')).toBe(false);
        expect(target.searchParams.has('attachment')).toBe(false);
        expect(safetyReviewReturn(target.pathname + target.search)).toBe(
            target.pathname + target.search,
        );
    });
    it('preserves the exact procedure request and version while returning to a filtered task queue', () => {
        const origin =
            '/tasks?assigned=me&sources=procedure_review_request&q=hoist&page=2';
        const target = new URL(
            safetyWorkspaceTaskHref(
                '/health-safety/procedures/7?section=connections&request=42&version=22&action=archive&return_to=/my-day',
                origin,
            ),
            'https://app.example',
        );
        expect(target.pathname).toBe('/health-safety/procedures/7');
        expect(target.searchParams.get('request')).toBe('42');
        expect(target.searchParams.get('version')).toBe('22');
        expect(target.searchParams.get('section')).toBe('connections');
        expect(target.searchParams.has('action')).toBe(false);
        const returned = new URL(
            target.searchParams.get('return_to')!,
            'https://app.example',
        );
        expect(returned.pathname).toBe('/tasks');
        expect(Object.fromEntries(returned.searchParams)).toEqual({
            assigned: 'me',
            sources: 'procedure_review_request',
            q: 'hoist',
            page: '2',
        });
    });
    it('restores a hazard and its filtered worklist while stripping further nested navigation', () => {
        const origin = '/sites/2/hazards?q=stairs&tab=overdue&page=3';
        const hazard = `/hazards/8?section=connections&action=close&return_to=${encodeURIComponent(`${origin}&return_to=/settings`)}`;
        const returned = new URL(
            safetyReviewReturn(hazard),
            'https://app.example',
        );
        expect(returned.pathname).toBe('/hazards/8');
        expect(returned.searchParams.get('section')).toBe('connections');
        expect(returned.searchParams.has('action')).toBe(false);
        expect(returned.searchParams.get('return_to')).toBe(origin);
        expect(safetyReviewReturn('/hazards/8?return_to=%2Fhazards%2F9')).toBe(
            '/hazards/8?return_to=%2Fcompliance%2Fhazards',
        );
    });
    it('keeps WorkSafe and occurrence refinements when returning from a review', () => {
        const returnTo =
            '/health-safety/events?worksafe=1&severity=high&from=2026-10-01&to=2026-10-08&site_id=2';
        const href = safetyReviewHref(17, { returnTo });
        expect(
            new URL(href, 'https://app.example').searchParams.get('return_to'),
        ).toBe(returnTo);
    });
    it('keeps the bounded action filters when returning to a monitoring-review worklist', () => {
        const params = new URLSearchParams({
            tab: 'monitoring',
            'action_context[q]': 'repair',
            'action_context[from]': '2026-10-01',
            'action_context[tab]': 'critical_open',
            'action_context[return_to]': 'https://other.example',
            'action_context[q][]': 'unsupported',
        });
        const href = safetyReviewHref(17, {
            returnTo: `/health-safety/events?${params}`,
        });
        const returned = new URL(
            new URL(href, 'https://app.example').searchParams.get('return_to')!,
            'https://app.example',
        );
        expect(returned.pathname).toBe('/health-safety/events');
        expect(returned.searchParams.get('action_context[q]')).toBe('repair');
        expect(returned.searchParams.get('action_context[from]')).toBe(
            '2026-10-01',
        );
        expect(returned.searchParams.get('action_context[tab]')).toBe(
            'critical_open',
        );
        expect(returned.searchParams.has('action_context[return_to]')).toBe(
            false,
        );
        expect(returned.searchParams.has('action_context[q][]')).toBe(false);
    });
    it('restores the complete Tasks filter context without carrying nested navigation', () => {
        const returnTo =
            '/tasks?sources=corrective_action&severity=high&bucket=in_progress&assigned=me&overdue=1&due=week&following=1&done=1&q=repair&page=2';
        expect(
            safetyReviewReturn(
                `${returnTo}&action=close&return_to=https://other.example`,
            ),
        ).toBe(returnTo);
        const href = safetyWorkspaceTaskHref(
            '/health-safety/corrective-actions/42?section=evidence',
            returnTo,
        );
        const query = new URL(href, 'https://app.example').searchParams;
        expect(query.get('section')).toBe('evidence');
        expect(query.get('return_to')).toBe(returnTo);
        expect(
            safetyWorkspaceTaskHref('/control-room/alerts/9', returnTo),
        ).toBe('/control-room/alerts/9');
        expect(
            safetyWorkspaceTaskHref(
                '/health-safety/corrective-actions/42/close',
                returnTo,
            ),
        ).toBe('/health-safety/corrective-actions/42/close');
        expect(
            safetyReviewReturn(
                '/health-safety/events/7?assigned=me&following=1&section=actions',
            ),
        ).toBe('/health-safety/events/7?section=actions');
    });
    it('opens the exact corrective action and retains its parent review section', () => {
        expect(
            correctiveActionHref(42, {
                action: 'verify',
                returnTo:
                    '/health-safety/events/77?section=actions&return_to=https://other.example',
            }),
        ).toBe(
            '/health-safety/corrective-actions/42?action=verify&return_to=%2Fhealth-safety%2Fevents%2F77%3Fsection%3Dactions',
        );
    });
    it('keeps the filtered register but removes nested navigation and modal state', () => {
        expect(
            safetyReviewReturn(
                '/health-safety/events?tab=open&site_id=3&q=fall&page=2&event=17&return_to=https://other.example#old',
            ),
        ).toBe('/health-safety/events?tab=open&site_id=3&q=fall&page=2');
        expect(
            safetyReviewReturn(
                '/health-safety/corrective-actions?unassigned=1&priority=high',
            ),
        ).toBe('/health-safety/corrective-actions?unassigned=1&priority=high');
    });

    it.each([
        'https://other.example',
        '//other.example',
        '/\\other.example',
        '/settings/users',
        '/incidents/0',
        '/control-room/alerts/6/edit',
        '/%2e%2e/settings',
    ])('rejects an unsupported return: %s', (url) => {
        expect(safetyReviewReturn(url)).toBe('/health-safety/events');
    });

    it('keeps a drill finding and its filtered origin through review/action and Tasks links', () => {
        const origin =
            '/health-safety/drills/8?section=findings&finding=19&action=resolve-finding&return_to=' +
            encodeURIComponent('/health-safety/drills?tab=findings&site_id=2');
        const safe = new URL(
            safetyReviewReturn(origin),
            'https://example.test',
        );
        expect(safe.pathname).toBe('/health-safety/drills/8');
        expect(safe.searchParams.get('finding')).toBe('19');
        expect(safe.searchParams.has('action')).toBe(false);
        expect(
            new URL(
                safe.searchParams.get('return_to')!,
                'https://example.test',
            ).searchParams.get('site_id'),
        ).toBe('2');
        const task = new URL(
            safetyWorkspaceTaskHref(
                origin,
                '/tasks?assigned=me&sources=emergency_drill_finding&due=week',
            ),
            'https://example.test',
        );
        expect(task.searchParams.get('finding')).toBe('19');
        const back = new URL(
            task.searchParams.get('return_to')!,
            'https://example.test',
        );
        expect(back.searchParams.get('sources')).toBe(
            'emergency_drill_finding',
        );
        expect(back.searchParams.get('due')).toBe('week');
    });

    it('preserves an allowed incident or response return', () => {
        expect(safetyReviewReturn('/hazards/8?section=connections')).toBe(
            '/hazards/8?section=connections',
        );
        expect(safetyReviewReturn('/incidents/42?section=linked')).toBe(
            '/incidents/42?section=linked',
        );
        expect(safetyReviewReturn('/control-room/alerts/6?tab=overview')).toBe(
            '/control-room/alerts/6?tab=overview',
        );
    });

    it('normalises existing menu actions and retains return context', () => {
        expect(
            safetyReviewHref(17, {
                action: 'accept_handover',
                returnTo: '/health-safety/events?site_id=3',
            }),
        ).toBe(
            '/health-safety/events/17?action=accept-handover&return_to=%2Fhealth-safety%2Fevents%3Fsite_id%3D3',
        );
    });

    it.each([
        { kind: 'inv_findings', investigationId: 31 },
        { kind: 'inv_complete', investigationId: 31 },
        { kind: 'inv_return', investigationId: 31 },
        {
            kind: 'inv_disposition',
            investigationId: 31,
            recommendationIndex: 0,
        },
        { kind: 'ca_complete', actionId: 61 },
        { kind: 'ca_verify', actionId: 61 },
        { kind: 'ca_return', actionId: 61 },
        { kind: 'inv_start' },
        { kind: 'ca_add' },
    ] as const)('round trips a specific workflow: $kind', (pane) => {
        expect(safetyReviewPaneFromUrl(safetyReviewHref(17, { pane }))).toEqual(
            pane,
        );
    });

    it.each([
        'action=inv-findings&investigation=0',
        'action=inv-findings&investigation=-1',
        'action=inv-findings&investigation=1.5',
        'action=inv-findings&investigation=9007199254740992',
        'action=ca-verify&corrective_action=61oops',
        'action=ca-verify',
        'action=inv-disposition&investigation=31',
        'action=inv-disposition&investigation=31&recommendation=-1',
        'action=unknown&investigation=31',
    ])('does not guess a missing or invalid record target: %s', (query) => {
        expect(
            safetyReviewPaneFromUrl(`/health-safety/events/17?${query}`),
        ).toBeNull();
    });
});
