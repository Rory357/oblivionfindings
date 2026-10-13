import type { CoverageGap } from './build-queue';
import type { ConflictConfirmKind } from './conflict-confirm-dialog';
export type CoverageReviewResult = {
    version: 1;
    scope: 'coverage_gap';
    action: ConflictConfirmKind;
    actor_id: number;
    request_id: string;
    window: {
        site_id: number;
        coverage_requirement_id: number | null;
        coverage_window_key: string;
        window_starts_at: string;
        window_ends_at: string;
    };
    outcome: 'recorded' | 'cleared' | 'unchanged';
    changed: boolean;
    state: 'acked' | 'dismissed' | null;
    acknowledgement_id: number | null;
    cleared_ids: number[];
    reason: string | null;
    audit_id: number;
    committed_at: string;
    staffing_resolved: false;
};
const object = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
const positiveId = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
function instant(value: unknown) {
    return typeof value === 'string' && Number.isFinite(Date.parse(value))
        ? Date.parse(value)
        : null;
}
/** Transport success is insufficient: match the physical-commit receipt to this exact attempt. */
export function confirmedCoverageReview(
    value: unknown,
    expected: {
        gap: CoverageGap;
        action: ConflictConfirmKind;
        actorId: number;
        requestId: string;
        reason?: string;
    },
): CoverageReviewResult | null {
    const result = object(value);
    const window = object(result?.window);
    const target = expected.gap.action_window;
    if (
        !result ||
        !window ||
        !target ||
        !positiveId(expected.actorId) ||
        result.version !== 1 ||
        result.scope !== 'coverage_gap' ||
        result.action !== expected.action ||
        result.actor_id !== expected.actorId ||
        result.request_id !== expected.requestId ||
        result.staffing_resolved !== false ||
        !positiveId(result.audit_id) ||
        instant(result.committed_at) === null ||
        window.site_id !== target.site_id ||
        window.coverage_requirement_id !== target.coverage_requirement_id ||
        window.coverage_window_key !== expected.gap.coverage_window_key ||
        instant(window.window_starts_at) === null ||
        instant(window.window_starts_at) !== instant(target.window_starts_at) ||
        instant(window.window_ends_at) === null ||
        instant(window.window_ends_at) !== instant(target.window_ends_at) ||
        !Array.isArray(result.cleared_ids) ||
        !result.cleared_ids.every(positiveId) ||
        new Set(result.cleared_ids).size !== result.cleared_ids.length
    )
        return null;
    if (expected.action === 'clear') {
        if (
            result.state !== null ||
            result.acknowledgement_id !== null ||
            result.reason !== null ||
            (result.outcome !== 'cleared' && result.outcome !== 'unchanged') ||
            result.changed !== result.cleared_ids.length > 0 ||
            result.outcome !== (result.changed ? 'cleared' : 'unchanged')
        )
            return null;
    } else if (
        result.outcome !== 'recorded' ||
        result.changed !== true ||
        !positiveId(result.acknowledgement_id) ||
        result.state !== (expected.action === 'ack' ? 'acked' : 'dismissed') ||
        result.reason !== (expected.reason ?? null)
    )
        return null;
    return result as CoverageReviewResult;
}
export function csvCell(value: unknown): string {
    const text = String(value ?? '');
    const first = [...text].find(
        (character) => character.charCodeAt(0) > 31 && !/\s/.test(character),
    );
    const safe = first && '=+@-'.includes(first) ? "'" + text : text;
    return '"' + safe.replace(/"/g, '""') + '"';
}
