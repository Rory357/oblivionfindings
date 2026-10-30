import { describe, expect, it } from 'vitest';
import {
    actionCommandUrl,
    actionSourceUrl,
    confirmedAction,
    currentActionSummary,
    mayConfirmAction,
    prepareActionIntent,
    readActionSource,
    type ActionIntent,
    type AlertSource,
    type PositionSource,
} from './_action-contract';

export const source: PositionSource = {
    actor_id: 18,
    action: 'claim',
    source_revision: 'a'.repeat(64),
    display: {
        position_id: 70,
        shift_id: 32,
        status: 'open',
        starts_at: '2026-10-20T20:00:00.123456Z',
        ends_at: '2026-10-21T04:00:00Z',
        client: 'J. R.',
        client_redacted: true,
        site: 'Matai House',
        claimed_by: null,
        claimant: null,
    },
    can: { claim: true, approve: false },
    urls: {
        claim: '/operations/job-board/70/claim',
        approve: '/operations/job-board/70/approve',
    },
    eligibility: {
        assessment_status: 'assessed',
        warning_reasons: ['The gap between duties needs review.'],
        blocked_reasons: [],
        acknowledgement_required: false,
        reason_required: false,
    },
};
export const requestId = '12345678-1234-4234-8234-123456789abc';
const target = { action: 'claim' as const, positionId: 70 };
export function approvedSource(): PositionSource {
    return {
        ...structuredClone(source),
        action: 'approve',
        can: { claim: false, approve: true },
        display: {
            ...source.display,
            status: 'claimed',
            claimed_by: 24,
            claimant: 'Worker',
        },
    };
}
export function resultFor(intent: ActionIntent) {
    const alert = intent.target.action === 'set_alert_preference';
    const approve = intent.target.action === 'approve';
    const changed =
        alert && 'enabled' in intent.source
            ? intent.source.enabled !== intent.body.enabled
            : true;
    return {
        result: {
            version: 1,
            scope: 'job_board',
            action: intent.target.action,
            outcome: alert
                ? changed
                    ? 'changed'
                    : 'unchanged'
                : approve
                  ? 'approved'
                  : 'claimed',
            changed,
            actor_id: intent.source.actor_id,
            request_id: intent.body.request_id,
            source_revision: intent.body.source_revision,
            result_revision: 'b'.repeat(64),
            committed_at: '2026-10-10T11:00:00.987654Z',
            position: alert
                ? null
                : {
                      id: 70,
                      shift_id: 32,
                      status: approve ? 'filled' : 'claimed',
                      claimed_by: approve ? 24 : 18,
                      claimed_at: '2026-10-10T10:00:00Z',
                      approved_by: approve ? 18 : null,
                      approved_at: approve ? '2026-10-10T11:00:00Z' : null,
                  },
            shift: alert
                ? null
                : {
                      id: 32,
                      status: 'in_progress',
                      assigned: approve,
                      starts_at: source.display.starts_at,
                      ends_at: source.display.ends_at,
                  },
            preference: alert ? { enabled: intent.body.enabled } : null,
            eligibility: alert ? null : source.eligibility,
        },
    };
}

describe('current Job Board action contract', () => {
    it('keeps unknown assessment readable when denied but rejects an actionable unknown source', () => {
        const next = structuredClone(source);
        next.eligibility!.assessment_status = 'unknown';
        expect(() => readActionSource(next, target, 18)).toThrow();
        next.can.claim = false;
        expect(readActionSource(next, target, 18)).toEqual(next);
        expect(mayConfirmAction(next, target)).toBe(false);
    });
    it('does not complete a duty command whose result has an unknown assessment', () => {
        const intent = prepareActionIntent(source, target, 18, requestId);
        const result = resultFor(intent);
        result.result.eligibility = {
            ...structuredClone(source.eligibility!),
            assessment_status: 'unknown',
        };
        expect(confirmedAction(200, result, intent)).toBeNull();
    });
    it('uses only the numeric local command and source routes', () => {
        expect(actionSourceUrl(target)).toBe(
            '/operations/job-board/70/action-source?action=claim',
        );
        expect(actionCommandUrl(target)).toBe('/operations/job-board/70/claim');
        expect(() => actionSourceUrl({ ...target, positionId: NaN })).toThrow();
        expect(actionSourceUrl({ action: 'set_alert_preference' })).toBe(
            '/operations/job-board/alerts/preference',
        );
    });
    it('captures displayed warning, permitted initials and microsecond times without extra acknowledgement policy', () => {
        const intent = prepareActionIntent(source, target, 18, requestId);
        expect(intent.body).toEqual({
            request_id: requestId,
            source_revision: 'a'.repeat(64),
        });
        source.display.site = 'Changed site';
        expect((intent.source as PositionSource).display.site).toBe(
            'Matai House',
        );
        source.display.site = 'Matai House';
        expect(
            (intent.source as PositionSource).eligibility
                ?.acknowledgement_required,
        ).toBe(false);
    });
    it.each([
        [
            'old actor',
            (v: PositionSource) => {
                v.actor_id = 24;
            },
        ],
        [
            'other action',
            (v: PositionSource) => {
                v.action = 'approve';
            },
        ],
        [
            'other position',
            (v: PositionSource) => {
                v.display.position_id = 71;
            },
        ],
        [
            'invalid civil date',
            (v: PositionSource) => {
                v.display.starts_at = '2026-02-30T00:00:00Z';
            },
        ],
        [
            'external route',
            (v: PositionSource) => {
                v.urls.claim = 'https://example.invalid';
            },
        ],
        [
            'unassessed other action',
            (v: PositionSource) => {
                v.can.approve = true;
            },
        ],
        [
            'new acknowledgement policy',
            (v: PositionSource) => {
                Object.assign(v.eligibility!, {
                    acknowledgement_required: true,
                });
            },
        ],
        [
            'private field',
            (v: PositionSource) => {
                Object.assign(v.display, { leave_reason: 'private' });
            },
        ],
        [
            'permitted block',
            (v: PositionSource) => {
                v.eligibility!.blocked_reasons.push('Blocked');
            },
        ],
    ] as const)('rejects a source with %s', (_, change) => {
        const next = structuredClone(source);
        change(next);
        expect(() => readActionSource(next, target, 18)).toThrow();
    });
    it('keeps a current denied source readable but prevents confirmation', () => {
        const denied = {
            ...source,
            can: { claim: false, approve: false },
            eligibility: null,
        };
        expect(readActionSource(denied, target, 18)).toEqual(denied);
        expect(mayConfirmAction(denied, target)).toBe(false);
        expect(() =>
            prepareActionIntent(denied, target, 18, requestId),
        ).toThrow();
    });
    it('accepts matching confirmed claim with its safe warnings and nullable shift times', () => {
        const next = structuredClone(source);
        next.display.starts_at = null;
        next.display.ends_at = null;
        const intent = prepareActionIntent(next, target, 18, requestId);
        const result = resultFor(intent);
        result.result.shift!.starts_at = null;
        result.result.shift!.ends_at = null;
        expect(confirmedAction(200, result, intent)?.outcome).toBe('claimed');
        expect(confirmedAction(200, result, intent)?.warnings).toEqual(
            source.eligibility!.warning_reasons,
        );
    });
    it('requires approved worker, approving actor and actual assignment while preserving in-progress policy', () => {
        const intent = prepareActionIntent(
            approvedSource(),
            { action: 'approve', positionId: 70 },
            18,
            requestId,
        );
        const result = resultFor(intent);
        expect(confirmedAction(200, result, intent)?.outcome).toBe('approved');
        result.result.shift!.assigned = false;
        expect(confirmedAction(200, result, intent)).toBeNull();
    });
    it.each([
        'version',
        'scope',
        'actor_id',
        'request_id',
        'source_revision',
        'result_revision',
        'committed_at',
        'action',
        'outcome',
        'changed',
    ])('holds a mismatched %s rather than showing success', (field) => {
        const intent = prepareActionIntent(source, target, 18, requestId);
        const result = resultFor(intent);
        Object.assign(result.result, {
            [field]: field === 'changed' ? false : null,
        });
        expect(confirmedAction(200, result, intent)).toBeNull();
    });
    it('holds changed dates, wrong claimed worker, unexpected rows and result-less success', () => {
        const intent = prepareActionIntent(source, target, 18, requestId);
        const changed = resultFor(intent);
        changed.result.shift!.starts_at = '2026-10-20T20:01:00Z';
        expect(confirmedAction(200, changed, intent)).toBeNull();
        const wrong = resultFor(intent);
        wrong.result.position!.claimed_by = 24;
        expect(confirmedAction(200, wrong, intent)).toBeNull();
        const extra = resultFor(intent);
        Object.assign(extra.result.position!, { user_email: 'private' });
        expect(confirmedAction(200, extra, intent)).toBeNull();
        expect(confirmedAction(200, { result: null }, intent)).toBeNull();
    });
    it.each([202, 302, 403, 404, 409, 422, 500])(
        'does not infer rollback or success from unconfirmed HTTP %s',
        (status) => {
            const intent = prepareActionIntent(source, target, 18, requestId);
            expect(
                confirmedAction(status, resultFor(intent), intent),
            ).toBeNull();
        },
    );
    it.each([true, false])(
        'sets alerts explicitly to %s and validates unchanged as well as changed',
        (enabled) => {
            const alert: AlertSource = {
                actor_id: 18,
                source_revision: 'a'.repeat(64),
                enabled: false,
                can_update: true,
            };
            const intent = prepareActionIntent(
                alert,
                { action: 'set_alert_preference' },
                18,
                requestId,
                enabled,
            );
            expect(intent.body.enabled).toBe(enabled);
            expect(
                confirmedAction(200, resultFor(intent), intent)?.outcome,
            ).toBe(enabled ? 'changed' : 'unchanged');
            const wrong = resultFor(intent);
            wrong.result.preference!.enabled = !enabled;
            expect(confirmedAction(200, wrong, intent)).toBeNull();
        },
    );
    it('a current read remains current state only, without matching the old UUID or promoting success', () => {
        const intent = prepareActionIntent(source, target, 18, requestId);
        const now = structuredClone(source);
        now.display.status = 'claimed';
        now.display.claimed_by = 18;
        now.display.claimant = 'Worker';
        now.can.claim = false;
        expect(currentActionSummary(now, intent)).toContain(
            'cannot show whether your earlier request went through',
        );
        expect(confirmedAction(200, now, intent)).toBeNull();
    });
});
